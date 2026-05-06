<?php

declare(strict_types=1);

/**
 * Auth0 → OpenEMR SSO (Pure Session Login)
 * Correct OpenEMR-compatible version
 */

use Dotenv\Dotenv;
use Auth0\SDK\Auth0;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Auth\AuthHash;

if (session_status() === PHP_SESSION_NONE) {
    session_name('OpenEMR');
    session_start([
        'cookie_samesite' => 'None',
        'cookie_secure' => true,       // only HTTPS
        'cookie_httponly' => true,
        'use_strict_mode' => true,
    ]);
}

define('SITE', 'default');

// Required for globals.php site checks
$_GET['site'] = SITE;
$_REQUEST['site'] = SITE;
$_SESSION['site_id'] = SITE;
$_GET['auth'] = 'login';
$_SESSION['provider_sso'] = 'provider_sso';

// Force Auth flags to skip login checks
$ignoreAuth = true;
$ignoreAuth_onsite_portal = true;
$portal_onsite_two_enable = true;
$GLOBALS['ignoreAuth'] = true;
$GLOBALS['ignoreAuth_onsite_portal'] = true;
$sessionAllowWrite = true;

// --------------------------------------------------
// AUTOLOAD + ENV
// --------------------------------------------------
require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();

// --------------------------------------------------
// AUTH0 CONFIG
// --------------------------------------------------
$auth0 = new Auth0([
    'domain'       => $_ENV['AUTH0_DOMAIN'],
    'clientId'     => $_ENV['AUTH0_CLIENT_ID'],
    'clientSecret' => $_ENV['AUTH0_CLIENT_SECRET'],
    'redirectUri'  => $_ENV['PROVIDER_AUTH0_REDIRECT_URI'],
    'cookieSecret' => $_ENV['AUTH0_COOKIE_SECRET'],
]);

try {
    // --------------------------------------------------
    // AUTH0 CALLBACK
    // --------------------------------------------------
    $auth0->exchange();
    $user = $auth0->getUser();

    if (!$user || empty($user['email'])) {
        throw new Exception('Auth0 user not found');
    }

    $email = strtolower(trim($user['email']));
    $fname = $user['given_name'] ?? '';
    $lname = $user['family_name'] ?? '';
    $mname = $user['middle_name'] ?? '';

    $originalGet  = $_GET;

    // --------------------------------------------------
    // LOAD OPENEMR CORE (SESSION STARTS HERE)
    // --------------------------------------------------
    require_once __DIR__ . '/../../interface/globals.php';

    $_GET  = $originalGet;

    $_SESSION['site_id'] = SITE;

    // --------------------------------------------------
    // ENSURE USER EXISTS
    // --------------------------------------------------
    $provider = sqlQuery(
        "SELECT id FROM users WHERE username = ? AND active = 1",
        [$email]
    );

    if (!$provider) {
        $uuid = (new UuidRegistry(['table_name' => 'users']))->createUuid();

        $providerId = sqlInsert(
            "INSERT INTO users
             (fname, lname, username, uuid, password, active, see_auth, facility_id)
             VALUES (?, ?, ?, ?, 'NoLongerUsed', 1, 1, 3)",
            [$fname, $lname, $email, $uuid]
        );

        sqlInsert(
            "INSERT INTO groups (name, user)
             VALUES ('Default', ?)",
            [$email]
        );

        // Set a default password (will not be used)
        $password = 'Test123$';
        $authHash = new AuthHash('auth');
        $hash = $authHash->passwordHash($password);
        $passwordSQL = "INSERT INTO `users_secure`" .
                    " (`id`,`username`,`password`,`last_update_password`)" .
                    " VALUES (?,?,?,NOW()) ";
        privStatement($passwordSQL, [$providerId, $email, $hash]);

        // Assign default ACLs
        AclExtended::setUserAro(
            ['Clinicians'],
            $email,
            ($fname ?? ''),
            ($mname ?? ''),
            ($lname ?? '')
        );

    } else {
        $providerId = $provider['id'];
    }

    $authPass = sqlQuery("SELECT password FROM users_secure WHERE username = ?",[$email]);

    
    // --------------------------------------------------
    // ESTABLISH OPENEMR AUTH SESSION
    // --------------------------------------------------
    $_SESSION['authUser']       = $email;
    $_SESSION['authUserID']     = $providerId;
    $_SESSION['authProvider']   = 'Default';
    $_SESSION['authGroup']      = 'Default';
    $_SESSION['userauthorized'] = 1;
    $_SESSION['authPass']  = $authPass['password'];
    $_SESSION['ignoreAuth'] = true;
    $_SESSION['ignoreAuth_onsite_portal'] = true;
    $_SESSION['enable_database_connection_pooling'] = 1;

    $_SESSION['language_choice']    = 1;
    $_SESSION['language_direction'] = 'ltr';

    $_SESSION['token_main_php'] = bin2hex(random_bytes(16));
    $token_main = $_SESSION['token_main_php'];
    
    $_SESSION['default_open_tabs']   = [
        [
            'list_id' => 'default_open_tabs', 
            'option_id' => 'cal',
            'title' => "Calendar",
            'seq' => 10,
            'is_default' => 0,
            'option_value' => 0,
            'mapping' => '',
            'notes' => 'interface/main/main_info.php',
            'codes' => '',
            'toggle_setting_1' => 0,
            'toggle_setting_2' => 0,
            'activity' => 1,
            'subtype' => '',
            'edit_options' => 1,
            'timestamp' => date('Y-m-d H:i:s'),
            'last_updated' => date('Y-m-d H:i:s')
        ],
        [
            'list_id' => 'default_open_tabs',
            'option_id' => 'msg',
            'title' => "Message Inbox",
            'seq' => 50,
            'is_default' => 0,
            'option_value' => 0,
            'mapping' => '',
            'notes' => 'interface/main/messages/messages.php?form_active=1',
            'codes' => '',
            'toggle_setting_1' => 0,
            'toggle_setting_2' => 0,
            'activity' => 1,
            'subtype' => '',
            'edit_options' => 1,
            'timestamp' => date('Y-m-d H:i:s'),
            'last_updated' => date('Y-m-d H:i:s')
        ]
    ];
    

    // --------------------------------------------------
    // REDIRECT INTO OPENEMR
    // --------------------------------------------------
    header(
        "Location: {$GLOBALS['webroot']}/interface/main/tabs/main.php" .
        "?token_main=" . $token_main
    );
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo "<h3>SSO ERROR</h3>";
    echo htmlspecialchars($e->getMessage());
    exit;
}


