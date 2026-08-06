# oe-module-gheit-s3

Centralized S3 file storage for OpenEMR. Provides a driver-agnostic
storage layer (`FileStorageInterface`), upload validation, and metadata
tracking that patient documents, message attachments, and fax media can
all share, instead of each subsystem implementing its own ad-hoc
local/S3 handling.

## What this module ships

```
oe-module-gheit-s3/
├── openemr.bootstrap.php               # PRIMARY entry point — loaded by ModulesApplication
├── module.php                          # legacy-loader compatibility shim, see comments inside
├── composer.json                       # PSR-4 autoload + aws/aws-sdk-php dependency
├── .env.example                        # config vars to append to OpenEMR's .env
├── sql/
│   ├── install.sql                     # file_storage table + documents.storage_file_id
│   └── uninstall.sql
├── src/
│   └── Bootstrap.php                   # subscribes to OpenEMR events, adds Globals status panel
├── src/Services/FileStorage/
│   ├── FileStorageConfig.php           # reads all FILE_*/AWS_* env vars
│   ├── FileStorageInterface.php        # store/retrieve/delete/exists/getSignedUrl
│   ├── S3FileStorage.php               # S3 implementation (SSE-S3 or SSE-KMS)
│   ├── S3ClientFactory.php             # builds Aws\S3\S3Client (default credential chain)
│   ├── S3ObjectKeyGenerator.php        # builds the S3 key layout (see below)
│   ├── FileUploadValidator.php         # size / extension / MIME-sniff checks
│   ├── FileMetadataService.php         # file_storage row read/write orchestration
│   ├── SqlFileMetadataRepository.php   # file_storage table access
│   ├── PatientDocumentStorageService.php    # entry point for patient documents
│   ├── MessageAttachmentStorageService.php  # entry point for message attachments
│   ├── SqlPatientDocumentRecordRepository.php # links documents.storage_file_id
│   ├── FileStorageContainer.php        # composition root / factory
│   ├── PendingFile.php / StoredFile.php / ValidatedUpload.php  # value objects
│   └── *Exception.php
├── tests/                              # PHPUnit isolated tests
└── docs/
    ├── ARCHITECTURE.md
    └── INTEGRATION.md                  # exact call-site changes for core files
```

## S3 folder structure

```
your-bucket/
└── {AWS_S3_PREFIX}/                    e.g. "dev"
    └── {OPENEMR__ENVIRONMENT}/         e.g. "dev" or "prod"
        └── {site-uuid}/
            ├── patients/
            │   └── {patient-uuid}/
            │       ├── general/                  (no encounter linked)
            │       │   ├── images/{file-uuid}.png
            │       │   ├── pdfs/{file-uuid}.pdf
            │       │   └── documents/{file-uuid}.docx
            │       └── encounter/{encounter-uuid}/
            │           └── images/{file-uuid}.png
            └── communications/                    (messages, fax)
                └── {file-uuid}.pdf
```

Object keys are opaque UUIDs — the original filename is preserved only
in the `file_storage.original_filename` column, never in the S3 key.

## Install

1. Copy this directory to `interface/modules/custom_modules/oe-module-gheit-s3`.
2. From the OpenEMR root: `composer require gheit/oe-module-gheit-s3` (or merge
   this module's `composer.json` autoload/require entries into the root
   `composer.json` and run `composer update`), then `composer dump-autoload`.
3. Apply `sql/install.sql` against the OpenEMR database.
4. Append the contents of `.env.example` to OpenEMR's root `.env` and fill
   in `AWS_REGION`, `AWS_S3_BUCKET`, `AWS_S3_PREFIX`. Set AWS credentials
   via environment/IAM role, not in `.env`.
5. In OpenEMR: **Modules → Manage Modules → Custom Modules tab → Register**
   this module, then **Install** and **Enable**.
6. Follow `docs/INTEGRATION.md` to route the patient-document, message-attachment,
   and fax-media call sites through this module's services.

## Uninstall

Disable the module in Module Manager, then run `sql/uninstall.sql` if you
want the metadata table and `documents.storage_file_id` column removed.
This never deletes objects already written to S3.

## Testing

```bash
vendor/bin/phpunit oe-module-gheit-s3/tests
```

See `tests/` for the isolated unit tests covering config parsing, key
generation, upload validation, and the two storage services (S3 calls
are mocked — no live AWS credentials required to run these).
