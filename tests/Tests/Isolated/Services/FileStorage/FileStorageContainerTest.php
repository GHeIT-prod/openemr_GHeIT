<?php

/**
 * File storage container wiring test
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\FileStorage;

use OpenEMR\Core\Kernel;
use OpenEMR\Services\FileStorage\FileStorageInterface;
use OpenEMR\Services\FileStorage\S3FileStorage;
use PHPUnit\Framework\TestCase;

final class FileStorageContainerTest extends TestCase
{
    public function testContainerProvidesS3StorageImplementation(): void
    {
        $originalEnvironment = $_ENV;
        $_ENV['AWS_REGION'] = 'us-east-1';
        $_ENV['AWS_S3_BUCKET'] = 'private-files';

        try {
            $storage = (new Kernel())->getContainer()->get(FileStorageInterface::class);
        } finally {
            $_ENV = $originalEnvironment;
        }

        $this->assertInstanceOf(S3FileStorage::class, $storage);
    }
}
