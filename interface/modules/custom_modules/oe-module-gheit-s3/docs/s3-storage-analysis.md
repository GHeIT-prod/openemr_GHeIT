# S3 Storage Analysis

Status: Phase 1 analysis and Phase 2 design baseline. No S3 migration code is implemented by this document.

## 1. Executive summary

This fork does not have one file-storage architecture. It has at least five permanent-storage families:

1. Patient and clinical documents use the legacy `Document` model and `C_Document` controller. Binaries are stored under `{OE_SITE_DIR}/documents/`, in CouchDB, or through an unimplemented off-site event hook. Metadata is stored in `documents`.
2. Portal forms, signatures, and templates store binary or binary-like content directly in `onsite_documents`, `onsite_signatures`, and `document_templates`.
3. Billing, EDI, ERA, fax, lab, QRDA, EHI export, education, branding, and module workflows write named files directly below the site directory without using `Document`.
4. Care-coordination code uses the separate `ccda` table and `CDADocumentService`; `ccda_data` can contain inline data or a local/CouchDB reference.
5. The customized REST message workflow already writes attachments to S3, but constructs `S3Client` in `MessageService`, requires static credentials, includes a patient database identifier in the key, returns a 60-minute URL, and does not persist an ownership or lifecycle record.

The primary clinical path is:

```text
UI / portal / REST / FHIR / background importer
    -> C_Document or DocumentService
    -> Document::createDocument()
    -> local filesystem or CouchDB
    -> documents + categories_to_documents
```

The proposed target is an S3-only permanent binary store behind one application service. Local files remain permissible only as restrictive, short-lived processing files. Runtime files that are not application binaries—PHP sessions, mPDF/Smarty caches, TLS/OAuth private keys, and logs—are outside the S3 file-service boundary and must use their appropriate runtime or secret-management systems.

Important conclusions:

- The AWS SDK is already declared in `composer.json` as `aws/aws-sdk-php:^3.374`; `composer require` is not needed. Current uncommitted dependency updates are unrelated and must not be folded into this work.
- The existing `PatientDocumentStoreOffsite` and `PatientRetrieveOffsiteDocument` events are extension hooks, not a storage abstraction. No repository listener provides complete S3 metadata, transaction, validation, delete, copy, move, or multipart behavior.
- Core document deletion is intentionally soft and retains the binary for ONC-related reasons. Clinical retention behavior must be approved before any S3 hard-delete path is enabled.
- Existing documents use SHA3-512, while the target requires SHA-256. New metadata must store SHA-256 without silently reinterpreting the existing `documents.hash`.
- No HTTP Range implementation was found. Videos currently have no dedicated local storage workflow; FHIR Media uses the ordinary document path. Video seeking requires presigned S3 GET or a new range-aware proxy.
- A repository-wide static audit found 172 application PHP files using direct file I/O, 33 referencing `$_FILES`, and 102 setting file-response headers after excluding `vendor/`, tests, and `Documentation/`. Not all are permanent binaries: many are imports, response-only exports, caches, logs, runtime credentials, or framework code.

## 2. Current storage architecture

### 2.1 Site-scoped filesystem

`sites/default/config.php` configures `repopath` and later aliases `repository` to it:

```text
$GLOBALS['oer_config']['documents']['repopath'] =
    $GLOBALS['OE_SITE_DIR'] . "/documents/";
$GLOBALS['oer_config']['documents']['repository'] =
    $GLOBALS['oer_config']['documents']['repopath'];
```

`interface/globals.php` resolves `OE_SITE_DIR` per site. The default document layout is:

```text
sites/{site}/documents/
├── {patient-pid}/{drive-uuid}
├── {non-patient-label}/{random}/{drive-uuid}
├── encounters/...                         optional higher-level paths
├── temp/                                  mixed temporary staging
├── doctemplates/                          permanent templates
├── education/                             permanent education PDFs
├── edi/ and era/                          permanent billing artifacts
├── received_faxes/                        persistent unassigned fax staging
├── procedure_results/                     permanent lab results
│   └── logs/                               persistent integration logs
├── labs/{lab}/logs/                        persistent procedure-order logs
├── system-ehi-export/                     permanent EHI ZIPs
├── cqm_qrda/, cat1_reports/, cat3_reports/
├── letter_templates/
├── onsite_portal_documents/templates/     portal EOB/template files
├── holidays_storage/                       persistent import staging
├── erx_error/                              persistent eRx error logs
├── custom_menus/, patient_menus/           site menu JSON
├── couchdb/                                CouchDB operational log
└── logs_and_misc/                          integration caches/keys/imports
```

Other site-scoped paths include `images/`, `images/logos/`, `faxcache/`, and the configurable scanner intake directory.

### 2.2 Core `Document` storage

Relevant code:

- `library/classes/Document.class.php`
- `controllers/C_Document.class.php`
- `library/documents.php`
- `library/ajax/upload.php`
- `src/Services/DocumentService.php`
- `src/RestControllers/DocumentRestController.php`
- `library/classes/CategoryTree.class.php`

`Document::createDocument()` supports:

- `storagemethod=0`: local filesystem.
- `storagemethod=1`: CouchDB.
- `PatientDocumentStoreOffsite` event: optional external interception; no complete implementation was found.

For local storage it creates directories with mode `0700`, generates `drive_uuid`, writes `file://{absolute-path}/{uuid}`, and optionally writes `th_{uuid}`. It then persists metadata and the category relation. Original filenames are stored in `documents.name`; disk names are UUIDs.

The method receives the entire binary as a PHP string. This is unsuitable for large videos and causes memory amplification. It also writes the binary before the database row and publishes a fork-specific FHIR `DocumentReference` to Google Pub/Sub after persistence. Exceptions or failures after the object write can leave unmanaged files.

### 2.3 Database blobs

- `onsite_documents.full_document`: generated/signed portal document bytes.
- `onsite_documents.patient_signature` and `authorized_signature`: signature content.
- `onsite_signatures.sig_image`: encoded signature image.
- `document_templates.template_content`: portal/document template bytes.
- `documents.document_data`: DICOM viewer state, not the primary binary.
- `documents_legal_detail.dld_file_for_pdf_generation`: FDF/PDF-generation input.

These bypass the local document repository but are still permanent application file content and therefore fall within the S3-only requirement.

### 2.4 Separate CCDA persistence

The `ccda` table is a fifth storage family. `ccda_data`, `couch_docid`, and `encrypted` are handled by:

- `src/Services/CDADocumentService.php`
- `interface/modules/zend_modules/module/Carecoordination/src/Carecoordination/Model/EncounterccdadispatchTable.php`
- `interface/modules/zend_modules/module/Carecoordination/src/Carecoordination/Model/EncountermanagerTable.php`

The service supports inline, local-path, and CouchDB-backed CCDA content. It must be migrated independently from ordinary `documents`.

### 2.5 Existing direct S3 path

`src/Services/MessageService.php::s3DocumentHandler()` is called by `src/RestControllers/MessageRestController.php` after message insertion. It:

- Reads `AWS_BUCKET_NAME`, `AWS_DEFAULT_REGION`, `AWS_ACCESS_KEY_ID`, and `AWS_SECRET_ACCESS_KEY`.
- Instantiates `S3Client` directly.
- Allows selected document/image MIME types and a 10 MB maximum.
- Uploads to `attachments/patients/{pid}/documents/{random}.{extension}`.
- Uses SSE-S3 (`AES256`).
- Returns an S3 key and a 60-minute presigned URL.

