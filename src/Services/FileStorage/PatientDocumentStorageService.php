<?php

/**
 * S3-backed patient document upload and download orchestration
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

final class PatientDocumentStorageService
{
    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly FileMetadataServiceInterface $metadataService,
        private readonly FileUploadValidatorInterface $validator,
        private readonly S3ObjectKeyGenerator $keyGenerator,
        private readonly PatientDocumentRecordRepositoryInterface $documents,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?string $environment = null,
        private readonly ?string $siteUuid = null
    ) {
    }

    public function upload(int $pid, int $categoryId, array $fileData, int $ownerId): int
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
            $key = $this->keyGenerator->forPatient(
                $this->environment(),
                $this->siteUuid(),
                $this->documents->resolvePatientUuid($pid),
                $validated->getKind(),
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

            return $this->documents->createDocument(
                $pid,
                $categoryId,
                $pending->getId(),
                $validated->getOriginalFilename(),
                $validated->getMimeType(),
                $validated->getSize(),
                (string)$storedFile->getChecksumSha256(),
                $ownerId
            );
        } catch (Throwable $exception) {
            $this->compensateFailedUpload($pending->getId(), $storedFile);
            if (
                $exception instanceof FileStorageException
                || $exception instanceof FileMetadataException
                || $exception instanceof FileValidationException
            ) {
                throw $exception;
            }

            $this->logger?->error('Patient document upload failed', [
                'operation' => 'patient document upload',
                'exception_class' => $exception::class,
            ]);
            throw FileStorageException::forOperation('patient document upload');
        }
    }

    /**
     * @return array{filename: string, mimetype: string, download_url: string}
     */
    public function createDownload(int $pid, int $documentId): array
    {
        $document = $this->documents->findDocumentForPatient($pid, $documentId);
        if ($document === null || empty($document['storage_file_id'])) {
            throw new FileStorageException('Patient document is unavailable');
        }
        if (
            ($document['storage_status'] ?? null) !== 'uploaded'
            || ($document['scan_status'] ?? null) !== 'clean'
            || empty($document['storage_key'])
        ) {
            throw new FileStorageException('Patient document is not ready for download');
        }

        $filename = (string)($document['original_filename'] ?: $document['name'] ?: 'document');
        $mimeType = (string)($document['storage_mime_type'] ?: $document['mimetype'] ?: 'application/octet-stream');

        return [
            'filename' => $filename,
            'mimetype' => $mimeType,
            'download_url' => $this->storage->createDownloadUrl(
                (string)$document['storage_key'],
                $filename,
                $mimeType,
                $document['storage_version_id'] ?? null
            ),
        ];
    }

    private function compensateFailedUpload(int $fileId, ?StoredFile $storedFile): void
    {
        try {
            if ($storedFile !== null) {
                $this->storage->delete($storedFile->getKey(), $storedFile->getVersionId());
            }
        } catch (Throwable $exception) {
            $this->logger?->error('Failed compensating S3 delete after patient document upload failure', [
                'operation' => 'patient document upload compensation',
                'exception_class' => $exception::class,
            ]);
        }

        try {
            $this->metadataService->markFailed($fileId);
        } catch (Throwable $exception) {
            $this->logger?->error('Failed marking patient document metadata failed', [
                'operation' => 'patient document upload compensation',
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
