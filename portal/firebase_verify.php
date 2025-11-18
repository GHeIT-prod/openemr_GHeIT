<?php

/**
 * portal/firebase_verify.php
 *
 * Verifies Firebase token for patient portal
 */

use OpenEMR\Common\Session\SessionUtil;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use OpenEMR\Common\Uuid\UuidRegistry;

header("Content-Type: application/json");

// --- CORS headers for localhost or portal domain ---
header("Access-Control-Allow-Origin: https://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --- Define portal context ---
define('IS_PORTAL', true);
define('IS_PORTAL_LOGIN', true);
$GLOBALS['ignoreAuth'] = true;

// set cookie params once, before starting portal session
$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => $cookieParams['lifetime'] ?? 0,
    'path' => $cookieParams['path'] ?? '/',
    // 'domain' => $cookieParams['domain'] ?? '',   // keep blank to use host-only, or set explicit domain
    'domain' => '',
    'secure' => true,           // set true in production (requires HTTPS)
    'httponly' => true,
    'samesite' => 'None'        // allow cross-site cookies for Firebase flows
]);

// --- Start OpenEMR session ---
require_once(__DIR__ . '/../interface/globals.php');
require_once(__DIR__ . '/../vendor/autoload.php');
require_once($GLOBALS['srcdir'] . '/patient.inc');
SessionUtil::portalSessionStart();

$sessionAllowWrite = true;

if (empty($_SESSION['site_id'])) {
    $_SESSION['site_id'] = 'default';
}

// --- Parse incoming token ---
$input = json_decode(file_get_contents('php://input'), true);
$firebaseToken = $input['firebase_token'] ?? '';

if (empty($firebaseToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Firebase token missing.']);
    exit;
}

try {
    try {
        $serviceFile = __DIR__ . '/firebase/firebase-service-account.json';

        if (!file_exists($serviceFile)) {
            echo json_encode(['success' => false, 'msg' => "File not found at: $serviceFile"]);
            exit;
        }

        $json = file_get_contents($serviceFile);
        if (empty($json)) {
            echo json_encode(['success' => false, 'msg' => "File is empty or unreadable at: $serviceFile"]);
            exit;
        }

        json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'msg' => "Invalid JSON: " . json_last_error_msg()]);
            exit;
        }

        $factory = (new Factory)->withServiceAccount($serviceFile);

    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    if (!file_exists($serviceFile)) {
        echo json_encode(['success' => false, 'serviceFile' => $serviceFile]);
        exit;
    }
    
    $auth = $factory->createAuth();

    // --- Verify Firebase ID token ---
    $verifiedIdToken = $auth->verifyIdToken($firebaseToken);
    $uid = $verifiedIdToken->claims()->get('sub');
    $user = $auth->getUser($uid);
    $email = strtolower($user->email);
    $FullNameparts = explode(" ", $user->displayName);
    $fname = $FullNameparts[0] ?? '';
    $lname = $FullNameparts[1] ?? '';
    $displayFullName = str_replace(' ', '', $user->displayName);

    // --- Create patient and portal user if not existing ---
    $patient = sqlQuery("SELECT pid, providerID FROM patient_data WHERE email = ?", [$email]);
    if (!$patient) {
        $pidRow = sqlQuery("SELECT MAX(pid)+1 AS next_pid FROM patient_data");
        $pid = $pidRow['next_pid'] ?? 1;
        $uuid = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();

        sqlInsert(
            "INSERT INTO patient_data (fname, lname, email, pid, uuid, date, sex)
            VALUES (?, ?, ?, ?, ?, NOW(), 'U')",
            [$fname, $lname, $email, $pid, $uuid]
        );

        $portalUser = sqlQuery(
            "SELECT pid, portal_login_username FROM patient_access_onsite WHERE portal_login_username = ?",
            [$email]
        );
        if (!$portalUser) {
            sqlInsert(
                "INSERT INTO patient_access_onsite (pid, portal_username, portal_login_username, date_created)
                VALUES (?, ?, ?, NOW())",
                array($pid, $displayFullName . $pid, $email)
            );
            $portalUser = sqlQuery(
                "SELECT pid, portal_login_username FROM patient_access_onsite WHERE portal_login_username = ?",
                [$email]
            );
        }
    } else {
        $pid = $patient['pid'];
    }

    // --- Set OpenEMR portal session variables ---
    $_SESSION['pid'] = $portalUser['pid']?? $pid;
    $_SESSION['portal_login_username'] = $portalUser['portal_login_username']?? $email;
    $_SESSION['patient_portal'] = true;
    $_SESSION['authenticated'] = true;
    $_SESSION['patient_portal_onsite_two'] = 1;
    $_SESSION['authUser'] = 'portal-user';
    $_SESSION['authUserID'] = (int)($_SESSION['pid']); 
    $_SESSION['portal_username'] = $displayFullName . $_SESSION['pid'];
    $_SESSION['ignoreAuth_onsite_portal'] = true;
    $_SESSION['site_id'] = 'default';
    $_SESSION['itsme'] = 1;
    $_SESSION['authUserRole'] = 'patient';
    $_SESSION['ignoreAuth'] = true;
    $_SESSION['userauthorized'] = 1;
    $_SESSION['sessionUser'] = '-patient-';
    // --- Optional: patient name for UI ---
    $pt = sqlQuery("SELECT fname, lname FROM patient_data WHERE pid = ?", [$pid]);
    // $_SESSION['sessionUser'] = $pt['fname'] . ' ' . ($pt['lname']);
    $_SESSION['ptName'] = $pt['fname'] . ' ' . ($pt['lname']);


    session_write_close();

    echo json_encode([
        'status' => 'success',
        'message' => 'Firebase token verified successfully.',
        'patient_id' => $portalUser['pid']
    ]);
    exit;

} catch (FailedToVerifyToken $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid Firebase token.']);
    exit;
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}