The `pnotes` row is inserted first under the REST route's `patients/notes` permission; S3 upload runs afterward. It does not persist the key against `pnotes`, verify with `HeadObject`, calculate a checksum, record scan state, delete on message deletion, compensate after database failure, or use the SDK default credential chain. A failed S3 call can therefore leave a message without a durable attachment relation. It also leaks AWS exception messages in the HTTP response. This implementation must be replaced by the central service, not expanded.

## 3. Complete inventory of file-handling services

The inventory groups each runtime workflow. Static source assets, Composer/npm packages, test fixtures, generated caches, and installer-only files are listed separately where they affect deployment but are not application uploads.

### 3.1 Core clinical and patient workflows

| Feature | Relevant code | Permanent store and association | Validation/auth/access |
|---|---|---|---|
| Patient document upload | `controllers/C_Document.class.php`, `library/ajax/upload.php`, `library/documents.php`, `library/classes/Document.class.php` | `documents`; `foreign_id=patient_data.pid`; optional `encounter_id`, `list_id`, `foreign_reference_*`; category join | Category `aco_spec`; `secure_upload`/`isWhiteFile`; PHP upload errors; zero-size rejection; `mime_content_type` |
| Patient document browser | `C_Document::list_action`, `view_action`, templates under `templates/documents/` | Reads `documents`, categories, notes, issues, encounter/procedure links | `patients/docs`, per-category ACL, patient-ID ownership check |
| Document preview/download | `C_Document::retrieve_action` | Local/CouchDB binary, thumbnail, or generated JPEG | `Document::can_access`, portal `can_patient_access`, patient-ID check; inline/attachment headers |
| Document REST | `src/RestControllers/DocumentRestController.php`, `src/Services/DocumentService.php`, standard routes | Same `documents` storage | OAuth/API route authorization; path/category validation; secure upload whitelist |
| FHIR DocumentReference/Binary/Media | `src/Services/FHIR/`, `src/RestControllers/FHIR/FhirDocumentRestController.php`, FHIR routes | Same document binary; FHIR URL resolves application Binary endpoint | SMART/OAuth plus document/category or portal access checks |
| Patient photograph/ID card | `src/Services/PatientService.php`, `interface/patient_file/summary/demographics.php`, `templates/patient/card/photo.html.twig` | Ordinary `documents` categories | Category ACL; `patient_picture` context binds request to active/portal patient |
| Encounter/procedure tagging | `C_Document::tag_action_process`, `image_procedure_action`; `procedure_result` | Updates metadata/join only; does not move binary | Existing document/category access; encounter/procedure selection |
| Clinical-note attachments | `interface/forms/clinical_notes/save.php`, `src/Services/ClinicalNotesService.php` | `clinical_notes_documents` links an existing `documents.id` | Form and patient ACL inherited by workflow |
| DICOM upload/view state | `C_Document::zip_dicom_folder`, `library/dicom_frame.php`, `library/ajax/upload.php` | ZIP/single DICOM in `documents`; viewer JSON in `documents.document_data` | `.dcm` filtering, DICM marker checks, document ACL |
| Thumbnails/conversion | `library/classes/thumbnail/`, `C_Document::retrieve_action`, `interface/super/manage_site_files.php` | Permanent sidecars `th_{uuid}` and `_converted.jpg`; CouchDB converted objects | Generated after authorized upload/view; ImageMagick subprocess |
| Scanner CLI import | `custom/zutil.cli.doc_import.php`, `library/allow_cronjobs.php` | Scanner intake to ordinary documents | CLI/cron mode; configurable `in_situ`; `finfo` and upload helper |
| CCDA/CCR import | `interface/modules/zend_modules/module/Documents/`, `Carecoordination/`, `Ccr/`, `contrib/util/ccda_import/` | XML in `documents`; source may be moved to processed/duplicate dirs | Module/CLI access, XML MIME checks, category ACL or explicit background bypass |
| CCDA dispatch/service store | `src/Services/CDADocumentService.php`, Carecoordination `EncounterccdadispatchTable`, `EncountermanagerTable` | Separate `ccda` table; inline/local path/CouchDB content associated with patient and encounter | Carecoordination encounter workflow; module authorization must be preserved |
| Zend generic XML upload | `interface/modules/zend_modules/module/Documents/src/Documents/Controller/DocumentsController.php` | Calls document storage using POST `file_location`; permits XML/CCDA | Zend module authorization; user-influenced subpath is a migration/security risk |

### 3.2 Portal, forms, images, and templates

| Feature | Relevant code | Permanent store and association | Validation/auth/access |
|---|---|---|---|
| Portal document upload | `library/ajax/upload.php`, `portal/get_patient_documents.php` | Ordinary patient documents, portal category | Portal session, CSRF, patient ownership |
| Portal bulk ZIP download | `portal/report/document_downloads_action.php` | Sources are documents; local temp directory/ZIP is response-only | Portal session, CSRF, each document belongs to portal patient |
| Portal chart/review PDF | `portal/lib/doc_lib.php` | Generated PDF may be inserted into Reviewed documents category | Portal/staff workflow controls |
| Onsite forms | `portal/patient/libs/Controller/OnsiteDocumentController.php`, `portal/lib/doc_lib.php` | `onsite_documents.full_document`, signatures, file name/path | Portal patient/provider state; document status/signing workflow |
| Portal signatures | `portal/sign/lib/save-signature.php`, `show-signature.php` | `onsite_signatures.sig_image`, `sig_hash` | Patient owns PID or staff user matches; session controls |
| Portal template repository | `portal/import_template.php`, `src/Services/DocumentTemplates/DocumentTemplateService.php` | `document_templates.template_content` | CSRF; admin/forms where required; server MIME detection |
| Legacy document templates | `interface/super/manage_document_templates.php`, `interface/patient_file/download_template.php`, `portal/lib/download_template.php` | `{OE_SITE_DIR}/documents/doctemplates/` | `admin/super`; 1 MB; ODT/TXT/DOCX/ZIP rules; basename; optional encryption |
| Education PDFs | `interface/super/manage_site_files.php`, `interface/patient_file/education.php` | `documents/education/*.pdf` | `admin/super`; PDF/whitelist checks |
| Eye Mag drawings/PDF | `interface/forms/eye_mag/save.php`, `report.php`, `eye_mag_functions.php` | Documents category; replacement path can physically unlink old drawing | Encounter/form access; base64 image or generated PDF; custom hard replacement |
| Legacy legal-document schema | `documents_legal_master`, `documents_legal_detail`, `documents_legal_categories` | Paths plus FDF blob exist in SQL, but no active PHP references were found in this fork | Treat as inactive/schema-only until deployment data or external module use proves otherwise |
| CAMOS import/export | `interface/forms/CAMOS/admin.php` | Uploaded text/XML parsed to DB; generated download response | Form admin controls; direct uploaded-temp read |

### 3.3 Communications, fax, and custom modules

