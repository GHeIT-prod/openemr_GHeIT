<?php

/**
 * S3-backed patient message attachment upload orchestration
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

use OpenEMR\Common\Uuid\UniqueInstallationUuid;
use Psr\Log\LoggerInterface;
use Throwable;

final class MessageAttachmentStorageService
{
    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly FileMetadataServiceInterface $metadataService,
        private readonly FileUploadValidatorInterface $validator,
        private readonly S3ObjectKeyGenerator $keyGenerator,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?string $environment = null,
        private readonly ?string $siteUuid = null
    ) {
    }

    /**
     * @return array{
     *     view_url: string,
     *     s3_key: string,
     *     file_storage_id: int,
     *     filename: string,
     *     mimetype: string
     * }
     */
    public function upload(int $pid, array $fileData, int $ownerId): array
    {
        unset($pid);

        $validated = $this->validator->validateUploadedFileAutoKind($fileData);

        return $this->storeValidatedUpload($validated, $ownerId);
    }

    /**
     * @return array{
     *     view_url: string,
     *     s3_key: string,
     *     file_storage_id: int,
     *     filename: string,
     *     mimetype: string
     * }
     */
    public function uploadFromPath(string $path, string $originalFilename, int $ownerId): array
    {
        $validated = $this->validator->validateFile(
            $path,
            $originalFilename,
            $this->validator->kindForFilename($originalFilename)
        );

        return $this->storeValidatedUpload($validated, $ownerId);
    }

    public function readStoredContent(int $fileStorageId): string
    {
        $metadata = $this->requireUploadedCleanMetadata($fileStorageId);
        $temporaryPath = tempnam($this->temporaryDirectory(), 'oer');
        if ($temporaryPath === false) {
            throw FileStorageException::forOperation('communication file read');
        }

        try {
            $this->storage->downloadToPath(
                (string)$metadata['storage_key'],
                $temporaryPath,
                $metadata['storage_version_id'] ?? null
            );
            $content = file_get_contents($temporaryPath);
            if ($content === false) {
                throw FileStorageException::forOperation('communication file read');
            }

            return $content;
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function createStoredAccessUrl(int $fileStorageId, bool $asDownload): string
    {
        $metadata = $this->requireUploadedCleanMetadata($fileStorageId);
        $filename = (string)($metadata['original_filename'] ?: 'attachment');
        $mimeType = (string)($metadata['mime_type'] ?: 'application/octet-stream');
        $key = (string)$metadata['storage_key'];
        $versionId = $metadata['storage_version_id'] ?? null;

        if ($asDownload) {
            return $this->storage->createDownloadUrl($key, $filename, $mimeType, $versionId);
        }

        try {
            return $this->storage->createViewUrl($key, $filename, $mimeType, $versionId);
        } catch (FileStorageException) {
            return $this->storage->createInlineUrl($key, $filename, $mimeType, $versionId);
        }
    }

    public function deleteStoredFile(int $fileStorageId): void
    {
        $metadata = $this->metadataService->getById($fileStorageId);
        if (($metadata['storage_status'] ?? null) === 'deleted') {
            return;
        }

        if (($metadata['storage_status'] ?? null) === 'uploaded' && !empty($metadata['storage_key'])) {
            $this->metadataService->beginDelete($fileStorageId);
            $this->storage->delete(
                (string)$metadata['storage_key'],
                $metadata['storage_version_id'] ?? null
            );
            $this->metadataService->markDeleted($fileStorageId);

            return;
        }

        $this->metadataService->markFailed($fileStorageId);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireUploadedCleanMetadata(int $fileStorageId): array
    {
        $metadata = $this->metadataService->getById($fileStorageId);
        if (
            ($metadata['storage_status'] ?? null) !== 'uploaded'
            || ($metadata['scan_status'] ?? null) !== 'clean'
            || empty($metadata['storage_key'])
        ) {
            throw new FileStorageException('Communication file is not ready for access');
        }

        return $metadata;
    }

    /**
     * @return array{
     *     view_url: string,
     *     s3_key: string,
     *     file_storage_id: int,
     *     filename: string,
     *     mimetype: string
     * }
     */
    private function storeValidatedUpload(ValidatedUpload $validated, int $ownerId): array
    {
        $pending = $this->metadataService->createPending(
            $validated->getOriginalFilename(),
            $validated->getMimeType(),
            $validated->getSize(),
            $ownerId
        );

        $storedFile = null;
        try {
            $key = $this->keyGenerator->forSharedNamespace(
                $this->environment(),
                $this->siteUuid(),
                'communications',
                $pending->getUuid(),
                $validated->getExtension()
            );
            $this->metadataService->assignStorageKey($pending->getId(), $key);

            $storedFile = $this->storage->upload(
                $validated->getPath(),
                $key,
                $validated->getOriginalFilename(),
                $validated->getMimeType()
            );
            $this->metadataService->markUploaded($pending->getId(), $storedFile);
            // Malware scanning integration will replace this temporary clean mark.
            $this->metadataService->markScanClean($pending->getId());

            $filename = $validated->getOriginalFilename();
            $mimeType = $validated->getMimeType();
            $versionId = $storedFile->getVersionId();

            try {
                $viewUrl = $this->storage->createViewUrl($key, $filename, $mimeType, $versionId);
            } catch (FileStorageException) {
                $viewUrl = $this->storage->createInlineUrl($key, $filename, $mimeType, $versionId);
            }

            return [
                'view_url' => $viewUrl,
                's3_key' => $key,
                'file_storage_id' => $pending->getId(),
                'filename' => $filename,
                'mimetype' => $mimeType,
            ];
        } catch (Throwable $exception) {
            $this->compensateFailedUpload($pending->getId(), $storedFile);
            if (
                $exception instanceof FileStorageException
                || $exception instanceof FileMetadataException
                || $exception instanceof FileValidationException
            ) {
                throw $exception;
            }

            $this->logger?->error('Communication file upload failed', [
                'operation' => 'communication file upload',
                'exception_class' => $exception::class,
            ]);
            throw FileStorageException::forOperation('communication file upload');
        }
    }

    private function compensateFailedUpload(int $fileId, ?StoredFile $storedFile): void
    {
        try {
            if ($storedFile !== null) {
                $this->storage->delete($storedFile->getKey(), $storedFile->getVersionId());
            }
        } catch (Throwable $exception) {
            $this->logger?->error('Failed compensating S3 delete after communication file upload failure', [
                'operation' => 'communication file upload compensation',
                'exception_class' => $exception::class,
            ]);
        }

        try {
            $this->metadataService->markFailed($fileId);
        } catch (Throwable $exception) {
            $this->logger?->error('Failed marking communication file metadata failed', [
                'operation' => 'communication file upload compensation',
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function environment(): string
    {
        if ($this->environment !== null && $this->environment !== '') {
            return $this->environment;
        }

        $environment = strtolower(trim((string)($_ENV['OPENEMR__ENVIRONMENT'] ?? 'prod')));

        return $environment !== '' ? $environment : 'prod';
    }

    private function siteUuid(): string
    {
        if ($this->siteUuid !== null && $this->siteUuid !== '') {
            return $this->siteUuid;
        }

        return UniqueInstallationUuid::getUniqueInstallationUuid();
    }

    private function temporaryDirectory(): string
    {
        $directory = $GLOBALS['temporary_files_dir'] ?? sys_get_temp_dir();

        return is_string($directory) && $directory !== '' ? $directory : sys_get_temp_dir();
    }
}
