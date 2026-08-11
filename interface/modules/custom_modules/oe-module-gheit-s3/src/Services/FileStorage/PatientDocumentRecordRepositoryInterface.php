<?php

/**
 * PatientDocumentRecordRepositoryInterface
 *
 * Bridges this module's file_storage metadata table to OpenEMR's legacy
 * `documents` table (library/classes/Document.class.php /
 * C_Document.class.php). Keeping this behind an interface means the
 * integration point with core is exactly one small class
 * (SqlPatientDocumentRecordRepository), which is what a future core
 * patch/PR would replace or core would absorb directly — nothing else
 * in this module touches the `documents` table.
 *
 * @package   OpenEMR\Modules\GheitS3\Services\FileStorage
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

interface PatientDocumentRecordRepositoryInterface
{
    public function resolvePatientUuid(int $pid): string;

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
    ): int;

    /**
     * @return array<string, mixed>|null
     */
    public function findDocumentForPatient(int $pid, int $documentId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findDocumentById(int $documentId): ?array;
}
