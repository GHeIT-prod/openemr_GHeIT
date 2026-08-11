# S3 Storage Configuration

## Required settings

```env
FILE_STORAGE_DRIVER=s3
AWS_REGION=us-east-1
AWS_S3_BUCKET=private-openemr-files
```

`FILE_STORAGE_DRIVER` supports only `s3`. Missing region or bucket configuration is a startup/service-construction error. There is no local permanent-storage fallback.

## Optional S3 settings

```env
AWS_S3_PREFIX=
AWS_S3_KMS_KEY_ID=
AWS_S3_SIGNED_URL_TTL_SECONDS=180
```

- `AWS_S3_PREFIX`: optional non-PHI deployment prefix. Leading and trailing slashes are removed.
- `AWS_S3_KMS_KEY_ID`: enables SSE-KMS. When empty, the S3 adapter uses SSE-S3.
- `AWS_S3_SIGNED_URL_TTL_SECONDS`: must be between 1 and 900 seconds.

Do not include patient names, MRNs, email addresses, diagnoses, document titles, or other PHI in `AWS_S3_PREFIX`.

## Upload limits

```env
FILE_MAX_IMAGE_MB=20
FILE_MAX_PDF_MB=50
FILE_MAX_DOCUMENT_MB=50
FILE_MAX_VIDEO_MB=500
```

Values are positive integers in mebibytes (`1024 * 1024` bytes). PHP/web-server request limits must be at least as large for server-mediated uploads. Direct multipart uploads do not require the application server to receive the binary.

## MIME allowlists

```env
FILE_UPLOAD_ALLOWED_IMAGE_MIME_TYPES=image/jpeg,image/png,image/webp
FILE_UPLOAD_ALLOWED_VIDEO_MIME_TYPES=video/mp4,video/webm
FILE_UPLOAD_ALLOWED_DOCUMENT_MIME_TYPES=application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,text/csv
FILE_UPLOAD_ALLOWED_PDF_MIME_TYPES=application/pdf
FILE_UPLOAD_ALLOWED_IMAGE_EXTENSIONS=jpg,jpeg,png,webp
FILE_UPLOAD_ALLOWED_VIDEO_EXTENSIONS=mp4,webm
FILE_UPLOAD_ALLOWED_DOCUMENT_EXTENSIONS=doc,docx,xls,xlsx,txt,csv
FILE_UPLOAD_ALLOWED_PDF_EXTENSIONS=pdf
```

The values are comma-separated server-detected MIME types and matching extensions. Browser-provided MIME values are never trusted. Known formats enforce their MIME-to-extension mapping. For a custom format, operators must add both its detected MIME type and extension to the same category after security review.

GIF, TIFF, QuickTime, PowerPoint, archives, and unknown binary formats are disabled by default. Add a type only after its workflow, preview behavior, malware-scanning behavior, and size limits are approved.

PHP, HTML, JavaScript, shell scripts, and executables must not be added.

## AWS credentials

Production configuration must use the AWS SDK default credential provider chain:

- EC2 instance profile.
- ECS task role.
- EKS workload identity or IAM role for service accounts.
- Shared AWS profile or environment credentials for local development only.

The application does not require or read static credential settings from its file-storage configuration. Never commit credentials or send them to the browser.

## Bucket requirements

- Private bucket.
- S3 Block Public Access enabled.
- Bucket-owner-enforced object ownership.
- Versioning enabled.
- Default encryption enabled.
- TLS-only bucket policy.
- CloudTrail object data events enabled.
- Lifecycle rule to abort incomplete multipart uploads.
- CORS limited to approved application origins for direct multipart upload.

When `AWS_S3_KMS_KEY_ID` is configured, the workload role needs the required S3 permissions and KMS encrypt/decrypt/data-key permissions for that key.

## IAM operation baseline

Limit resources to the configured bucket and prefix.

```text
s3:PutObject
s3:GetObject
s3:HeadObject
s3:DeleteObject
s3:GetObjectVersion
s3:DeleteObjectVersion
s3:CreateMultipartUpload
s3:UploadPart
s3:CompleteMultipartUpload
s3:AbortMultipartUpload
s3:ListMultipartUploadParts
```

Exact permissions depend on the implemented adapter and retention policy. Do not grant public ACL or unrestricted bucket access.

## Validation

Run the isolated configuration tests:

```bash
vendor/bin/phpunit -c phpunit-isolated.xml tests/Tests/Isolated/Services/FileStorage/FileStorageConfigTest.php
```

Configuration parsing does not make an AWS network request.