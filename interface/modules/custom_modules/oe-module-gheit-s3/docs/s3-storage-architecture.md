# S3 Storage Architecture

## Scope

Amazon S3 is the only permanent binary store. Local disk is allowed only for short-lived upload, validation, conversion, scanning, import/export, and report-generation work.

The architecture covers patient documents, images, PDFs, office documents, text files, DICOM, video, communications, portal files, billing artifacts, generated reports, exports, branding, and custom-module binaries identified in `s3-storage-analysis.md`.

Runtime secrets, PHP sessions, caches, deployed static assets, and structured logs are not application binaries and must use their appropriate platform services.

## Request flow

```text
OpenEMR UI / REST / FHIR / background job
    -> existing domain controller or service
    -> existing OpenEMR authentication and ACL checks
    -> FileMetadataService
    -> FileUploadValidator / S3ObjectKeyGenerator
    -> FileStorageInterface
    -> S3FileStorage
    -> private, versioned Amazon S3 bucket
```

Controllers must not instantiate AWS clients or accept S3 keys from clients.

## Components

### `FileStorageConfig`

Immutable environment-backed configuration:

- Region, bucket, prefix, KMS key, signed-URL TTL.
- Per-category size and MIME allowlists.
- Multipart threshold and part size.
- Environment and site UUID namespaces.
- No access key or secret key requirement.

Missing or invalid required configuration fails during service construction. There is no local permanent fallback.

### `S3ClientFactory`

The only production constructor for `S3Client`.

- Uses the AWS SDK default credential provider chain.
- Sets region and current SDK API version.
- Does not configure static credentials.
- Supports constructor injection of `S3ClientInterface` in tests.

### `FileStorageInterface`

Minimum operations:

```php
upload()
exists()
getMetadata()
createViewUrl()
createDownloadUrl()
delete()
copy()
move()
```

Multipart operations are added to the same interface when the direct-upload phase begins:

```php
initiateMultipartUpload()
createMultipartPartUrl()
completeMultipartUpload()
abortMultipartUpload()
```

The interface accepts streams or file paths for upload; it must not require loading complete binaries into memory.

### `S3FileStorage`

S3-specific implementation:

- Private objects only; no ACL calls.
- SSE-S3 by default or SSE-KMS when configured.
- SHA-256/checksum metadata supplied through supported S3 request fields.
- `HeadObject` verification after upload and multipart completion.
- Version-aware delete/copy/move.
- Short-lived presigned URLs with response content type and disposition overrides.
- AWS exceptions converted to domain exceptions without exposing bucket, key, KMS, credentials, or stack traces.

### `StoredFile`

Immutable result containing:

```text
bucket
key
version ID
ETag
original filename
detected MIME type
size
SHA-256 checksum
```

It contains no presigned URL.

### `FileUploadValidator`

Validates:

- PHP upload error.
- Non-empty content.
- Configured maximum size.
- Server-detected MIME via `finfo`.
- Extension and MIME compatibility.
- Double extensions and dangerous names.
- Executable, script, HTML, unknown binary, and unsupported archive rejection.
- Image dimensions and decodability where applicable.
- Video type and configured size.

Original filenames are retained only as database metadata and safe response-disposition values.

### `S3ObjectKeyGenerator`

Accepts existing application UUIDs; it does not query the database or generate entity IDs.

```text
{environment}/{site-uuid}/patients/{patient-uuid}/general/{kind}/{file-uuid}.{ext}
{environment}/{site-uuid}/patients/{patient-uuid}/encounters/{encounter-uuid}/{kind}/{file-uuid}.{ext}
{environment}/{site-uuid}/users/{user-uuid}/{kind}/{file-uuid}.{ext}
{environment}/{site-uuid}/organizations/{organization-uuid}/{kind}/{file-uuid}.{ext}
{environment}/{site-uuid}/branding/{file-uuid}.{ext}
{environment}/{site-uuid}/billing/{file-uuid}.{ext}
{environment}/{site-uuid}/reports/{file-uuid}.{ext}
{environment}/{site-uuid}/exports/{file-uuid}.{ext}
{environment}/{site-uuid}/communications/{file-uuid}.{ext}
{environment}/{site-uuid}/derivatives/{parent-file-uuid}/{file-uuid}.{ext}
```

Keys never contain names, MRNs, email addresses, diagnoses, document titles, category labels, or numeric patient IDs.

### `FileMetadataService`

Owns database state transitions and application associations.

```text
pending -> uploaded -> deleted
pending -> failed
uploaded -> deleting -> deleted
```

Scan state:

```text
pending -> clean
pending -> infected
pending -> failed
```

Only `uploaded + clean` files are available to normal users.

