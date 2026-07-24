<?php

/**
 * Patient document record persistence for S3-backed documents
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

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
