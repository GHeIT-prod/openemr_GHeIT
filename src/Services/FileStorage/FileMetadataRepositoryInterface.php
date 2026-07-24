<?php

/**
 * File metadata persistence contract
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

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

    public function markFailed(int $fileId): bool;

    public function markDeleting(int $fileId): bool;

    public function markDeleted(int $fileId): bool;

    public function findById(int $fileId): ?array;
}