| Feature | Relevant code | Permanent store and association | Validation/auth/access |
|---|---|---|---|
| Internal linked documents | `interface/main/messages/messages.php`, `templates/linked_documents.php`, `gprelations` | Existing documents linked to `pnotes` | Message/patient ACL plus document ACL |
| REST message S3 attachment | `MessageRestController`, `MessageService::s3DocumentHandler` | S3 only; currently no durable DB association | `patients/notes`; scattered MIME/extension/10 MB checks; architectural/security gaps above |
| Trusted Direct send | `interface/main/messages/trusted-messages-ajax.php` | Reads existing document bytes for transmission | Logged-in message workflow plus document access |
| Direct receive | `library/direct_message_check.inc.php` | Incoming attachments become documents | Background service; whitelist event; background ACL bypass |
| FaxSMS assigned fax | `interface/modules/custom_modules/oe-module-faxsms/src/Controller/FaxDocumentService.php` | Ordinary document in FAX category; queue stores `document_id` | Provider webhook/module controls; `finfo`; patient assignment |
| FaxSMS unassigned fax | Same module plus provider clients | `documents/received_faxes/unassigned/`; `oe_faxsms_queue.media_path` | Module/admin assignment; local path is persistent staging |
| Fax provider staging | `SignalWireClient.php`, `RCFaxClient.php`, `EtherFaxActions.php` | Site fax dirs or OS temp before send/import | Provider-specific webhook/auth; inconsistent validation |
| Legacy fax/scanner | `interface/fax/faxq.php`, `fax_dispatch.php`, `fax_view.php` | Scanner dir; `faxcache/`; documents/encounter images | Staff fax access; shell ImageMagick/TIFF tools; cache can be web-addressable |
| Custom GHEit Pub/Sub | `library/classes/Document.class.php`, `oe-module-custom-gheit/src/Controller/PubSub.php` | Publishes FHIR metadata after document creation | Runs synchronously after DB persist; failure coupling must be removed |
| EHI exporter | `oe-module-ehi-exporter/src/Services/EhiExporter.php` | Temp ZIP then permanent document under `system-ehi-export` | Export job authorization; background task tables |
| ClaimRev | `oe-module-claimrev-connect/src/ClaimUpload.php`, `ReportDownload.php`, `EligibilityTransfer.php` | `documents/edi`, `documents/era`, history | Billing/module ACL; SFTP credentials/config |
| DORN HL7 | `oe-module-dorn/src/ReceiveHl7Results.php` | `documents/procedure_results` and logs | Background integration authorization |
| Weno import | `oe-module-weno/scripts/file_download.php` and module services | `documents/logs_and_misc/weno/*.zip`, extracted CSV/logs | Module/admin/background controls |
| Telehealth | `oe-module-comlink-telehealth` | Remote WebRTC only; no recorded video persistence found | External service/session controls |
| FHIR Media video | `src/Services/FHIR/FhirMediaService.php` | `video/mp4` can be represented through ordinary documents; no dedicated upload/range implementation | SMART/OAuth and document authorization |

### 3.4 Billing, reports, imports, and exports

| Feature | Relevant code | Permanent or temporary behavior | Access/validation |
|---|---|---|---|
| X12 claim generation | `src/Billing/BillingProcessor/`, generator tasks | Permanent `documents/edi/*` except validation downloads | Billing ACL; generated server-side |
| Claim download/delete | `interface/billing/get_claim_file.php` | Streams EDI/PDF; optional unlink for temp validation result | `acct/eob` or `acct/bill`, CSRF, basename/path selection |
| ERA/EOB upload | `interface/billing/sl_eob_process.php`, `era_payments.php`, `edi_271.php`, `sl_eob_search.php` | Permanent `documents/era`, `documents/edi` and HTML derivatives | Billing ACL; extension/MIME checks vary |
| EDI history | `library/edihistory/` | Permanent archive and indexes; local tmp subtrees | Billing workflow controls; direct rename/copy/unlink |
| X12 SFTP background | `src/Billing/BillingProcessor/X12RemoteTracker.php`, `library/billing_sftp_service.php` | Reads local EDI files and tracks names in DB | Background service and partner config |
| Patient statements | `sites/default/statement.inc.php` | Response-only PDF/text via predictable temp names | Billing workflow; generated data |
| Reports/CSV/XLSX | `interface/reports/`, `src/Services/SpreadSheetService.php` | Mostly streamed response; no permanent file | Report ACL; generated server-side |
| Patient custom report | `interface/patient_file/report/custom_report.php`, portal equivalent | Temp document copies/images and merged PDF, then response | Patient/report ACL; cleanup is per-path and incomplete on fatal exit |
| QRDA/CQM | `custom/export_qrda_xml.php`, `qrda_download.php`, `src/Cqm/QrdaControllers/QrdaReportController.php` | Permanent local XML/report directories and response downloads | CQM/report ACL |
| FHIR bulk export | `src/Services/FHIR/FhirExportJobService.php`, `src/FHIR/Export/`, FHIR `$export` routes | Job metadata in `export_job`; stream/job outputs | SMART scopes and export job owner |
| Backup/config export | `interface/main/backup.php`, `backuplog.sh` | Temp tar/SQL; current backup includes local site documents | Admin ACL and CSRF; filesystem assumptions must be removed |
| De-identification export | `interface/de_identification_forms/` | Temp XLS and metadata; some paths rely on manual deletion | Admin workflow |
| Generic code/data imports | `interface/super/load_codes.php`, `layout_service_codes.php`, `interface/orders/load_compendium.php` | Uploaded temp file parsed into DB; no permanent binary | Admin/module ACL; format-specific parsing |
| Holiday import staging | `interface/main/holidays/Holidays_Controller.php`, `import_holidays.php` | Persistent `documents/holidays_storage/holidays_to_import.csv` until workflow cleanup | Holiday/admin workflow; CSV-specific parsing |
| LabWorks export | `custom/export_labworks.php` | Hard-coded `/tmp/labworks` staging and FTP transfer | Custom integration authorization; temp cleanup/path portability risk |
| Bamboo PMP debug artifact | `interface/modules/custom_modules/oe-bamboo-pmp/src/Controllers/GatewayRequests.php` | Hard-coded `/var/www/html/quest/xmlData.xml` debug write | Must be removed or converted to safe structured logging; not a valid permanent store |
| SSL client certificate export | `interface/usergroup/ssl_certificates_admin.php` | Temp PEM/P12/ZIP streamed and deleted | Admin ACL; runtime secret material, not S3 application content |

### 3.5 Generated files that are response-only

Most label, prescription, immunization, demographics, ledger, collections, postcard, and form report code generates PDF/CSV/XLSX directly to the response. It does not create a permanent application file and should continue to use memory or restrictive temp storage. If a workflow adds a “save to chart,” the saved copy must use the central service.

Representative files:

- `src/Pdf/PdfCreator.php`
- `controllers/C_Prescription.class.php`
- `interface/patient_file/label.php`
- `interface/patient_file/barcode_label.php`
- `interface/patient_file/addr_label.php`
- `interface/patient_file/summary/demographics_print.php`
- `interface/patient_file/summary/shot_record.php`
- `interface/main/messages/print_postcards.php`
- `interface/reports/collections_report.php`
- `interface/reports/pat_ledger.php`

### 3.6 Runtime files explicitly outside permanent S3 application storage

These need deployment changes or cleanup, but putting them in the application file bucket would be incorrect:

- OAuth, DB, CouchDB, and LDAP keys/certificates under `documents/certificates/`: move to a secret store or read-only mounted secrets.
- Smarty compiled templates and mPDF temp/cache: use ephemeral local storage.
- PHP sessions and framework caches: use the configured session/cache backend.
- Operational and integration logs: send to structured logging, not the file bucket.
- Static source assets in `public/`, `interface/pic/`, module assets, npm/Composer packages: deploy with the application image/CDN.

## 4. Upload workflows

### 4.1 Staff and portal document upload

Endpoint:

```text
POST /controller.php?document&upload&patient_id={pid}
POST /library/ajax/upload.php
```

Flow:

1. Session and category are resolved.
2. Category `aco_spec` is checked unless a background caller explicitly invokes `skipAclCheck()`.
3. DICOM folders may be copied from PHP temp paths into a ZIP under `$temporary_files_dir`.
4. Upload errors and zero-byte files are rejected.
5. `secure_upload` calls `isWhiteFile()` using the `files_white_list` list and `IsAcceptedFileFilterEvent`.
6. MIME is determined with `mime_content_type`; the supplied `$_FILES.type` still influences ZIP detection.
7. The complete binary is loaded into memory.
8. `Document::createDocument()` writes the binary/thumbnail, metadata, category relation, and Pub/Sub notification.

