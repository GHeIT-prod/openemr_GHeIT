<?php

/**
 * oe-module-gheit-prior-auth
 *
 * Entry point OpenEMR's module manager includes for every enabled custom
 * module. Hands the shared EventDispatcher to this module's own Bootstrap
 * class, which performs the actual registration.
 *
 * @package   GheitPriorAuth
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitPriorAuth;

require_once __DIR__ . '/src/Bootstrap.php';

use OpenEMR\Modules\GheitPriorAuth\Bootstrap;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * @global OpenEMR\Core\ModulesClassLoader $classLoader
 */
$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\GheitPriorAuth\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

$eventDispatcher = $GLOBALS['kernel']->getEventDispatcher();

$bootstrap = new Bootstrap($eventDispatcher);
$bootstrap->subscribeToEvents();
