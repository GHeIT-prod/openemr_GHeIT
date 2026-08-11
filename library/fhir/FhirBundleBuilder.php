<?php

class FhirBundleBuilder
{
    public static function buildTransactionBundle(
        $patient,
        $resource,
        array $locations = [],
        array $organizations = [],
        array $practitioners = [],
        array $encounters = [],
        array $binaries = []
    ): array {

        $patient      = self::normalize($patient);
        $resource     = self::normalize($resource);

        $locations     = array_values(array_filter(array_map([self::class, 'normalize'], $locations)));
        $organizations = array_values(array_filter(array_map([self::class, 'normalize'], $organizations)));
        $practitioners = array_values(array_filter(array_map([self::class, 'normalize'], $practitioners)));
        $encounters    = array_values(array_filter(array_map([self::class, 'normalize'], $encounters)));

        $resourceType = $resource['resourceType'] ?? 'Resource';

        /*
        |----------------------------------------------------------------------
        | Generate real UUIDs for all fullUrl values upfront
        | urn:uuid:patient-0 style labels are NOT valid UUIDs and fail R4
        | validation ("UUIDs must be valid and lowercase")
        |----------------------------------------------------------------------
        */
        $uuidMap = [
            'patient'  => 'urn:uuid:' . self::generateUuid(),
            'resource' => 'urn:uuid:' . self::generateUuid(),
        ];

        $practitionerUuids  = [];
        $locationUuids      = [];
        $organizationUuids  = [];
        $encounterUuids     = [];
        $binaryUuids        = [];

        foreach ($practitioners  as $i => $_) { $practitionerUuids[$i]  = 'urn:uuid:' . self::generateUuid(); }
        foreach ($locations      as $i => $_) { $locationUuids[$i]      = 'urn:uuid:' . self::generateUuid(); }
        foreach ($organizations  as $i => $_) { $organizationUuids[$i]  = 'urn:uuid:' . self::generateUuid(); }
        foreach ($encounters     as $i => $_) { $encounterUuids[$i]     = 'urn:uuid:' . self::generateUuid(); }
        foreach ($binaries       as $i => $_) { $binaryUuids[$i]        = 'urn:uuid:' . self::generateUuid(); }

        $bundle = [
            'resourceType' => 'Bundle',
            'id'           => strtolower($resourceType) . '-bundle-' . uniqid(),
            'type'         => 'transaction',
            'entry'        => [],
        ];

        /*
        |----------------------------------------------------------------------
        | PATIENT
        |----------------------------------------------------------------------
        */
        if (!empty($patient)) {
            $bundle['entry'][] = self::entry($uuidMap['patient'], $patient, 'Patient');
        }

        /*
        |----------------------------------------------------------------------
        | BINARIES  (before DocumentReference so binary map is ready)
        |----------------------------------------------------------------------
        */
        $binaryMap = [];

        foreach ($binaries as $i => $binary) {
            $binary   = self::normalize($binary);
            $uuid     = $binaryUuids[$i];
            $binaryId = $binary['id'] ?? null;

            if ($binaryId) {
                $binaryMap[$binaryId] = $uuid;
            }

            $bundle['entry'][] = self::entry($uuid, $binary, 'Binary');
        }

        /*
        |----------------------------------------------------------------------
        | PRE-PATCH $resource — rewrite all references to urn:uuid: BEFORE
        | entry() captures the resource. This is the fix for the ordering bug.
        |----------------------------------------------------------------------
        */

        // Subject → patient
        if (isset($resource['subject']['reference'])) {
            $resource['subject']['reference'] = $uuidMap['patient'];
        }

        // Beneficiary → patient (Coverage / EOB)
        if (isset($resource['beneficiary']['reference'])) {
            $resource['beneficiary']['reference'] = $uuidMap['patient'];
        }

        // Patient inside participant.actor
        if (isset($resource['participant'])) {
            foreach ($resource['participant'] as &$p) {
                $ref = $p['actor']['reference'] ?? null;
                if ($ref && str_starts_with($ref, 'Patient/')) {
                    $p['actor']['reference'] = $uuidMap['patient'];
                }
            }
            unset($p);
        }

        // Practitioners
        foreach ($practitioners as $i => $practitioner) {
            $id = $practitioner['id'] ?? null;
            if ($id) {
                self::patchReferenceExact($resource, "Practitioner/$id", $practitionerUuids[$i]);
                // Also patch Person/<id> → urn:uuid: in case OpenEMR used Person reference
                self::patchReferenceExact($resource, "Person/$id", $practitionerUuids[$i]);
            }
        }

        // Locations
        foreach ($locations as $i => $location) {
            $id = $location['id'] ?? null;
            if ($id) {
                self::patchReferenceExact($resource, "Location/$id", $locationUuids[$i]);
            }
        }

        // Organizations
        foreach ($organizations as $i => $org) {
            $id = $org['id'] ?? null;
            if ($id) {
                self::patchReferenceExact($resource, "Organization/$id", $organizationUuids[$i]);
            }
        }

        // Encounters referenced inside the main resource (e.g. Observation.encounter)
        foreach ($encounters as $i => $encounter) {
            $id = $encounter['id'] ?? null;
            if ($id) {
                self::patchReferenceExact($resource, "Encounter/$id", $encounterUuids[$i]);
            }
        }

        // Binary URLs inside DocumentReference.content
        if ($resourceType === 'DocumentReference' && isset($resource['content'])) {
            foreach ($resource['content'] as &$content) {
                $url = $content['attachment']['url'] ?? null;
                if (!$url) {
                    continue;
                }
                if (preg_match('#(?:/fhir)?/Binary/([^/\s]+)$#', $url, $m)) {
                    $binaryId = $m[1];
                    if (isset($binaryMap[$binaryId])) {
                        $content['attachment']['url'] = $binaryMap[$binaryId];
                    }
                }
            }
            unset($content);
        }

        // Resource-specific fixes
        if ($resourceType === 'Encounter') {
            $resource = self::fixEncounterResource($resource);
        }

        /*
        |----------------------------------------------------------------------
        | MAIN RESOURCE
        |----------------------------------------------------------------------
        */
        if (!empty($resource)) {
            $bundle['entry'][] = self::entry($uuidMap['resource'], $resource, $resourceType);
        }

        /*
        |----------------------------------------------------------------------
        | PRACTITIONERS
        |----------------------------------------------------------------------
        */
        foreach ($practitioners as $i => $practitioner) {
            $bundle['entry'][] = self::entry($practitionerUuids[$i], $practitioner, 'Practitioner');
        }

        /*
        |----------------------------------------------------------------------
        | LOCATIONS
        |----------------------------------------------------------------------
        */
        foreach ($locations as $i => $location) {
            $bundle['entry'][] = self::entry($locationUuids[$i], $location, 'Location');
        }

        /*
        |----------------------------------------------------------------------
        | ORGANIZATIONS
        |----------------------------------------------------------------------
        */
        foreach ($organizations as $i => $org) {
            $bundle['entry'][] = self::entry($organizationUuids[$i], $org, 'Organization');
        }

        /*
        |----------------------------------------------------------------------
        | ENCOUNTERS  (supporting encounters linked from other resources)
        |----------------------------------------------------------------------
        */
        foreach ($encounters as $i => $encounter) {
            $bundle['entry'][] = self::entry($encounterUuids[$i], $encounter, 'Encounter');
        }

        return $bundle;
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE UUID v4
    |--------------------------------------------------------------------------
    */
    private static function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant bits

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /*
    |--------------------------------------------------------------------------
    | FIX ENCOUNTER-SPECIFIC ISSUES
    |--------------------------------------------------------------------------
    */
    private static function fixEncounterResource(array $resource): array
    {
        // 1. Normalize identifier system + value
        if (isset($resource['identifier'])) {
            foreach ($resource['identifier'] as &$identifier) {
                $system = $identifier['system'] ?? '';
                $value  = $identifier['value']  ?? '';

                if ($system === 'urn:ietf:rfc:3986') {
                    // value must be a full URI — prefix bare UUIDs with urn:uuid:
                    if (
                        preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) &&
                        !str_starts_with($value, 'urn:')
                    ) {
                        $identifier['value'] = 'urn:uuid:' . strtolower($value);
                    }
                }

                // Replace self-referential urn:uuid: systems with proper namespace
                if (str_starts_with($system, 'urn:uuid:')) {
                    $identifier['system'] = 'urn:ietf:rfc:3986';
                    // Also fix the value if it is a bare UUID
                    if (
                        preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) &&
                        !str_starts_with($value, 'urn:')
                    ) {
                        $identifier['value'] = 'urn:uuid:' . strtolower($value);
                    }
                }
            }
            unset($identifier);
        }

