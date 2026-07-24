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
        $validated = $this->validator->validateUploadedFileAutoKind($fileData);
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

            $this->logger?->error('Message attachment upload failed', [
                'operation' => 'message attachment upload',
                'patient_id' => $pid,
                'exception_class' => $exception::class,
            ]);
            throw FileStorageException::forOperation('message attachment upload');
        }
    }

    private function compensateFailedUpload(int $fileId, ?StoredFile $storedFile): void
    {
        try {
            if ($storedFile !== null) {
                $this->storage->delete($storedFile->getKey(), $storedFile->getVersionId());
            }
        } catch (Throwable $exception) {
            $this->logger?->error('Failed compensating S3 delete after message attachment upload failure', [
                'operation' => 'message attachment upload compensation',
                'exception_class' => $exception::class,
            ]);
        }

        try {
            $this->metadataService->markFailed($fileId);
        } catch (Throwable $exception) {
            $this->logger?->error('Failed marking message attachment metadata failed', [
                'operation' => 'message attachment upload compensation',
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
}
