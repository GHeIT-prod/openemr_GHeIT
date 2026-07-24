<?php

/**
 * Creates the application S3 client
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;

final class S3ClientFactory
{
    public static function create(FileStorageConfig $config): S3ClientInterface
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $config->getRegion(),
        ]);
    }
}