There is no application-level per-category maximum beyond PHP request limits. Filename normalization is mostly `basename` for the optional destination. MIME-to-extension compatibility, double extensions, image dimensions, malware status, and transactional object cleanup are not centralized.

### 4.2 REST document upload

```text
POST /api/patient/:pid/document?path=/Category/Subcategory
```

`DocumentService::insertAtPath()` validates the category path and `isWhiteFile`, reads the whole temp file, determines MIME, and calls `Document::createDocument()`. The route checks broad `patients/docs` authorization. It does not independently enforce the target category's `aco_spec`.

### 4.3 Programmatic/background upload

`addNewDocument()` rewrites global `$_FILES`, `$_GET`, and `$_POST`, then invokes `C_Document::upload_action_process()`. Direct messaging, fax, CCDA, eye forms, scanner jobs, and exporters use it. Background callers may bypass category ACL. This global mutation and controller reuse should be removed; callers should pass an authenticated/authorized ownership context to the central application service.

### 4.4 Non-document uploads

Direct uploads bypassing the document model include:

- Site logos resolved by `LogoService` from site/static paths and SMART custom logos written by `interface/smart/uploadSmartLogo.php` under `interface/smart/public/images/logos/custom/rideon/`.
- Templates and education files: admin controllers.
- ERA/EDI and holidays/import files.
- Fax provider staging.
- Backup restore/config imports.
- Message attachments through the existing direct S3 method.

Each requires migration to a storage record or classification as temp-only import.

## 5. View and download workflows

### 5.1 Legacy controller

```text
GET /controller.php?document&view&patient_id={pid}&doc_id={id}
GET /controller.php?document&retrieve&patient_id={pid}&document_id={id}
```

`retrieve_action()` applies portal ownership or category ACL, verifies the requested patient, selects original/thumbnail/converted content, and sends inline or attachment headers. It can redirect through `PatientRetrieveOffsiteDocument`, but the event itself does not guarantee authorization or auditing. Internal callers can request raw bytes only after `onReturnRetrieveKey()`.

### 5.2 REST and FHIR

```text
GET /api/patient/:pid/document/:did
GET /fhir/Binary/:id
```

REST currently obtains the full binary through `C_Document` and returns a binary response. FHIR DocumentReference points to the application Binary route rather than a public storage URL.

### 5.3 Direct-path downloads

Billing, education, templates, fax cache, QRDA, Weno, reports, portal ZIPs, and module endpoints build local paths and use `readfile`, `fpassthru`, `file_get_contents`, or direct web paths. They must resolve an application file identifier, authorize ownership, and ask `FileAccessService` for a short-lived inline/download URL or an audited stream.

### 5.4 Target decision

- Use short-lived presigned GET URLs for ordinary image/PDF preview, document download, and video playback after application authorization and audit.
- Use backend streaming when transformation, application-level encryption, one-time export assembly, legal logging, or protocol integration requires server possession of bytes.
- Never accept a bucket/key from the browser. Resolve storage metadata from a UUID.
- Use distinct response overrides for inline and attachment disposition. Original filenames must be encoded safely.

## 6. Delete and replacement workflows

- `interface/patient_file/deleter.php::delete_document()` sets `documents.deleted=1` and deletes category/gprelation rows. It intentionally leaves the binary in place.
- Patient deletion calls the same soft-delete behavior for each document.
- `Document::process_deleted()` also only sets the flag.
- Eye Mag drawing replacement is an exception: it can unlink the old local binary and remove rows before creating a replacement.
- Template, education, fax staging, billing temp, and generated artifact handlers use direct `unlink`.
- Category move updates `categories_to_documents`.
- Patient move updates `documents.foreign_id`; local path is not moved.
- Patient merge contains local directory rename assumptions.
- There is no core content-replacement API and no core document-copy API.
- Existing S3 message attachment deletion is absent.

Target behavior:

- Clinical documents: application soft delete, retain the S3 version, audit the action, and apply an approved retention/lifecycle policy.
- Replace: create a new immutable storage object/version and atomically change the application relation; retain the prior version when policy requires.
- Non-clinical files: authorized hard delete may use a `deleting -> deleted` state machine and version-aware `DeleteObject`.
- Copy/move: S3 copy plus verified metadata transaction; “move” is copy then delete only when object-key namespace must change. Metadata-only owner/category changes should not move objects.

## 7. Database dependencies

### 7.1 `documents`

Current key columns:

```text
id, uuid, type, size, date, date_expires, url, thumb_url, mimetype,
owner, foreign_id, docdate, hash, list_id, name, drive_uuid,
couch_docid, couch_revid, storagemethod, path_depth, imported,
encounter_id, encounter_check, encrypted, document_data, deleted,
foreign_reference_id, foreign_reference_table
```

Dependencies:

- `categories_to_documents(document_id, category_id)`
- `categories.aco_spec` and `categories.codes`
- `notes.foreign_id`
- `gprelations`
- `clinical_notes_documents`
- `procedure_result.document_id`
- FHIR UUID registry/search mappings

`url`, `thumb_url`, `drive_uuid`, `path_depth`, `couch_*`, `storagemethod`, and `encrypted` encode backend assumptions. They cannot be removed in the first migration because old rows and upstream code reference them.

### 7.2 Other tables

| Table | File dependency |
|---|---|
| `onsite_documents` | BLOB, signatures, `file_name`, `file_path`, PID/encounter |
| `onsite_signatures` | Encoded signature image and hash |
| `document_templates` | Template BLOB, MIME, size, optional location |
| `ccda` | CCDA data/local path/CouchDB reference, encryption and patient/encounter metadata |
| `ccda_components`, `ccda_sections`, `ccda_field_mapping`, `ccda_table_mapping` | CCDA generation and mapping dependencies; not primary binary stores |
| `documents_legal_master/detail/categories` | Schema-only local paths/names and FDF BLOB; no active PHP references found |
| `oe_faxsms_queue` | `media_path`, `document_id`, patient relation |
| `x12_remote_tracker` | Named local X12 files |
| `export_job` | Serialized output/error references |
| `ehi_export_job*` | Export task/job metadata; final ZIP is a document |
| `pnotes` / `gprelations` | Messages and linked core documents; current direct S3 attachment has no relation |
| `background_services` | Jobs whose implementations read local paths |

## 8. Authorization dependencies

Existing controls that must remain ahead of any signed URL:

- Staff session authentication.
- OAuth/SMART scopes on standard and FHIR APIs.
- `AclMain::aclCheckCore('patients', 'docs')` and write/add-only variants.
- Per-category `categories.aco_spec`, checked across all categories by `Document::can_access()`.
- `patients/docs_rm` for clinical document deletion.
- Portal session plus `Document::can_patient_access(pid)`.
- Requested-patient versus `documents.foreign_id` checks.
- Billing ACLs such as `acct/bill` and `acct/eob`.
- Administrative ACLs such as `admin/super` and `admin/forms`.
- Module-specific webhook signatures/provider authentication and job ownership.
- `patients/notes` on the customized REST message/S3 attachment route.
- CSRF controls on state-changing browser workflows.

The storage layer must not decide business authorization. `FileAccessService` should receive an application file UUID, resolve its owner relation, invoke the owning workflow's authorization policy, verify `storage_status` and `scan_status`, audit, then request a signed URL.

## 9. Temporary-file dependencies

Current temp roots:

