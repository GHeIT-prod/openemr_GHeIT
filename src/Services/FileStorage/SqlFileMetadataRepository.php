<?php

/**
 * SQL persistence for canonical file metadata
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;

final class SqlFileMetadataRepository implements FileMetadataRepositoryInterface
{
    public function createPending(
        string $bucket,
        string $originalFilename,
        string $mimeType,
        int $size,
        int $createdBy,
        ?int $parentFileId = null
    ): PendingFile {
        $uuid = UuidRegistry::getRegistryForTable('file_storage')->createUuid();
        $id = QueryUtils::sqlInsert(
            'INSERT INTO `file_storage` '
            . '(`uuid`, `storage_provider`, `storage_bucket`, `original_filename`, `mime_type`, '
            . '`file_size`, `storage_status`, `scan_status`, `created_by`, `parent_file_id`) '
            . "VALUES (?, 's3', ?, ?, ?, ?, 'pending', 'pending', ?, ?)",
            [$uuid, $bucket, $originalFilename, $mimeType, $size, $createdBy, $parentFileId]
        );

        if (!$id) {
            throw new FileMetadataException('Unable to create pending file metadata');
        }

        return new PendingFile((int)$id, UuidRegistry::uuidToString($uuid));
    }

    public function assignStorageKey(int $fileId, string $key): bool
    {
        return $this->update(
            'UPDATE `file_storage` SET `storage_key` = ? '
            . "WHERE `id` = ? AND `storage_status` = 'pending' AND `storage_key` IS NULL",
            [$key, $fileId]
        );
    }

    public function markUploaded(int $fileId, StoredFile $storedFile): bool
    {
        return $this->update(
            'UPDATE `file_storage` SET '
            . '`storage_bucket` = ?, `storage_key` = ?, `storage_version_id` = ?, '
            . '`mime_type` = ?, `file_size` = ?, `checksum_sha256` = ?, `storage_status` = ? '
            . "WHERE `id` = ? AND `storage_status` = 'pending'",
            [
                $storedFile->getBucket(),
                $storedFile->getKey(),
                $storedFile->getVersionId(),
                $storedFile->getMimeType(),
                $storedFile->getSize(),
                $storedFile->getChecksumSha256(),
                'uploaded',
                $fileId,
            ]
        );
    }

    public function markFailed(int $fileId): bool
    {
        return $this->update(
            "UPDATE `file_storage` SET `storage_status` = 'failed' "
            . "WHERE `id` = ? AND `storage_status` = 'pending'",
            [$fileId]
        );
    }

    public function markDeleting(int $fileId): bool
    {
        return $this->update(
            "UPDATE `file_storage` SET `storage_status` = 'deleting' "
            . "WHERE `id` = ? AND `storage_status` = 'uploaded'",
            [$fileId]
        );
    }

    public function markDeleted(int $fileId): bool
    {
        return $this->update(
            "UPDATE `file_storage` SET `storage_status` = 'deleted', `deleted_at` = NOW() "
            . "WHERE `id` = ? AND `storage_status` = 'deleting'",
            [$fileId]
        );
    }

    public function findById(int $fileId): ?array
    {
        $records = QueryUtils::fetchRecords(
            'SELECT * FROM `file_storage` WHERE `id` = ? LIMIT 1',
            [$fileId]
        );

        return $records[0] ?? null;
    }

    private function update(string $sql, array $parameters): bool
    {
        QueryUtils::sqlStatementThrowException($sql, $parameters);

        return generic_sql_affected_rows() === 1;
    }
}
