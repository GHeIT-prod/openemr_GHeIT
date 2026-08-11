<?php

/**
 * Environment-backed permanent file storage configuration
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

use InvalidArgumentException;

final class FileStorageConfig
{
    public const DRIVER_S3 = 's3';
    public const DEFAULT_SIGNED_URL_TTL_SECONDS = 180;
    public const MAX_SIGNED_URL_TTL_SECONDS = 900;

    private const DEFAULT_IMAGE_MIME_TYPES = 'image/jpeg,image/png,image/webp';
    private const DEFAULT_VIDEO_MIME_TYPES = 'video/mp4,video/webm';
    private const DEFAULT_DOCUMENT_MIME_TYPES = 'application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,text/csv';
    private const DEFAULT_PDF_MIME_TYPES = 'application/pdf';
    private const DEFAULT_IMAGE_EXTENSIONS = 'jpg,jpeg,png,webp';
    private const DEFAULT_VIDEO_EXTENSIONS = 'mp4,webm';
    private const DEFAULT_DOCUMENT_EXTENSIONS = 'doc,docx,xls,xlsx,txt,csv';
    private const DEFAULT_PDF_EXTENSIONS = 'pdf';

    private function __construct(
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $prefix,
        private readonly ?string $kmsKeyId,
        private readonly int $signedUrlTtlSeconds,
        private readonly int $maxImageBytes,
        private readonly int $maxPdfBytes,
        private readonly int $maxDocumentBytes,
        private readonly int $maxVideoBytes,
        private readonly array $allowedImageMimeTypes,
        private readonly array $allowedVideoMimeTypes,
        private readonly array $allowedDocumentMimeTypes,
        private readonly array $allowedPdfMimeTypes,
        private readonly array $allowedImageExtensions,
        private readonly array $allowedVideoExtensions,
        private readonly array $allowedDocumentExtensions,
        private readonly array $allowedPdfExtensions
    ) {
    }

    public static function fromEnvironment(?array $environment = null): self
    {
        $environment ??= $_ENV;
        $driver = strtolower(trim((string)($environment['FILE_STORAGE_DRIVER'] ?? self::DRIVER_S3)));
        if ($driver !== self::DRIVER_S3) {
            throw new InvalidArgumentException('FILE_STORAGE_DRIVER must be s3');
        }

        $region = self::required($environment, 'AWS_REGION');
        $bucket = self::required($environment, 'AWS_S3_BUCKET');
        $prefix = trim((string)($environment['AWS_S3_PREFIX'] ?? ''), " \t\n\r\0\x0B/");
        $kmsKeyId = trim((string)($environment['AWS_S3_KMS_KEY_ID'] ?? '')) ?: null;

        return new self(
            $region,
            $bucket,
            $prefix,
            $kmsKeyId,
            self::signedUrlTtl($environment['AWS_S3_SIGNED_URL_TTL_SECONDS'] ?? self::DEFAULT_SIGNED_URL_TTL_SECONDS),
            self::megabytesToBytes($environment['FILE_MAX_IMAGE_MB'] ?? 20, 'FILE_MAX_IMAGE_MB'),
            self::megabytesToBytes($environment['FILE_MAX_PDF_MB'] ?? 50, 'FILE_MAX_PDF_MB'),
            self::megabytesToBytes($environment['FILE_MAX_DOCUMENT_MB'] ?? 50, 'FILE_MAX_DOCUMENT_MB'),
            self::megabytesToBytes($environment['FILE_MAX_VIDEO_MB'] ?? 500, 'FILE_MAX_VIDEO_MB'),
            self::mimeTypes($environment['FILE_UPLOAD_ALLOWED_IMAGE_MIME_TYPES'] ?? self::DEFAULT_IMAGE_MIME_TYPES),
            self::mimeTypes($environment['FILE_UPLOAD_ALLOWED_VIDEO_MIME_TYPES'] ?? self::DEFAULT_VIDEO_MIME_TYPES),
            self::mimeTypes($environment['FILE_UPLOAD_ALLOWED_DOCUMENT_MIME_TYPES'] ?? self::DEFAULT_DOCUMENT_MIME_TYPES),
            self::mimeTypes($environment['FILE_UPLOAD_ALLOWED_PDF_MIME_TYPES'] ?? self::DEFAULT_PDF_MIME_TYPES),
            self::extensions($environment['FILE_UPLOAD_ALLOWED_IMAGE_EXTENSIONS'] ?? self::DEFAULT_IMAGE_EXTENSIONS),
            self::extensions($environment['FILE_UPLOAD_ALLOWED_VIDEO_EXTENSIONS'] ?? self::DEFAULT_VIDEO_EXTENSIONS),
            self::extensions($environment['FILE_UPLOAD_ALLOWED_DOCUMENT_EXTENSIONS'] ?? self::DEFAULT_DOCUMENT_EXTENSIONS),
            self::extensions($environment['FILE_UPLOAD_ALLOWED_PDF_EXTENSIONS'] ?? self::DEFAULT_PDF_EXTENSIONS)
        );
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getKmsKeyId(): ?string
    {
        return $this->kmsKeyId;
    }

    public function getSignedUrlTtlSeconds(): int
    {
        return $this->signedUrlTtlSeconds;
    }

    public function getMaxImageBytes(): int
    {
        return $this->maxImageBytes;
    }

    public function getMaxPdfBytes(): int
    {
        return $this->maxPdfBytes;
    }

    public function getMaxDocumentBytes(): int
    {
        return $this->maxDocumentBytes;
    }

    public function getMaxVideoBytes(): int
    {
        return $this->maxVideoBytes;
    }

    public function getAllowedImageMimeTypes(): array
    {
        return $this->allowedImageMimeTypes;
    }

    public function getAllowedVideoMimeTypes(): array
    {
        return $this->allowedVideoMimeTypes;
    }

    public function getAllowedDocumentMimeTypes(): array
    {
        return $this->allowedDocumentMimeTypes;
    }

    public function getAllowedPdfMimeTypes(): array
    {
        return $this->allowedPdfMimeTypes;
    }

    public function getAllowedImageExtensions(): array
    {
        return $this->allowedImageExtensions;
    }

    public function getAllowedVideoExtensions(): array
    {
        return $this->allowedVideoExtensions;
    }

    public function getAllowedDocumentExtensions(): array
    {
        return $this->allowedDocumentExtensions;
    }

    public function getAllowedPdfExtensions(): array
    {
        return $this->allowedPdfExtensions;
    }

    private static function required(array $environment, string $name): string
    {
        $value = trim((string)($environment[$name] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException($name . ' is required');
        }

        return $value;
    }

    private static function signedUrlTtl(mixed $value): int
    {
        $ttl = filter_var($value, FILTER_VALIDATE_INT);
        if ($ttl === false || $ttl < 1 || $ttl > self::MAX_SIGNED_URL_TTL_SECONDS) {
            throw new InvalidArgumentException(
                'AWS_S3_SIGNED_URL_TTL_SECONDS must be between 1 and ' . self::MAX_SIGNED_URL_TTL_SECONDS
            );
        }

        return $ttl;
    }

    private static function megabytesToBytes(mixed $value, string $name): int
    {
        $megabytes = filter_var($value, FILTER_VALIDATE_INT);
        if ($megabytes === false || $megabytes < 1) {
            throw new InvalidArgumentException($name . ' must be a positive integer');
        }

        return $megabytes * 1024 * 1024;
    }

    private static function mimeTypes(mixed $value): array
    {
        $mimeTypes = array_values(array_unique(array_filter(array_map(
            static fn(string $mimeType): string => strtolower(trim($mimeType)),
            explode(',', (string)$value)
        ))));

        if ($mimeTypes === []) {
            throw new InvalidArgumentException('File upload MIME type allowlists cannot be empty');
        }

        return $mimeTypes;
    }

    private static function extensions(mixed $value): array
    {
        $extensions = array_values(array_unique(array_filter(array_map(
            static fn(string $extension): string => strtolower(ltrim(trim($extension), '.')),
            explode(',', (string)$value)
        ))));

        if (
            $extensions === []
            || array_filter($extensions, static fn(string $extension): bool => !preg_match('/^[a-z0-9]{1,10}$/', $extension))
        ) {
            throw new InvalidArgumentException('File upload extension allowlists are invalid');
        }

        return $extensions;
    }
}