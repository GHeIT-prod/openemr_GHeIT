<?php

/**
 * Amazon S3 permanent file storage
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

use Aws\Exception\AwsException;
use Aws\ResultInterface;
use Aws\S3\S3ClientInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final class S3FileStorage implements FileStorageInterface
{
    public function __construct(
        private readonly S3ClientInterface $client,
        private readonly FileStorageConfig $config,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function upload(
        string $sourcePath,
        string $key,
        string $originalFilename,
        string $mimeType
    ): StoredFile {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw FileStorageException::forOperation('upload');
        }

        $size = filesize($sourcePath);
        $checksum = hash_file('sha256', $sourcePath);
        $stream = fopen($sourcePath, 'rb');
        if ($size === false || $checksum === false || $stream === false) {
            throw FileStorageException::forOperation('upload');
        }

        $objectKey = $this->objectKey($key);
        $versionId = null;
        $uploaded = false;

        try {
            $result = $this->execute(
                'PutObject',
                array_merge([
                    'Bucket' => $this->config->getBucket(),
                    'Key' => $objectKey,
                    'Body' => $stream,
                    'ContentType' => $mimeType,
                    'ChecksumSHA256' => base64_encode(hex2bin($checksum)),
                ], $this->encryptionArguments()),
                'upload'
            );
            $uploaded = true;
            $versionId = $this->nullableString($result['VersionId'] ?? null);

            if (($result['ChecksumSHA256'] ?? null) !== base64_encode(hex2bin($checksum))) {
                throw FileStorageException::forOperation('upload verification');
            }

            $metadata = $this->getMetadata($objectKey, $versionId);
            if (
                $metadata->getSize() !== $size
                || $metadata->getMimeType() !== $mimeType
                || (
                    $metadata->getChecksumSha256() !== null
                    && $metadata->getChecksumSha256() !== $checksum
                )
            ) {
                throw FileStorageException::forOperation('upload verification');
            }

            return new StoredFile(
                $metadata->getBucket(),
                $metadata->getKey(),
                $metadata->getVersionId(),
                $metadata->getEtag(),
                $originalFilename,
                $metadata->getMimeType(),
                $metadata->getSize(),
                $checksum
            );
        } catch (Throwable $exception) {
            if ($uploaded) {
                try {
                    $this->delete($objectKey, $versionId);
                } catch (Throwable) {
                }
            }

            if ($exception instanceof FileStorageException) {
                throw $exception;
            }

            throw $this->operationException('upload', $exception);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function exists(string $key, ?string $versionId = null): bool
    {
        try {
            $this->client->execute($this->client->getCommand(
                'HeadObject',
                $this->objectArguments($key, $versionId)
            ));

            return true;
        } catch (Throwable $exception) {
            if (
                $exception instanceof AwsException
                && ($exception->getStatusCode() === 404 || $exception->getAwsErrorCode() === 'NoSuchKey')
            ) {
                return false;
            }

            throw $this->operationException('existence check', $exception);
        }
    }

    public function getMetadata(string $key, ?string $versionId = null): StoredFile
    {
        $objectKey = $this->objectKey($key);
        $result = $this->execute(
            'HeadObject',
            array_merge(
                $this->objectArguments($objectKey, $versionId),
                ['ChecksumMode' => 'ENABLED']
            ),
            'metadata read'
        );

        return new StoredFile(
            $this->config->getBucket(),
            $objectKey,
            $this->nullableString($result['VersionId'] ?? $versionId),
            $this->etag($result['ETag'] ?? null),
            null,
            (string)($result['ContentType'] ?? 'application/octet-stream'),
            (int)($result['ContentLength'] ?? 0),
            $this->checksumHex($result['ChecksumSHA256'] ?? null)
        );
    }

    public function createViewUrl(
        string $key,
        string $filename,
        string $mimeType,
        ?string $versionId = null
    ): string {
        if (!$this->isInlineMimeType($mimeType)) {
            throw FileStorageException::forOperation('view validation');
        }

        return $this->createPresignedUrl($key, $filename, $mimeType, 'inline', $versionId);
    }

    public function createDownloadUrl(
        string $key,
        string $filename,
        string $mimeType,
        ?string $versionId = null
    ): string {
        $mimeType = in_array($mimeType, $this->allowedMimeTypes(), true)
            ? $mimeType
            : 'application/octet-stream';

        return $this->createPresignedUrl($key, $filename, $mimeType, 'attachment', $versionId);
    }

    public function createInlineUrl(
        string $key,
        string $filename,
        string $mimeType,
        ?string $versionId = null
    ): string {
        $mimeType = in_array($mimeType, $this->allowedMimeTypes(), true)
            ? $mimeType
            : 'application/octet-stream';

        return $this->createPresignedUrl($key, $filename, $mimeType, 'inline', $versionId);
    }

    public function downloadToPath(string $key, string $destinationPath, ?string $versionId = null): void
    {
        if ($destinationPath === '' || is_dir($destinationPath)) {
            throw FileStorageException::forOperation('download');
        }

        $result = $this->execute('GetObject', $this->objectArguments($key, $versionId), 'download');
        $body = $result['Body'] ?? null;
        if (!is_resource($body) && !is_string($body)) {
            throw FileStorageException::forOperation('download');
        }

        $destination = fopen($destinationPath, 'wb');
        if ($destination === false) {
            throw FileStorageException::forOperation('download');
        }

        try {
            if (is_string($body)) {
                if (fwrite($destination, $body) === false) {
                    throw FileStorageException::forOperation('download');
                }

                return;
            }

            while (!feof($body)) {
                $chunk = fread($body, 8192);
                if ($chunk === false) {
                    throw FileStorageException::forOperation('download');
                }
                if ($chunk !== '' && fwrite($destination, $chunk) === false) {
                    throw FileStorageException::forOperation('download');
                }
            }
        } catch (Throwable $exception) {
            if ($exception instanceof FileStorageException) {
                throw $exception;
            }

            throw $this->operationException('download', $exception);
        } finally {
            fclose($destination);
            if (is_resource($body)) {
                fclose($body);
            }
        }
    }

    public function delete(string $key, ?string $versionId = null): void
    {
        $this->execute('DeleteObject', $this->objectArguments($key, $versionId), 'delete');
    }

    public function copy(string $sourceKey, string $destinationKey, ?string $sourceVersionId = null): StoredFile
    {
        $sourceObjectKey = $this->objectKey($sourceKey);
        $destinationObjectKey = $this->objectKey($destinationKey);
        $sourceMetadata = $this->getMetadata($sourceObjectKey, $sourceVersionId);
        $copySource = rawurlencode($this->config->getBucket() . '/' . $sourceObjectKey);
        if ($sourceVersionId !== null) {
            $copySource .= '?versionId=' . rawurlencode($sourceVersionId);
        }

        $result = $this->execute(
            'CopyObject',
            array_merge([
                'Bucket' => $this->config->getBucket(),
                'Key' => $destinationObjectKey,
                'CopySource' => $copySource,
            ], $this->encryptionArguments()),
            'copy'
        );

        $destinationVersionId = $this->nullableString($result['VersionId'] ?? null);
        $destinationMetadata = $this->getMetadata(
            $destinationObjectKey,
            $destinationVersionId
        );

        if (
            $sourceMetadata->getSize() !== $destinationMetadata->getSize()
            || (
                $sourceMetadata->getChecksumSha256() !== null
                && $destinationMetadata->getChecksumSha256() !== null
                && $sourceMetadata->getChecksumSha256() !== $destinationMetadata->getChecksumSha256()
            )
        ) {
            try {
                $this->delete($destinationObjectKey, $destinationVersionId);
            } catch (FileStorageException) {
            }

            throw FileStorageException::forOperation('copy verification');
        }

        return $destinationMetadata;
    }

    public function move(string $sourceKey, string $destinationKey, ?string $sourceVersionId = null): StoredFile
    {
        $storedFile = $this->copy($sourceKey, $destinationKey, $sourceVersionId);
        $this->delete($sourceKey, $sourceVersionId);

        return $storedFile;
    }

    private function createPresignedUrl(
        string $key,
        string $filename,
        string $mimeType,
        string $disposition,
        ?string $versionId
    ): string {
        try {
            $arguments = array_merge(
                $this->objectArguments($key, $versionId),
                [
                    'ResponseContentType' => $mimeType,
                    'ResponseContentDisposition' => $this->contentDisposition($disposition, $filename),
                ]
            );
            $command = $this->client->getCommand('GetObject', $arguments);
            $expires = new DateTimeImmutable('+' . $this->config->getSignedUrlTtlSeconds() . ' seconds');

            return (string)$this->client->createPresignedRequest($command, $expires)->getUri();
        } catch (Throwable $exception) {
            throw $this->operationException('URL signing', $exception);
        }
    }

    private function execute(string $commandName, array $arguments, string $operation): ResultInterface
    {
        try {
            return $this->client->execute($this->client->getCommand($commandName, $arguments));
        } catch (Throwable $exception) {
            throw $this->operationException($operation, $exception);
        }
    }

    private function objectArguments(string $key, ?string $versionId): array
    {
        $arguments = [
            'Bucket' => $this->config->getBucket(),
            'Key' => $this->objectKey($key),
        ];

        if ($versionId !== null) {
            $arguments['VersionId'] = $versionId;
        }

        return $arguments;
    }

    private function objectKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || str_starts_with($key, '/') || str_contains($key, '//')) {
            throw FileStorageException::forOperation('key validation');
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw FileStorageException::forOperation('key validation');
            }
        }

        $prefix = $this->config->getPrefix();
        if ($prefix === '' || $key === $prefix || str_starts_with($key, $prefix . '/')) {
            return $key;
        }

        return $prefix . '/' . $key;
    }

    private function encryptionArguments(): array
    {
        $kmsKeyId = $this->config->getKmsKeyId();
        if ($kmsKeyId !== null) {
            return [
                'ServerSideEncryption' => 'aws:kms',
                'SSEKMSKeyId' => $kmsKeyId,
            ];
        }

        return ['ServerSideEncryption' => 'AES256'];
    }

    private function contentDisposition(string $disposition, string $filename): string
    {
        $filename = preg_split('/[\r\n]/', $filename, 2)[0] ?? '';
        $filename = basename(str_replace('\\', '/', $filename)) ?: 'file';
        $asciiFilename = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?: 'file';
        $asciiFilename = str_replace(['"', '\\'], '_', $asciiFilename);

        return $disposition
            . '; filename="' . $asciiFilename . '"'
            . "; filename*=UTF-8''" . rawurlencode($filename);
    }

    private function checksumHex(mixed $checksum): ?string
    {
        if (!is_string($checksum) || $checksum === '') {
            return null;
        }

        $binary = base64_decode($checksum, true);

        return $binary === false ? null : bin2hex($binary);
    }

    private function etag(mixed $etag): ?string
    {
        $etag = $this->nullableString($etag);

        return $etag === null ? null : trim($etag, '"');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isInlineMimeType(string $mimeType): bool
    {
        return in_array($mimeType, array_merge(
            $this->config->getAllowedImageMimeTypes(),
            $this->config->getAllowedVideoMimeTypes(),
            $this->config->getAllowedPdfMimeTypes()
        ), true);
    }

    private function allowedMimeTypes(): array
    {
        return array_merge(
            $this->config->getAllowedImageMimeTypes(),
            $this->config->getAllowedVideoMimeTypes(),
            $this->config->getAllowedDocumentMimeTypes(),
            $this->config->getAllowedPdfMimeTypes()
        );
    }

    private function operationException(string $operation, Throwable $exception): FileStorageException
    {
        try {
            $context = [
                'operation' => $operation,
                'exception_class' => $exception::class,
            ];
            if ($exception instanceof AwsException) {
                $context['aws_error_code'] = $exception->getAwsErrorCode();
                $context['aws_request_id'] = $exception->getAwsRequestId();
                $context['status_code'] = $exception->getStatusCode();
            }
            $this->logger?->error('File storage operation failed', $context);
        } catch (Throwable) {
        }

        return FileStorageException::forOperation($operation);
    }
}
