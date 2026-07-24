<?php

/**
 * Patient document S3 orchestration tests
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\FileStorage;

use OpenEMR\Services\FileStorage\FileMetadataServiceInterface;
use OpenEMR\Services\FileStorage\FileStorageException;
use OpenEMR\Services\FileStorage\FileStorageInterface;
use OpenEMR\Services\FileStorage\FileUploadValidator;
use OpenEMR\Services\FileStorage\FileUploadValidatorInterface;
use OpenEMR\Services\FileStorage\PatientDocumentRecordRepositoryInterface;
use OpenEMR\Services\FileStorage\PatientDocumentStorageService;
use OpenEMR\Services\FileStorage\PendingFile;
use OpenEMR\Services\FileStorage\S3ObjectKeyGenerator;
use OpenEMR\Services\FileStorage\StoredFile;
use OpenEMR\Services\FileStorage\ValidatedUpload;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PatientDocumentStorageServiceTest extends TestCase
{
    private const SITE_UUID = '11111111-1111-4111-8111-111111111111';
    private const PATIENT_UUID = '22222222-2222-4222-8222-222222222222';
    private const FILE_UUID = '44444444-4444-4444-8444-444444444444';

    public function testUploadValidatesStoresAndLinksDocument(): void
    {
        $path = $this->temporaryFile("%PDF-1.4\n%%EOF");
        $validated = new ValidatedUpload(
            $path,
            'report.pdf',
            'application/pdf',
            filesize($path),
            'pdf',
            FileUploadValidator::KIND_PDF
        );
        $pending = new PendingFile(9, self::FILE_UUID);
        $storedFile = new StoredFile(
            'private-files',
            'prod/' . self::SITE_UUID . '/patients/' . self::PATIENT_UUID
                . '/general/pdfs/' . self::FILE_UUID . '.pdf',
            'version-1',
            'etag',
            'report.pdf',
            'application/pdf',
            filesize($path),
            str_repeat('a', 64)
        );

        $storage = $this->createMock(FileStorageInterface::class);
        $metadata = $this->createMock(FileMetadataServiceInterface::class);
        $validator = $this->createMock(FileUploadValidatorInterface::class);
        $documents = $this->createMock(PatientDocumentRecordRepositoryInterface::class);

        $validator->expects($this->once())
            ->method('validateUploadedFileAutoKind')
            ->willReturn($validated);
        $metadata->expects($this->once())
            ->method('createPending')
            ->with('report.pdf', 'application/pdf', filesize($path), 7)
            ->willReturn($pending);
        $documents->expects($this->once())
            ->method('resolvePatientUuid')
            ->with(15)
            ->willReturn(self::PATIENT_UUID);
        $metadata->expects($this->once())
            ->method('assignStorageKey')
            ->with(9, $storedFile->getKey());
        $storage->expects($this->once())
            ->method('upload')
            ->with($path, $storedFile->getKey(), 'report.pdf', 'application/pdf')
            ->willReturn($storedFile);
        $metadata->expects($this->once())->method('markUploaded')->with(9, $storedFile);
        $metadata->expects($this->once())->method('markScanClean')->with(9);
        $documents->expects($this->once())
            ->method('createDocument')
            ->with(
                15,
                3,
                9,
                'report.pdf',
                'application/pdf',
                filesize($path),
                str_repeat('a', 64),
                7
            )
            ->willReturn(100);

        try {
            $documentId = $this->service($storage, $metadata, $validator, $documents)->upload(
                15,
                3,
                ['tmp_name' => $path, 'name' => 'report.pdf'],
                7
            );
        } finally {
            unlink($path);
        }

        $this->assertSame(100, $documentId);
    }

    public function testUploadCompensatesWhenDocumentLinkFails(): void
    {
        $path = $this->temporaryFile("%PDF-1.4\n%%EOF");
        $validated = new ValidatedUpload(
            $path,
            'report.pdf',
            'application/pdf',
            filesize($path),
            'pdf',
            FileUploadValidator::KIND_PDF
        );
        $pending = new PendingFile(9, self::FILE_UUID);
        $storedFile = new StoredFile(
            'private-files',
            'prod/' . self::SITE_UUID . '/patients/' . self::PATIENT_UUID
                . '/general/pdfs/' . self::FILE_UUID . '.pdf',
            'version-1',
            'etag',
            'report.pdf',
            'application/pdf',
            filesize($path),
            str_repeat('a', 64)
        );

        $storage = $this->createMock(FileStorageInterface::class);
        $metadata = $this->createMock(FileMetadataServiceInterface::class);
        $validator = $this->createMock(FileUploadValidatorInterface::class);
        $documents = $this->createMock(PatientDocumentRecordRepositoryInterface::class);

        $validator->method('validateUploadedFileAutoKind')->willReturn($validated);
        $metadata->method('createPending')->willReturn($pending);
        $documents->method('resolvePatientUuid')->willReturn(self::PATIENT_UUID);
        $metadata->method('assignStorageKey');
        $storage->method('upload')->willReturn($storedFile);
        $metadata->method('markUploaded');
        $metadata->method('markScanClean');
        $documents->method('createDocument')->willThrowException(new \RuntimeException('db failed'));
        $storage->expects($this->once())
            ->method('delete')
            ->with($storedFile->getKey(), 'version-1');
        $metadata->expects($this->once())->method('markFailed')->with(9);

        try {
            $this->expectException(FileStorageException::class);
            $this->expectExceptionMessage('File storage patient document upload failed');
            $this->service($storage, $metadata, $validator, $documents)->upload(
                15,
                3,
                ['tmp_name' => $path, 'name' => 'report.pdf'],
                7
            );
        } finally {
            unlink($path);
        }
    }

    public function testCreateDownloadRequiresUploadedCleanObject(): void
    {
        $storage = $this->createMock(FileStorageInterface::class);
        $metadata = $this->createMock(FileMetadataServiceInterface::class);
        $validator = $this->createMock(FileUploadValidatorInterface::class);
        $documents = $this->createMock(PatientDocumentRecordRepositoryInterface::class);

        $documents->expects($this->once())
            ->method('findDocumentForPatient')
            ->with(15, 100)
            ->willReturn([
                'id' => 100,
                'name' => 'report.pdf',
                'mimetype' => 'application/pdf',
                'storage_file_id' => 9,
                'storage_key' => 'prod/site/file.pdf',
                'storage_version_id' => 'version-1',
                'storage_status' => 'uploaded',
                'scan_status' => 'clean',
                'original_filename' => 'report.pdf',
                'storage_mime_type' => 'application/pdf',
            ]);
        $storage->expects($this->once())
            ->method('createDownloadUrl')
            ->with('prod/site/file.pdf', 'report.pdf', 'application/pdf', 'version-1')
            ->willReturn('https://example.test/download');

        $result = $this->service($storage, $metadata, $validator, $documents)->createDownload(15, 100);

        $this->assertSame('https://example.test/download', $result['download_url']);
        $this->assertSame('report.pdf', $result['filename']);
    }

    public function testCreateDownloadRejectsPendingScan(): void
    {
        $storage = $this->createMock(FileStorageInterface::class);
        $metadata = $this->createMock(FileMetadataServiceInterface::class);
        $validator = $this->createMock(FileUploadValidatorInterface::class);
        $documents = $this->createMock(PatientDocumentRecordRepositoryInterface::class);

        $documents->method('findDocumentForPatient')->willReturn([
            'storage_file_id' => 9,
            'storage_key' => 'prod/site/file.pdf',
            'storage_status' => 'uploaded',
            'scan_status' => 'pending',
        ]);
        $storage->expects($this->never())->method('createDownloadUrl');

        $this->expectException(FileStorageException::class);
        $this->expectExceptionMessage('Patient document is not ready for download');

        $this->service($storage, $metadata, $validator, $documents)->createDownload(15, 100);
    }

    public function testCreateDownloadRejectsLegacyUnlinkedDocument(): void
    {
        $storage = $this->createMock(FileStorageInterface::class);
        $documents = $this->createMock(PatientDocumentRecordRepositoryInterface::class);
        $documents->method('findDocumentForPatient')->willReturn([
            'id' => 100,
            'storage_file_id' => null,
        ]);

        $this->expectException(FileStorageException::class);
        $this->expectExceptionMessage('Patient document is unavailable');

        $this->service(
            $storage,
            $this->createMock(FileMetadataServiceInterface::class),
            $this->createMock(FileUploadValidatorInterface::class),
            $documents
        )->createDownload(15, 100);
    }

    private function service(
        FileStorageInterface&MockObject $storage,
        FileMetadataServiceInterface&MockObject $metadata,
        FileUploadValidatorInterface&MockObject $validator,
        PatientDocumentRecordRepositoryInterface&MockObject $documents
    ): PatientDocumentStorageService {
        return new PatientDocumentStorageService(
            $storage,
            $metadata,
            $validator,
            new S3ObjectKeyGenerator(),
            $documents,
            null,
            'prod',
            self::SITE_UUID
        );
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'openemr-patient-doc-');
        if ($path === false) {
            $this->fail('Unable to create test file');
        }
        file_put_contents($path, $contents);

        return $path;
    }
}
