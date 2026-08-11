<?php

namespace OpenEMR\Events\Orders;

use Symfony\Contracts\EventDispatcher\Event;

class ProcedureOrderCreatedEvent extends Event
{
    /**
     * Retained for callers that dispatch by name rather than by class
     * (e.g. $dispatcher->dispatch($event, self::EVENT_NAME)) - OpenEMR's
     * dispatch() accepts either form, but subscribers should still key on
     * the class name, not this string, per getSubscribedEvents() convention.
     */
    const EVENT_NAME = 'procedure_order.created';

    private int $orderId;
    private int $patientId;
    private int $encounterId;
    private int $practitionerId;
    private array $fhirServiceRequest;

    /**
     * @param int   $orderId       procedure_order.procedure_order_id - the value
     *                             passed into addForm() as $formid
     * @param int   $patientId     pid of the patient the order is for
     * @param int   $encounterId   encounter the order was placed in
     * @param int   $practitionerId provider_id of the signing practitioner
     *                             (form_provider_id at the call site)
     * @param array $fhirServiceRequest the already-serialized FHIR
     *              ServiceRequest, reused as-is from the caller so listeners
     *              never need to re-fetch it (the caller already built it via
     *              FhirServiceRequestService before dispatching)
     */
    public function __construct(
        int $orderId,
        int $patientId,
        int $encounterId,
        int $practitionerId,
        array $fhirServiceRequest
    ) {
        $this->orderId = $orderId;
        $this->patientId = $patientId;
        $this->encounterId = $encounterId;
        $this->practitionerId = $practitionerId;
        $this->fhirServiceRequest = $fhirServiceRequest;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getPatientId(): int
    {
        return $this->patientId;
    }

    public function getEncounterId(): int
    {
        return $this->encounterId;
    }

    public function getPractitionerId(): int
    {
        return $this->practitionerId;
    }

    public function getFhirServiceRequest(): array
    {
        return $this->fhirServiceRequest;
    }
}
