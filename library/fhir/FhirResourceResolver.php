<?php

use OpenEMR\Services\FHIR\FhirPatientService;
use OpenEMR\Services\FHIR\FhirOrganizationService;
use OpenEMR\Services\FHIR\FhirLocationService;
use OpenEMR\Services\FHIR\FhirPractitionerService;
use OpenEMR\Services\FHIR\FhirEncounterService;

class FhirResourceResolver
{
    public static function resolveResourceContext(array $resource): array
    {
        $patientService      = new FhirPatientService();
        $locationService     = new FhirLocationService();
        $orgService          = new FhirOrganizationService();
        $practitionerService = new FhirPractitionerService();
        $encounterService    = new FhirEncounterService();

        $patient = null;

        $patients      = [];
        $locations     = [];
        $organizations = [];
        $practitioners = [];
        $encounters    = [];

        $seen = [
            'Patient'      => [],
            'Practitioner' => [],
            'Location'     => [],
            'Organization' => [],
            'Encounter'    => [],
        ];

        /*
        |----------------------------------------------------------------------
        | PATIENT  (subject)
        |----------------------------------------------------------------------
        */
        if (isset($resource['subject']['reference'])) {
            $patient = self::resolveReference(
                $resource['subject']['reference'],
                $patientService,
                $seen['Patient']
            );
        }

        /*
        |----------------------------------------------------------------------
        | BENEFICIARY  (Coverage / ExplanationOfBenefit)
        |----------------------------------------------------------------------
        */
        if (!$patient && isset($resource['beneficiary']['reference'])) {
            $patient = self::resolveReference(
                $resource['beneficiary']['reference'],
                $patientService,
                $seen['Patient']
            );
        }

        /*
        |----------------------------------------------------------------------
        | ENCOUNTER  (direct reference on the resource, e.g. Observation)
        |----------------------------------------------------------------------
        */
        if (isset($resource['encounter']['reference'])) {
            $resolved = self::resolveReference(
                $resource['encounter']['reference'],
                $encounterService,
                $seen['Encounter']
            );
            if ($resolved) {
                $encounters[] = $resolved;
            }
        }

        /*
        |----------------------------------------------------------------------
        | REQUESTER  (ServiceRequest)
        |----------------------------------------------------------------------
        */
        if (isset($resource['requester']['reference'])) {
            self::routeReference(
                $resource['requester']['reference'],
                $patientService, $locationService, $practitionerService,
                $orgService, $encounterService,
                $patients, $locations, $practitioners, $organizations, $encounters,
                $seen
            );
        }

        /*
        |----------------------------------------------------------------------
        | PARTICIPANTS  (Encounter, Appointment, …)
        | Supports both actor.reference (R4) and individual.reference (STU3)
        |----------------------------------------------------------------------
        */
        if (isset($resource['participant'])) {
            foreach ($resource['participant'] as $p) {

                $reference = $p['actor']['reference']
                    ?? $p['individual']['reference']
                    ?? null;

                if (!$reference) {
                    continue;
                }

                self::routeReference(
                    $reference,
                    $patientService, $locationService, $practitionerService,
                    $orgService, $encounterService,
                    $patients, $locations, $practitioners, $organizations, $encounters,
                    $seen
                );
            }
        }

        /*
        |----------------------------------------------------------------------
        | PATIENT PROMOTION
        | Resources like Appointment have no top-level subject/beneficiary.
        | The patient arrives via participant[].actor instead.
        | If $patient is still null after all subject/beneficiary checks,
        | promote the first resolved patient from the $patients array.
        |----------------------------------------------------------------------
        */
        if (!$patient && !empty($patients)) {
            $patient = $patients[0];
        }

        /*
        |----------------------------------------------------------------------
        | LOCATION  (Encounter.location[].location.reference)
        |----------------------------------------------------------------------
        */
        if (isset($resource['location'])) {
            foreach ($resource['location'] as $loc) {

                $reference = $loc['location']['reference']
                    ?? $loc['reference']
                    ?? null;

                if (!$reference) {
                    continue;
                }

                self::routeReference(
                    $reference,
                    $patientService, $locationService, $practitionerService,
                    $orgService, $encounterService,
                    $patients, $locations, $practitioners, $organizations, $encounters,
                    $seen
                );
            }
        }

        /*
        |----------------------------------------------------------------------
        | LOCATION REFERENCES  (ServiceRequest.locationReference[])
        |----------------------------------------------------------------------
        */
        if (isset($resource['locationReference'])) {
            foreach ($resource['locationReference'] as $loc) {

                $reference = $loc['reference'] ?? null;
                if (!$reference) {
                    continue;
                }

                self::routeReference(
                    $reference,
                    $patientService, $locationService, $practitionerService,
                    $orgService, $encounterService,
                    $patients, $locations, $practitioners, $organizations, $encounters,
                    $seen
                );
            }
        }

        /*
        |----------------------------------------------------------------------
        | SERVICE PROVIDER  (Encounter.serviceProvider)
        |----------------------------------------------------------------------
        */
        if (isset($resource['serviceProvider']['reference'])) {
            self::routeReference(
                $resource['serviceProvider']['reference'],
                $patientService, $locationService, $practitionerService,
                $orgService, $encounterService,
                $patients, $locations, $practitioners, $organizations, $encounters,
                $seen
            );
        }

        /*
        |----------------------------------------------------------------------
        | PAYOR  (Coverage / ExplanationOfBenefit)
        |----------------------------------------------------------------------
        */
        if (isset($resource['payor'])) {
            foreach ($resource['payor'] as $payor) {

                $reference = $payor['reference'] ?? null;
                if (!$reference) {
                    continue;
                }

                self::routeReference(
                    $reference,
                    $patientService, $locationService, $practitionerService,
                    $orgService, $encounterService,
                    $patients, $locations, $practitioners, $organizations, $encounters,
                    $seen
                );
            }
        }

        /*
        |----------------------------------------------------------------------
        | PERFORMER  (Observation, Procedure, …)
        |----------------------------------------------------------------------
        */
        if (isset($resource['performer'])) {
            foreach ($resource['performer'] as $p) {

                $reference = $p['reference'] ?? null;
                if (!$reference) {
                    continue;
                }

                self::routeReference(
                    $reference,
                    $patientService, $locationService, $practitionerService,
                    $orgService, $encounterService,
                    $patients, $locations, $practitioners, $organizations, $encounters,
                    $seen
                );
            }
        }

        /*
        |----------------------------------------------------------------------
        | AUTHOR  (DocumentReference, Composition, …)
        |----------------------------------------------------------------------
        */
        if (isset($resource['author'])) {
            foreach ($resource['author'] as $author) {

                $reference = $author['reference'] ?? null;
                if (!$reference) {
                    continue;
                }

                self::routeReference(
                    $reference,
                    $patientService, $locationService, $practitionerService,
                    $orgService, $encounterService,
                    $patients, $locations, $practitioners, $organizations, $encounters,
                    $seen
                );
            }
        }

        /*
        |----------------------------------------------------------------------
        | BINARY DETECTION  (DocumentReference.content[].attachment.url)
        | We only detect the ID here; the actual Binary fetch happens outside
        | this resolver (the caller must fetch Binary separately if needed).
        |----------------------------------------------------------------------
        */
        if (isset($resource['content'])) {
            foreach ($resource['content'] as $content) {

                $url = $content['attachment']['url'] ?? null;
                if (!$url) {
                    continue;
                }

                // Normalize: /fhir/Binary/<id>  OR  Binary/<id>
                if (preg_match('#(?:/fhir)?/Binary/([a-zA-Z0-9\-]+)$#', $url, $matches)) {

                    $binaryId = $matches[1];

                    // routeReference will silently skip "Binary/" — that is intentional:
                    // Binary resolution requires a different service not present here.
                    // The caller is responsible for fetching binaries and passing them
                    // to FhirBundleBuilder::buildTransactionBundle() as $binaries.
                    // Log for caller awareness:
                    // self::routeReference("Binary/$binaryId", ...);  // not resolvable here
                }
            }
        }

        return [
            'patient'       => $patient,
            'patients'      => self::unique($patients),
            'resource'      => $resource,
            'locations'     => self::unique($locations),
            'organizations' => self::unique($organizations),
            'practitioners' => self::unique($practitioners),
            'encounters'    => self::unique($encounters),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | ROUTER
    | Routes a reference string to the correct typed array.
    | urn:uuid: references are skipped — they are intra-bundle and cannot be
    | fetched from OpenEMR services by UUID alone.
    |--------------------------------------------------------------------------
    */
    private static function routeReference(
        $reference,
        $patientService,
        $locationService,
        $practitionerService,
        $orgService,
        $encounterService,
        array &$patients,
        array &$locations,
        array &$practitioners,
        array &$organizations,
        array &$encounters,
        array &$seen
    ): void {

        if (!$reference || !is_string($reference)) {
            return;
        }

        // urn:uuid: references are intra-bundle pointers; skip silently.
        if (str_starts_with($reference, 'urn:uuid:')) {
            return;
        }

        if (str_starts_with($reference, 'Patient/')) {
            $r = self::resolveReference($reference, $patientService, $seen['Patient']);
            if ($r) $patients[] = $r;
            return;
        }

        if (str_starts_with($reference, 'Practitioner/')) {
            $r = self::resolveReference($reference, $practitionerService, $seen['Practitioner']);
            if ($r) $practitioners[] = $r;
            return;
        }

        if (str_starts_with($reference, 'Location/')) {
            $r = self::resolveReference($reference, $locationService, $seen['Location']);
            if ($r) $locations[] = $r;
            return;
        }

        if (str_starts_with($reference, 'Organization/')) {
            $r = self::resolveReference($reference, $orgService, $seen['Organization']);
            if ($r) $organizations[] = $r;
            return;
        }

        if (str_starts_with($reference, 'Encounter/')) {
            $r = self::resolveReference($reference, $encounterService, $seen['Encounter']);
            if ($r) $encounters[] = $r;
            return;
        }

        // Binary/ and unknown resource types are intentionally ignored here.

        if (str_starts_with($reference, 'Person/')) {
            /*
            |----------------------------------------------------------------------
            | OpenEMR references some providers as Person/<uuid>.
            | Try to resolve as Practitioner using the same UUID.
            | If found, it goes into $practitioners so the bundle builder
            | can include it and patch the reference to urn:uuid:.
            |----------------------------------------------------------------------
            */
            $personId = explode('/', $reference)[1] ?? null;
            if ($personId) {
                $r = self::resolveReference(
                    "Practitioner/$personId",
                    $practitionerService,
                    $seen['Practitioner']
                );
                if ($r) {
                    $practitioners[] = $r;
                }
            }
            return;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SAFE RESOLVER  (with deduplication via $seenList)
    |--------------------------------------------------------------------------
    */
    private static function resolveReference(string $reference, $service, array &$seenList): ?array
    {
        $id = self::extractId($reference);
        if (!$id) {
            return null;
        }

        // Return cached result if already fetched in this request
        if (isset($seenList[$id])) {
            return $seenList[$id];
        }

        $result = $service->getOne($id)->getData()[0] ?? null;

        // Normalize object → array
        if (is_object($result)) {
            $result = json_decode(json_encode($result), true);
        }

        if ($result) {
            $seenList[$id] = $result;
        }

        return $result ?: null;
    }

    /*
    |--------------------------------------------------------------------------
    | UNIQUE  (deduplicate by resource id)
    |--------------------------------------------------------------------------
    */
    private static function unique(array $resources): array
    {
        $unique = [];

        foreach ($resources as $r) {

            if (!$r) {
                continue;
            }

            if (is_object($r)) {
                $r = json_decode(json_encode($r), true);
            }

            if (!isset($r['id'])) {
                continue;
            }

            $unique[$r['id']] = $r;
        }

        return array_values($unique);
    }

    /*
    |--------------------------------------------------------------------------
    | EXTRACT ID  from "ResourceType/id" strings
    |--------------------------------------------------------------------------
    */
    private static function extractId(string $reference): ?string
    {
        // urn:uuid: references cannot be resolved via service
        if (str_starts_with($reference, 'urn:uuid:')) {
            return null;
        }

        $parts = explode('/', $reference);

        // Expect exactly "ResourceType/id"
        return isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
    }
}