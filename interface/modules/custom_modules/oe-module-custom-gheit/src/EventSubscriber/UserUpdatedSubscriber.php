<?php

namespace OpenEMR\Modules\CustomModuleGheit\EventSubscriber;

use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Events\User\UserUpdatedEvent;
use OpenEMR\Services\FHIR\FhirPractitionerService;
use OpenEMR\Modules\CustomModuleGheit\Controller\PubSub;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class UserUpdatedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            UserUpdatedEvent::EVENT_HANDLE => 'onUserUpdated'
        ];
    }

    public function onUserUpdated(UserUpdatedEvent $event): void
    {
        try {

            $data = $event->getNewUserData();
            $userId = $data['id'];

            $uuidRow = sqlQuery(
                "SELECT uuid FROM users WHERE id = ?",
                [$userId]
            );

            if (empty($uuidRow['uuid'])) {
                error_log("UUID missing");
                return;
            }

            $uuid = UuidRegistry::uuidToString($uuidRow['uuid']);

            $service = new FhirPractitionerService();
            $result = $service->getOne($uuid);

            $practitioner = $result->getData()[0] ?? null;

            if (!$practitioner) {
                error_log("Practitioner not found");
                return;
            }

            $fhir = $practitioner->jsonSerialize();

            $pubSub = new PubSub();

            $pubSub->publishPubsub(
                'Practitioner',
                'practitioner_updated',
                'practitioner_data',
                $fhir
            );

        } catch (\Throwable $e) {
            error_log("Subscriber error: " . $e->getMessage());
        }
    }
}