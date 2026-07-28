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
        $clientConfig = [
            'version' => 'latest',
            'region' => $config->getRegion(),
        ];

        $environment = $_ENV;
        $accessKeyId = trim((string)($environment['AWS_ACCESS_KEY_ID'] ?? ''));
        $secretAccessKey = trim((string)($environment['AWS_SECRET_ACCESS_KEY'] ?? ''));
        if ($accessKeyId !== '' && $secretAccessKey !== '') {
            $credentials = [
                'key' => $accessKeyId,
                'secret' => $secretAccessKey,
            ];
            $sessionToken = trim((string)($environment['AWS_SESSION_TOKEN'] ?? ''));
            if ($sessionToken !== '') {
                $credentials['token'] = $sessionToken;
            }
            $clientConfig['credentials'] = $credentials;
        }

        return new S3Client($clientConfig);
    }
}
