# Patch: dispatch ProcedureOrderCreatedEvent from common.php

Event-based version. Three edits to
interface/forms/procedure_order/common.php, reusing the `$ed` dispatcher
instance the file already sets up at the top for DornLabEvent/QuestLabTransmitEvent.

Requires the core addition in openemr-core-patch.zip
(src/Events/Orders/ProcedureOrderCreatedEvent.php) and the module's
CrdOrderListener (already registered via Bootstrap.php - no extra step
needed once the module is installed).

## 1. No new import needed in common.php

The event class is referenced by its fully-qualified name at the dispatch
site (see step 2) - no `use` statement required, matching the existing style
of `\OpenEMR\Common\Orders\Hl7OrderGenerationException` elsewhere if that's
referenced by FQCN too. If you prefer a `use` statement instead, add:

```php
use OpenEMR\Events\Orders\ProcedureOrderCreatedEvent;
```

## 2. Capture the payload and dispatch in both branches

In the `if ($formid) { ... }` (update) branch, right after the existing
`publishPubsub(...)` call:

```php
        $pubSubController = new PubSub();
        $pubSubController->publishPubsub('ServiceRequest', 'service_request_updated', 'service_request_data', $fhirArray);

        // add these two lines
        $crdEvent = new \OpenEMR\Events\Orders\ProcedureOrderCreatedEvent(
            (int) $formid, (int) $pid, (int) $encounter, (int) $_POST['form_provider_id'], $fhirArray
        );

    } else {
```

And symmetrically in the `else { ... }` (insert) branch, right after its own
`publishPubsub(...)` call:

```php
        $pubSubController = new PubSub();
        $pubSubController->publishPubsub('ServiceRequest', 'service_request_created', 'service_request_data', $fhirArray);

        // add this line
        $crdEvent = new \OpenEMR\Events\Orders\ProcedureOrderCreatedEvent(
            (int) $formid, (int) $pid, (int) $encounter, (int) $_POST['form_provider_id'], $fhirArray
        );
    }
```

Note: only the event object is built here, not dispatched yet - dispatch
happens once, at the tail, so it can be wrapped in the same
`fastcgi_finish_request()` background trick as before.

## 3. Dispatch after the response is flushed, at the existing tail

Original:

```php
    unset($_POST['bn_save']);
    $reload_url = $rootdir . '/patient_file/encounter/view_form.php?formname=procedure_order&id=' . attr($formid);
    if (empty($order_data)) {
        header('Location:' . $reload_url);
    }
}
```

Patched:

```php
    unset($_POST['bn_save']);
    $reload_url = $rootdir . '/patient_file/encounter/view_form.php?formname=procedure_order&id=' . attr($formid);
    if (empty($order_data)) {
        header('Location:' . $reload_url);

        if (!empty($crdEvent)) {
            if (function_exists('fastcgi_finish_request')) {
                // Flush the redirect now; everything below runs after the
                // connection is already closed - genuinely "background."
                fastcgi_finish_request();
            }
            // $ed is already defined at the top of this file
            $ed->dispatch($crdEvent, \OpenEMR\Events\Orders\ProcedureOrderCreatedEvent::EVENT_NAME);
        }
    }
}
```

## Why the event class needed extending

The original ProcedureOrderCreatedEvent only carried orderId/patientId/
encounterId. CrdComplianceCheck (called by CrdOrderListener) also needs the
FHIR ServiceRequest and the practitioner id - both already sit in scope in
common.php as `$fhirArray` and `$_POST['form_provider_id']`. Rather than
have the listener re-fetch the ServiceRequest via UUID lookup (duplicating
work common.php already did), the event constructor now takes both directly:

```php
new ProcedureOrderCreatedEvent($orderId, $patientId, $encounterId, $practitionerId, $fhirServiceRequest)
```

## Not covered

- Same `bn_save_exit` gap as before: that branch calls `exit;` earlier in the
  file, bypassing this tail entirely. If the check should also run on
  "Save and Exit," that branch needs its own `$crdEvent` + dispatch before
  its `exit;`.
- Only `procedure_order` is wired. Lab/drug order forms, if they exist as
  separate save handlers, would need their own dispatch call following the
  same pattern.

## Trade-off vs. the direct-call design (CrdComplianceCheck::run() called
## straight from common.php, no event)

Functionally identical - same process, same request, same
fastcgi_finish_request() background behavior. The only difference is
indirection: this version decouples common.php from knowing about CRD
specifically, at the cost of three extra files (event class, listener,
Bootstrap registration) versus one direct method call. Worth it mainly if
other modules might also want to react to "a procedure order was created."