- PHP upload temp files.
- `$GLOBALS['temporary_files_dir']`, currently based on `sys_get_temp_dir()`.
- `{OE_SITE_DIR}/documents/temp/`, which `interface/super/edit_globals.php` can create with mode `0777`, is incorrectly mixed into the permanent repository, and can be web-addressable.
- Workflow-specific directories for backup, EHI, EDI, scanner, fax, CCDA, reports, statements, mPDF, and ImageMagick.
- `$GLOBALS['temporary_files_dir']/cookiejar_MedExAPI` used by the MedEx background integration.

Required local-temp use after migration:

- Upload MIME/checksum/virus scanning when streaming cannot perform it.
- DICOM ZIP creation and inspection.
- Image thumbnail and PDF/JPEG conversion.
- PDF/report/ZIP creation.
- SFTP/provider integrations requiring a path.
- Import parsers and certificate export.

All new temp handling must use unpredictable names, restrictive permissions, `finally` cleanup, and a separate non-public root. A periodic cleanup task is still required for process crashes. No original filename may be used as the temp disk path.

## 10. Generated-file dependencies

Generated artifacts divide into:

1. Response-only: labels, reports, spreadsheets, statements, prescriptions, backups. Generate in memory or temp and delete after response.
2. Saved clinical artifacts: CCDA, reviewed portal PDFs, Eye Mag reports, EHI ZIPs. Upload through the central service and create application metadata.
3. Persistent operational artifacts: EDI/ERA/QRDA/lab results. Create typed storage records and update SFTP/job consumers to use streams or temporary materialization.
4. Derivatives: thumbnails and converted previews. Store as separate S3 objects with a parent relation and delete/retain under the parent's policy.

Background consumers that currently require paths need a helper that materializes an authorized storage object into managed temp scope and guarantees cleanup.

## 11. Local filesystem assumptions

High-risk assumptions include:

- `file://` URLs and absolute paths are stored in `documents.url`.
- Paths are reconstructed from `path_depth`, PID directories, and URL basename.
- `file_exists`, `is_file`, `filesize`, `readfile`, `fopen`, directory scans, and globbing are used as repository APIs.
- Patient merges update or rename directories.
- Backups assume the site directory contains all document bytes.
- Thumbnails and converted images are sibling files discoverable by naming convention.
- Fax/EDI jobs scan directories for work rather than querying durable job metadata.
- Site menu loaders scan `documents/custom_menus` and `documents/patient_menus`.
- SFTP integrations require named local files.
- `documents/temp` is assumed to be writable and sometimes web-readable.
- Docker development persists the entire site directory as a volume.
- Multi-site isolation is based on separate `OE_SITE_DIR` trees.

The S3 key prefix must preserve site/environment isolation without exposing patient data. Directory-list-driven jobs must move to database/job queries; S3 listing must not become the primary application index.

## 12. Migration risks

1. Missing a direct-path workflow can silently retain PHI on local disks.
2. Clinical soft-delete and legal retention requirements conflict with unconditional S3 deletion.
3. Presigned URLs bypass application checks after issuance and are bearer tokens.
4. The existing upload code is not transactionally safe; S3 and MySQL cannot share a transaction.
5. Current Pub/Sub publication after upload can turn a successful storage operation into an apparent failure.
6. Existing database rows reference local paths/CouchDB objects. The instruction says old files are not required, but rows may still be needed for audit and relational integrity.
7. DB BLOB extraction changes portal signature/document behavior and must preserve hashes and signing status.
8. EDI/SFTP/fax tooling often requires local path access and can leave temp PHI.
9. Large uploads cannot pass through current in-memory `createDocument`.
10. Thumbnail/conversion subprocesses need bounded resources and safe temp cleanup.
11. The existing MIME whitelist contains legacy types and differs by workflow.
12. Multi-site deployments need collision-proof site prefixes and per-site authorization, but keys must not contain PHI.
13. S3 versioning means a delete marker is not physical erasure.
14. KMS permissions can permit upload but later deny read/delete if IAM/key policy is incomplete.
15. Malware scanning is asynchronous; existing UI expects immediate availability.
16. Direct S3 uploads need checksum, size, MIME, multipart-abort, and abandoned-session controls.
17. Existing message S3 objects may already be orphaned and cannot be reliably associated from the database.
18. Local backup/restore no longer protects binaries; S3 backup, versioning, replication, and restore procedures become separate.
19. The separate `ccda` table and Zend `file_location` upload can be missed if only `documents` is migrated.

## 13. Proposed S3 architecture

```text
Existing UI / REST / FHIR / background workflow
    -> existing controller or service
    -> FileMetadataService / owning domain service
    -> FileUploadValidator + S3ObjectKeyGenerator
    -> FileStorageInterface
    -> S3FileStorage
    -> private versioned S3 bucket
```

### 13.1 Responsibilities

- `FileStorageInterface`: upload/stream source, exists/head metadata, view/download presign, delete version, copy, move, multipart initiate/presign/complete/abort.
- `S3FileStorage`: the only production class constructing `S3Client`; default credential chain; private objects; SSE-S3 or SSE-KMS; no ACL.
- `StoredFile`: immutable transfer object for bucket, key, version, size, MIME, checksum, and ETag.
- `FileUploadValidator`: upload error, size, server MIME, extension compatibility, filename safety, image dimensions, supported archive rules.
- `S3ObjectKeyGenerator`: environment/site and non-PHI UUID namespaces.
- `FileMetadataService`: pending/uploaded/deleting/deleted and scan transitions, owner links, compensation.
- `FileAccessService`: domain authorization, scan gate, audit, disposition-specific short URL.
- `TemporaryFileService`: restrictive creation/materialization/finally cleanup and abandoned cleanup.

`FileStorageFactory` is unnecessary while S3 is the only permanent driver. Dependency injection should bind `FileStorageInterface` directly to `S3FileStorage`. A factory should be added only if runtime selection becomes a real requirement.

### 13.2 Object keys

Use UUIDs from database entities, never names, MRNs, diagnoses, email addresses, titles, or numeric PIDs:

```text
{environment}/{site-uuid}/patients/{patient-uuid}/general/{kind}/{file-uuid}.{safe-ext}
{environment}/{site-uuid}/patients/{patient-uuid}/encounters/{encounter-uuid}/{kind}/{file-uuid}.{safe-ext}
{environment}/{site-uuid}/users/{user-uuid}/{kind}/{file-uuid}.{safe-ext}
{environment}/{site-uuid}/organizations/{organization-uuid}/{kind}/{file-uuid}.{safe-ext}
{environment}/{site-uuid}/branding/{file-uuid}.{safe-ext}
{environment}/{site-uuid}/billing/{file-uuid}.{safe-ext}
{environment}/{site-uuid}/reports/{file-uuid}.{safe-ext}
{environment}/{site-uuid}/exports/{file-uuid}.{safe-ext}
{environment}/{site-uuid}/communications/{file-uuid}.{safe-ext}
{environment}/{site-uuid}/derivatives/{parent-file-uuid}/{file-uuid}.{safe-ext}
```

Numeric patient IDs, original filenames, category names, and other PHI stay in database metadata only.

### 13.3 Upload consistency

1. Authenticate and authorize domain ownership.
2. Validate temp upload or requested multipart metadata.
3. Insert metadata with `pending`.
4. Generate key from persisted file UUID.
5. Calculate SHA-256 while streaming.
6. Upload with encryption and checksum.
7. `HeadObject` and verify expected size/checksum/encryption/version.
8. In one DB transaction, persist metadata/owner relation and mark `uploaded`, then enqueue scan.
9. On DB failure, delete the uploaded version and record cleanup failure for retry.
10. On S3 failure, mark failed/remove pending record according to audit policy.
11. Always delete local temp in `finally`.

