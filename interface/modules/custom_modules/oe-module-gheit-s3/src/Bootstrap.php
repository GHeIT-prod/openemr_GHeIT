<?php

/**
 * Bootstrap
 *
 * Modern OpenEMR module entry point, loaded via openemr.bootstrap.php
 * through ModulesApplication when this module is enabled in
 * Modules -> Manage Modules. Registers this module's event listeners
 * and (optionally) its Globals settings panel.
 *
 * Kept deliberately light: the actual storage logic lives entirely in
 * Services/FileStorage and does not depend on anything in this class.
 * Bootstrap only wires the module into OpenEMR's lifecycle — config
 * screen, and any future event hooks (e.g. reacting to a
 * "document uploaded" event instead of requiring call-site edits in
 * Document.class.php).
 *
 * @package   OpenEMR\Modules\GheitS3
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3;

use OpenEMR\Core\Kernel;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Modules\GheitS3\Services\FileStorage\FileStorageException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_NAME = 'oe-module-gheit-s3';
    public const MODULE_MENU_NAME = 'GHeIT S3 File Storage';

    private EventDispatcherInterface $eventDispatcher;
    private Kernel $kernel;
    private string $moduleDirectory;

    public function __construct(EventDispatcherInterface $eventDispatcher, ?Kernel $kernel = null)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->kernel = $kernel ?? new Kernel();
        $this->moduleDirectory = dirname(__DIR__);
    }

    /**
     * Called once by openemr.bootstrap.php whenever the module is enabled.
     * Keep this fast — it runs on every request while the module is active.
     */
    public function subscribeToEvents(): void
    {
    }
}
