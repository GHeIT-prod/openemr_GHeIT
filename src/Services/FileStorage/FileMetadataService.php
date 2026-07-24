<?php

/**
 * Canonical file metadata state service
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

use InvalidArgumentException;

final class FileMetadataService implements FileMetadataServiceInterface
{
    public function __construct(
        private readonly FileMetadataRepositoryInterface $repository,
        private readonly FileStorageConfig $config
    ) {
    }

    public function createPending(
        string $originalFilename,
        string $mimeType,
        int $size,
        int $createdBy,
        ?int $parentFileId = null
    ): PendingFile {
        if (trim($originalFilename) === '' || trim($mimeType) === '' || $size < 1 || $createdBy < 1) {
            throw new InvalidArgumentException('Invalid pending file metadata');
        }
        if ($parentFileId !== null && $parentFileId < 1) {
            throw new InvalidArgumentException('Invalid parent file metadata');
        }

        return $this->repository->createPending(
            $this->config->getBucket(),
            $originalFilename,
            $mimeType,
            $size,
            $createdBy,
            $parentFileId
        );
    }

    public function assignStorageKey(int $fileId, string $key): void
    {
        if (trim($key) === '' || !$this->repository->assignStorageKey($fileId, $key)) {
            throw new FileMetadataException('Unable to assign file storage key');
        }
    }

    public function markUploaded(int $fileId, StoredFile $storedFile): void
    {
        if (!$this->repository->markUploaded($fileId, $storedFile)) {
            throw new FileMetadataException('Unable to mark file uploaded');
        }
    }

    public function markScanClean(int $fileId): void
    {
        if (!$this->repository->markScanClean($fileId)) {
            throw new FileMetadataException('Unable to mark file scan clean');
        }
    }

    public function markFailed(int $fileId): void
    {
        if (!$this->repository->markFailed($fileId)) {
            throw new FileMetadataException('Unable to mark file failed');
        }
    }

    public function beginDelete(int $fileId): void
    {
        if (!$this->repository->markDeleting($fileId)) {
            throw new FileMetadataException('Unable to begin file deletion');
        }
    }

    public function markDeleted(int $fileId): void
    {
        if (!$this->repository->markDeleted($fileId)) {
            throw new FileMetadataException('Unable to mark file deleted');
        }
    }

    public function getById(int $fileId): array
    {
        $metadata = $this->repository->findById($fileId);
        if ($metadata === null) {
            throw new FileMetadataException('File metadata not found');
        }

        return $metadata;
    }
}
