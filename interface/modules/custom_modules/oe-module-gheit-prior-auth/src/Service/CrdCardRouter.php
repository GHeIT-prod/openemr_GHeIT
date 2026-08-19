<?php

/**
 * Parses the Da Vinci CRD coverage-information extension off a returned
 * card and routes it to one of: no PA, PA required, unknown. Independently
 * detects a DTR launch link, since that can accompany any of the three
 * statuses.
 *
 * @package   GheitPriorAuth
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitPriorAuth\Service;

use OpenEMR\Common\Logging\SystemLogger;

class CrdCardRouter
{
    const STATUS_NO_PA       = 'no-pa';
    const STATUS_PA_REQUIRED = 'pa-required';
    const STATUS_DTR_REQUIRED = 'dtr-required'; 
    const STATUS_UNKNOWN     = 'unknown';

    private const COVERAGE_EXTENSION_URL =
        'http://hl7.org/fhir/us/davinci-crd/StructureDefinition/ext-coverage-information';

    /**
     * Full entry point: parse a card, then decide the action.
     *
     * @return array {status, action, dtr_launch_url}
     */
    public function routeCard(array $card, array $orderContext, array $service = []): array
    {
        $parsed = $this->parseCard($card);

        $response = [
            'status'         => $parsed['status'],
            'action'         => null,
            'dtr_launch_url' => null,
        ];

        switch ($parsed['status']) {
            case self::STATUS_NO_PA:
                $this->logDecision($orderContext, 'no-pa', $card);
                $response['action'] = 'proceed';
                break;

            case self::STATUS_DTR_REQUIRED:
                $this->logDecision($orderContext, 'dtr-required', $card);
                $this->stagePasBundle($orderContext, $card);
                $response['action'] = 'launch-dtr-then-submit';
                break;

            case self::STATUS_PA_REQUIRED:
                $this->logDecision($orderContext, 'pa-required', $card);
                $this->stagePasBundle($orderContext, $card);
                $response['action'] = $parsed['dtr_link'] ? 'launch-dtr-then-submit' : 'submit-pa-bundle';
                break;

            case self::STATUS_UNKNOWN:
            default:
                (new SystemLogger())->error(
                    "CrdCardRouter: unknown coverage status",
                    ['orderContext' => $orderContext, 'card' => $card]
                );
                $response['action'] = 'flag-for-manual-review';
                break;
        }

        return $response;
    }

    /**
     * @return array {status, indicator, pa_needed, covered, dtr_link}
     */
    public function parseCard(array $card): array
    {
        $result = [
            'status'     => self::STATUS_UNKNOWN,
            'indicator'  => $card['indicator'] ?? null,
            'pa_needed'  => null,
            'covered'    => null,
            'doc_needed' => null,
            'dtr_link'   => null,
        ];

        $coverageExt = null;
        foreach ($card['extension'] ?? [] as $ext) {
            if (($ext['url'] ?? '') === self::COVERAGE_EXTENSION_URL) {
                $coverageExt = $ext;
                break;
            }
        }

        if ($coverageExt !== null) {
            foreach ($coverageExt['extension'] ?? [] as $sub) {
                if (($sub['url'] ?? '') === 'covered') {
                    $result['covered'] = $sub['valueCode'] ?? null;
                }
                if (($sub['url'] ?? '') === 'pa-needed') {
                    $result['pa_needed'] = $sub['valueCode'] ?? null;
                }
                if (($sub['url'] ?? '') === 'doc-needed') {
                    $result['doc_needed'] = $sub['valueCode'] ?? null;
                }
            }
        }

        $result['status'] = $this->resolveStatus(
            $result['indicator'],
            $result['covered'],
            $result['pa_needed'],
            $result['doc_needed']
        );

        foreach ($card['links'] ?? [] as $link) {
            if (($link['type'] ?? '') === 'smart') {
                $result['dtr_link'] = $link;
                break;
            }
        }

        return $result;
    }

    /**
     * Primary signal is `indicator` (warning/critical = PA required), per
     * Nucural's real behavior - not every payer populates the fuller
     * coverage-information extension. The extension, when present, can only
     * strengthen a "no PA" read (never override a warning/critical
     * indicator) - a payer saying "warning" but also sending a stray
     * no-auth-needed extension should still be treated as PA required, not
     * silently downgraded.
     */
    private function resolveStatus(?string $indicator, ?string $covered, ?string $paNeeded, ?string $docNeeded = null): string
    {
        if (!empty($docNeeded)) {
            return self::STATUS_DTR_REQUIRED;
        }
        if (in_array($indicator, ['warning', 'critical'], true)) {
            return self::STATUS_PA_REQUIRED;
        }
        if ($paNeeded === 'auth-needed' || $paNeeded === 'conditional') {
            return self::STATUS_PA_REQUIRED;
        }
        if ($paNeeded === 'no-auth-needed' && in_array($covered, ['covered', 'conditional'], true)) {
            return self::STATUS_NO_PA;
        }
        if ($indicator === 'info' && $paNeeded === null) {
            return self::STATUS_NO_PA;
        }
        return self::STATUS_UNKNOWN;
    }

    /**
     * SMART App Launch URL for the DTR/Aidbox PA app iframe. iss + an
     * opaque, short-lived launch token as query params - never raw ids.
     */
    private function buildDtrLaunchUrl(array $dtrLink, array $orderContext, array $service = []): string
    {
        $launchToken = $this->mintLaunchToken($orderContext);

        $params = http_build_query([
            'iss'    => rtrim($service['fhir_server'] ?? ($GLOBALS['fhir_base_url'] ?? ''), '/'),
            'launch' => $launchToken,
        ]);

        $separator = str_contains($dtrLink['url'], '?') ? '&' : '?';
        return $dtrLink['url'] . $separator . $params;
    }

    /**
     * TODO: persist {token => orderContext} with a short expiry (e.g. 5 min)
     * in a dedicated table so a token-exchange endpoint can resolve it when
     * the DTR app calls back during its own OAuth2 flow.
     */
    private function mintLaunchToken(array $orderContext): string
    {
        return bin2hex(random_bytes(24));
    }

    private function logDecision(array $orderContext, string $status, array $card): void
    {
        sqlStatement(
            "INSERT INTO `cds_hooks_crd_log` (`order_id`, `patient_id`, `status`, `card_summary`, `date`) " .
            "VALUES (?, ?, ?, ?, NOW())",
            [$orderContext['order_id'] ?? null, $orderContext['patient_id'] ?? null, $status, $card['summary'] ?? '']
        );
    }

    /**
     * Skeleton only - population of Claim/ServiceRequest/QuestionnaireResponse
     * and actual submission to the payer are separate, not-yet-built steps.
     */
    private function stagePasBundle(array $orderContext, array $card): array
    {
        return [
            'resourceType' => 'Bundle',
            'type'         => 'collection',
            'entry'        => [],
        ];
    }

    public function persistStatus(array $orderContext, array $routed, array $card): void
    {
        $file = __DIR__ . '/persisted-responses.json';

        $existingResponses = [];

        if (file_exists($file)) {
            $existingResponses = json_decode(file_get_contents($file), true) ?? [];
        }

        $existingResponses[] = [
            'timestamp' => date('c'),
            'routed' => $routed,
            'card' => $card,
            'orderContext' => $orderContext,
        ];

        file_put_contents(
            $file,
            json_encode(
                $existingResponses,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );
        $authorizationNumber = 'AUTH-' . date('Y-m-d') . '-' . '00'.$orderContext['order_id'];
        sqlStatement(
            "INSERT INTO `cds_hooks_crd_status`
                (`order_id`, `patient_id`, `status`, `action`, `dtr_launch_url`, `card_summary`, `updated_at`, `created_at`, `encounter_id`, `resource_id`, `authorization_number`)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                `status` = VALUES(`status`),
                `action` = VALUES(`action`),
                `dtr_launch_url` = VALUES(`dtr_launch_url`),
                `card_summary` = VALUES(`card_summary`),
                `updated_at` = VALUES(`updated_at`)",
            [
                $orderContext['order_id'] ?? null,
                $orderContext['patient_id'] ?? null,
                $routed['status'],
                $routed['action'],
                $routed['dtr_launch_url'],
                $card['summary'] ?? null,
                $orderContext['encounter_id'] ?? null,
                $card['resourceId'] ?? null,
                $authorizationNumber
            ]
        );
    }
}
