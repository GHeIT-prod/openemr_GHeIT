<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use Auth0\SDK\Auth0;
use OpenEMR\Common\Session\SessionUtil;
use OpenEMR\Common\Uuid\UuidRegistry;

// ----------------------------
// CORS headers
// ----------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ----------------------------
// Portal constants
// ----------------------------
define('IS_PORTAL', true);
define('IS_PORTAL_LOGIN', true);
$GLOBALS['ignoreAuth'] = true;

// ----------------------------
// Load OpenEMR environment
// ----------------------------
require_once __DIR__ . '/../interface/globals.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once $GLOBALS['srcdir'] . '/patient.inc';

// ----------------------------
// Ensure OpenEMR session cookie
// ----------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_name('PortalOpenEMR');
    session_start([
        'cookie_samesite' => 'None',
        'cookie_secure' => true,       // only HTTPS
        'cookie_httponly' => true,
        'use_strict_mode' => true,
    ]);
}

// ----------------------------
// Load environment variables
// ----------------------------
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// ----------------------------
// Auth0 configuration
// ----------------------------
$auth0 = new Auth0([
    'domain' => $_ENV['AUTH0_DOMAIN'],
    'clientId' => $_ENV['AUTH0_CLIENT_ID'],
    'clientSecret' => $_ENV['AUTH0_CLIENT_SECRET'],
    'redirectUri' => $_ENV['AUTH0_REDIRECT_URI'],
    'cookieSecret' => $_ENV['AUTH0_COOKIE_SECRET'],
]);

try {
    // Exchange code for token
    $token = $auth0->exchange();
    $user = $auth0->getUser();
    if (!$user) {
        throw new Exception('User not found after token exchange.');
    }

    $_SESSION['auth0_user'] = $user;

    // Optional: store user email / name in portal session
    $_SESSION['portal_user'] = [
        'email' => $user['email'] ?? '',
        'name'  => $user['name'] ?? '',
    ];

    $email = $user['email'] ?? '';
    $fname = $user['given_name'] ?? '';
    $lname = $user['family_name'] ?? '';
    $fullName = str_replace(' ', '', $user['name']);

    // ----------------------------
    // Lookup or create patient
    // ----------------------------
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

        // Create portal user
        sqlInsert(
            "INSERT INTO patient_access_onsite (pid, portal_username, portal_login_username, date_created)
             VALUES (?, ?, ?, NOW())",
            [$pid, $fullName . $pid, $email]
        );

        $portalUser = sqlQuery(
            "SELECT pid, portal_login_username FROM patient_access_onsite WHERE portal_login_username = ?",
            [$email]
        );
    } else {
        $pid = $patient['pid'];
        $portalUser = sqlQuery(
            "SELECT pid, portal_login_username FROM patient_access_onsite WHERE portal_login_username = ?",
            [$email]
        );
    }

    // ----------------------------
    // Start OpenEMR portal session
    // ----------------------------
    SessionUtil::portalSessionStart();

    $_SESSION['pid'] = $portalUser['pid'] ?? $pid;
    $_SESSION['portal_login_username'] = $portalUser['portal_login_username'] ?? $email;
    $_SESSION['patient_portal'] = true;
    $_SESSION['authenticated'] = true;
    $_SESSION['patient_portal_onsite_two'] = 1;
    $_SESSION['authUser'] = 'portal-user';
    $_SESSION['authUserID'] = (int)$_SESSION['pid'];
    $_SESSION['portal_username'] = $fullName . $_SESSION['pid'];
    $_SESSION['ignoreAuth_onsite_portal'] = true;
    $_SESSION['site_id'] = 'default';
    $_SESSION['itsme'] = 1;
    $_SESSION['authUserRole'] = 'patient';
    $_SESSION['ignoreAuth'] = true;
    $_SESSION['userauthorized'] = 1;
    $_SESSION['sessionUser'] = '-patient-';

    // Optional: patient name for UI
    $pt = sqlQuery("SELECT fname, lname FROM patient_data WHERE pid = ?", [$pid]);
    $_SESSION['ptName'] = $pt['fname'] . ' ' . ($pt['lname'] ?? '');

    session_write_close();

    // Redirect to portal home
    header("Location: ./home.php");
    exit;

} catch (Exception $e) {
    echo "Auth0 ERROR:<br>";
    echo $e->getMessage();
    exit;
}
