<?php

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

interface FileMetadataRepositoryInterface
{
    public function createPending(
        string $bucket,
        string $originalFilename,
        string $mimeType,
        int $size,
        int $createdBy,
        ?int $parentFileId = null
    ): PendingFile;

    public function assignStorageKey(int $fileId, string $key): bool;

    public function markUploaded(int $fileId, StoredFile $storedFile): bool;

    public function markScanClean(int $fileId): bool;

    public function markFailed(int $fileId): bool;

    public function markDeleting(int $fileId): bool;

    public function markDeleted(int $fileId): bool;

    public function findById(int $fileId): ?array;
}
