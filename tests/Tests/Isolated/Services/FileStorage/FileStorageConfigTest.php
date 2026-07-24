<?php

/**
 * File storage configuration tests
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\FileStorage;

use InvalidArgumentException;
use OpenEMR\Services\FileStorage\FileStorageConfig;
use PHPUnit\Framework\TestCase;

final class FileStorageConfigTest extends TestCase
{
    public function testLoadsRequiredAndOptionalSettings(): void
    {
        $config = FileStorageConfig::fromEnvironment([
            'FILE_STORAGE_DRIVER' => 's3',
            'AWS_REGION' => 'us-east-1',
            'AWS_S3_BUCKET' => 'private-files',
            'AWS_S3_PREFIX' => '/production/site/',
            'AWS_S3_KMS_KEY_ID' => 'kms-key-id',
            'AWS_S3_SIGNED_URL_TTL_SECONDS' => '120',
            'FILE_MAX_IMAGE_MB' => '2',
            'FILE_MAX_PDF_MB' => '3',
            'FILE_MAX_DOCUMENT_MB' => '4',
            'FILE_MAX_VIDEO_MB' => '5',
            'FILE_UPLOAD_ALLOWED_IMAGE_MIME_TYPES' => 'image/jpeg, image/png',
            'FILE_UPLOAD_ALLOWED_VIDEO_MIME_TYPES' => 'video/mp4',
            'FILE_UPLOAD_ALLOWED_DOCUMENT_MIME_TYPES' => 'text/plain,text/csv',
            'FILE_UPLOAD_ALLOWED_PDF_MIME_TYPES' => 'application/pdf',
            'FILE_UPLOAD_ALLOWED_IMAGE_EXTENSIONS' => '.jpg,png',
            'FILE_UPLOAD_ALLOWED_VIDEO_EXTENSIONS' => 'mp4',
            'FILE_UPLOAD_ALLOWED_DOCUMENT_EXTENSIONS' => 'txt,csv',
            'FILE_UPLOAD_ALLOWED_PDF_EXTENSIONS' => 'pdf',
        ]);

        $this->assertSame('us-east-1', $config->getRegion());
        $this->assertSame('private-files', $config->getBucket());
        $this->assertSame('production/site', $config->getPrefix());
        $this->assertSame('kms-key-id', $config->getKmsKeyId());
        $this->assertSame(120, $config->getSignedUrlTtlSeconds());
        $this->assertSame(2 * 1024 * 1024, $config->getMaxImageBytes());
        $this->assertSame(3 * 1024 * 1024, $config->getMaxPdfBytes());
        $this->assertSame(4 * 1024 * 1024, $config->getMaxDocumentBytes());
        $this->assertSame(5 * 1024 * 1024, $config->getMaxVideoBytes());
        $this->assertSame(['image/jpeg', 'image/png'], $config->getAllowedImageMimeTypes());
        $this->assertSame(['video/mp4'], $config->getAllowedVideoMimeTypes());
        $this->assertSame(['text/plain', 'text/csv'], $config->getAllowedDocumentMimeTypes());
        $this->assertSame(['application/pdf'], $config->getAllowedPdfMimeTypes());
        $this->assertSame(['jpg', 'png'], $config->getAllowedImageExtensions());
        $this->assertSame(['mp4'], $config->getAllowedVideoExtensions());
        $this->assertSame(['txt', 'csv'], $config->getAllowedDocumentExtensions());
        $this->assertSame(['pdf'], $config->getAllowedPdfExtensions());
    }

    public function testUsesSecureDefaultsForOptionalSettings(): void
    {
        $config = FileStorageConfig::fromEnvironment([
            'AWS_REGION' => 'us-west-2',
            'AWS_S3_BUCKET' => 'private-files',
        ]);

        $this->assertNull($config->getKmsKeyId());
        $this->assertSame('', $config->getPrefix());
        $this->assertSame(180, $config->getSignedUrlTtlSeconds());
        $this->assertContains('image/webp', $config->getAllowedImageMimeTypes());
        $this->assertContains('video/webm', $config->getAllowedVideoMimeTypes());
        $this->assertNotContains('text/html', $config->getAllowedDocumentMimeTypes());
    }

    public function testRejectsMissingRequiredSettings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AWS_S3_BUCKET is required');

        FileStorageConfig::fromEnvironment(['AWS_REGION' => 'us-east-1']);
    }

    public function testRejectsNonS3Driver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FILE_STORAGE_DRIVER must be s3');

        FileStorageConfig::fromEnvironment([
            'FILE_STORAGE_DRIVER' => 'local',
            'AWS_REGION' => 'us-east-1',
            'AWS_S3_BUCKET' => 'private-files',
        ]);
    }

    public function testRejectsExcessiveSignedUrlLifetime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AWS_S3_SIGNED_URL_TTL_SECONDS must be between 1 and 900');

        FileStorageConfig::fromEnvironment([
            'AWS_REGION' => 'us-east-1',
            'AWS_S3_BUCKET' => 'private-files',
            'AWS_S3_SIGNED_URL_TTL_SECONDS' => '901',
        ]);
    }

    public function testRejectsEmptyMimeAllowlist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File upload MIME type allowlists cannot be empty');

        FileStorageConfig::fromEnvironment([
            'AWS_REGION' => 'us-east-1',
            'AWS_S3_BUCKET' => 'private-files',
            'FILE_UPLOAD_ALLOWED_IMAGE_MIME_TYPES' => ' ',
        ]);
    }

    public function testRejectsUnsafeExtensionAllowlist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File upload extension allowlists are invalid');

        FileStorageConfig::fromEnvironment([
            'AWS_REGION' => 'us-east-1',
            'AWS_S3_BUCKET' => 'private-files',
            'FILE_UPLOAD_ALLOWED_DOCUMENT_EXTENSIONS' => 'txt,pdf.exe',
        ]);
    }
}
