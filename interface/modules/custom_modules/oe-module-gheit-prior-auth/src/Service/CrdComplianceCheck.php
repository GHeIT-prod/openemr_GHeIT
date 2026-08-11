<?php

/**
 * Orchestrates a single CRD order-sign check for one order. The FHIR
 * payload common.php actually hands this is a transaction Bundle
 * (Patient + ServiceRequest + Encounter, cross-linked by urn:uuid
 * references) - not a bare ServiceRequest as originally assumed. This
 * class pulls the real ServiceRequest out of that bundle and resolves its
 * references before building the CDS Hooks context.
 *
 * @package   GheitPriorAuth
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitPriorAuth\Service;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\FHIR\FhirCoverageService;

class CrdComplianceCheck
{
    private const CPT_SYSTEM = 'http://www.ama-assn.org/go/cpt';

    /**
     * @param array $fhirBundle   the transaction Bundle common.php builds
     *              (Patient + ServiceRequest + Encounter entries)
     * @param int   $patientId     OpenEMR pid - used only for local logging/
     *              audit (cds_hooks_crd_log), NOT sent to Nucural - the FHIR
     *              Patient resource's own uuid is used for that instead
     * @param int   $encounterId   OpenEMR encounter - same, local-only
     * @param int   $orderId       procedure_order_id
     * @param int   $practitionerId provider_id of the signing practitioner
     */
    public static function run(
        array $fhirBundle,
        int $patientId,
        int $encounterId,
        int $orderId,
        int $practitionerId
    ): void {
        try {
            // if (empty($GLOBALS['enable_cds_hooks'])) {
            //     return;
            // }

            $client = new CdsHooksClient();
            $router = new CrdCardRouter();

            $services = array_filter(
                $client->getEnabledServices(),
                fn($service) => ($service['hook'] ?? '') === 'order-sign'
            );
            if (empty($services)) {
                return;
            }

            $resources = self::extractResourcesFromBundle($fhirBundle);
            if ($resources['serviceRequest'] === null) {
                (new SystemLogger())->warning(
                    "CrdComplianceCheck::run() bundle has no ServiceRequest entry",
                    ['orderId' => $orderId]
                );
                return;
            }
            if ($resources['patient'] === null || $resources['encounter'] === null) {
                (new SystemLogger())->warning(
                    "CrdComplianceCheck::run() bundle missing Patient or Encounter entry, cannot build references",
                    ['orderId' => $orderId, 'hasPatient' => $resources['patient'] !== null, 'hasEncounter' => $resources['encounter'] !== null]
                );
                return;
            }

            $serviceRequest = self::resolveReferences(
                $resources['serviceRequest'],
                $resources['patient'],
                $resources['encounter']
            );

            $serviceRequest = self::resolveCptCode($serviceRequest, $orderId);

            $cptCode = self::extractCptCode($serviceRequest);
            if ($cptCode === null) {
                // Warn but do not block - this particular order may genuinely
                // have no CPT coded yet (seen in practice: code = data-absent-reason).
                // Nucural still receives the full ServiceRequest either way.
                (new SystemLogger())->warning(
                    "CrdComplianceCheck::run() ServiceRequest has no CPT coding - sending anyway",
                    ['orderId' => $orderId]
                );
            }

            $prefetch = self::buildPrefetch($resources['patient'], $patientId);

            $orderContext = [
                'order_id'     => $orderId,
                'patient_id'   => $patientId,
                'encounter_id' => $encounterId,
            ];

            $hookContext = $client->buildOrderSignContext(
                $resources['patient']['id'],
                (string) $practitionerId,
                $resources['encounter']['id'],
                $serviceRequest
            );

            foreach ($services as $service) {
                $cards = $client->callService($service, $hookContext, $prefetch);

                // file_put_contents(__DIR__ . '/last-card.json', json_encode($cards, JSON_PRETTY_PRINT));

                if (empty($cards)) {
                    self::logDecision($orderContext, 'no-pa', ['summary' => 'no cards returned']);
                    continue;
                }

                $severity = ['no-pa' => 0, 'unknown' => 1, 'pa-required' => 2];
                $winningRouted = null;
                $winningCard = null;

                foreach ($cards as $card) {
                    $routed = $router->routeCard($card, $orderContext);
                    if ($winningRouted === null || $severity[$routed['status']] > $severity[$winningRouted['status']]) {
                        $winningRouted = $routed;
                        $winningCard = $card;
                    }
                }

                if ($winningRouted !== null) {
                    $router->persistStatus($orderContext, $winningRouted, $winningCard);
                }
            }
        } catch (\Throwable $e) {
            (new SystemLogger())->error(
                "CrdComplianceCheck::run() failed",
                ['orderId' => $orderId, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );
        }
    }

    /**
     * Pull the ServiceRequest, Patient, and Encounter resources out of the
     * transaction Bundle by resourceType. Bundle order isn't guaranteed, so
     * this scans all entries rather than assuming a fixed index.
     *
     * @return array {serviceRequest: array|null, patient: array|null, encounter: array|null}
     */
    private static function extractResourcesFromBundle(array $bundle): array
    {
        $result = ['serviceRequest' => null, 'patient' => null, 'encounter' => null];

        foreach ($bundle['entry'] ?? [] as $entry) {
            $resource = $entry['resource'] ?? null;
            if ($resource === null) {
                continue;
            }
            switch ($resource['resourceType'] ?? '') {
                case 'ServiceRequest':
                    $result['serviceRequest'] = $resource;
                    break;
                case 'Patient':
                    $result['patient'] = $resource;
                    break;
                case 'Encounter':
                    $result['encounter'] = $resource;
                    break;
            }
        }

        return $result;
    }

    /**
     * The ServiceRequest inside the bundle references Patient/Encounter via
     * urn:uuid (bundle-internal linking), e.g.
     * "subject": {"reference": "urn:uuid:6a9e9531-..."}. Nucural's expected
     * payload uses direct "Patient/{id}" / "Encounter/{id}" references
     * instead, matching their own example. This rewrites both in place on a
     * copy of the ServiceRequest, using the real .id from the sibling
     * Patient/Encounter resources (not the urn:uuid, which is just a
     * bundle-internal linking token, not a real resource id).
     */
    private static function resolveReferences(array $serviceRequest, array $patient, array $encounter): array
    {
        if (isset($serviceRequest['subject'])) {
            $serviceRequest['subject']['reference'] = 'Patient/' . $patient['id'];
        }
        if (isset($serviceRequest['encounter'])) {
            $serviceRequest['encounter']['reference'] = 'Encounter/' . $encounter['id'];
        }

        // Draft orders in order-sign shouldn't carry server-assigned meta or
        // an "active" status - the order hasn't been signed yet.
        $serviceRequest['status'] = 'draft';
        unset($serviceRequest['meta']);

        return $serviceRequest;
    }

    private static function extractCptCode(array $serviceRequest): ?string
    {
        foreach ($serviceRequest['code']['coding'] ?? [] as $coding) {
            if (($coding['system'] ?? '') === self::CPT_SYSTEM) {
                return $coding['code'] ?? null;
            }
        }
        return null;
    }

    private static function buildPrefetch(array $patient, int $patientId): array
    {
        $prefetch = ['patient' => $patient];

        $coverageService = new FhirCoverageService();
        $result = $coverageService->getAll(['patient' => $patient['id']]);
        $coverageRecords = $result->getData();

        if (empty($coverageRecords)) {
            (new SystemLogger())->warning(
                "CrdComplianceCheck::buildPrefetch() no active Coverage found for patient - sending prefetch without coverage",
                ['patientId' => $patientId]
            );
            return $prefetch;
        }

        // Prefer primary coverage (order = 1) if the patient has multiple policies
        $primary = null;
        foreach ($coverageRecords as $coverage) {
            $order = $coverage->getOrder();
            if ($order !== null && (int) $order->getValue() === 1) {
                $primary = $coverage;
                break;
            }
        }
        $chosen = $primary ?? $coverageRecords[0];

        $prefetch['coverage'] = json_decode(json_encode($chosen), true);

        return $prefetch;
    }

    private static function resolveCptCode(array $serviceRequest, int $orderId): array
    {
        if (self::extractCptCode($serviceRequest) !== null) {
            return $serviceRequest;
        }

        $row = sqlQuery(
            "SELECT `procedure_code`, `procedure_name` FROM `procedure_order_code`
            WHERE `procedure_order_id` = ? AND `procedure_code` != '' LIMIT 1",
            [$orderId]
        );

        error_log('CRD: NeoCareX CPT: ' . $row['procedure_code'] . ' and display ' . $row['procedure_name']);

        if (empty($row['procedure_code'])) {
            return $serviceRequest;
        }

        $rawCode = $row['procedure_code'];
        $bareCode = strpos($rawCode, ':') !== false
            ? substr($rawCode, strpos($rawCode, ':') + 1)
            : $rawCode;

        $serviceRequest['code'] = [
            'coding' => [[
                'system'  => self::CPT_SYSTEM,
                'code'    => $bareCode,
                'display' => $row['procedure_name'] ?? '',
            ]],
        ];

        return $serviceRequest;
    }

    private static function logDecision(array $orderContext, string $status, array $card): void
    {
        sqlStatement(
            "INSERT INTO `cds_hooks_crd_log` (`order_id`, `patient_id`, `status`, `card_summary`, `date`) " .
            "VALUES (?, ?, ?, ?, NOW())",
            [$orderContext['order_id'] ?? null, $orderContext['patient_id'] ?? null, $status, $card['summary'] ?? '']
        );
    }
}
