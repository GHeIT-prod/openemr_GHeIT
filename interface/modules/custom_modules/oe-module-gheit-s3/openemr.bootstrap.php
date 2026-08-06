<?php

/**
 * openemr.bootstrap.php — oe-module-gheit-s3
 *
 * Required directly by OpenEMR's ModulesApplication when this module is
 * enabled (Modules -> Manage Modules -> Custom Modules -> Enable). This
 * is the modern module entry point; module.php is kept alongside it only
 * for backward compatibility with older OpenEMR versions that still use
 * the legacy custom_modules loader and never call this file.
 *
 * @package   OpenEMR\Modules\GheitS3
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3;

require_once __DIR__ . '/src/Bootstrap.php';

// require_once __DIR__ . '/vendor/autoload.php';

use OpenEMR\Core\Kernel;
use OpenEMR\Modules\GheitS3\Bootstrap;

$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\GheitS3\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

/** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher */
$eventDispatcher = $eventDispatcher ?? $GLOBALS['kernel']->getEventDispatcher();

$bootstrap = new Bootstrap($eventDispatcher, $GLOBALS['kernel'] instanceof Kernel ? $GLOBALS['kernel'] : null);
$bootstrap->subscribeToEvents();