The application must deny access unless `storage_status=uploaded` and `scan_status=clean`.

### 13.4 Large files

Direct multipart upload is required above a configurable threshold:

- Backend creates pending metadata and S3 multipart upload.
- Browser receives presigned URLs only for that upload ID/key.
- Completion validates all parts, size, checksum where supported, content metadata, and `HeadObject`.
- Abandoned uploads are aborted by an application cleanup job and S3 lifecycle rule.
- The frontend never receives AWS credentials.
- Video playback uses presigned GET so S3 handles byte ranges.

### 13.5 Bucket controls

- Block Public Access enabled.
- Bucket-owner-enforced ownership.
- Versioning enabled.
- Default encryption; explicit `aws:kms` and KMS key ID when configured.
- No object ACL calls.
- TLS-only bucket policy.
- IAM restricted to configured bucket/prefix and required multipart/version operations.
- CloudTrail data events for object operations.
- Lifecycle for abandoned multipart uploads and approved deleted/quarantine retention.
- CORS restricted to application origins and required methods/headers for direct multipart upload.

## 14. Proposed implementation sequence

1. Approve this inventory, storage boundaries, and retention policy.
2. Add configuration and dependency-injection binding; reuse the already-installed AWS SDK.
3. Add storage metadata schema and domain owner/link model.
4. Implement S3 storage, key generation, validator, temp service, metadata state transitions, and unit tests.
5. Migrate one representative core patient-document upload/view/download/delete path.
6. Add scan gating and an asynchronous scanner callback/job contract.
7. Migrate patient photos, clinical images, DICOM, thumbnails, and Eye Mag.
8. Migrate portal BLOBs/signatures/templates and legal-document artifacts.
9. Migrate PDFs and generated saved documents.
10. Migrate message, Direct, fax, and scanner workflows; remove current direct S3 code.
11. Migrate EDI/ERA/billing/lab/QRDA/EHI artifacts and path-based background jobs.
12. Add direct multipart/video support and range-playback tests.
13. Update backup/restore, deployment, monitoring, and cleanup jobs.
14. Mark unsupported historical local/CouchDB rows explicitly; do not generate broken links.
15. Remove permanent local/CouchDB reads/writes, off-site fallback hooks, obsolete globals, and persistent site directories.
16. Run repository-wide file-I/O audit again and complete end-to-end/security testing.

Each migration should be independently reviewable and leave no fallback that silently writes locally.

## 15. Files expected to be modified

### 15.1 New central code and tests

```text
src/Services/FileStorage/FileStorageInterface.php
src/Services/FileStorage/S3FileStorage.php
src/Services/FileStorage/StoredFile.php
src/Services/FileStorage/FileUploadValidator.php
src/Services/FileStorage/S3ObjectKeyGenerator.php
src/Services/FileStorage/FileAccessService.php
src/Services/FileStorage/FileMetadataService.php
src/Services/FileStorage/TemporaryFileService.php
src/Services/FileStorage/FileStorageException.php
tests/Tests/Unit/Services/FileStorage/*
tests/Tests/Services/FileStorage/*
```

### 15.2 Core document/API

```text
library/classes/Document.class.php
controllers/C_Document.class.php
library/documents.php
library/ajax/upload.php
src/Services/DocumentService.php
src/RestControllers/DocumentRestController.php
src/RestControllers/FHIR/FhirDocumentRestController.php
src/Services/FHIR/Document/*
src/Services/FHIR/FhirDocumentReferenceService.php
src/Services/FHIR/FhirMediaService.php
apis/routes/_rest_routes_standard.inc.php
apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php
interface/patient_file/deleter.php
interface/patient_file/merge_patients.php
library/classes/thumbnail/*
```

### 15.3 Portal/forms/templates/images

```text
portal/get_patient_documents.php
portal/report/document_downloads_action.php
portal/lib/doc_lib.php
portal/lib/download_template.php
portal/import_template.php
portal/patient/libs/Controller/OnsiteDocumentController.php
portal/sign/lib/save-signature.php
portal/sign/lib/show-signature.php
src/Services/DocumentTemplates/DocumentTemplateService.php
interface/super/manage_document_templates.php
interface/super/manage_site_files.php
interface/patient_file/download_template.php
interface/patient_file/education.php
interface/patient_file/letter.php
interface/forms/eye_mag/save.php
interface/forms/eye_mag/report.php
interface/smart/uploadSmartLogo.php
src/Services/LogoService.php
```

### 15.4 Communications/modules/billing/generated artifacts

```text
src/Services/MessageService.php
src/RestControllers/MessageRestController.php
library/direct_message_check.inc.php
interface/main/messages/trusted-messages-ajax.php
interface/fax/*
interface/modules/custom_modules/oe-module-faxsms/src/Controller/*
interface/modules/custom_modules/oe-module-ehi-exporter/src/Services/*
interface/modules/custom_modules/oe-module-claimrev-connect/src/*
interface/modules/custom_modules/oe-module-dorn/src/ReceiveHl7Results.php
interface/modules/custom_modules/oe-module-weno/*
src/Billing/BillingProcessor/*
interface/billing/get_claim_file.php
interface/billing/era_payments.php
interface/billing/sl_eob_process.php
interface/billing/sl_eob_search.php
interface/billing/edi_271.php
library/edihistory/*
library/billing_sftp_service.php
interface/orders/receive_hl7_results.inc.php
custom/export_qrda_xml.php
custom/qrda_download.php
src/Cqm/QrdaControllers/QrdaReportController.php
custom/zutil.cli.doc_import.php
interface/main/backup.php
```

### 15.5 Configuration/schema/deployment

```text
.env.example
library/globals.inc.php
interface/globals.php
sites/default/config.php
docker/* compose/environment templates
.github/workflows/buildanddeploydevinstanceaws.yaml
sql/database.sql
sql/{current-version}-to-{next-version}_upgrade.sql
interface/modules/custom_modules/oe-module-faxsms/table.sql
docs/s3-storage-*.md
```

This is the expected change surface, not permission for a single broad refactor. Response-only generators should be changed only if they persist or materialize source files unsafely.

## 16. Database migrations required

Recommended schema:

1. Create `file_storage` as the canonical binary metadata table:

```text
id
uuid binary(16) unique
storage_provider ('s3')
storage_bucket
storage_key
storage_version_id
original_filename
mime_type
file_size
checksum_sha256
storage_status
scan_status
created_by
created_at
updated_at
deleted_at
parent_file_id nullable
```

Indexes are required on UUID, `(storage_provider, storage_bucket, storage_key)`, status/scan cleanup fields, creator, and parent. Follow OpenEMR's binary UUID and upgrade-script conventions; avoid introducing foreign keys where existing schema conventions or upgrade compatibility make them unsafe.

2. Add nullable `storage_file_id` links to:

- `documents`
- `onsite_documents`
- `onsite_signatures` if signatures are migrated as files
- `document_templates`
- `ccda`
- `documents_legal_master/detail` as applicable
- `oe_faxsms_queue`
- any durable EDI/export artifact table that currently stores only a filename/path

3. Add a relation table for entities that can own multiple attachments but have no suitable link, beginning with `pnotes`.

4. Keep existing `documents.url`, `storagemethod`, `drive_uuid`, `path_depth`, `couch_*`, and `hash` through the compatibility period, but stop populating local-path values for new records. Do not overload `url` with an S3 or presigned URL.

5. Historical-row policy:

