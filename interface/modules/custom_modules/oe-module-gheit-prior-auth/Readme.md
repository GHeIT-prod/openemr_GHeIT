# oe-module-gheit-prior-auth

Da Vinci CRD (Coverage Requirements Discovery) and DTR (Documentation Templates
and Rules) integration for OpenEMR, built for NeoCareX.

This module is the **CDS Hooks client** side only. The actual coverage/PA
decision logic is never built here - it lives at the payer or an intermediary
vendor (e.g. Nucural). This module registers those external services, calls
them at order time, and routes whatever card comes back.

## What's included

- `src/Service/CdsHooksClient.php` - registry (`cds_hooks_services`) read/write,
  discovery, and the actual hook POST call.
- `src/Service/CrdCardRouter.php` - parses the `coverage-information` extension
  off a returned card, routes to one of `no-pa` / `pa-required` / `unknown`,
  and independently detects a DTR launch link.
- `src/EventListener/CrdOrderListener.php` - the Symfony subscriber that ties
  the two together when an order is created.
- `src/Bootstrap.php` / `openemr.bootstrap.php` - module registration.
- `table.sql` - `cds_hooks_services`, `cds_hooks_crd_log`, and the
  `enable_cds_hooks` global flag.

## What's NOT included (open dependencies)

1. **The order-created event itself.** `CrdOrderListener` subscribes to
   `OpenEMR\Events\Orders\ProcedureOrderCreatedEvent`, which does not exist in
   OpenEMR core yet. It needs to be added, and dispatched at the order save
   site (e.g. `interface/forms/procedure_order/common.php`, right after its
   `addForm()` call succeeds). Without that, this module registers correctly
   but nothing ever fires it.
2. **Real FHIR order-select context.** `buildOrderSelectContext()` currently
   sends only `patientId`/`encounterId` - a real payer service expects a
   `draftOrders` FHIR Bundle with the actual `ServiceRequest`.
3. **The DTR iframe launch page.** `CrdCardRouter::buildDtrLaunchUrl()` builds
   the URL; nothing yet hosts the page that puts it in an iframe or resolves
   the launch token when the DTR app calls back for its access token.
4. **PAS bundle submission.** `stagePasBundle()` only returns an empty
   skeleton. Populating the `Claim`/`ServiceRequest`/`QuestionnaireResponse`
   and actually submitting to the payer (Da Vinci PAS) is separate work,
   gated on (2) and (3) above.

## Install

```
git clone <this module> interface/modules/custom_modules/oe-module-gheit-prior-auth
```

Then, as an OpenEMR administrator: Modules -> Manage Modules -> Unregistered ->
Register -> Install -> Enable. Installing runs `table.sql`. After enabling,
turn on `enable_cds_hooks` under Administration -> Globals, and register at
least one service via `CdsHooksClient::discoverServices()` /
`registerService()` from an admin screen (not included here - a simple
list/add/enable form calling those two methods is the next natural addition).
