<?php

/**
 * main.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Kevin Yeh <kevin.y@integralemr.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Ranganath Pathak <pathak@scrs1.org>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2016 Kevin Yeh <kevin.y@integralemr.com>
 * @copyright Copyright (c) 2016-2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Ranganath Pathak <pathak@scrs1.org>
 * @copyright Copyright (c) 2024 Care Management Solutions, Inc. <stephen.waite@cmsvt.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$sessionAllowWrite = true;
require_once(__DIR__ . '/../../globals.php');
require_once $GLOBALS['srcdir'] . '/ESign/Api.php';

use ESign\Api;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Events\Main\Tabs\RenderEvent;
use OpenEMR\Menu\MainMenuRole;
use OpenEMR\Services\LogoService;
use OpenEMR\Services\ProductRegistrationService;
use OpenEMR\Telemetry\TelemetryService;
use Symfony\Component\Filesystem\Path;

const ENV_DISABLE_TELEMETRY = 'OPENEMR_DISABLE_TELEMETRY';

$logoService = new LogoService();
$menuLogo = $logoService->getLogo('core/menu/primary/');
// Registration status and options.
$productRegistration = new ProductRegistrationService();
$product_row = $productRegistration->getProductDialogStatus();
$allowRegisterDialog = $product_row['allowRegisterDialog'] ?? 0;
$allowTelemetry = $product_row['allowTelemetry'] ?? null; // for dialog
$allowEmail = $product_row['allowEmail'] ?? null; // for dialog

// Check if telemetry is disabled via environment variable
// Telemetry disable flag (set env var to: 1/true)
$val = getenv(ENV_DISABLE_TELEMETRY);
if ($val === false || $val === '') {
    $val = $_ENV[ENV_DISABLE_TELEMETRY] ?? $_SERVER[ENV_DISABLE_TELEMETRY] ?? null;
}
$disableTelemetry = ($val !== null) && filter_var($val, FILTER_VALIDATE_BOOLEAN);
if ($disableTelemetry) {
    $allowRegisterDialog = false;
    $allowTelemetry = false;
}

// If running unit tests, then disable the registration dialog
if ($_SESSION['testing_mode'] ?? false) {
    $allowRegisterDialog = false;
}
// If the user is not a super admin, then disable the registration dialog
if (!AclMain::aclCheckCore('admin', 'super')) {
    $allowRegisterDialog = false;
}

// Ensure token_main matches so this script can not be run by itself
//  If tokens do not match, then destroy the session and go back to log in screen
if (
    (empty($_SESSION['token_main_php'])) ||
    (empty($_GET['token_main'])) ||
    ($_GET['token_main'] != $_SESSION['token_main_php'])
) {
// Below functions are from auth.inc, which is included in globals.php
    authCloseSession();
    authLoginScreen(false);
}
// this will not allow copy/paste of the link to this main.php page or a refresh of this main.php page
//  (default behavior, however, this behavior can be turned off in the prevent_browser_refresh global)
if ($GLOBALS['prevent_browser_refresh'] > 1) {
    unset($_SESSION['token_main_php']);
}

$esignApi = new Api();
$twig = (new TwigContainer(null, $GLOBALS['kernel']))->getTwig();

?>
<!DOCTYPE html>
<html>

<head>
    <title><?php echo text($openemr_name); ?></title>

    <script>
        // This is to prevent users from losing data by refreshing or backing out of OpenEMR.
        //  (default behavior, however, this behavior can be turned off in the prevent_browser_refresh global)
        <?php if ($GLOBALS['prevent_browser_refresh'] > 0) { ?>
        window.addEventListener('beforeunload', (event) => {
            if (!timed_out) {
                event.returnValue = <?php echo xlj('Recommend not leaving or refreshing or you may lose data.'); ?>;
            }
        });
        <?php } ?>

        <?php require($GLOBALS['srcdir'] . "/restoreSession.php"); ?>

        // Since this should be the parent window, this is to prevent calls to the
        // window that opened this window. For example when a new window is opened
        // from the Patient Flow Board or the Patient Finder.
        window.opener = null;
        window.name = "main";

        // This flag indicates if another window or frame is trying to reload the login
        // page to this top-level window.  It is set by javascript returned by auth.inc.php
        // and is checked by handlers of beforeunload events.
        var timed_out = false;
        // some globals to access using top.variable
        // note that 'let' or 'const' does not allow global scope here.
        // only use var
        var isPortalEnabled = "<?php echo $GLOBALS['portal_onsite_two_enable'] ?>";
        // Set the csrf_token_js token that is used in the below js/tabs_view_model.js script
        var csrf_token_js = <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>;
        var userDebug = <?php echo js_escape($GLOBALS['user_debug']); ?>;
        var webroot_url = <?php echo js_escape($web_root); ?>;
        var jsLanguageDirection = <?php echo js_escape($_SESSION['language_direction']); ?> ||
        'ltr';
        var jsGlobals = {};
        // used in tabs_view_model.js.
        jsGlobals.enable_group_therapy = <?php echo js_escape($GLOBALS['enable_group_therapy']); ?>;
        jsGlobals.languageDirection = jsLanguageDirection;
        jsGlobals.date_display_format = <?php echo js_escape($GLOBALS['date_display_format']); ?>;
        jsGlobals.time_display_format = <?php echo js_escape($GLOBALS['time_display_format']); ?>;
        jsGlobals.timezone = <?php echo js_escape($GLOBALS['gbl_time_zone'] ?? ''); ?>;
        jsGlobals.assetVersion = <?php echo js_escape($GLOBALS['v_js_includes']); ?>;
        var WindowTitleAddPatient = <?php echo($GLOBALS['window_title_add_patient_name'] ? 'true' : 'false'); ?>;
        var WindowTitleBase = <?php echo js_escape($openemr_name); ?>;
        const isSms = "<?php echo !empty($GLOBALS['oefax_enable_sms'] ?? null); ?>";
        const isFax = "<?php echo !empty($GLOBALS['oefax_enable_fax']) ?? null?>";
        const isServicesOther = (isSms || isFax);
        var telemetryEnabled = <?php echo js_escape((new TelemetryService())->isTelemetryEnabled()); ?>;

        /**
         * Async function to get session value from the server
         * Usage Example
         * let authUser;
         * let sessionPid = await top.getSessionValue('pid');
         * // If using then() method a promise is returned instead of the value.
         * await top.getSessionValue('authUser').then(function (auth) {
         *    authUser = auth;
         *    console.log('authUser', authUser);
         * });
         * console.log('session pid', sessionPid);
         * console.log('auth User', authUser);
         */
        async function getSessionValue(key) {
            restoreSession();
            let csrf_token_js = <?php echo js_escape(CsrfUtils::collectCsrfToken('default')); ?>;
            const config = {
                url: `${webroot_url}/library/ajax/set_pt.php?csrf_token_form=${csrf_token_js}`,
                method: 'POST',
                data: {
                    mode: 'session_key',
                    key: key
                }
            };
            try {
                const response = await $.ajax(config);
                restoreSession();
                return response;
            } catch (error) {
                throw error;
            }
        }

        function goRepeaterServices() {
            // Ensure send the skip_timeout_reset parameter to not count this as a manual entry in the
            // timing out mechanism in OpenEMR.

            // Send the skip_timeout_reset parameter to not count this as a manual entry in the
            // timing out mechanism in OpenEMR. Notify App for various portal and reminder alerts.
            // Combined portal and reminders ajax to fetch sjp 06-07-2020.
            // Incorporated timeout mechanism in 2021
            restoreSession();
            let request = new FormData;
            request.append("skip_timeout_reset", "1");
            request.append("isPortal", isPortalEnabled);
            request.append("isServicesOther", isServicesOther);
            request.append("isSms", isSms);
            request.append("isFax", isFax);
            request.append("csrf_token_form", csrf_token_js);
            fetch(webroot_url + "/library/ajax/dated_reminders_counter.php", {
                method: 'POST',
                credentials: 'same-origin',
                body: request
            }).then((response) => {
                if (response.status !== 200) {
                    console.log('Reminders start failed. Status Code: ' + response.status);
                    return;
                }
                return response.json();
            }).then((data) => {
                if (data.timeoutMessage && (data.timeoutMessage == 'timeout')) {
                    // timeout has happened, so logout
                    timeoutLogout();
                }
                if (isPortalEnabled) {
                    let mail = data.mailCnt;
                    let chats = data.chatCnt;
                    let audits = data.auditCnt;
                    let payments = data.paymentCnt;
                    let total = data.total;
                    let enable = ((1 * mail) + (1 * audits)); // payments are among audits.
                    // Send portal counts to notification button model
                    // Will turn off button display if no notification!
                    app_view_model.application_data.user().portal(enable);
                    if (enable > 0) {
                        app_view_model.application_data.user().portalAlerts(total);
                        app_view_model.application_data.user().portalAudits(audits);
                        app_view_model.application_data.user().portalMail(mail);
                        app_view_model.application_data.user().portalChats(chats);
                        app_view_model.application_data.user().portalPayments(payments);
                    }
                }
                if (isServicesOther) {
                    let sms = data.smsCnt;
                    let fax = data.faxCnt;
                    let total = data.serviceTotal;
                    let enable = ((1 * sms) + (1 * fax));
                    // Will turn off button display if no notification!
                    app_view_model.application_data.user().servicesOther(enable);
                    if (enable > 0) {
                        app_view_model.application_data.user().serviceAlerts(total);
                        app_view_model.application_data.user().smsAlerts(sms);
                        app_view_model.application_data.user().faxAlerts(fax);
                    }
                }
                // Always send reminder count text to model
                app_view_model.application_data.user().messages(data.reminderText);
            }).catch(function (error) {
                console.log('Request failed', error);
            });

            // run background-services
            // delay 10 seconds to prevent both utility trigger at close to same time.
            // Both call globals so that is my concern.
            setTimeout(function () {
                restoreSession();
                request = new FormData;
                request.append("skip_timeout_reset", "1");
                request.append("ajax", "1");
                request.append("csrf_token_form", csrf_token_js);
                fetch(webroot_url + "/library/ajax/execute_background_services.php", {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: request
                }).then((response) => {
                    if (response.status !== 200) {
                        console.log('Background Service start failed. Status Code: ' + response.status);
                    }
                }).catch(function (error) {
                    console.log('HTML Background Service start Request failed: ', error);
                });
            }, 10000);

            // auto run this function every 60 seconds
            var repeater = setTimeout("goRepeaterServices()", 60000);
        }

        function isEncounterLocked(encounterId) {
            <?php if ($esignApi->lockEncounters()) { ?>
            // If encounter locking is enabled, make a synchronous call (async=false) to check the
            // DB to see if the encounter is locked.
            // Call restore session, just in case
            // @TODO next clean up pass, turn into await promise then modify tabs_view_model.js L-309
            restoreSession();
            let url = webroot_url + "/interface/esign/index.php?module=encounter&method=esign_is_encounter_locked";
            $.ajax({
                type: 'POST',
                url: url,
                data: {
                    encounterId: encounterId
                },
                success: function (data) {
                    encounter_locked = data;
                },
                dataType: 'json',
                async: false
            });
            return encounter_locked;
            <?php } else { ?>
            // If encounter locking isn't enabled then always return false
            return false;
            <?php } ?>
        }
    </script>

    <?php Header::setupHeader(['knockout', 'tabs-theme', 'i18next', 'hotkeys', 'i18formatting']); ?>
    <script>
        // set up global translations for js
        function setupI18n(lang_id) {
            restoreSession();
            return fetch(<?php echo js_escape($GLOBALS['webroot']) ?> +"/library/ajax/i18n_generator.php?lang_id=" + encodeURIComponent(lang_id) + "&csrf_token_form=" + encodeURIComponent(csrf_token_js), {
                credentials: 'same-origin',
                method: 'GET'
            }).then((response) => {
                if (response.status !== 200) {
                    console.log('I18n setup failed. Status Code: ' + response.status);
                    return [];
                }
                return response.json();
            })
        }

        setupI18n(<?php echo js_escape($_SESSION['language_choice']); ?>).then(translationsJson => {
            i18next.init({
                lng: 'selected',
                debug: false,
                nsSeparator: false,
                keySeparator: false,
                resources: {
                    selected: {
                        translation: translationsJson
                    }
                }
            });
        }).catch(error => {
            console.log(error.message);
        });

        /**
         * Assign and persist documents to portal patients
         * @var int patientId pid
         */
        function assignPatientDocuments(patientId) {
            let url = top.webroot_url + '/portal/import_template_ui.php?from_demo_pid=' + encodeURIComponent(patientId);
            dlgopen(url, 'pop-assignments', 'modal-lg', 850, '', '', {
                allowDrag: true,
                allowResize: true,
                sizeHeight: 'full',
            });
        }
    </script>

    <script src="js/custom_bindings.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/user_data_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/patient_data_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/therapy_group_data_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/tabs_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/application_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/frame_proxies.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/dialog_utils.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/shortcuts.js?v=<?php echo $v_js_includes; ?>"></script>

    <?php
    // Below code block is to prepare certain elements for deciding what links to show on the menu
    // prepare Ensora eRx globals that are used in creating the menu
    if ($GLOBALS['erx_enable']) {
        $newcrop_user_role_sql = sqlQuery("SELECT `newcrop_user_role` FROM `users` WHERE `username` = ?", [$_SESSION['authUser']]);
        $GLOBALS['newcrop_user_role'] = $newcrop_user_role_sql['newcrop_user_role'];
        if ($GLOBALS['newcrop_user_role'] === 'erxadmin') {
            $GLOBALS['newcrop_user_role_erxadmin'] = 1;
        }
    }

    // prepare track anything to be used in creating the menu
    $track_anything_sql = sqlQuery("SELECT `state` FROM `registry` WHERE `directory` = 'track_anything'");
    $GLOBALS['track_anything_state'] = ($track_anything_sql['state'] ?? 0);
    // prepare Issues popup link global that is used in creating the menu
    $GLOBALS['allow_issue_menu_link'] = (
        (AclMain::aclCheckCore('encounters', 'notes', '', 'write')
        || AclMain::aclCheckCore('encounters', 'notes_a', '', 'write'))
        && AclMain::aclCheckCore('patients', 'med', '', 'write')
    );

    // we use twig templates here so modules can customize some of these files
    // at some point we will twigify all of main.php so we can extend it.
    echo $twig->render("interface/main/tabs/tabs_template.html.twig", []);
    echo $twig->render("interface/main/tabs/menu_template.html.twig", []);
    // TODO: patient_data_template.php is a more extensive refactor that could be done in a future feature request but to not jeopardize 7.0.3 release we will hold off.
    ?>
    <?php require_once("templates/patient_data_template.php"); ?>
    <?php
    echo $twig->render("interface/main/tabs/therapy_group_template.html.twig", []);
    echo $twig->render("interface/main/tabs/user_data_template.html.twig", [
        'openemr_name' => $GLOBALS['openemr_name']
    ]);
    // Collect the menu then build it
    $menuMain = new MainMenuRole($GLOBALS['kernel']->getEventDispatcher());
    $menu_restrictions = $menuMain->getMenu();
    echo $twig->render("interface/main/tabs/menu_json.html.twig", ['menu_restrictions' => $menu_restrictions]);
    ?>
    <?php $userQuery = sqlQuery("select * from users where username = ?", [$_SESSION['authUser']]); ?>

    <script>
        <?php
        if ($_SESSION['default_open_tabs']) :
            // For now, only the first tab is visible, this could be improved upon by further customizing the list options in a future feature request
            $visible = "true";
            foreach ($_SESSION['default_open_tabs'] as $i => $tab) :
                $_unsafe_url = preg_replace('/(\?.*)/m', '', Path::canonicalize($fileroot . DIRECTORY_SEPARATOR . $tab['notes']));
                if (realpath($_unsafe_url) === false || !str_starts_with($_unsafe_url, (string) $fileroot)) {
                    unset($_SESSION['default_open_tabs'][$i]);
                    continue;
                }
                $url = json_encode($webroot . "/" . $tab['notes']);
                $target = json_encode($tab['option_id']);
                $label = json_encode(xl("Loading") . " " . $tab['title']);
                $loading = xlj("Loading");
                echo "app_view_model.application_data.tabs.tabsList.push(new tabStatus($label, $url, $target, $loading, true, $visible, false));\n";
                $visible = "false";
            endforeach;
        endif;
        ?>

        app_view_model.application_data.user(new user_data_view_model(<?php echo json_encode($_SESSION["authUser"])
            . ',' . json_encode($userQuery['fname'])
            . ',' . json_encode($userQuery['lname'])
            . ',' . json_encode($_SESSION['authProvider']); ?>));
    </script>
    <style>
      html,
      body {
        width: max-content;
        min-height: 100% !important;
        height: 100% !important;
      }
      #userdropdown.dropdown-menu {
        white-space: nowrap;        /* prevents multi-line wrapping */
        min-width: max-content;     /* expands to fit the widest item */
      }
    </style>
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot']; ?>/interface/themes/custom_sidebar.css">
</head>

