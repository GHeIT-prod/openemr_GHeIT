<?php

/**
 * new_comprehensive_save.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2009-2017 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Services\ContactService;
use OpenEMR\Services\ContactAddressService;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Patient\PatientBeforeCreatedAuxEvent;
use OpenEMR\Modules\CustomModuleGheit\Controller\PubSub;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\FHIR\FhirPatientService;

if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    CsrfUtils::csrfNotVerified();
}

// Validation for non-unique external patient identifier.
$alertmsg = '';
if (!empty($_POST["form_pubpid"])) {
    $form_pubpid = trim((string) $_POST["form_pubpid"]);
    $result = sqlQuery("SELECT count(*) AS count FROM patient_data WHERE " .
    "pubpid = ?", [$form_pubpid]);
    if ($result['count']) {
        // Error, not unique.
        $alertmsg = xl('Warning: Patient ID is not unique!');
    }
}

require_once("$srcdir/pid.inc.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");

// Update patient_data and employer_data:
// First, we prepare the data for insert into DB by querying the layout
// fields to see what valid fields we have to insert from the post we are receiving
$newdata = [];
$newdata['patient_data'] = [];
$newdata['employer_data'] = [];
$fres = sqlStatement("SELECT * FROM layout_options " .
  "WHERE form_id = 'DEM' AND (uor > 0 OR field_id = 'pubpid') AND field_id != '' " .
  "ORDER BY group_id, seq");
$addressFieldsToSave = [];
while ($frow = sqlFetchArray($fres)) {
    $data_type = $frow['data_type'];
    $field_id  = $frow['field_id'];
  // $value     = '';
    $colname   = $field_id;
    $tblname   = 'patient_data';
    if (str_starts_with((string) $field_id, 'em_')) {
        $colname = substr((string) $field_id, 3);
        $tblname = 'employer_data';
    }

  //get value only if field exist in $_POST (prevent deleting of field with disabled attribute)
    // TODO: why is this a different conditional than demographics_save.php...
    if ($data_type == 54) { // address list
        $addressFieldsToSave[$field_id] = get_layout_form_value($frow);
    } else if (isset($_POST["form_$field_id"]) || $field_id == "pubpid") {
        $value = get_layout_form_value($frow);
        $newdata[$tblname][$colname] = $value;
    }
}

// Use the global helper to use the PatientService to create a new patient
// The result contains the pid, so use that to set the global session pid
$pid = updatePatientData(null, $newdata['patient_data'], true);

if (empty($newdata['patient_data']['MRN'])) {
    $userAuthId = $_SESSION['authUserID'] ?? null;
    $facilityId = sqlQuery("SELECT facility_id FROM users WHERE id = ?", [$userAuthId])['facility_id'] ?? null;
    $formattedFacilityId = str_pad($facilityId, 3, '0', STR_PAD_LEFT);
    $date = date('Ymd');

    // Create guaranteed unique sequence
    $sequenceId = sqlInsert("
        INSERT INTO custom_mrn_sequence ()
        VALUES ()
    ");

    $sequence = str_pad($sequenceId, 3, '0', STR_PAD_LEFT);
    $mrn = "A-{$formattedFacilityId}-{$date}-{$sequence}";
    $newdata['patient_data']['MRN'] = $mrn;
} else {
    sqlInsert("
        INSERT INTO custom_mrn_sequence ()
        VALUES ()
    ");
}

$uuid = sqlQuery("SELECT uuid FROM patient_data WHERE pid = ?", [$pid])['uuid'];
$patientuuid = UuidRegistry::uuidToString($uuid);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../modules/custom_modules/oe-module-custom-gheit/src/Controller/PubSub.php';

$service = new FhirPatientService();

$result = $service->getOne($patientuuid);

$patient = $result->getData()[0];

// $fhirArray = $patient->jsonSerialize();
$fhirArray = json_decode(json_encode($patient->jsonSerialize()), true);

$medicalRecordNumber = $newdata['patient_data']['MRN'] ?? '';

if (!empty($medicalRecordNumber)) {

    if (!isset($fhirArray['identifier'])) {
        $fhirArray['identifier'] = [];
    }

    $fhirArray['identifier'][] = [
        'use' => 'usual',
        'type' => [
            'coding' => [[
                'system' => 'http://terminology.hl7.org/CodeSystem/v2-0203',
                'code' => 'MR'
            ]],
            'text' => 'Medical Record Number'
        ],
        'system' => 'http://hospital.smarthealth.org/mrn',
        'value' => (string)$medicalRecordNumber
    ];
}

/*
|--------------------------------------------------------------------------
| CLEAN PATIENT BEFORE PUBLISHING
|--------------------------------------------------------------------------
*/