- Old binaries are explicitly not migrated.
- Preserve document rows needed for audit/relationships.
- Set a distinct unavailable/legacy status on rows without a valid S3 object.
- Remove or redact obsolete local paths only after dependent code is migrated.
- Do not mark legacy rows `uploaded` and do not synthesize nonexistent keys.
- Existing direct-message S3 objects cannot be safely backfilled unless an external inventory can prove ownership; otherwise document them as orphan cleanup.

6. Destructive removal of BLOB/path columns is a later migration after verification and retention approval.

## 17. Environment variables required

```env
FILE_STORAGE_DRIVER=s3

AWS_REGION=
AWS_S3_BUCKET=
AWS_S3_PREFIX=
AWS_S3_KMS_KEY_ID=
AWS_S3_SIGNED_URL_TTL_SECONDS=180

FILE_MAX_IMAGE_MB=20
FILE_MAX_PDF_MB=50
FILE_MAX_DOCUMENT_MB=50
FILE_MAX_VIDEO_MB=500

FILE_UPLOAD_ALLOWED_IMAGE_MIME_TYPES=image/jpeg,image/png,image/webp
FILE_UPLOAD_ALLOWED_VIDEO_MIME_TYPES=video/mp4,video/webm
FILE_UPLOAD_ALLOWED_DOCUMENT_MIME_TYPES=application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,text/csv
FILE_UPLOAD_ALLOWED_PDF_MIME_TYPES=application/pdf
```

Additional settings likely required:

```env
APP_ENVIRONMENT=
OPENEMR_SITE_UUID=
FILE_MULTIPART_THRESHOLD_MB=
FILE_MULTIPART_PART_SIZE_MB=
FILE_SCAN_MODE=
FILE_SCAN_CALLBACK_SECRET_ARN=
FILE_TEMP_DIRECTORY=
```

Do not add access-key variables as required application configuration. The AWS SDK default credential provider chain must support EC2/ECS/EKS roles and local environment credentials. Existing `AWS_BUCKET_NAME` and explicit credential reads in `MessageService` should be removed after migration.

## 18. Testing strategy

### Unit

- MIME and extension compatibility, empty files, upload errors, maximum sizes, dangerous/double filenames.
- Image dimensions and optional GIF/TIFF policy.
- UUID object keys contain no original names, numeric PID, or other PHI.
- SHA-256 streaming checksum.
- SSE-S3/SSE-KMS request construction.
- Inline/download response overrides and TTL bounds.
- Metadata mappings and valid state transitions.
- Compensation after S3 or DB failure.
- Scan access gate.
- Multipart validation, part numbering, complete/abort behavior.
- Temp cleanup in success and exception paths.

Mock AWS SDK clients/commands at the S3 adapter boundary.

### Integration

Use LocalStack for default CI and a dedicated non-production AWS bucket for KMS/versioning contract tests:

- Put, `HeadObject`, stream/get, exists, presign, copy, move, version-aware delete.
- Multipart initiate/upload/complete/abort.
- Size/checksum/version persistence.
- KMS allowed and denied paths.
- Database transaction and orphan cleanup.
- Scanner status callback/job.
- CORS and byte-range video GET.

### Authorization

- Staff/category/portal/OAuth permissions.
- Cross-patient, cross-encounter, cross-site, and raw-key denial.
- Pending/infected/failed/deleted access denial.
- Delete/replace retention policy.
- Signed URL expiration and disposition.
- Audit event creation.

### End-to-end

- Image/profile/scanned-image upload and preview.
- PDF upload, generated PDF save, preview, and download.
- DOCX/XLSX/TXT upload/download.
- DICOM ZIP and thumbnail/converted preview.
- Message/fax/Direct attachment.
- Video multipart upload and seeking.
- EDI generation/SFTP materialization.
- Portal signed document/template.
- Soft deletion and approved hard deletion.
- S3 failure, DB failure, scanner failure, and abandoned multipart cleanup.

### Regression audit

Repeat repository searches for direct permanent writes/reads. Every remaining `file_put_contents`, `copy`, `rename`, `unlink`, `readfile`, and file-path field must be classified as temp, runtime, static/build, or explicitly approved.

## 19. Rollback considerations

The requested final state has no local permanent fallback. Rollback therefore means deploying the prior application and restoring compatible database state; it must not silently switch new writes to disk.

- Deploy schema additions before code and keep them backward-compatible during phased rollout.
- Feature rollout may route one migrated workflow at a time to S3, but once a workflow writes S3-only records, old code must not process those records as local paths.
- Preserve S3 objects and versions throughout rollback windows.
- Record deployment cutover IDs/timestamps and schema versions.
- Drain or abort multipart sessions before rollback.
- Keep migration compensation/reconciliation jobs idempotent.
- Backup MySQL separately and protect S3 with versioning/replication/backup controls.
- Do not delete old local directories or BLOB columns until all migrated workflows pass verification and the rollback window closes.
- Because old binaries are not required, legacy records should remain explicitly unavailable rather than falling back to missing paths.

## 20. Security considerations

- Treat all patient and communication files as PHI unless classified otherwise.
- Keep the bucket private with Block Public Access and no public ACLs.
- Use least-privilege workload roles and KMS grants; never require static production credentials.
- Generate signed URLs only after application authorization, scan-state checks, and audit.
- Keep signed URLs short-lived and out of durable DB/logs/analytics/referrers.
- Do not log bucket, full key, KMS key ID, AWS exception details, original PHI filenames, or patient details in client errors.
- Use opaque correlation IDs for server logs.
- Detect MIME server-side with `finfo`; do not trust browser MIME.
- Enforce explicit MIME/extension allowlists and reject scripts, HTML, executables, unsupported archives, and unknown binaries.
- Use `Content-Disposition: attachment` for active/non-previewable formats and `X-Content-Type-Options: nosniff` on proxy responses.
- Sanitize response filenames against CRLF/header injection.
- Store no PHI in object keys, tags, or S3 custom metadata.
- Verify size/checksum/encryption/version after upload.
- Quarantine new objects and block access until scan status is `clean`.
- Protect scanner callbacks with authenticated, replay-resistant messages and validate bucket/key against the pending record.
- Apply S3 lifecycle rules only after clinical/legal retention approval.
- Audit create, view URL issuance, download URL issuance, delete, restore, copy/move, scan transition, and administrative diagnostic access.
- Bound decompression, image dimensions, PDF conversion, ZIP entry count, and subprocess resources.
- Use non-public restrictive temp storage and cleanup both in `finally` and by periodic orphan cleanup.
- Configure direct-upload CORS narrowly; presigned requests must constrain method, key, expiry, size strategy, and required encryption/checksum headers.
- CloudTrail object data events supplement but do not replace application audit records.

## Appendix A: Current endpoints

```text
POST /controller.php?document&upload&patient_id={pid}
GET  /controller.php?document&view&patient_id={pid}&doc_id={id}
GET  /controller.php?document&retrieve&patient_id={pid}&document_id={id}
POST /controller.php?document&move&patient_id={pid}&document_id={id}
POST /controller.php?document&update&patient_id={pid}&document_id={id}
POST /interface/patient_file/deleter.php?document={id}

POST /api/patient/:pid/document?path={category-path}
GET  /api/patient/:pid/document?path={category-path}
GET  /api/patient/:pid/document/:did
GET  /fhir/DocumentReference
GET  /fhir/DocumentReference/:uuid
GET  /fhir/Binary/:id

POST /api/patient/:pid/message
PUT  /api/patient/:pid/message/:mid
DELETE /api/patient/:pid/message/:mid

POST /portal/import_template.php
POST /portal/sign/lib/save-signature.php
GET  /portal/sign/lib/show-signature.php
POST /portal/report/document_downloads_action.php
GET  /portal/get_patient_documents.php
```

