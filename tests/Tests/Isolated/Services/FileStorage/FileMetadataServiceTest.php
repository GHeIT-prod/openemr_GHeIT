<?php

/**
 * File metadata service tests
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\FileStorage;

use InvalidArgumentException;
use OpenEMR\Services\FileStorage\FileMetadataException;
use OpenEMR\Services\FileStorage\FileMetadataRepositoryInterface;
use OpenEMR\Services\FileStorage\FileMetadataService;
use OpenEMR\Services\FileStorage\FileStorageConfig;
use OpenEMR\Services\FileStorage\PendingFile;
use OpenEMR\Services\FileStorage\StoredFile;
use PHPUnit\Framework\TestCase;

final class FileMetadataServiceTest extends TestCase
{
    public function testCreatesPendingMetadataInConfiguredBucket(): void
    {
        $repository = $this->createMock(FileMetadataRepositoryInterface::class);
        $pendingFile = new PendingFile(42, '44444444-4444-4444-8444-444444444444');
        $repository->expects($this->once())
            ->method('createPending')
            ->with(
                'private-files',
                'report.pdf',
                'application/pdf',
                1234,
                7,
                null
            )
            ->willReturn($pendingFile);

        $result = (new FileMetadataService($repository, $this->config()))->createPending(
            'report.pdf',
            'application/pdf',
            1234,
            7
        );

        $this->assertSame($pendingFile, $result);
    }

    public function testRejectsInvalidPendingMetadata(): void
    {
        $repository = $this->createMock(FileMetadataRepositoryInterface::class);
        $repository->expects($this->never())->method('createPending');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid pending file metadata');

        (new FileMetadataService($repository, $this->config()))->createPending(
            '',
            'application/pdf',
            0,
            0
        );
    }

    public function testAssignsGeneratedStorageKey(): void
    {
        $repository = $this->createMock(FileMetadataRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('assignStorageKey')
            ->with(42, 'production/site/file.pdf')
            ->willReturn(true);

        (new FileMetadataService($repository, $this->config()))->assignStorageKey(
            42,
            'production/site/file.pdf'
        );
    }

    public function testRejectsInvalidUploadStateTransition(): void
    {
        $repository = $this->createMock(FileMetadataRepositoryInterface::class);
        $storedFile = $this->storedFile();
        $repository->expects($this->once())
            ->method('markUploaded')
            ->with(42, $storedFile)
            ->willReturn(false);

        $this->expectException(FileMetadataException::class);
        $this->expectExceptionMessage('Unable to mark file uploaded');

        (new FileMetadataService($repository, $this->config()))->markUploaded(42, $storedFile);
    }

    public function testTransitionsUploadedFileThroughDeletion(): void
    {
        $repository = $this->createMock(FileMetadataRepositoryInterface::class);
        $repository->expects($this->once())->method('markDeleting')->with(42)->willReturn(true);
        $repository->expects($this->once())->method('markDeleted')->with(42)->willReturn(true);
        $service = new FileMetadataService($repository, $this->config());

        $service->beginDelete(42);
        $service->markDeleted(42);
    }

    public function testMarksUploadedFileScanClean(): void
    {
        $repository = $this->createMock(FileMetadataRepositoryInterface::class);
        $repository->expects($this->once())->method('markScanClean')->with(42)->willReturn(true);

        (new FileMetadataService($repository, $this->config()))->markScanClean(42);
    }

    public function testReturnsMetadataAndRejectsMissingRecord(): void
    {
        $repository = $this->createMock(FileMetadataRepositoryInterface::class);
        $repository->expects($this->exactly(2))
            ->method('findById')
            ->willReturnMap([
                [42, ['id' => 42, 'storage_status' => 'uploaded']],
                [43, null],
            ]);
        $service = new FileMetadataService($repository, $this->config());

        $this->assertSame(
            ['id' => 42, 'storage_status' => 'uploaded'],
            $service->getById(42)
        );

        $this->expectException(FileMetadataException::class);
        $this->expectExceptionMessage('File metadata not found');
        $service->getById(43);
    }

    private function config(): FileStorageConfig
    {
        return FileStorageConfig::fromEnvironment([
            'AWS_REGION' => 'us-east-1',
            'AWS_S3_BUCKET' => 'private-files',
        ]);
    }

    private function storedFile(): StoredFile
    {
        return new StoredFile(
            'private-files',
            'production/site/file.pdf',
            'version-1',
            'etag',
            'report.pdf',
            'application/pdf',
            1234,
            str_repeat('a', 64)
        );
    }
}
