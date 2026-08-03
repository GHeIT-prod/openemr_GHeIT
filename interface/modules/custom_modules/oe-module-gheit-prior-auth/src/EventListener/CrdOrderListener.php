<?php

/**
 * Subscribes to ProcedureOrderCreatedEvent and runs the CRD order-sign
 * check. Depends on OpenEMR\Events\Orders\ProcedureOrderCreatedEvent, which
 * must exist in core (see openemr-core-patch.zip) and be dispatched from
 * interface/forms/procedure_order/common.php - see COMMON_PHP_PATCH.md for
 * the exact dispatch call.
 *
 * @package   GheitPriorAuth
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitPriorAuth\EventListener;

use OpenEMR\Events\Orders\ProcedureOrderCreatedEvent;
use OpenEMR\Modules\GheitPriorAuth\Service\CrdComplianceCheck;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CrdOrderListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProcedureOrderCreatedEvent::EVENT_NAME => 'onOrderCreated',
        ];
    }

    public function onOrderCreated(ProcedureOrderCreatedEvent $event): void
    {
        // All the data CrdComplianceCheck needs was already built by
        // common.php before dispatch - no re-fetching here.
        CrdComplianceCheck::run(
            $event->getFhirServiceRequest(),
            $event->getPatientId(),
            $event->getEncounterId(),
            $event->getOrderId(),
            $event->getPractitionerId()
        );
    }
}