// 1. Normalize profiles — collapse all us-core-patient variants to 9.0.0
if (isset($fhirArray['meta']['profile'])) {
    $others = array_values(array_filter(
        $fhirArray['meta']['profile'],
        fn($p) => !str_contains($p, 'us-core-patient')
    ));
    $fhirArray['meta']['profile'] = array_merge($others, [
        'http://hl7.org/fhir/us/core/StructureDefinition/us-core-patient|9.0.0',
    ]);
}

// 2. Strip us-core-sex, fix us-core-interpreter-needed
if (isset($fhirArray['extension']) && is_array($fhirArray['extension'])) {
    foreach ($fhirArray['extension'] as &$ext) {
        $url = $ext['url'] ?? '';

        if (str_ends_with($url, 'us-core-sex')) {
            $ext = null;
            continue;
        }

        if (str_ends_with($url, 'us-core-interpreter-needed')) {
            $system = $ext['valueCoding']['system'] ?? '';
            if (
                str_contains($system, 'data-absent-reason') ||
                !str_starts_with($system, 'http://snomed')
            ) {
                $ext['valueCoding'] = [
                    'system'  => 'http://snomed.info/sct',
                    'code'    => '373067005',
                    'display' => 'No (qualifier value)',
                ];
            }
        }
    }
    unset($ext);

    $fhirArray['extension'] = array_values(
        array_filter($fhirArray['extension'], fn($e) => $e !== null && !empty($e))
    );
}

// 3. Strip invalid communication.language (data-absent-reason)
if (isset($fhirArray['communication'])) {
    $fhirArray['communication'] = array_values(array_filter(
        $fhirArray['communication'],
        fn($c) => ($c['language']['coding'][0]['system'] ?? '')
                  !== 'http://terminology.hl7.org/CodeSystem/data-absent-reason'
    ));
    if (empty($fhirArray['communication'])) {
        unset($fhirArray['communication']);
    }
}

/*
|--------------------------------------------------------------------------
| PUBLISH
|--------------------------------------------------------------------------
*/
$pubSubController = new PubSub();
$pubSubController->publishPubsub('Patient', 'patient_created', 'patient_data', $fhirArray);

if (empty($pid)) {
    die("Internal error: setpid(" . text($pid) . ") failed!");
}
setpid($pid);
if (!$GLOBALS['omit_employers']) {
    updateEmployerData($pid, $newdata['employer_data'], true, $newdata['patient_data']);
}

