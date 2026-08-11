<?php

/**
 * Da Vinci PAS (Prior Authorization Support) Claim submission - the step
 * after DTR documentation requirements are completed.
 *
 * STATUS: structural stub, not functional end-to-end. Two real dependencies
 * are still missing before this can actually run:
 *   1. A way to know DTR is "complete" for a given order - nothing in this
 *      module yet receives that signal (would come from the DTR/Aidbox app
 *      calling back into OpenEMR, likely via its own webhook or a
 *      redirect-with-status back to the order screen).
 *   2. The actual QuestionnaireResponse DTR produced, to attach as
 *      supporting evidence in the Claim bundle - not persisted anywhere yet.
 *
 * @package   GheitPriorAuth
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitPriorAuth\Service;

use OpenEMR\Common\Logging\SystemLogger;

class PasSubmissionService
{
    /**
     * Assemble and submit a Da Vinci PAS $submit request bundle.
     *
     * Real PAS $submit expects a Bundle of type "collection" whose first
     * entry is a Claim (use=preauthorization), followed by every resource
     * that Claim references (Patient, Coverage, the ServiceRequest/Claim
     * items, and any supportingInfo like a QuestionnaireResponse from DTR).
     * This method currently only builds the empty skeleton - population of
     * the actual Claim resource is not done here since it depends on how
     * NeoCareX's Claim/Coverage data is modeled, which hasn't been defined.
     *
     * @param array  $fhirServiceRequest the signed order
     * @param array  $questionnaireResponse DTR's completed QuestionnaireResponse,
     *               if any - pass empty array if DTR wasn't required for this order
     * @param array  $service  the cds_hooks_services row for the target payer
     *               (reused for its base_url/token config - PAS submission
     *               endpoint is typically a sibling of the CDS Hooks endpoint,
     *               not the same URL, so a real implementation needs a
     *               dedicated `pas_submit_url` column, not shown here)
     * @return array|null decoded ClaimResponse on success, null on failure
     */
    public function submit(array $fhirServiceRequest, array $questionnaireResponse, array $service): ?array
    {
        try {
            $bundle = $this->buildSubmitBundle($fhirServiceRequest, $questionnaireResponse);

            // TODO: this needs its own endpoint, not $service['base_url'] as-is -
            // PAS $submit is a distinct FHIR operation URL
            // (e.g. {base}/Claim/$submit), separate from the CDS Hooks
            // service_id path used for order-sign. Left unresolved pending
            // Nucural's actual PAS endpoint documentation.
            (new SystemLogger())->info(
                "PasSubmissionService::submit() bundle assembled but not sent - endpoint undefined",
                ['bundle' => $bundle]
            );

            return null;
        } catch (\Throwable $e) {
            (new SystemLogger())->error("PasSubmissionService::submit() failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @return array bare Bundle skeleton - Claim resource population is the
     *               open TODO described in the class docblock
     */
    private function buildSubmitBundle(array $fhirServiceRequest, array $questionnaireResponse): array
    {
        $entries = [
            // TODO: populate the Claim resource itself (use=preauthorization,
            // item[] referencing $fhirServiceRequest, patient, insurance/Coverage).
        ];

        if (!empty($questionnaireResponse)) {
            $entries[] = ['resource' => $questionnaireResponse];
        }

        return [
            'resourceType' => 'Bundle',
            'type'         => 'collection',
            'entry'        => $entries,
        ];
    }
}
