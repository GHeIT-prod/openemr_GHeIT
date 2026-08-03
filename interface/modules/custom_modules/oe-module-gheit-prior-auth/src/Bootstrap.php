<?php

/**
 * Module bootstrap class - registers CrdOrderListener against
 * ProcedureOrderCreatedEvent, dispatched from
 * interface/forms/procedure_order/common.php (see COMMON_PHP_PATCH.md).
 *
 * @package   GheitPriorAuth
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitPriorAuth;

use OpenEMR\Modules\GheitPriorAuth\EventListener\CrdOrderListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use OpenEMR\Modules\GheitPriorAuth\EventListener\CrdMenuListener;

class Bootstrap
{
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addSubscriber(new CrdOrderListener());
        $this->eventDispatcher->addSubscriber(new CrdMenuListener());
    }
}