<body class="min-vw-100">
    <?php
    // fire off an event here
    if (!empty($GLOBALS['kernel']->getEventDispatcher())) {
        $dispatcher = $GLOBALS['kernel']->getEventDispatcher();
        $dispatcher->dispatch(new RenderEvent(), RenderEvent::EVENT_BODY_RENDER_PRE);
    }
    ?>
    <!-- Below iframe is to support logout, which needs to be run in an inner iframe to work as intended -->
    <iframe name="logoutinnerframe" id="logoutinnerframe" style="visibility:hidden; position:absolute; left:0; top:0; height:0; width:0; border:none;" src="about:blank"></iframe>
    <?php // mdsupport - app settings
    $disp_mainBox = '';
    if (isset($_SESSION['app1'])) {
        $rs = sqlquery(
            "SELECT title app_url FROM list_options WHERE activity=1 AND list_id=? AND option_id=?",
            ['apps', $_SESSION['app1']]
        );
        if ($rs['app_url'] != "main/main_screen.php") {
            echo '<iframe name="app1" src="../../' . attr($rs['app_url']) . '"
            style="position: absolute; left: 0; top: 0; height: 100%; width: 100%; border: none;" />';
            $disp_mainBox = 'style="display: none;"';
        }
    }
    ?>
    <div id="mainBox" <?php echo $disp_mainBox ?>>
        <nav class="navbar navbar-expand-xl navbar-light bg-light py-0">
            <?php if ($GLOBALS['display_main_menu_logo'] === '1') : ?>
                <!-- <a class="navbar-brand" href="https://www.open-emr.org" title="OpenEMR <?php echo xla("Website"); ?>" rel="noopener" target="_blank">
                    <img src="<?php echo $menuLogo; ?>" class="d-inline-block align-middle" height="16" alt="<?php echo xlt('Main Menu Logo'); ?>">
                </a> -->
                <!-- <a class="navbar-brand d-flex align-items-center" href="https://www.open-emr.org" rel="noopener">
                    <img src="<?php echo $menuLogo; ?>" class="d-inline-block align-middle" height="50">
                    <span class="ms-2">NeoCareX</span>
                </a> -->
                <a class="navbar-brand d-flex align-items-center" href="#" onclick="(function(){ var cal = Array.from(document.querySelectorAll('nav.navbar .menuLabel')).find(function(el){ return el.textContent.trim() === 'Calendar'; }); if(cal) cal.click(); return false; })(); return false;">
                    <img src="<?php echo $menuLogo; ?>" class="d-inline-block align-middle" height="50">
                    <span class="ms-2">NeoCareX</span>
                </a>
            <?php endif; ?>
            <button class="navbar-toggler mr-auto" type="button" data-toggle="collapse" data-target="#mainMenu" aria-controls="mainMenu" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainMenu" data-bind="template: {name: 'menu-template', data: application_data}"></div>
            <?php if ($GLOBALS['search_any_patient'] != 'none') : ?>
                <form name="frm_search_globals" class="form-inline">
                    <div class="input-group">
                        <input type="text" id="anySearchBox" class="form-control-sm <?php echo $any_search_class ?> form-control" name="anySearchBox" placeholder="<?php echo xla("Search by any demographics") ?>" autocomplete="off">
                        <div class="input-group-append">
                            <button type="button" id="search_globals" class="btn btn-sm btn-secondary <?php echo $search_globals_class ?>" title='<?php echo xla("Search for patient by entering whole or part of any demographics field information"); ?>' data-bind="event: {mousedown: viewPtFinder.bind( $data, '<?php echo xla("The search field cannot be empty. Please enter a search term") ?>', '<?php echo attr($search_any_type); ?>')}">
                                <i class="fa fa-search">&nbsp;</i></button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
            <!--Below is the user data section that contains the user information and the attendant data-->
            <span id="userData" data-bind="template: {name: 'user-data-template', data: application_data}"></span>
            <?php
            // fire off a nav event
            $dispatcher->dispatch(new RenderEvent(), RenderEvent::EVENT_BODY_RENDER_NAV);
            ?>
        </nav>
        <div id="attendantData" class="body_title acck" data-bind="template: {name: app_view_model.attendant_template_type, data: application_data}"></div>
        <div class="body_title pt-1" id="tabs_div" data-bind="template: {name: 'tabs-controls', data: application_data}"></div>
        <div class="mainFrames d-flex flex-row" id="mainFrames_div">
            <div id="framesDisplay" data-bind="template: {name: 'tabs-frames', data: application_data}"></div>
        </div>
        <?php echo $twig->render("product_registration/product_registration_modal.html.twig", [
            'webroot' => $webroot,
            'allowEmail' => $allowEmail ?? false,
            'allowTelemetry' => $allowTelemetry ?? false]); ?>
    </div>
    <script>
        ko.applyBindings(app_view_model);

        $(function () {
            $('.dropdown-toggle').dropdown();
            $('#patient_caret').click(function () {
                $('#attendantData').slideToggle();
                $('#patient_caret').toggleClass('fa-caret-down').toggleClass('fa-caret-up');
            });
            if ($('body').css('direction') == "rtl") {
                $('.dropdown-menu-right').each(function () {
                    $(this).removeClass('dropdown-menu-right');
                });
            }
        });
        $(function () {
            $('#logo_menu').focus();
        });
        $('#anySearchBox').keypress(function (event) {
            if (event.which === 13 || event.keyCode === 13) {
                event.preventDefault();
                $('#search_globals').mousedown();
            }
        });
        document.addEventListener('touchstart', {}); //specifically added for iOS devices, especially in iframes
        <?php if (($_ENV['OPENEMR__NO_BACKGROUND_TASKS'] ?? 'false') !== 'true') { ?>
        $(function () {
            goRepeaterServices();
        });
        <?php } ?>
    </script>
    <?php

    // fire off an event here
    $dispatcher->dispatch(new RenderEvent(), RenderEvent::EVENT_BODY_RENDER_POST);

    if (!empty($allowRegisterDialog)) { // disable if running unit tests.
        // Include the product registration js, telemetry and usage data reporting dialog
        echo $twig->render("product_registration/product_reg.js.twig", ['webroot' => $webroot]);
    }

    ?>

    <script>
        (function () {

            /* ── 1. Accordion toggle ── */
            function initSidebarMenu() {
                document.querySelectorAll(
                    'nav.navbar .menuSection > .menuLabel.dropdown-toggle'
                    ).forEach(function (toggle) {
                    if (toggle.dataset.oeToggleInit) return;
                    toggle.dataset.oeToggleInit = 'true';

                var section = toggle.closest('.menuSection');
                if (!section) return;
                    // Check if this is a TOP-LEVEL menuSection
                    // (direct child of appMenu > div, not inside a ul.menuEntries)
                    var isTopLevel = !!section.parentElement.closest('.appMenu');
                    var isInsideSubmenu = !!section.closest('ul.menuEntries');

                if (isTopLevel && !isInsideSubmenu) {
                    // TOP LEVEL — show children in topbar instead of expanding sidebar
                    toggle.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();

                        var isActive = section.classList.contains('oe-top-active');

                    // Remove active from all top-level sections
                    document.querySelectorAll('nav.navbar .appMenu > div > .menuSection')
                        .forEach(function (s) { s.classList.remove('oe-top-active'); });

                    if (isActive) {
                        // Clicking again closes the topbar nav
                        clearSecondaryNav();
                    } else {
                        section.classList.add('oe-top-active');
                        showLevel1Nav(section, toggle);
                    }
                    });

                } else {
                    // NESTED LEVEL (inside a submenu) — keep existing accordion behaviour
                    toggle.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var isOpen = section.classList.contains('oe-open');

                    var parent = section.parentElement;
                    if (parent) {
                        parent.querySelectorAll(
                        ':scope > li > .menuSection.oe-open, :scope > .menuSection.oe-open'
                        ).forEach(function (s) {
                        if (s !== section) s.classList.remove('oe-open');
                        });
                    }

                    section.classList.toggle('oe-open', !isOpen);
                    });
                }
                });
            }

            /* ── 2. Show Level 1 children in topbar ── */
            function showLevel1Nav(section, sourceToggle) {
                var parentLabel = sourceToggle.textContent.trim();
                var submenu = section.querySelector(':scope > ul.menuEntries');
                if (!submenu) return;

                var children = collectChildren(submenu);
                renderSecondaryNav([{ label: parentLabel, children: children }]);
            }

            /* ── 3. Collect children from a ul.menuEntries ── */
            function collectChildren(submenu) {
                var children = [];
                submenu.querySelectorAll(':scope > li').forEach(function (li) {
                    var label = li.querySelector(':scope > .menuLabel:not(.dropdown-toggle)');
                    var nestedSection = li.querySelector(':scope > .menuSection');

                    if (nestedSection) {
                        var nestedToggle = nestedSection.querySelector(':scope > .menuLabel.dropdown-toggle');
                        var nestedSubmenu = nestedSection.querySelector(':scope > ul.menuEntries');
                        if (nestedToggle) {
                            children.push({
                                label: nestedToggle.textContent.trim(),
                                element: nestedToggle,
                                disabled: false,
                                hasChildren: !!nestedSubmenu,
                                submenu: nestedSubmenu
                            });
                        }
                    } else if (label) {
                        children.push({
                        label: label.textContent.trim(),
                        element: label,
                        disabled: label.classList.contains('menuDisabled'),
                        hasChildren: false,
                        submenu: null
                        });
                    }
                });
                return children;
            }

            /* ── 4. Render breadcrumb trail + pills in topbar ── */
            var breadcrumbTrail = []; // stack of { label, children }

            function renderSecondaryNav(trail) {
                breadcrumbTrail = trail;

                var nav = document.getElementById('oe-secondary-nav');
                if (!nav) return;
                nav.innerHTML = '';

                // Render breadcrumb trail
                trail.forEach(function (crumb, idx) {
                    var isLast = (idx === trail.length - 1);

                    // Breadcrumb label (clickable if not last)
                    var crumbEl = document.createElement('span');
                    crumbEl.className = 'oe-sec-parent' + (idx > 0 ? ' oe-sec-crumb-link' : '');
                    crumbEl.textContent = crumb.label;

                    if (!isLast) {
                        crumbEl.style.cursor = 'pointer';
                        crumbEl.style.textDecoration = 'underline';
                        crumbEl.style.opacity = '0.7';
                        (function (i) {
                            crumbEl.addEventListener('click', function () {
                                renderSecondaryNav(trail.slice(0, i + 1));
                            });
                        })(idx);
                    }

                    nav.appendChild(crumbEl);

                    // Separator
                    var sep = document.createElement('span');
                    sep.className = 'oe-sec-sep';
                    sep.innerHTML = '&#8250;';
                    nav.appendChild(sep);
                });

                // Render current level's children as pills
                var currentChildren = trail[trail.length - 1].children;
                currentChildren.forEach(function (child) {
                var btn = document.createElement('button');
                btn.className = 'oe-sec-item' +
                    (child.disabled ? ' oe-sec-disabled' : '') +
                    (child.hasChildren ? ' oe-sec-has-children' : '');

                if (child.hasChildren) {
                    btn.innerHTML = child.label + ' <span class="oe-sec-chevron">&#8250;</span>';
                } else {
                    btn.textContent = child.label;
                }

                if (!child.disabled) {
                    btn.addEventListener('click', function () {
                    // Mark active
                    nav.querySelectorAll('.oe-sec-item').forEach(function (b) {
                        b.classList.remove('oe-sec-active');
                    });
                    btn.classList.add('oe-sec-active');

                    if (child.hasChildren && child.submenu) {
                        // Drill down — push to trail
                        var newChildren = collectChildren(child.submenu);
                        renderSecondaryNav(trail.concat([{ label: child.label, children: newChildren }]));
                    } else {
                        // Navigate
                        child.element.click();
                    }
                    });
                }

                nav.appendChild(btn);
                });
            }

            function clearSecondaryNav() {
                breadcrumbTrail = [];
                var nav = document.getElementById('oe-secondary-nav');
                if (nav) nav.innerHTML = '';
                document.querySelectorAll('nav.navbar .appMenu > div > .menuSection.oe-top-active')
                .forEach(function (s) { s.classList.remove('oe-top-active'); });
            }

            /* ── 5. Build top bar ── */
            function buildTopBar() {
                var existingBar = document.getElementById('oe-topbar');
                if (existingBar && existingBar.querySelector('.navbar-brand')) return;

                var navbar = document.querySelector('nav.navbar');
                if (!navbar) return;

                var searchForm = navbar.querySelector('form[name="frm_search_globals"], .form-inline');
                var notifIcons = document.querySelector('#attendantData .flex-column.mx-2');
                var attendantData = document.getElementById('attendantData');
                var logo = navbar.querySelector('.navbar-brand');

                if (!searchForm && !logo) return;

                var bar = existingBar || document.createElement('div');
                if (!existingBar) {
                    bar.id = 'oe-topbar';
                    document.body.appendChild(bar);
                }

                if (logo && !bar.contains(logo)) {
                    logo.style.cssText = '';
                    bar.insertBefore(logo, bar.firstChild);
                }

                if (!document.getElementById('oe-secondary-nav')) {
                    var secNav = document.createElement('div');
                    secNav.id = 'oe-secondary-nav';
                    var logoEl = bar.querySelector('.navbar-brand');
                    if (logoEl && logoEl.nextSibling) {
                        bar.insertBefore(secNav, logoEl.nextSibling);
                    } else {
                        bar.appendChild(secNav);
                    }
                }

                if (!bar.querySelector('.oe-spacer')) {
                    var spacer = document.createElement('div');
                    spacer.className = 'oe-spacer';
                    spacer.style.flex = '1';
                    bar.appendChild(spacer);
                }

                if (searchForm && !bar.contains(searchForm)) {
                    searchForm.style.cssText = 'margin:0;display:flex;align-items:center;';
                    bar.appendChild(searchForm);
                }

                if (notifIcons && !bar.contains(notifIcons)) {
                    notifIcons.style.cssText = 'display:flex!important;flex-direction:row!important;align-items:center;gap:6px;';
                    bar.appendChild(notifIcons);
                }

                // ── NEW: place #attendantData as a sub-bar BELOW #oe-topbar ──
                if (attendantData && !document.getElementById('oe-patient-bar')) {
                    var patientBar = document.createElement('div');
                    patientBar.id = 'oe-patient-bar';
                    patientBar.appendChild(attendantData);
                    document.body.appendChild(patientBar); // just append to body, CSS handles position
                }
            }

            /* ── 6. Sidebar collapse toggle ── */
            function initSidebarToggle() {
                if (document.getElementById('oe-sidebar-toggle')) return;
                if (window.self !== window.top) return;

                var popupNames = ['Popup', 'RTop', 'RBot', 'LeftNav', 'logoutinnerframe'];
                if (popupNames.indexOf(window.name) !== -1) return;

                var btn = document.createElement('div');
                btn.id = 'oe-sidebar-toggle';
                btn.innerHTML = '&#8249;';
                btn.title = 'Toggle sidebar';
                document.body.appendChild(btn);

                btn.addEventListener('click', function () {
                    document.body.classList.toggle('sidebar-collapsed');
                    btn.innerHTML = document.body.classList.contains('sidebar-collapsed')
                        ? '&#8250;' : '&#8249;';

                    setTimeout(function () {
                        window.dispatchEvent(new Event('resize'));
                        document.querySelectorAll('iframe').forEach(function (f) {
                        try { f.contentWindow.dispatchEvent(new Event('resize')); } catch(e) {}
                        });
                    }, 300);
                });
            }

            /* ── 7. User section in sidebar ── */
            function buildUserSidebar() {
                if (document.getElementById('oe-user-sidebar')) return;

                var userData = document.getElementById('userData');
                if (!userData) return;

                var userName = 'Administrator';
                var lname = userData.querySelector('[data-bind*="lname"]');
                var fname = userData.querySelector('[data-bind*="fname"]');
                if (lname) userName = (fname ? fname.textContent.trim() + ' ' : '') + lname.textContent.trim();

                var dropdownItems = userData.querySelectorAll('#userdropdown .dropdown-item');

                var section = document.createElement('div');
                section.id = 'oe-user-sidebar';

                var header = document.createElement('div');
                header.id = 'oe-user-header';
                header.innerHTML = '\
                <i class="fa fa-user-circle oe-user-avatar"></i>\
                <span class="oe-user-name">' + userName + '</span>\
                ';
                section.appendChild(header);

                var submenu = document.createElement('ul');
                submenu.id = 'oe-user-submenu';

                dropdownItems.forEach(function (item) {
                    var li = document.createElement('li');
                    var icon = item.querySelector('i');
                    var iconClass = icon ? icon.className.replace('pr-2', '').trim() : 'fa fa-fw fa-circle';
                    var label = item.textContent.trim();
                    li.innerHTML = '<i class="' + iconClass + '"></i><span>' + label + '</span>';
                    li.addEventListener('click', function () { item.click(); });
                    submenu.appendChild(li);
                });

                section.appendChild(submenu);

                section.addEventListener('mouseenter', function () {
                    submenu.classList.add('oe-submenu-visible');
                });
                section.addEventListener('mouseleave', function () {
                    submenu.classList.remove('oe-submenu-visible');
                });

                var navbar = document.querySelector('nav.navbar');
                if (navbar) navbar.appendChild(section);
            }

            /* ── 8. Mobile sidebar toggle ── */
            function initMobileToggle() {
                if (document.getElementById('oe-mobile-toggle')) return;

                var tabsDiv = document.getElementById('tabs_div');
                if (!tabsDiv) { setTimeout(initMobileToggle, 300); return; }

                var caretWrapper = tabsDiv.querySelector('.tabsNoHover.w-1');
                if (!caretWrapper) { setTimeout(initMobileToggle, 300); return; }

                var btn = document.createElement('i');
                btn.id = 'oe-mobile-toggle';
                btn.className = 'fa fa-bars';
                btn.title = 'Toggle menu';
                caretWrapper.insertBefore(btn, caretWrapper.firstChild);

                var overlay = document.createElement('div');
                overlay.id = 'oe-mobile-overlay';
                document.body.appendChild(overlay);

                function closeMobileSidebar() {
                    document.body.classList.remove('sidebar-mobile-open');
                }

                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    document.body.classList.toggle('sidebar-mobile-open');
                });

                overlay.addEventListener('click', closeMobileSidebar);

                document.addEventListener('click', function (e) {
                    if (window.innerWidth > 768) return;
                    var label = e.target.closest('.menuLabel:not(.dropdown-toggle)');
                    if (label) { setTimeout(closeMobileSidebar, 150); }
                });
            }

            /* ── 9. Watch for Knockout rendering ── */
            var observer = new MutationObserver(function () {
                initSidebarMenu();
                buildTopBar();
            });

            document.addEventListener('DOMContentLoaded', function () {
                observer.observe(document.body, { childList: true, subtree: true });
                initSidebarMenu();
                buildTopBar();
                initSidebarToggle();

                setTimeout(function () {
                buildUserSidebar();
                initMobileToggle();
                }, 800);
            });

        })();
    </script>
  
</body>

</html>