<?php

/**
 * CDS Hooks client: registry read/write against cds_hooks_services, plus
 * the discovery and call-out logic.
 *
 * @package   GheitPriorAuth
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitPriorAuth\Service;

use OpenEMR\Common\Logging\SystemLogger;

class CdsHooksClient
{
    /**
     * @return array enabled rows from cds_hooks_services
     */
    public function getEnabledServices(): array
    {
        $sql = sqlStatement("SELECT * FROM `cds_hooks_services` WHERE `enabled` = 1");
        $services = [];
        while ($row = sqlFetchArray($sql)) {
            $services[] = $row;
        }
        return $services;
    }

    /**
     * @return array all rows from cds_hooks_services, for the admin screen
     */
    public function getAllServices(): array
    {
        $sql = sqlStatement("SELECT * FROM `cds_hooks_services` ORDER BY `name`");
        $services = [];
        while ($row = sqlFetchArray($sql)) {
            $services[] = $row;
        }
        return $services;
    }

    public function getServiceById(int $id): ?array
    {
        $row = sqlQuery("SELECT * FROM `cds_hooks_services` WHERE `id` = ?", [$id]);
        return $row ?: null;
    }

    /**
     * Discovery: GET the vendor's base URL, filter to the given hook type.
     * Call only from an admin registration screen, never from a request path.
     */
    public function discoverServices(string $baseUrl, string $hookFilter = 'patient-view'): array
    {
        $ch = curl_init(rtrim($baseUrl, '/'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new \Exception("Discovery request failed (HTTP $httpCode): $error");
        }

        $decoded = json_decode($response, true);
        if (empty($decoded['services']) || !is_array($decoded['services'])) {
            throw new \Exception("Discovery response did not contain a services array");
        }

        return array_values(array_filter(
            $decoded['services'],
            fn($svc) => ($svc['hook'] ?? null) === $hookFilter
        ));
    }

    /**
     * Persist one chosen service. Call once per service an admin selects
     * from discoverServices() results.
     */
    public function registerService(
        string $name,
        string $baseUrl,
        string $serviceId,
        string $hook = 'patient-view',
        ?string $authToken = null,
        int $timeoutSeconds = 3,
        bool $enabled = true,
        ?string $tokenUrl = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?int $tenantId = null,
        ?string $serviceHash = null,
        ?string $fhirServer = null
    ): int {
        return sqlInsert(
            "INSERT INTO `cds_hooks_services`
                (`name`, `base_url`, `service_id`, `hook`, `enabled`, `auth_token`, `timeout_seconds`, `token_url`, `client_id`, `client_secret`, `tenant_id`, `service_hash`, `fhir_server`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$name, rtrim($baseUrl, '/'), $serviceId, $hook, $enabled ? 1 : 0, $authToken, $timeoutSeconds, $tokenUrl, $clientId, $clientSecret, $tenantId, $serviceHash, $fhirServer]
        );
    }

    public function updateService(int $id, array $fields): void
    {
        $allowed = ['name', 'base_url', 'service_id', 'hook', 'enabled', 'auth_token', 'timeout_seconds', 'token_url', 'client_id', 'client_secret', 'cached_token', 'cached_token_expires_at', 'tenant_id', 'service_hash', 'fhir_server'];
        $sets = [];
        $binds = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $sets[] = "`" . $key . "` = ?";
                $binds[] = $value;
            }
        }
        if (empty($sets)) {
            return;
        }
        $binds[] = $id;
        sqlStatement("UPDATE `cds_hooks_services` SET " . implode(', ', $sets) . " WHERE `id` = ?", $binds);
    }

    public function deleteService(int $id): void
    {
        sqlStatement("DELETE FROM `cds_hooks_services` WHERE `id` = ?", [$id]);
    }

    /**
     * POST a hook request to one configured service, return its cards
     * (empty array on failure/timeout - callers should not treat that as
     * an "Unknown" coverage status by default, since it may just be a
     * transient network failure rather than the service's own answer).
     *
     * URL is base_url as-is, no service_id suffix appended - confirmed
     * against Nucural's real endpoint, which is already a complete URL.
     *
     * @param array $service  row from cds_hooks_services
     * @param array $context  CDS Hooks context object - use buildOrderSignContext()
     *                        to build this in the exact shape Nucural expects
     */
    public function callService(array $service, array $context, array $prefetch = []): array
    {
        $url = rtrim($service['base_url'], '/') . '/' . $service['tenant_id'] . '/' . $service['service_hash'] . '/cds-services/' . $service['service_id'];

        $payload = [
            'hook'         => $service['hook'] ?? 'patient-view',
            'hookInstance' => $this->generateUuid(),
            'fhirServer'   => rtrim($service['fhir_server'] ?? ($GLOBALS['fhir_base_url'] ?? ''), '/'),
            'context'      => $context,
            'prefetch'     => $prefetch,
        ];

        $jsonPayload = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        // file_put_contents(__DIR__ . '/last-payload.json', $jsonPayload);

        $payload_file = __DIR__ . '/all-payload.json';
    
        $existingPayloads = [];
        $existingPayloads[] = [
            'timestamp' => date('c'),
            'body' => $payload,
        ];

        file_put_contents(
            $payload_file,
            json_encode(
                $existingPayloads,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );

        $headers = ['Content-Type: application/json'];
        $token = $this->getBearerToken($service);
        if (!empty($token)) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($service['timeout_seconds'] ?? 3));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // file_put_contents(__DIR__ . '/last-response.json', json_encode([
        //     'httpCode' => $httpCode,
        //     'curlError' => $error,
        //     'body' => $response,
        // ], JSON_PRETTY_PRINT));

        if ($response === false || $httpCode !== 200) {
            (new SystemLogger())->error(
                "CdsHooksClient::callService() failed",
                ['service' => $service['name'] ?? '', 'httpCode' => $httpCode, 'error' => $error]
            );
            return [];
        }

        $decoded = json_decode($response, true);

        // error_log('CRD: Nucural Service Response: ' . json_encode($decoded));

        $file = __DIR__ . '/all-responses.json';

        $existingResponses = [];

        if (file_exists($file)) {
            $existingResponses = json_decode(file_get_contents($file), true) ?? [];
        }

        $existingResponses[] = [
            'timestamp' => date('c'),
            'httpCode' => $httpCode,
            'curlError' => $error,
            'body' => json_decode($response, true) ?? $response,
        ];

        file_put_contents(
            $file,
            json_encode(
                $existingResponses,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );

        if (empty($decoded['cards']) && empty($decoded['systemActions'])) {
            return [];
        }

        $cards = is_array($decoded['cards'] ?? null) ? $decoded['cards'] : [];

        // Adapt systemActions into the same card shape CrdCardRouter::parseCard()
        // already understands (extension list is what carries the real signal -
        // systemActions don't carry `indicator` or `links`, only cards do).
        foreach ($decoded['systemActions'] ?? [] as $action) {
            $resource = $action['resource'] ?? [];
            $links = $this->extractSystemActionLinks($action, $resource);

            $cards[] = [
                'summary'    => $resource['note'][0]['text'] ?? ($action['description'] ?? 'System action received'),
                'indicator'  => null,
                'extension'  => $resource['extension'] ?? [],
                'links'      => $links,
                'source'     => ['label' => 'systemAction'],
                'resourceId' => $resource['id'] ?? null,
            ];
        }

        return $cards;
    }

    /**
     * Build the CDS Hooks context for an order-sign check, matching
     * Nucural's real example payload: patientId and encounterId as full
     * "Patient/{uuid}" / "Encounter/{uuid}" references using the FHIR
     * resource's own id (NOT OpenEMR's internal integer pid/encounter -
     * those don't match anything on Nucural's side). userId stays as a
     * Practitioner reference built from OpenEMR's user id, since no FHIR
     * Practitioner resource is available to pull a uuid from.
     *
     * @param string $patientFhirId   Patient resource's own .id (uuid)
     * @param string $practitionerId  OpenEMR user id - formatted as "Practitioner/{id}"
     * @param string $encounterFhirId Encounter resource's own .id (uuid)
     * @param array  $draftOrderResource the ServiceRequest, with subject/encounter
     *               references already resolved to Patient/{id} and Encounter/{id}
     * @return array CDS Hooks context object
     */
    public function buildOrderSignContext(
        string $patientFhirId,
        string $practitionerId,
        string $encounterFhirId,
        array $draftOrderResource,
        array $prefetch = []
    ): array {
        $context = [
            'userId'      => 'Practitioner/' . $practitionerId,
            'patientId'   => 'Patient/' . $patientFhirId,
            'encounterId' => 'Encounter/' . $encounterFhirId,
            'draftOrders' => [
                'resourceType' => 'Bundle',
                'type'         => 'collection',
                'entry'        => [
                    ['resource' => $draftOrderResource],
                ],
            ],
        ];
        return $context;
    }

    /**
     * Fetch (and cache) an OAuth2 client_credentials bearer token for a
     * service. Falls back to the static `auth_token` column if no
     * `token_url` is configured for that service.
     *
     * @param array $service row from cds_hooks_services
     * @return string|null
     */
    private function getBearerToken(array $service): ?string
    {
        if (empty($service['token_url'])) {
            return $service['auth_token'] ?? null;
        }

        if (
            !empty($service['cached_token']) &&
            !empty($service['cached_token_expires_at']) &&
            strtotime($service['cached_token_expires_at']) > (time() + 30)
        ) {
            return $service['cached_token'];
        }

        $ch = curl_init($service['token_url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $service['client_id'] ?? '',
            'client_secret' => $service['client_secret'] ?? '',
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            (new SystemLogger())->error(
                "CdsHooksClient::getBearerToken() failed",
                ['service' => $service['name'] ?? '', 'httpCode' => $httpCode]
            );
            return null;
        }

        $decoded = json_decode($response, true);
        $token = $decoded['access_token'] ?? null;
        $expiresIn = (int) ($decoded['expires_in'] ?? 300);

        if (!empty($token)) {
            // Deliberately NOT logging the raw token value - it's a live
            // credential, not diagnostic data. Only its expiry is logged.
            error_log('CRD: cached bearer token for service ' . $service['name'] . ' at ' . $service['base_url'] . ', expires in ' . $expiresIn . ' seconds');
            $this->updateService((int) $service['id'], [
                'cached_token'            => $token,
                'cached_token_expires_at' => date('Y-m-d H:i:s', time() + $expiresIn),
            ]);
        }

        return $token;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function extractSystemActionLinks(array $action, array $resource): array
    {
        // Shape 1: vendor already used the card "links" convention directly
        // on the systemAction.
        if (!empty($action['links']) && is_array($action['links'])) {
            return array_values(array_filter(
                $action['links'],
                fn($link) => ($link['type'] ?? '') === 'smart' && !empty($link['url'])
            ));
        }

        // Shape 2: FHIR-native `link` array on the resource itself
        // (e.g. resource.link = [{ "relation": "...", "url": "..." }]).
        if (!empty($resource['link']) && is_array($resource['link'])) {
            $found = [];
            foreach ($resource['link'] as $link) {
                if (!empty($link['url'])) {
                    $found[] = ['type' => 'smart', 'url' => $link['url']];
                }
            }
            if (!empty($found)) {
                return $found;
            }
        }

        // Shape 3: a launch URL buried in an extension on the resource,
        // identified by "smart" or "launch" appearing in the extension's URL.
        foreach ($resource['extension'] ?? [] as $ext) {
            $extUrl = $ext['url'] ?? '';
            if (stripos($extUrl, 'smart') !== false || stripos($extUrl, 'launch') !== false) {
                $launchUrl = $ext['valueUrl'] ?? $ext['valueString'] ?? null;
                if (!empty($launchUrl)) {
                    return [['type' => 'smart', 'url' => $launchUrl]];
                }
            }
        }

        // Nothing recognized. Not necessarily an error - this systemAction may
        // genuinely have no DTR link - but log it so a real payload with a
        // link can be captured and this method updated to match its actual shape.
        if (!empty($action) || !empty($resource)) {
            (new SystemLogger())->info(
                "CdsHooksClient::extractSystemActionLinks() no recognized link shape on systemAction",
                ['actionKeys' => array_keys($action), 'resourceKeys' => array_keys($resource)]
            );
        }

        return [];
    }
}