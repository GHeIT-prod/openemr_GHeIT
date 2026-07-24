<?php

/**
 * Canonical file metadata state contract
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

interface FileMetadataServiceInterface
{
    public function createPending(
        string $originalFilename,
        string $mimeType,
        int $size,
        int $createdBy,
        ?int $parentFileId = null
    ): PendingFile;

    public function assignStorageKey(int $fileId, string $key): void;

    public function markUploaded(int $fileId, StoredFile $storedFile): void;

    public function markScanClean(int $fileId): void;

    public function markFailed(int $fileId): void;

    public function beginDelete(int $fileId): void;

    public function markDeleted(int $fileId): void;

    public function getById(int $fileId): array;
}
