<?php

/**
 * File upload validator tests
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\GheitS3\Tests\Isolated\Services\FileStorage;

use OpenEMR\Modules\GheitS3\Services\FileStorage\FileStorageConfig;
use OpenEMR\Modules\GheitS3\Services\FileStorage\FileUploadValidator;
use OpenEMR\Modules\GheitS3\Services\FileStorage\FileValidationException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class FileUploadValidatorTest extends TestCase
{
    private array $temporaryFiles = [];
    private FileUploadValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FileUploadValidator(FileStorageConfig::fromEnvironment([
            'AWS_REGION' => 'us-east-1',
            'AWS_S3_BUCKET' => 'private-files',
        ]));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testValidatesPdfUsingServerDetectedContent(): void
    {
        $path = $this->file("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

        $upload = $this->validator->validateFile(
            $path,
            'report.pdf',
            FileUploadValidator::KIND_PDF
        );

        $this->assertSame('application/pdf', $upload->getMimeType());
        $this->assertSame('pdf', $upload->getExtension());
        $this->assertSame(filesize($path), $upload->getSize());
    }

    public function testInfersKindFromFilenameExtension(): void
    {
        $this->assertSame(
            FileUploadValidator::KIND_PDF,
            $this->validator->kindForFilename('report.PDF')
        );
        $this->assertSame(
            FileUploadValidator::KIND_IMAGE,
            $this->validator->kindForFilename('scan.png')
        );
    }

    public function testValidatesPlainTextDocument(): void
    {
        $path = $this->file('clinical note');

        $upload = $this->validator->validateFile(
            $path,
            'note.txt',
            FileUploadValidator::KIND_DOCUMENT
        );

        $this->assertSame('text/plain', $upload->getMimeType());
        $this->assertSame(FileUploadValidator::KIND_DOCUMENT, $upload->getKind());
    }

    public function testValidatesOpenXmlDocumentContainer(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Zip extension is required for OpenXML validation');
        }
        $path = $this->emptyFile();
        $archive = new ZipArchive();
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $archive->addFromString('[Content_Types].xml', '<Types/>');
        $archive->addFromString('word/document.xml', '<document/>');
        $archive->close();

        $upload = $this->validator->validateFile(
            $path,
            'letter.docx',
            FileUploadValidator::KIND_DOCUMENT
        );

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $upload->getMimeType()
        );
    }

    public function testValidatesConfiguredCustomDocumentType(): void
    {
        $validator = new FileUploadValidator(FileStorageConfig::fromEnvironment([
            'AWS_REGION' => 'us-east-1',
            'AWS_S3_BUCKET' => 'private-files',
            'FILE_UPLOAD_ALLOWED_DOCUMENT_MIME_TYPES' => 'application/json',
            'FILE_UPLOAD_ALLOWED_DOCUMENT_EXTENSIONS' => 'json',
        ]));

        $upload = $validator->validateFile(
            $this->file('{"resourceType":"DocumentReference"}'),
            'document.json',
            FileUploadValidator::KIND_DOCUMENT
        );

        $this->assertSame('application/json', $upload->getMimeType());
        $this->assertSame('json', $upload->getExtension());
    }

    public function testValidatesDecodableImage(): void
    {
        $path = $this->file(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        ));

        $upload = $this->validator->validateFile(
            $path,
            'pixel.png',
            FileUploadValidator::KIND_IMAGE
        );

        $this->assertSame('image/png', $upload->getMimeType());
    }

    public function testRejectsEmptyFile(): void
    {
        $this->expectException(FileValidationException::class);
        $this->expectExceptionMessage('Uploaded file is empty');

        $this->validator->validateFile(
            $this->file(''),
            'empty.txt',
            FileUploadValidator::KIND_DOCUMENT
        );
    }

    public function testRejectsContentAndExtensionMismatch(): void
    {
        $this->expectException(FileValidationException::class);
        $this->expectExceptionMessage('Uploaded file extension does not match its content');

        $this->validator->validateFile(
            $this->file('plain text'),
            'fake.doc',
            FileUploadValidator::KIND_DOCUMENT
        );
    }

    public function testRejectsExecutableOrHtmlContent(): void
    {
        $this->expectException(FileValidationException::class);
        $this->expectExceptionMessage('Uploaded file extension is not allowed');

        $this->validator->validateFile(
            $this->file('<html><script>alert(1)</script></html>'),
            'page.html',
            FileUploadValidator::KIND_DOCUMENT
        );
    }

    public function testRejectsDoubleExtension(): void
    {
        $this->expectException(FileValidationException::class);
        $this->expectExceptionMessage('Double-extension filenames are not allowed');

        $this->validator->validateFile(
            $this->file('plain text'),
            'note.php.txt',
            FileUploadValidator::KIND_DOCUMENT
        );
    }

    public function testRejectsDeclaredSizeMismatch(): void
    {
        $this->expectException(FileValidationException::class);
        $this->expectExceptionMessage('Uploaded file size is invalid');

        $this->validator->validateFile(
            $this->file('plain text'),
            'note.txt',
            FileUploadValidator::KIND_DOCUMENT,
            999
        );
    }

    public function testAllowsBenignVersionedFilename(): void
    {
        $upload = $this->validator->validateFile(
            $this->file("%PDF-1.4\n%%EOF"),
            'report.v2.pdf',
            FileUploadValidator::KIND_PDF
        );

        $this->assertSame('report.v2.pdf', $upload->getOriginalFilename());
    }

    public function testRejectsUnicodeDirectionOverrideInFilename(): void
    {
        $this->expectException(FileValidationException::class);
        $this->expectExceptionMessage('Uploaded filename is invalid');

        $this->validator->validateFile(
            $this->file('plain text'),
            "report\u{202E}fdp.txt",
            FileUploadValidator::KIND_DOCUMENT
        );
    }

    public function testRejectsFileAboveConfiguredCategoryLimit(): void
    {
        $validator = new FileUploadValidator(FileStorageConfig::fromEnvironment([
            'AWS_REGION' => 'us-east-1',
            'AWS_S3_BUCKET' => 'private-files',
            'FILE_MAX_DOCUMENT_MB' => '1',
        ]));

        $this->expectException(FileValidationException::class);
        $this->expectExceptionMessage('Uploaded file size is invalid');

        $validator->validateFile(
            $this->file(str_repeat('a', 1024 * 1024 + 1)),
            'large.txt',
            FileUploadValidator::KIND_DOCUMENT
        );
    }

    public function testRejectsNonHttpTemporaryFileAsHttpUpload(): void
    {
        $this->expectException(FileValidationException::class);
        $this->expectExceptionMessage('Invalid HTTP file upload');

        $this->validator->validateUploadedFile([
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $this->file('plain text'),
            'name' => 'note.txt',
            'size' => strlen('plain text'),
        ], FileUploadValidator::KIND_DOCUMENT);
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'openemr-upload-test-');
        if ($path === false) {
            $this->fail('Unable to create test file');
        }
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function emptyFile(): string
    {
        return $this->file('');
    }
}