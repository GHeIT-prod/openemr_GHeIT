<?php

/**
 * S3 file storage tests
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\FileStorage;

use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use DateTimeInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Services\FileStorage\FileStorageConfig;
use OpenEMR\Services\FileStorage\FileStorageException;
use OpenEMR\Services\FileStorage\S3FileStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class S3FileStorageTest extends TestCase
{
    public function testUploadUsesPrivateEncryptedObjectAndVerifiesIt(): void
    {
        $sourcePath = $this->temporaryFile('clinical document');
        $checksumBase64 = base64_encode(hash('sha256', 'clinical document', true));
        $client = $this->client();

        $client->expects($this->exactly(2))
            ->method('getCommand')
            ->willReturnCallback(static fn(string $name, array $arguments): Command => new Command($name, $arguments));
        $client->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (CommandInterface $command) use ($checksumBase64): Result {
                if ($command->getName() === 'PutObject') {
                    $this->assertSame('private-files', $command['Bucket']);
                    $this->assertSame('root/production/site/file.pdf', $command['Key']);
                    $this->assertSame('application/pdf', $command['ContentType']);
                    $this->assertSame('AES256', $command['ServerSideEncryption']);
                    $this->assertArrayNotHasKey('ACL', $command->toArray());
                    $this->assertSame($checksumBase64, $command['ChecksumSHA256']);
                    $this->assertTrue(is_resource($command['Body']));

                    return new Result([
                        'VersionId' => 'version-1',
                        'ChecksumSHA256' => $checksumBase64,
                    ]);
                }

                $this->assertSame('HeadObject', $command->getName());
                $this->assertSame('ENABLED', $command['ChecksumMode']);
                $this->assertSame('version-1', $command['VersionId']);

                return new Result([
                    'VersionId' => 'version-1',
                    'ETag' => '"etag-value"',
                    'ContentType' => 'application/pdf',
                    'ContentLength' => strlen('clinical document'),
                    'ChecksumSHA256' => $checksumBase64,
                ]);
            });

        try {
            $storedFile = (new S3FileStorage($client, $this->config()))->upload(
                $sourcePath,
                'production/site/file.pdf',
                'report.pdf',
                'application/pdf'
            );
        } finally {
            unlink($sourcePath);
        }

        $this->assertSame('root/production/site/file.pdf', $storedFile->getKey());
        $this->assertSame('report.pdf', $storedFile->getOriginalFilename());
        $this->assertSame(hash('sha256', 'clinical document'), $storedFile->getChecksumSha256());
        $this->assertSame('etag-value', $storedFile->getEtag());
    }

    public function testUploadUsesConfiguredKmsKey(): void
    {
        $sourcePath = $this->temporaryFile('image');
        $checksumBase64 = base64_encode(hash('sha256', 'image', true));
        $client = $this->client();

        $client->method('getCommand')
            ->willReturnCallback(static fn(string $name, array $arguments): Command => new Command($name, $arguments));
        $client->method('execute')
            ->willReturnCallback(function (CommandInterface $command) use ($checksumBase64): Result {
                if ($command->getName() === 'PutObject') {
                    $this->assertSame('aws:kms', $command['ServerSideEncryption']);
                    $this->assertSame('kms-key-id', $command['SSEKMSKeyId']);

                    return new Result(['ChecksumSHA256' => $checksumBase64]);
                }

                return new Result([
                    'ContentType' => 'image/png',
                    'ContentLength' => strlen('image'),
                    'ChecksumSHA256' => $checksumBase64,
                ]);
            });

        try {
            (new S3FileStorage($client, $this->config('kms-key-id')))->upload(
                $sourcePath,
                'production/site/file.png',
                'image.png',
                'image/png'
            );
        } finally {
            unlink($sourcePath);
        }
    }

    public function testFailedUploadVerificationDeletesUploadedVersion(): void
    {
        $sourcePath = $this->temporaryFile('document');
        $client = $this->client();
        $commands = [];

        $client->method('getCommand')
            ->willReturnCallback(function (string $name, array $arguments) use (&$commands): Command {
                $commands[] = [$name, $arguments];

                return new Command($name, $arguments);
            });
        $client->method('execute')
            ->willReturnOnConsecutiveCalls(
                new Result(['VersionId' => 'version-2', 'ChecksumSHA256' => 'invalid']),
                new Result()
            );

        try {
            $this->expectException(FileStorageException::class);
            $this->expectExceptionMessage('File storage upload verification failed');

            (new S3FileStorage($client, $this->config()))->upload(
                $sourcePath,
                'production/site/file.txt',
                'file.txt',
                'text/plain'
            );
        } finally {
            unlink($sourcePath);
        }

        $this->assertSame('PutObject', $commands[0][0]);
        $this->assertSame('DeleteObject', $commands[1][0]);
        $this->assertSame('version-2', $commands[1][1]['VersionId']);
    }

    public function testCreatesShortLivedInlinePresignedUrl(): void
    {
        $client = $this->client();
        $client->expects($this->once())
            ->method('getCommand')
            ->with(
                'GetObject',
                $this->callback(function (array $arguments): bool {
                    $this->assertSame('root/production/site/file.pdf', $arguments['Key']);
                    $this->assertSame('application/pdf', $arguments['ResponseContentType']);
                    $this->assertStringStartsWith('inline; filename="report.pdf"', $arguments['ResponseContentDisposition']);

                    return true;
                })
            )
            ->willReturn(new Command('GetObject'));
        $client->expects($this->once())
            ->method('createPresignedRequest')
            ->with(
                $this->isInstanceOf(CommandInterface::class),
                $this->callback(function (DateTimeInterface $expires): bool {
                    $remainingSeconds = $expires->getTimestamp() - time();

                    return $remainingSeconds >= 119 && $remainingSeconds <= 120;
                })
            )
            ->willReturn(new Request('GET', 'https://example.test/signed'));

        $url = (new S3FileStorage($client, $this->config()))->createViewUrl(
            'production/site/file.pdf',
            "report.pdf\r\nInjected: value",
            'application/pdf'
        );

        $this->assertSame('https://example.test/signed', $url);
    }

    public function testRejectsUnsafeInlineMimeType(): void
    {
        $this->expectException(FileStorageException::class);
        $this->expectExceptionMessage('File storage view validation failed');

        (new S3FileStorage($this->client(), $this->config()))->createViewUrl(
            'production/site/file.html',
            'file.html',
            'text/html'
        );
    }

    public function testDownloadUsesBinaryMimeTypeForUnknownContent(): void
    {
        $client = $this->client();
        $client->expects($this->once())
            ->method('getCommand')
            ->with(
                'GetObject',
                $this->callback(function (array $arguments): bool {
                    $this->assertSame('application/octet-stream', $arguments['ResponseContentType']);
                    $this->assertStringStartsWith(
                        'attachment; filename="file.bin"',
                        $arguments['ResponseContentDisposition']
                    );

                    return true;
                })
            )
            ->willReturn(new Command('GetObject'));
        $client->method('createPresignedRequest')
            ->willReturn(new Request('GET', 'https://example.test/download'));

        $url = (new S3FileStorage($client, $this->config()))->createDownloadUrl(
            'production/site/file.bin',
            'file.bin',
            'text/html'
        );

        $this->assertSame('https://example.test/download', $url);
    }

    public function testExistsReturnsFalseForMissingObject(): void
    {
        $client = $this->client();
        $client->method('getCommand')->willReturn(new Command('HeadObject'));
        $client->method('execute')->willThrowException(new AwsException(
            'Not found',
            new Command('HeadObject'),
            ['response' => new Response(404)]
        ));

        $this->assertFalse(
            (new S3FileStorage($client, $this->config()))->exists('production/site/missing.pdf')
        );
    }

    public function testDeleteTargetsExactObjectVersion(): void
    {
        $client = $this->client();
        $client->expects($this->once())
            ->method('getCommand')
            ->with('DeleteObject', [
                'Bucket' => 'private-files',
                'Key' => 'root/production/site/file.pdf',
                'VersionId' => 'version-3',
            ])
            ->willReturn(new Command('DeleteObject'));
        $client->expects($this->once())->method('execute')->willReturn(new Result());

        (new S3FileStorage($client, $this->config()))->delete(
            'production/site/file.pdf',
            'version-3'
        );
    }

    public function testCopyVerifiesSourceAndDestinationMetadata(): void
    {
        $checksumBase64 = base64_encode(hash('sha256', 'copy contents', true));
        $client = $this->client();
        $commandNames = [];
        $client->method('getCommand')
            ->willReturnCallback(static fn(string $name, array $arguments): Command => new Command($name, $arguments));
        $client->method('execute')
            ->willReturnCallback(function (CommandInterface $command) use (&$commandNames, $checksumBase64): Result {
                $commandNames[] = $command->getName();
                if ($command->getName() === 'CopyObject') {
                    $this->assertSame(
                        'private-files%2Froot%2Fsource.pdf?versionId=source-version',
                        $command['CopySource']
                    );
                    $this->assertSame('root/destination.pdf', $command['Key']);

                    return new Result(['VersionId' => 'destination-version']);
                }

                return new Result([
                    'VersionId' => $command['Key'] === 'root/source.pdf'
                        ? 'source-version'
                        : 'destination-version',
                    'ContentType' => 'application/pdf',
                    'ContentLength' => strlen('copy contents'),
                    'ChecksumSHA256' => $checksumBase64,
                ]);
            });

        $storedFile = (new S3FileStorage($client, $this->config()))->copy(
            'source.pdf',
            'destination.pdf',
            'source-version'
        );

        $this->assertSame(['HeadObject', 'CopyObject', 'HeadObject'], $commandNames);
        $this->assertSame('root/destination.pdf', $storedFile->getKey());
        $this->assertSame('destination-version', $storedFile->getVersionId());
    }

    public function testExistsWrapsNonAwsErrors(): void
    {
        $client = $this->client();
        $client->method('getCommand')->willReturn(new Command('HeadObject'));
        $client->method('execute')->willThrowException(new \RuntimeException('transport failed'));

        $this->expectException(FileStorageException::class);
        $this->expectExceptionMessage('File storage existence check failed');

        (new S3FileStorage($client, $this->config()))->exists('production/site/file.pdf');
    }

    public function testStorageErrorsDoNotExposeBucketOrKey(): void
    {
        $client = $this->client();
        $client->method('getCommand')->willReturn(new Command('HeadObject'));
        $client->method('execute')->willThrowException(new \RuntimeException(
            'private-files root/production/site/secret.pdf'
        ));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'File storage operation failed',
                $this->callback(function (array $context): bool {
                    $this->assertSame('metadata read', $context['operation']);
                    $this->assertSame(\RuntimeException::class, $context['exception_class']);
                    $this->assertStringNotContainsString('private-files', json_encode($context));
                    $this->assertStringNotContainsString('secret.pdf', json_encode($context));

                    return true;
                })
            );

        try {
            (new S3FileStorage($client, $this->config(), $logger))->getMetadata(
                'production/site/secret.pdf'
            );
            $this->fail('Expected FileStorageException');
        } catch (FileStorageException $exception) {
            $this->assertSame('File storage metadata read failed', $exception->getMessage());
            $this->assertStringNotContainsString('private-files', $exception->getMessage());
            $this->assertStringNotContainsString('secret.pdf', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    /**
     * @return S3ClientInterface&MockObject
     */
    private function client(): S3ClientInterface
    {
        return $this->createMock(S3ClientInterface::class);
    }

    private function config(?string $kmsKeyId = null): FileStorageConfig
    {
        return FileStorageConfig::fromEnvironment([
            'AWS_REGION' => 'us-east-1',
            'AWS_S3_BUCKET' => 'private-files',
            'AWS_S3_PREFIX' => 'root',
            'AWS_S3_KMS_KEY_ID' => $kmsKeyId ?? '',
            'AWS_S3_SIGNED_URL_TTL_SECONDS' => '120',
        ]);
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'openemr-s3-test-');
        if ($path === false) {
            $this->fail('Unable to create test file');
        }

        file_put_contents($path, $contents);

        return $path;
    }
}
