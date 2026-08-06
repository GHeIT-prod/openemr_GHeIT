<?php

/**
 * S3ClientFactory
 *
 * Builds an Aws\S3\S3Client from FileStorageConfig. Credentials are
 * intentionally never read from our own config object — we let the AWS
 * SDK's default provider chain resolve them (environment variables,
 * shared ~/.aws/credentials file, ECS/EC2/EKS instance metadata, or an
 * assumed IAM role), which is what lets the exact same code run
 * unmodified in local Docker (via .env-loaded AWS_ACCESS_KEY_ID /
 * AWS_SECRET_ACCESS_KEY) and in production (via an IAM task role, with
 * no static keys anywhere).
 *
 * @package   OpenEMR\Modules\GheitS3\Services\FileStorage
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

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