### `FileAccessService`

Given an application file UUID:

1. Loads metadata and ownership.
2. Calls the owning workflow's existing OpenEMR ACL policy.
3. Checks storage and scan status.
4. Records an application audit event.
5. Requests a view or download URL from `FileStorageInterface`.

It never signs a browser-supplied bucket or key.

### `TemporaryFileService`

- Uses one non-public configured root.
- Creates unpredictable names with restrictive permissions.
- Never uses original filenames as disk paths.
- Deletes files and directories in `finally`.
- Supports safe materialization for integrations requiring a local path.
- Provides periodic abandoned-file cleanup.

## Dependency injection

`src/Core/Kernel.php` is the existing Symfony container bootstrap.

The production container binds:

```text
FileStorageInterface -> S3FileStorage
S3FileStorage -> S3ClientInterface + FileStorageConfig
```

S3 is the only permanent driver, so no driver-selection factory or local-storage implementation is required. A factory is added only if runtime provider selection becomes a real requirement.

Legacy procedural entry points may resolve the interface from `$GLOBALS['kernel']->getContainer()` during phased migration. New services use constructor injection.

## Metadata model

`file_storage` is the canonical S3 metadata table:

```text
id
uuid
storage_provider
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
date_created
date_updated
deleted_at
parent_file_id
```

Domain tables link to `file_storage.id`. Initial migration links `documents.storage_file_id`; later focused migrations add portal, CCDA, fax, message, billing, and export associations.

No permanent URL or presigned URL is stored.

Existing local/CouchDB rows remain unlinked and explicitly legacy/unavailable. No local-to-S3 binary migration is performed.

## Upload consistency

```text
authenticate
-> authorize domain owner
-> validate upload
-> insert pending metadata
-> generate opaque key
-> calculate SHA-256
-> upload to S3
-> verify HeadObject
-> persist version/size/checksum/MIME and domain link
-> mark uploaded
-> trigger malware scan
-> delete temp in finally
```

S3 and MySQL cannot share a transaction:

- S3 failure leaves a failed/pending record, never an uploaded record.
- Database failure after S3 upload triggers version-aware compensating deletion.
- Failed compensation is recorded for retry by a reconciliation job.
- Pub/Sub and other notifications occur after durable metadata commit and do not roll back successful storage.

## View and download

Default:

- Images and PDFs: short-lived inline presigned URL.
- Documents and exports: short-lived attachment presigned URL.
- Video: presigned GET so S3 handles byte ranges and seeking.

Backend proxy:

- Required transformation or application-level encryption.
- Protocol integrations that require bytes.
- Audit/legal requirements requiring server-side streaming.
- One-time ZIP/report assembly.

Presigned URLs are bearer tokens and use the configured short TTL.

## Multipart upload

1. Client requests a session with application metadata.
2. Backend authenticates, authorizes, validates requested size/type, and inserts pending metadata.
3. Backend initiates multipart upload for the internally generated key.
4. Backend signs bounded part requests.
5. Client uploads directly to S3.
6. Backend completes and verifies the object.
7. Backend updates metadata and starts scanning.

S3 lifecycle rules abort abandoned multipart uploads. Application cleanup expires pending sessions and calls abort where possible.

## Malware scanning

Initial contract:

- New objects have `scan_status=pending`.
- Scanner consumes an S3 event or application queue message.
- Scanner reports `clean`, `infected`, or `failed` against the application file UUID.
- Normal access is denied unless clean.
- Infected objects remain quarantined or are version-aware deleted according to policy.

No PHI is stored in S3 object tags or custom metadata.

## Deletion and retention

Clinical files use application soft deletion and S3 version preservation until a retention policy explicitly authorizes physical deletion.

When hard deletion is allowed:

```text
authorize
-> mark deleting
-> delete exact S3 version
-> verify object/version result
-> mark deleted
-> audit
```

Replacing content creates a new immutable object/version. Metadata-only patient, encounter, category, or issue changes do not move S3 objects.

## Security baseline

- S3 Block Public Access.
- Bucket-owner-enforced object ownership.
- Versioning.
- TLS-only bucket policy.
- SSE-S3 or SSE-KMS.
- Least-privilege workload role.
- CloudTrail object data events.
- Restricted direct-upload CORS.
- No static production credentials.
- No public-read ACL.
- No PHI in keys, tags, or custom metadata.
- No bucket/key/KMS details in client errors.

## Rollout boundary

Each migrated workflow must:

- Stop permanent local and CouchDB writes.
- Stop permanent local and CouchDB reads.
- Use central validation and metadata.
- Preserve existing authorization.
- Add focused automated coverage.
- Fail closed when S3 is unavailable.

Local fallback is never introduced.