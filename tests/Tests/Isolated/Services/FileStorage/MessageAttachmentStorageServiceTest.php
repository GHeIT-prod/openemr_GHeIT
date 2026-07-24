<?php

/**
 * Message attachment S3 orchestration tests
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
use OpenEMR\Services\FileStorage\MessageAttachmentStorageService;
use OpenEMR\Services\FileStorage\PendingFile;
use OpenEMR\Services\FileStorage\S3ObjectKeyGenerator;
use OpenEMR\Services\FileStorage\StoredFile;
use OpenEMR\Services\FileStorage\ValidatedUpload;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MessageAttachmentStorageServiceTest extends TestCase
{
    private const SITE_UUID = '11111111-1111-4111-8111-111111111111';
    private const FILE_UUID = '44444444-4444-4444-8444-444444444444';

    public function testUploadValidatesStoresAndReturnsViewUrl(): void
    {
        $path = $this->temporaryFile('plain text');
        $validated = new ValidatedUpload(
            $path,
            'note.txt',
            'text/plain',
            filesize($path),
            'txt',
            FileUploadValidator::KIND_DOCUMENT
        );
        $pending = new PendingFile(9, self::FILE_UUID);
        $storedFile = new StoredFile(
            'private-files',
            'prod/' . self::SITE_UUID . '/communications/' . self::FILE_UUID . '.txt',
            'version-1',
            'etag',
            'note.txt',
            'text/plain',
            filesize($path),
            str_repeat('a', 64)
        );

        $storage = $this->createMock(FileStorageInterface::class);
        $metadata = $this->createMock(FileMetadataServiceInterface::class);
        $validator = $this->createMock(FileUploadValidatorInterface::class);

        $validator->expects($this->once())
            ->method('validateUploadedFileAutoKind')
            ->willReturn($validated);
        $metadata->expects($this->once())
            ->method('createPending')
            ->with('note.txt', 'text/plain', filesize($path), 7)
            ->willReturn($pending);
        $metadata->expects($this->once())
            ->method('assignStorageKey')
            ->with(9, $storedFile->getKey());
        $storage->expects($this->once())
            ->method('upload')
            ->with($path, $storedFile->getKey(), 'note.txt', 'text/plain')
            ->willReturn($storedFile);
        $metadata->expects($this->once())->method('markUploaded')->with(9, $storedFile);
        $metadata->expects($this->once())->method('markScanClean')->with(9);
        $storage->expects($this->once())
            ->method('createViewUrl')
            ->willThrowException(FileStorageException::forOperation('view validation'));
        $storage->expects($this->once())
            ->method('createInlineUrl')
            ->with($storedFile->getKey(), 'note.txt', 'text/plain', 'version-1')
            ->willReturn('https://example.test/view');

        try {
            $result = $this->service($storage, $metadata, $validator)->upload(
                15,
                ['tmp_name' => $path, 'name' => 'note.txt', 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)],
                7
            );
        } finally {
            unlink($path);
        }

        $this->assertSame('https://example.test/view', $result['view_url']);
        $this->assertSame($storedFile->getKey(), $result['s3_key']);
        $this->assertSame(9, $result['file_storage_id']);
        $this->assertSame('note.txt', $result['filename']);
    }

    public function testUploadCompensatesWhenMetadataUpdateFails(): void
    {
        $path = $this->temporaryFile('plain text');
        $validated = new ValidatedUpload(
            $path,
            'note.txt',
            'text/plain',
            filesize($path),
            'txt',
            FileUploadValidator::KIND_DOCUMENT
        );
        $pending = new PendingFile(9, self::FILE_UUID);
        $storedFile = new StoredFile(
            'private-files',
            'prod/' . self::SITE_UUID . '/communications/' . self::FILE_UUID . '.txt',
            'version-1',
            'etag',
            'note.txt',
            'text/plain',
            filesize($path),
            str_repeat('a', 64)
        );

        $storage = $this->createMock(FileStorageInterface::class);
        $metadata = $this->createMock(FileMetadataServiceInterface::class);
        $validator = $this->createMock(FileUploadValidatorInterface::class);

        $validator->method('validateUploadedFileAutoKind')->willReturn($validated);
        $metadata->method('createPending')->willReturn($pending);
        $metadata->method('assignStorageKey');
        $storage->method('upload')->willReturn($storedFile);
        $metadata->method('markUploaded')->willThrowException(new \RuntimeException('db failed'));
        $storage->expects($this->once())->method('delete')->with($storedFile->getKey(), 'version-1');
        $metadata->expects($this->once())->method('markFailed')->with(9);

        try {
            $this->expectException(FileStorageException::class);
            $this->service($storage, $metadata, $validator)->upload(
                15,
                ['tmp_name' => $path, 'name' => 'note.txt', 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)],
                7
            );
        } finally {
            unlink($path);
        }
    }

    private function service(
        FileStorageInterface&MockObject $storage,
        FileMetadataServiceInterface&MockObject $metadata,
        FileUploadValidatorInterface&MockObject $validator
    ): MessageAttachmentStorageService {
        return new MessageAttachmentStorageService(
            $storage,
            $metadata,
            $validator,
            new S3ObjectKeyGenerator(),
            null,
            'prod',
            self::SITE_UUID
        );
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'openemr-message-attachment-');
        if ($path === false) {
            $this->fail('Unable to create test file');
        }
        file_put_contents($path, $contents);

        return $path;
    }
}