        // 2. Add period.end when status is finished and end is missing
        if (
            ($resource['status'] ?? '') === 'finished' &&
            isset($resource['period']['start']) &&
            empty($resource['period']['end'])
        ) {
            $resource['period']['end'] = $resource['period']['start'];
        }

        // 3. Add period.end to participants missing it
        if (isset($resource['participant'])) {
            foreach ($resource['participant'] as &$p) {
                if (isset($p['period']['start']) && empty($p['period']['end'])) {
                    $p['period']['end'] = $p['period']['start'];
                }
            }
            unset($p);
        }

        return $resource;
    }

    /*
    |--------------------------------------------------------------------------
    | FIX PATIENT EXTENSIONS  (R4 compliance)
    |--------------------------------------------------------------------------
    */
   private static function fixPatientExtensions(array &$resource): void
    {
        if (($resource['resourceType'] ?? '') !== 'Patient') {
            return;
        }

        if (!isset($resource['extension']) || !is_array($resource['extension'])) {
            return;
        }

        foreach ($resource['extension'] as $i => &$ext) {

            $url = $ext['url'] ?? '';

            /*
            |--------------------------------------------------------------------------
            | 1. US Core Birth Sex (STRICT)
            |--------------------------------------------------------------------------
            | Allowed ONLY: M | F | UNK
            */
            if (str_ends_with($url, 'us-core-birthsex')) {

                $code = strtoupper($ext['valueCode'] ?? 'UNK');

                if (!in_array($code, ['M', 'F', 'UNK'], true)) {
                    $code = 'UNK';
                }

                $ext = [
                    'url' => $url,
                    'valueCode' => $code
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | 2. US Core Interpreter Needed (FIX TYPE)
            |--------------------------------------------------------------------------
            | MUST be valueBoolean (NOT Coding, NOT mixed)
            */
            // AFTER
            if (str_ends_with($url, 'us-core-interpreter-needed')) {

                // The extension type is Coding, NOT boolean.
                // Valid codes: Y | N  (v3-YesNoIndicator)
                if (isset($ext['valueBoolean'])) {
                    $needed = (bool) $ext['valueBoolean'];
                } elseif (isset($ext['valueCoding']['code'])) {
                    $c      = strtoupper($ext['valueCoding']['code']);
                    $needed = in_array($c, ['Y', 'TRUE', '1', 'YES'], true);
                } else {
                    $needed = false;
                }

                $ext = [
                    'url'         => $url,
                    'valueCoding' => [
                        'system'  => 'http://snomed.info/sct',
                        'code'    => $needed ? '373066001' : '373067005',
                        'display' => $needed ? 'Yes (qualifier value)' : 'No (qualifier value)',
                    ],
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | 3. US Core Race (SAFE NORMALIZATION ONLY)
            |--------------------------------------------------------------------------
            */
            if (str_ends_with($url, 'us-core-race')) {

                if (!empty($ext['extension'])) {
                    foreach ($ext['extension'] as &$inner) {

                        if (($inner['url'] ?? '') === 'ombCategory') {

                            // fix invalid nullFlavor misuse if present
                            if (
                                isset($inner['valueCoding']['system']) &&
                                str_contains($inner['valueCoding']['system'], 'NullFlavor')
                            ) {
                                $inner['valueCoding'] = [
                                    'system' => 'http://terminology.hl7.org/CodeSystem/v3-NullFlavor',
                                    'code'   => 'UNK',
                                    'display'=> 'Unknown'
                                ];
                            }
                        }
                    }
                    unset($inner);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 4. REMOVE deprecated / invalid US Core Sex extension
            |--------------------------------------------------------------------------
            */
            if (str_ends_with($url, 'us-core-sex')) {
                unset($resource['extension'][$i]);
            }
        }

        unset($ext);

        /*
        |--------------------------------------------------------------------------
        | 5. FINAL CLEANUP (IMPORTANT)
        |--------------------------------------------------------------------------
        */
        $resource['extension'] = array_values(
            array_filter($resource['extension'], fn($e) => !empty($e))
        );

        /*
        |--------------------------------------------------------------------------
        | 6. HARD SAFETY: REMOVE INVALID communication.language
        |--------------------------------------------------------------------------
        | Prevents repeated validator failures from upstream data
        */
        if (isset($resource['communication'])) {

            $resource['communication'] = array_values(array_filter(
                $resource['communication'],
                function ($comm) {

                    $coding = $comm['language']['coding'][0] ?? null;

                    if (!$coding) {
                        return false;
                    }

                    $code = $coding['code'] ?? '';
                    $system = $coding['system'] ?? '';

                    // remove invalid data-absent-reason usage
                    if ($system === 'http://terminology.hl7.org/CodeSystem/data-absent-reason') {
                        return false;
                    }

                    // keep only valid BCP-47-ish or known HL7 languages
                    return !empty($code);
                }
            ));

            if (empty($resource['communication'])) {
                unset($resource['communication']);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ENTRY BUILDER
    |--------------------------------------------------------------------------
    */
    private static function entry(string $fullUrl, array $resource, string $type): array
    {
        // Apply resource-type-specific fixes before encoding
        self::fixPatientExtensions($resource);

        self::fixParticipationTypeDisplays($resource);

        self::fixMedicationRequestCategoryDisplays($resource);

        self::fixIcd10SystemUri($resource);
        
        self::fixUnresolvableReferences($resource);

        if (($resource['resourceType'] ?? '') === 'Encounter') {
            $resource = self::fixEncounterResource($resource);
        }

        if (($resource['resourceType'] ?? '') === 'Appointment') {
            $resource = self::fixAppointmentResource($resource);
        }

        if (($resource['resourceType'] ?? '') === 'Condition') {
            $resource = self::fixConditionResource($resource);
        }

         if (($resource['resourceType'] ?? '') === 'Coverage') {
            $resource = self::fixCoverageResource($resource);
        }
        if (($resource['resourceType'] ?? '') === 'Organization') {
            $resource = self::fixOrganizationResource($resource);
        }

        self::cleanResource($resource);

        self::normalizeProfiles($resource);

        self::ensureNarrative($resource);

        if (($resource['resourceType'] ?? '') === 'Observation') {
            $resource = self::fixObservationResource($resource);
        }

        return [
            'fullUrl'  => $fullUrl,
            'resource' => $resource,
            'request'  => [
                'method' => 'PUT',
                'url'    => $type . '/' . ($resource['id'] ?? uniqid()),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | EXACT-MATCH REFERENCE PATCHER
    | Only replaces when the full value equals $search exactly.
    | Prevents clobbering unrelated references that share a prefix.
    |--------------------------------------------------------------------------
    */
    private static function patchReferenceExact(array &$resource, string $search, string $replace): void
    {
        array_walk_recursive($resource, function (&$value) use ($search, $replace) {
            if (is_string($value) && $value === $search) {
                $value = $replace;
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE  (object → array, empty-safe)
    |--------------------------------------------------------------------------
    */
    private static function normalize($resource): array
    {
        if (empty($resource)) {
            return [];
        }
        if (is_object($resource)) {
            return json_decode(json_encode($resource), true);
        }
        return $resource;
    }

    /*
    |--------------------------------------------------------------------------
    | CLEAN RESOURCE  (strip empty codings from code / category)
    |--------------------------------------------------------------------------
    */
    private static function cleanResource(array &$resource): void
    {
        // --- code.coding ---
        if (isset($resource['code']['coding'])) {
            foreach ($resource['code']['coding'] as $i => &$coding) {
                $coding['system']  = trim($coding['system'] ?? '');
                $coding['code']    = preg_replace('/\s+/', '', trim($coding['code'] ?? ''));
                $coding['display'] = trim($coding['display'] ?? '');

                if (empty($coding['system']) || empty($coding['code'])) {
                    unset($resource['code']['coding'][$i]);
                }
            }
            unset($coding);

            $resource['code']['coding'] = array_values($resource['code']['coding']);

            if (empty($resource['code']['coding'])) {
                unset($resource['code']);
            }
        }

        // --- category ---
        if (isset($resource['category'])) {
            foreach ($resource['category'] as $i => &$cat) {
                if (empty($cat['coding'])) {
                    unset($resource['category'][$i]);
                    continue;
                }

                foreach ($cat['coding'] as $j => &$coding) {
                    $coding['system'] = trim($coding['system'] ?? '');
                    $coding['code']   = trim($coding['code'] ?? '');

                    if (empty($coding['system']) || empty($coding['code'])) {
                        unset($cat['coding'][$j]);
                    }
                }
                unset($coding);

                $cat['coding'] = array_values($cat['coding']);

                if (empty($cat['coding'])) {
                    unset($resource['category'][$i]);
                }
            }
            unset($cat);

            $resource['category'] = array_values($resource['category']);

            if (empty($resource['category'])) {
                unset($resource['category']);
            }
        }
    }

    private static function fixAppointmentResource(array $resource): array
    {
        if (($resource['resourceType'] ?? '') !== 'Appointment') {
            return $resource;
        }

        // Fix invalid appointmentType system URIs and displays
        if (isset($resource['appointmentType']['coding'])) {
            foreach ($resource['appointmentType']['coding'] as &$coding) {
                $system = $coding['system'] ?? '';
                if (
                    empty($system) ||
                    str_contains($system, 'localhost') ||
                    !str_starts_with($system, 'http')
                ) {
                    $coding['system'] = 'http://terminology.hl7.org/CodeSystem/v2-0276';
                    $coding['code']   = self::mapAppointmentTypeCode($coding['code'] ?? '');
                }
                if ($coding['system'] === 'http://terminology.hl7.org/CodeSystem/v2-0276') {
                    $coding['display'] = self::appointmentTypeDisplay($coding['code']);
                }
            }
            unset($coding);
        }

        if (isset($resource['participant'])) {

            foreach ($resource['participant'] as &$participant) {
                $actorType = $participant['actor']['type']      ?? null;
                $actorRef  = $participant['actor']['reference'] ?? null;

                /*
                |------------------------------------------------------------------
                | Remap Person → Practitioner
                | OpenEMR stores some providers as Person resources.
                | Person is not a valid Appointment.participant.actor target.
                |------------------------------------------------------------------
                */
                if ($actorType === 'Person' && $actorRef) {
                    $participant['actor']['type']      = 'Practitioner';
                    $participant['actor']['reference'] = preg_replace(
                        '#^Person/#',
                        'Practitioner/',
                        $actorRef
                    );
                }
            }
            unset($participant);

            /*
            |----------------------------------------------------------------------
            | Drop participants whose actor is still an external ResourceType/id
            | reference that won't exist in Aidbox (not in bundle, not pre-loaded).
            | Keeps urn:uuid: (intra-bundle) and empty/display-only actors.
            |----------------------------------------------------------------------
            */
            $resource['participant'] = array_values(array_filter(
                $resource['participant'],
                function ($participant) {
                    $ref = $participant['actor']['reference'] ?? '';

                    // Always keep intra-bundle urn:uuid: references
                    if (str_starts_with($ref, 'urn:uuid:') || empty($ref)) {
                        return true;
                    }

                    // Drop external ResourceType/id that Aidbox will reject
                    if (preg_match(
                        '#^(Practitioner|Person|Patient|RelatedPerson|Device|HealthcareService)/[a-f0-9\-]+$#i',
                        $ref
                    )) {
                        return false;
                    }

                    return true;
                }
            ));

            // Auto-correct status
            $allAccepted = array_reduce(
                $resource['participant'],
                fn($carry, $p) => $carry && (($p['status'] ?? '') === 'accepted'),
                true
            );

            if ($allAccepted && ($resource['status'] ?? '') === 'proposed') {
                $resource['status'] = 'booked';
            }
        }

        return $resource;
    }

    // AFTER
    private static function mapAppointmentTypeCode(string $localCode): string
    {
        $map = [
            'office_visit'        => 'ROUTINE',
            'established_patient' => 'FOLLOWUP',
            'new_patient'         => 'WALKIN',
            'urgent'              => 'URGENT',
            'wellness'            => 'CHECKUP',
        ];

        return $map[strtolower($localCode)] ?? 'ROUTINE';
    }

    private static function appointmentTypeDisplay(string $code): string
    {
        $displays = [
            'ROUTINE'  => 'Routine appointment - default if not valued',
            'WALKINCL' => 'A previously unscheduled walk-in visit',
            'FOLLOWUP' => 'A follow up visit from a previous appointment',
            'URGENT'   => 'An urgent appointment',
            'CHECKUP'  => 'A routine check-up, such as an annual physical',
            'WALKIN'   => 'A previously unscheduled walk-in visit',
        ];

        return $displays[$code] ?? 'Routine appointment - default if not valued';
    }

    // In FhirBundleBuilder::cleanResource() or a new fixConditionResource()
    private static function fixConditionResource(array $resource): array
    {
        if (!isset($resource['code'])) {
            return $resource;
        }

        // If code has text but no coding, add data-absent-reason
        if (
            isset($resource['code']['text']) &&
            empty($resource['code']['coding'])
        ) {
            $resource['code']['coding'] = [
                [
                    'system'  => 'http://terminology.hl7.org/CodeSystem/data-absent-reason',
                    'code'    => 'unknown',
                    'display' => 'Unknown',
                ]
            ];
        }

        return $resource;
    }

    private static function normalizeProfiles(array &$resource): void
    {
        $type = $resource['resourceType'] ?? '';

        // Patient
        if ($type === 'Patient') {
            $profiles = $resource['meta']['profile'] ?? [];
            $matching = array_filter($profiles, fn($p) => str_contains($p, 'us-core-patient'));
            if (!empty($matching)) {
                $resource['meta']['profile'] = [
                    'http://hl7.org/fhir/us/core/StructureDefinition/us-core-patient|9.0.0'
                ];
            }
            return;
        }

        // Organization — ensure us-core-organization profile is present
        if ($type === 'Organization') {
            $profiles = $resource['meta']['profile'] ?? [];
            $hasCoreOrg = !empty(array_filter($profiles, fn($p) => str_contains($p, 'us-core-organization')));
            if (!$hasCoreOrg) {
                $resource['meta']['profile'][] = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-organization';
            }
            return;
        }
    }

    private static function fixOrganizationResource(array $resource): array
    {
        if (($resource['resourceType'] ?? '') !== 'Organization') {
            return $resource;
        }

        // Fix deprecated identifier system URIs
        if (isset($resource['identifier'])) {
            foreach ($resource['identifier'] as &$identifier) {
                $system = $identifier['system'] ?? '';
                // Old HL7 v2 OID-style → correct terminology URI
                if ($system === 'http://hl7.org/fhir/v2/0203') {
                    $identifier['system'] = 'http://terminology.hl7.org/CodeSystem/v2-0203';
                }
            }
            unset($identifier);
        }

        // Remove null entries from address array
        if (isset($resource['address'])) {
            $resource['address'] = array_values(
                array_filter($resource['address'], fn($a) => !is_null($a) && !empty($a))
            );
            if (empty($resource['address'])) {
                unset($resource['address']);
            }
        }

        return $resource;
    }

    private static function fixCoverageResource(array $resource): array
    {
        if (($resource['resourceType'] ?? '') !== 'Coverage') {
            return $resource;
        }

        // Fix wrong display names for v3-ActCode coverage types
        $actCodeDisplays = [
            'HIP'    => 'health insurance plan policy',
            'ANNU'   => 'annuity policy',
            'AUTOPOL' => 'automobile',
            'COL'    => 'collision coverage policy',
            'UNINSMOT' => 'uninsured motorist policy',
            'DENTPOL' => 'dental policy',
            'DISEASE' => 'disease specific policy',
            'DRUGPOL' => 'drug policy',
            'EHCPOL'  => 'extended healthcare',
            'HSAPOL'  => 'health savings account',
            'LIFEINS' => 'life insurance',
            'MANDPOL' => 'mandatory health program',
            'MENTPOL' => 'mental health policy',
            'SUBPOL'  => 'substance use policy',
            'VISPOL'  => 'vision care policy',
        ];

        if (isset($resource['type']['coding'])) {
            foreach ($resource['type']['coding'] as &$coding) {
                if (
                    ($coding['system'] ?? '') === 'http://terminology.hl7.org/CodeSystem/v3-ActCode'
                    && isset($coding['code'], $actCodeDisplays[$coding['code']])
                ) {
                    $coding['display'] = $actCodeDisplays[$coding['code']];
                }
            }
            unset($coding);
        }

        return $resource;
    }

    private static function ensureNarrative(array &$resource): void
    {
        if (isset($resource['text'])) {
            return;
        }

        $type = $resource['resourceType'] ?? 'Resource';

        $resource['text'] = [
            'status' => 'generated',
            'div' => '<div xmlns="http://www.w3.org/1999/xhtml"><p>' .
                htmlspecialchars($type) .
                '</p></div>'
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | V3 PARTICIPATION TYPE CANONICAL DISPLAYS
    |--------------------------------------------------------------------------
    | Source: http://terminology.hl7.org/CodeSystem/v3-ParticipationType
    | Wrong display names from upstream data are silently corrected here.
    |--------------------------------------------------------------------------
    */
    private static function participationTypeDisplay(string $code): ?string
    {
        $displays = [
            'PART'    => 'Participation',
            'PPRF'    => 'primary performer',
            'SPRF'    => 'secondary performer',
            'PRF'     => 'performer',
            'RESP'    => 'responsible party',
            'VRF'     => 'verifier',
            'AUTHEN'  => 'authenticator',
            'LA'      => 'legal authenticator',
            'AUT'     => 'author (originator)',
            'INF'     => 'informant',
            'TRANS'   => 'Transcriber',
            'ENT'     => 'data entry person',
            'WIT'     => 'witness',
            'CST'     => 'custodian',
            'DIR'     => 'direct target',
            'ALY'     => 'analyte',
            'BBY'     => 'baby',
            'CAT'     => 'catalyst',
            'CSM'     => 'consumable',
            'TPA'     => 'therapeutic agent',
            'DEV'     => 'device',
            'NRD'     => 'non-reuseable device',
            'RDV'     => 'reusable device',
            'DON'     => 'donor',
            'EXPAGNT' => 'ExposureAgent',
            'EXPART'  => 'ExposureParticipation',
            'EXPTRGT' => 'ExposureTarget',
            'EXSRC'   => 'ExposureSource',
            'PRD'     => 'product',
            'SBJ'     => 'subject',
            'SPC'     => 'specimen',
            'IND'     => 'indirect target',
            'BEN'     => 'beneficiary',
            'CAGNT'   => 'causative agent',
            'COV'     => 'coverage target',
            'GUAR'    => 'guarantor party',
            'HLD'     => 'holder',
            'RCT'     => 'record target',
            'RCV'     => 'receiver',
            'IRCP'    => 'information recipient',
            'NOT'     => 'ugent notification contact',
            'PRCP'    => 'primary information recipient',
            'REFB'    => 'Referred By',
            'REFT'    => 'Referred to',
            'TRC'     => 'tracker',
            'LOC'     => 'location',
            'DST'     => 'destination',
            'ELOC'    => 'entry location',
            'ORG'     => 'origin',
            'RML'     => 'remote',
            'VIA'     => 'via',
            'ADM'     => 'admitter',
            'ATND'    => 'attender',
            'CALLBCK' => 'callback contact',
            'CON'     => 'consultant',
            'DIS'     => 'discharger',
            'ESC'     => 'escort',
            'REF'     => 'referrer',
            'ECON'    => 'emergency contact',
            'NOK'     => 'next of kin',
            'GUARD'   => 'guardian',
            'CIT'     => 'citizen',
            'COVPTY'  => 'covered party',
            'CLAIM'   => 'claimant',
            'NAM'     => 'named insured',
            'DEPEN'   => 'dependent',
            'INDIV'   => 'individual',
            'SUBSCR'  => 'subscriber',
            'PROG'    => 'program eligible',
            'PAT'     => 'patient',
            'PAYEE'   => 'payee',
            'PAYOR'   => 'invoice payor',
            'EMRGCON' => 'emergency contact',
        ];

        return $displays[$code] ?? null;
    }

    // Add this as a new private static method
    private static function fixParticipationTypeDisplays(array &$resource): void
    {
        if (!isset($resource['participant'])) {
            return;
        }

        foreach ($resource['participant'] as &$participant) {
            if (!isset($participant['type'])) {
                continue;
            }
            foreach ($participant['type'] as &$type) {
                if (!isset($type['coding'])) {
                    continue;
                }
                foreach ($type['coding'] as &$coding) {
                    if (
                        ($coding['system'] ?? '') === 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType'
                        && isset($coding['code'])
                    ) {
                        $canonical = self::participationTypeDisplay($coding['code']);
                        if ($canonical !== null) {
                            $coding['display'] = $canonical;
                        }
                    }
                }
                unset($coding);
            }
            unset($type);
        }
        unset($participant);
    }

    private static function fixMedicationRequestCategoryDisplays(array &$resource): void
    {
        if (($resource['resourceType'] ?? '') !== 'MedicationRequest') {
            return;
        }

        if (!isset($resource['category'])) {
            return;
        }

        $displays = [
            'inpatient'  => 'Inpatient',
            'outpatient' => 'Outpatient',
            'community'  => 'Community',
            'discharge'  => 'Discharge',
        ];

        foreach ($resource['category'] as &$cat) {
            foreach ($cat['coding'] as &$coding) {
                if (
                    ($coding['system'] ?? '') === 'http://terminology.hl7.org/CodeSystem/medicationrequest-category'
                    && isset($coding['code'], $displays[$coding['code']])
                ) {
                    $coding['display'] = $displays[$coding['code']];
                }
            }
            unset($coding);
        }
        unset($cat);
    }

    private static function fixObservationResource(array $resource): array
    {
        if (($resource['resourceType'] ?? '') !== 'Observation') {
            return $resource;
        }

        /*
        |--------------------------------------------------------------------------
        | US Core Simple Observation requires at least one category.
        | If none is present, default to "survey" which is the broadest
        | catch-all category accepted by us-core-simple-observation.
        |--------------------------------------------------------------------------
        | Common values:
        |   social-history | vital-signs | imaging | laboratory |
        |   procedure | survey | exam | therapy | activity
        |--------------------------------------------------------------------------
        */
        if (empty($resource['category'])) {
            $resource['category'] = [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                            'code'    => 'survey',
                            'display' => 'Survey',
                        ],
                    ],
                ],
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize profile to latest single version — same pattern as Patient.
        | Keeps only the highest us-core-simple-observation version present,
        | or defaults to 9.0.0 if none match.
        |--------------------------------------------------------------------------
        */
        if (isset($resource['meta']['profile'])) {
            $profiles    = $resource['meta']['profile'];
            $obsProfiles = array_filter($profiles, fn($p) => str_contains($p, 'us-core-simple-observation'));

            if (!empty($obsProfiles)) {
                $resource['meta']['profile'] = array_values(
                    array_filter($profiles, fn($p) => !str_contains($p, 'us-core-simple-observation'))
                );
                $resource['meta']['profile'][] = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-simple-observation|9.0.0';
            }
        }

        return $resource;
    }

    /*
    |--------------------------------------------------------------------------
    | FIX ICD-10 SYSTEM URI
    |--------------------------------------------------------------------------
    | http://hl7.org/fhir/sid/icd-10      → international ICD-10 (WHO)
    | http://hl7.org/fhir/sid/icd-10-cm   → US clinical modification (correct)
    |
    | US-specific codes (dot notation like J45.909, Z00.00, etc.) must use
    | icd-10-cm. The international system only knows whole-number codes.
    |--------------------------------------------------------------------------
    */
    private static function fixIcd10SystemUri(array &$resource): void
    {
        // Single CodeableConcept fields
        foreach (['code'] as $field) {
            if (!isset($resource[$field]['coding'])) {
                continue;
            }
            foreach ($resource[$field]['coding'] as &$coding) {
                if (($coding['system'] ?? '') === 'http://hl7.org/fhir/sid/icd-10') {
                    $coding['system'] = 'http://hl7.org/fhir/sid/icd-10-cm';
                }
            }
            unset($coding);
        }

        // Array of CodeableConcept fields
        foreach (['reasonCode', 'orderDetail'] as $field) {
            if (!isset($resource[$field])) {
                continue;
            }
            foreach ($resource[$field] as &$item) {
                if (!isset($item['coding'])) {
                    continue;
                }
                foreach ($item['coding'] as &$coding) {
                    if (($coding['system'] ?? '') === 'http://hl7.org/fhir/sid/icd-10') {
                        $coding['system'] = 'http://hl7.org/fhir/sid/icd-10-cm';
                    }
                }
                unset($coding);
            }
            unset($item);
        }

        // Encounter.diagnosis
        if (isset($resource['diagnosis'])) {
            foreach ($resource['diagnosis'] as &$diag) {
                if (!isset($diag['condition']['coding'])) {
                    continue;
                }
                foreach ($diag['condition']['coding'] as &$coding) {
                    if (($coding['system'] ?? '') === 'http://hl7.org/fhir/sid/icd-10') {
                        $coding['system'] = 'http://hl7.org/fhir/sid/icd-10-cm';
                    }
                }
                unset($coding);
            }
            unset($diag);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FIX UNRESOLVABLE REFERENCES
    |--------------------------------------------------------------------------
    | Drops performer[], requester, and other external ResourceType/id
    | references that were not resolved into the bundle.
    | These cause Aidbox 422 "resource does not exist".
    |--------------------------------------------------------------------------
    */
    private static function fixUnresolvableReferences(array &$resource): void
    {
        // performer[]
        if (isset($resource['performer'])) {
            $resource['performer'] = array_values(array_filter(
                $resource['performer'],
                function ($performer) {
                    $ref = $performer['reference'] ?? '';
                    if (str_starts_with($ref, 'urn:uuid:') || empty($ref)) {
                        return true;
                    }
                    if (preg_match(
                        '#^(Practitioner|Person|Patient|RelatedPerson|Device|Organization)/[a-f0-9\-]+$#i',
                        $ref
                    )) {
                        return false;
                    }
                    return true;
                }
            ));

            if (empty($resource['performer'])) {
                unset($resource['performer']);
            }
        }

        // requester
        if (isset($resource['requester']['reference'])) {
            $ref = $resource['requester']['reference'];
            if (
                !str_starts_with($ref, 'urn:uuid:') &&
                preg_match('#^(Practitioner|Person|Patient|Organization)/[a-f0-9\-]+$#i', $ref)
            ) {
                unset($resource['requester']);
            }
        }
    }

}