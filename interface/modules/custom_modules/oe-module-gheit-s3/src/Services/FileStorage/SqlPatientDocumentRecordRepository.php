<?php

/**
 * SqlPatientDocumentRecordRepository
 *
 * @package   OpenEMR\Modules\GheitS3\Services\FileStorage
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\PatientService;

final class SqlPatientDocumentRecordRepository implements PatientDocumentRecordRepositoryInterface
{
    public const STORAGE_METHOD_S3 = 2;

    public function __construct(private readonly PatientService $patientService = new PatientService())
    {
    }

    public function resolvePatientUuid(int $pid): string
    {
        $uuid = $this->patientService->getUuid((string)$pid);
        if ($uuid === false || $uuid === '') {
            throw new FileStorageException('Unable to resolve patient UUID');
        }

        return UuidRegistry::uuidToString($uuid);
    }

    public function createDocument(
        int $pid,
        int $categoryId,
        int $storageFileId,
        string $originalFilename,
        string $mimeType,
        int $size,
        string $checksumSha256,
        int $ownerId,
        ?int $foreignReferenceId = null,
        ?string $foreignReferenceTable = null,
        ?string $dateExpires = null
    ): int {
        $documentId = QueryUtils::generateId();
        $documentUuid = UuidRegistry::getRegistryForTable('documents')->createUuid();

        QueryUtils::sqlInsert(
            'INSERT INTO `documents` ('
            . '`id`, `uuid`, `type`, `size`, `date`, `url`, `mimetype`, `owner`, '
            . '`foreign_id`, `docdate`, `hash`, `name`, `storagemethod`, `encrypted`, '
            . '`deleted`, `storage_file_id`, `foreign_reference_id`, `foreign_reference_table`, '
            . '`date_expires`'
            . ') VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, CURDATE(), ?, ?, ?, 0, 0, ?, ?, ?, ?)',
            [
                $documentId,
                $documentUuid,
                'file_url',
                $size,
                's3://' . $storageFileId,
                $mimeType,
                $ownerId,
                $pid,
                $checksumSha256,
                $originalFilename,
                self::STORAGE_METHOD_S3,
                $storageFileId,
                $foreignReferenceId,
                $foreignReferenceTable,
                $dateExpires,
            ]
        );

        QueryUtils::sqlStatementThrowException(
            'REPLACE INTO `categories_to_documents` SET `category_id` = ?, `document_id` = ?',
            [$categoryId, $documentId]
        );

        return (int)$documentId;
    }

    public function findDocumentForPatient(int $pid, int $documentId): ?array
    {
        $records = QueryUtils::fetchRecords(
            $this->documentSelectSql()
            . 'WHERE `d`.`id` = ? AND `d`.`foreign_id` = ? AND `d`.`deleted` = 0 '
            . 'LIMIT 1',
            [$documentId, $pid]
        );

        return $records[0] ?? null;
    }

    public function findDocumentById(int $documentId): ?array
    {
        $records = QueryUtils::fetchRecords(
            $this->documentSelectSql()
            . 'WHERE `d`.`id` = ? AND `d`.`deleted` = 0 '
            . 'LIMIT 1',
            [$documentId]
        );

        return $records[0] ?? null;
    }

    private function documentSelectSql(): string
    {
        return 'SELECT '
            . '`d`.`id`, `d`.`name`, `d`.`mimetype`, `d`.`foreign_id`, `d`.`deleted`, '
            . '`d`.`storage_file_id`, `d`.`storagemethod`, '
            . '`fs`.`storage_key`, `fs`.`storage_version_id`, `fs`.`storage_status`, '
            . '`fs`.`scan_status`, `fs`.`original_filename`, `fs`.`mime_type` AS `storage_mime_type` '
            . 'FROM `documents` `d` '
            . 'LEFT JOIN `file_storage` `fs` ON `fs`.`id` = `d`.`storage_file_id` ';
    }
}