if (!empty($addressFieldsToSave)) {
    try {
        // TODO: we would handle other types of address fields here, for now we will just go through and populate the patient
        // address information
        // TODO: how are error messages supposed to display if the save fails?
        foreach ($addressFieldsToSave as $addressFieldData) {
            // if we need to save other kinds of addresses we could do that here with our field column...
            if (!empty($addressFieldData)) {
                $contactService = new ContactService();
                $contact = $contactService->getOrCreateForEntity('patient_data', $pid);
                $contactAddressService = new ContactAddressService();
                $contactAddressService->saveAddressesForContact($contact->get_id(), $addressFieldData);
            }
        }
    } catch (Exception $e) {
        (new SystemLogger())->error("Fatal error in address processing", [
            'pid' => $pid,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

/**
 * Parse demographics data to listeners who want data that is not directly available in
 * the patient_data table on update
 */
$GLOBALS["kernel"]->getEventDispatcher()->dispatch(new PatientBeforeCreatedAuxEvent($pid, $_POST), PatientBeforeCreatedAuxEvent::EVENT_HANDLE, 10);


$i1dob = DateToYYYYMMDD(filter_input(INPUT_POST, "i1subscriber_DOB"));
$i1date = DateToYYYYMMDD(filter_input(INPUT_POST, "i1effective_date"));

newHistoryData($pid);
// no need to save insurance for simple demos
if (!$GLOBALS['simplified_demographics']) {
    newInsuranceData(
        $pid,
        "primary",
        filter_input(INPUT_POST, "i1provider"),
        filter_input(INPUT_POST, "i1policy_number"),
        filter_input(INPUT_POST, "i1group_number"),
        filter_input(INPUT_POST, "i1plan_name"),
        filter_input(INPUT_POST, "i1subscriber_lname"),
        filter_input(INPUT_POST, "i1subscriber_mname"),
        filter_input(INPUT_POST, "i1subscriber_fname"),
        filter_input(INPUT_POST, "form_i1subscriber_relationship"),
        filter_input(INPUT_POST, "i1subscriber_ss"),
        $i1dob,
        filter_input(INPUT_POST, "i1subscriber_street"),
        filter_input(INPUT_POST, "i1subscriber_postal_code"),
        filter_input(INPUT_POST, "i1subscriber_city"),
        filter_input(INPUT_POST, "form_i1subscriber_state"),
        filter_input(INPUT_POST, "form_i1subscriber_country"),
        filter_input(INPUT_POST, "i1subscriber_phone"),
        filter_input(INPUT_POST, "i1subscriber_employer"),
        filter_input(INPUT_POST, "i1subscriber_employer_street"),
        filter_input(INPUT_POST, "i1subscriber_employer_city"),
        filter_input(INPUT_POST, "i1subscriber_employer_postal_code"),
        filter_input(INPUT_POST, "form_i1subscriber_employer_state"),
        filter_input(INPUT_POST, "form_i1subscriber_employer_country"),
        filter_input(INPUT_POST, 'i1copay'),
        filter_input(INPUT_POST, 'form_i1subscriber_sex'),
        $i1date,
        filter_input(INPUT_POST, 'i1accept_assignment')
    );

    //Dont save more than one insurance since only one is allowed / save space in DB
    if (!$GLOBALS['insurance_only_one']) {
        $i2dob = DateToYYYYMMDD(filter_input(INPUT_POST, "i2subscriber_DOB"));
        $i2date = DateToYYYYMMDD(filter_input(INPUT_POST, "i2effective_date"));

        newInsuranceData(
            $pid,
            "secondary",
            filter_input(INPUT_POST, "i2provider"),
            filter_input(INPUT_POST, "i2policy_number"),
            filter_input(INPUT_POST, "i2group_number"),
            filter_input(INPUT_POST, "i2plan_name"),
            filter_input(INPUT_POST, "i2subscriber_lname"),
            filter_input(INPUT_POST, "i2subscriber_mname"),
            filter_input(INPUT_POST, "i2subscriber_fname"),
            filter_input(INPUT_POST, "form_i2subscriber_relationship"),
            filter_input(INPUT_POST, "i2subscriber_ss"),
            $i2dob,
            filter_input(INPUT_POST, "i2subscriber_street"),
            filter_input(INPUT_POST, "i2subscriber_postal_code"),
            filter_input(INPUT_POST, "i2subscriber_city"),
            filter_input(INPUT_POST, "form_i2subscriber_state"),
            filter_input(INPUT_POST, "form_i2subscriber_country"),
            filter_input(INPUT_POST, "i2subscriber_phone"),
            filter_input(INPUT_POST, "i2subscriber_employer"),
            filter_input(INPUT_POST, "i2subscriber_employer_street"),
            filter_input(INPUT_POST, "i2subscriber_employer_city"),
            filter_input(INPUT_POST, "i2subscriber_employer_postal_code"),
            filter_input(INPUT_POST, "form_i2subscriber_employer_state"),
            filter_input(INPUT_POST, "form_i2subscriber_employer_country"),
            filter_input(INPUT_POST, 'i2copay'),
            filter_input(INPUT_POST, 'form_i2subscriber_sex'),
            $i2date,
            filter_input(INPUT_POST, 'i2accept_assignment')
        );

        $i3dob = DateToYYYYMMDD(filter_input(INPUT_POST, "i3subscriber_DOB"));
        $i3date = DateToYYYYMMDD(filter_input(INPUT_POST, "i3effective_date"));

        newInsuranceData(
            $pid,
            "tertiary",
            filter_input(INPUT_POST, "i3provider"),
            filter_input(INPUT_POST, "i3policy_number"),
            filter_input(INPUT_POST, "i3group_number"),
            filter_input(INPUT_POST, "i3plan_name"),
            filter_input(INPUT_POST, "i3subscriber_lname"),
            filter_input(INPUT_POST, "i3subscriber_mname"),
            filter_input(INPUT_POST, "i3subscriber_fname"),
            filter_input(INPUT_POST, "form_i3subscriber_relationship"),
            filter_input(INPUT_POST, "i3subscriber_ss"),
            $i3dob,
            filter_input(INPUT_POST, "i3subscriber_street"),
            filter_input(INPUT_POST, "i3subscriber_postal_code"),
            filter_input(INPUT_POST, "i3subscriber_city"),
            filter_input(INPUT_POST, "form_i3subscriber_state"),
            filter_input(INPUT_POST, "form_i3subscriber_country"),
            filter_input(INPUT_POST, "i3subscriber_phone"),
            filter_input(INPUT_POST, "i3subscriber_employer"),
            filter_input(INPUT_POST, "i3subscriber_employer_street"),
            filter_input(INPUT_POST, "i3subscriber_employer_city"),
            filter_input(INPUT_POST, "i3subscriber_employer_postal_code"),
            filter_input(INPUT_POST, "form_i3subscriber_employer_state"),
            filter_input(INPUT_POST, "form_i3subscriber_employer_country"),
            filter_input(INPUT_POST, 'i3copay'),
            filter_input(INPUT_POST, 'form_i3subscriber_sex'),
            $i3date,
            filter_input(INPUT_POST, 'i3accept_assignment')
        );
    }
}
?>
<html>
<body>
<script>
<?php
if ($alertmsg) {
    echo "alert(" . js_escape($alertmsg) . ");\n";
}

  echo "window.location='$rootdir/patient_file/summary/demographics.php?" .
    "set_pid=" . attr_url($pid) . "&is_new=1';\n";
?>
</script>

</body>
</html>