There are no standard REST document delete, content-replace, copy, move, multipart, or signed-view endpoints today.

## Appendix B: File-operation audit scope

The audit searched the repository for upload globals, multipart forms, MIME discovery, response headers, common file functions, document/upload/temp paths, services, controllers, routes, SQL tables, background services, event hooks, custom modules, and tests. Matches were classified into:

- Permanent application binary storage: must migrate.
- Temporary transformation/import/export material: may remain local under managed temp rules.
- Response-only generation: no permanent storage migration.
- Runtime secrets/cache/logs: move to appropriate platform services, not the application S3 file bucket.
- Static/build/vendor/test data: excluded from runtime migration.

Known upload entry-point files include:

```text
controllers/C_Document.class.php
library/ajax/upload.php
library/documents.php
src/Services/DocumentService.php
src/Services/MessageService.php
src/RestControllers/MessageRestController.php
apis/routes/_rest_routes_standard.inc.php
interface/modules/zend_modules/module/Documents/src/Documents/Controller/DocumentsController.php
interface/modules/zend_modules/module/Carecoordination/src/Carecoordination/Controller/*
interface/modules/zend_modules/module/Ccr/src/Ccr/Controller/CcrController.php
interface/super/manage_document_templates.php
interface/super/manage_site_files.php
interface/smart/uploadSmartLogo.php
interface/billing/era_payments.php
interface/billing/edi_271.php
interface/billing/sl_eob_search.php
library/edihistory/edih_uploads.php
interface/modules/custom_modules/oe-module-faxsms/src/Controller/*
portal/import_template.php
interface/main/backup.php
interface/main/holidays/import_holidays.php
interface/main/holidays/Holidays_Controller.php
interface/orders/load_compendium.php
interface/super/load_codes.php
interface/forms/CAMOS/admin.php
interface/forms/procedure_order/common.php
interface/eRx_xml.php
custom/ajax_download.php
custom/export_labworks.php
contrib/util/ccda_import/import_ccda.php
interface/orders/gen_hl7_order.inc.php
src/Services/CDADocumentService.php
src/Services/ImageUtilities/HandleImageService.php
```

## Appendix C: Workflow operation matrix

`None` means no application operation was found, not that direct filesystem manipulation is impossible.

| Workflow | Upload/create | View/download | Delete/replace/move | MIME, size, filename | Preview/public URL | Temp/background |
|---|---|---|---|---|---|---|
| Core patient documents | Legacy controller, AJAX, REST, `addNewDocument()` | Legacy retrieve/view; REST download; FHIR Binary | Soft delete; metadata rename; category/patient reassignment; no content copy/replace | `isWhiteFile`, `mime_content_type`, PHP limits, zero-byte reject; UUID disk name | Inline/attachment; thumbnail/JPEG conversion; application URL only | PHP temp, DICOM ZIP, ImageMagick; Direct/scanner/CCDA callers |
| Patient photo/ID | Core upload to configured category | Demographics/card through retrieve endpoint | Core soft delete | Core rules; latest category document selected | Inline image via application controller | Thumbnail/conversion may use temp |
| DICOM | Multi-file folder/ZIP or ordinary document upload | DICOM launcher retrieves document; viewer state via AJAX | Core soft delete | `.dcm`, DICM marker, ZIP inspection; no explicit app maximum | Application retrieve URL; no public object URL | ZIP and conversion temp |
| REST/FHIR document/media | Standard REST upload; FHIR references existing/generated files | REST binary and FHIR Binary | No REST delete/replace/move | Core whitelist; full binary loaded in memory | Application Binary URL; no range handling | FHIR/CCDA export jobs |
| CCDA table | Carecoordination dispatch/service generation | `CDADocumentService` and encounter manager | No centralized object delete found | XML/CCDA generated names and local/CouchDB/inline representation | Carecoordination views/downloads | CCDA validation/import temp and background flows |
| Portal documents | Portal AJAX/core upload; generated reviewed PDF | Portal listing, retrieve, bulk ZIP | Core soft delete; onsite activity can mark/delete DB rows | Core upload rules or generated PDF names | Application controller; ZIP response | Per-patient temp tree and ZIP cleanup |
| Onsite forms/signatures | Portal form/signature POST | Portal controller and signature display | Status/audit delete paths; DB-row operations | DB BLOB/text; hashes for signatures; no central size policy | Rendered portal HTML/PDF/image; no storage URL | Generated PDF may use temp |
| Portal/admin templates | Template import or admin filesystem upload | Template menu/download/fill | Admin direct unlink/DB update | Server MIME; legacy ODT/TXT/DOCX/ZIP and 1 MB rule | Attachment download; no public URL | ZIP/ODT substitution temp |
| Education PDFs | Admin upload | Patient education view/download | Admin direct file management | PDF/category filename; whitelist | Application response/local path | Permanent named local file |
| Eye Mag | Base64 drawing or generated PDF to core documents | Form links to core retrieve | Hard replacement exception plus core delete | JPEG/PDF; encounter/zone-derived original name | Core inline image/PDF | Web-addressable `documents/temp`, ImageMagick/PDF |
| Message attachment | REST POST uploads after `pnotes` insert | 60-minute URL returned only at creation | Message soft delete does not delete object | 10 MB; MIME and extension arrays; random hex name | Direct presigned S3 URL | Synchronous; no scanner |
| Direct messaging | Incoming attachments through `addNewDocument`; outbound uses existing docs | Existing document retrieval for send | Core delete only | Whitelist event and sender MIME data | No public URL; transmitted through Direct | `phimail` background service, temp attachments |
| Fax/scanner | Provider webhook/download, scanner intake, patient assignment | Fax UI/cache and core retrieve after assignment | Unassigned staging unlink; assigned core delete | Provider/finfo rules vary; generated names | `faxcache` may be directly web-addressable | Scanner/fax dirs, image/PDF conversion, module jobs |
| Billing EDI/ERA | Server generation and billing upload forms | Billing download endpoints and SFTP | Direct unlink/rename/archive rules | X12/EDI/PDF/HTML; workflow-specific names and checks | Attachment response only | `X12_SFTP` background service; history/tmp dirs |
| QRDA/CQM | Server-generated XML/ZIP/report | QRDA and AJAX download endpoints | Direct path cleanup/manual retention | Generated report names | Attachment response | Persistent report dirs plus ZIP temp |
| EHI export | Background job creates ZIP then core document | Core retrieve from export category | Core soft delete/job cleanup | Generated ZIP/UUID/job name | Application download | EHI task tables and managed temp directories |
| Logos/branding | Site deployment/admin paths; SMART PNG upload | `LogoService` or static SMART asset path | Direct replacement/deployment | SMART `.png`; site logos discovered by extension | Static/public URL currently possible | Permanent deployment/site asset |
| Holiday import | CSV upload copied to fixed staging file | Admin import UI | Workflow cleanup/overwrite | CSV; fixed `holidays_to_import.csv` | No public preview intended | Persistent staging, not safe temp |
| Reports/statements/exports | Server-side generation | Direct PDF/CSV/XLSX response | Temp unlink where implemented | Generated filename/content type | Response only | Memory/OS temp; some manual cleanup gaps |
| Menu JSON/config | Admin/deployment-created local JSON | Menu role classes scan/read directories | Direct filesystem replacement | JSON named by role/menu | No public URL intended | Permanent site configuration; should move to DB/config service or typed S3 record |

Phase 1 is complete when this report is reviewed and accepted. No S3 implementation should begin before retention, historical-record handling, DB-link strategy, and scope boundaries are approved.