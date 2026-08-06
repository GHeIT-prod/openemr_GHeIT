<?php

/**
 * BrandingAssetStorageServiceFactory
 *
 * Wires up S3FileStorage + FileUploadValidator + S3ObjectKeyGenerator for
 * non-patient, app-level assets (branding logos, etc.) that live under the
 * "branding" shared namespace in S3ObjectKeyGenerator::forSharedNamespace().
 * Kept separate from PatientDocumentStorageService/PatientDocumentStorageServiceFactory
 * because this class carries no patient/category/foreign-reference concepts.
 *
 * @package   OpenEMR\Modules\GheitS3\Services\FileStorage
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

use OpenEMR\Common\Uuid\UniqueInstallationUuid;
use OpenEMR\Common\Logging\SystemLogger;

final class BrandingAssetStorageServiceFactory
{
    private static ?S3FileStorage $storage = null;
    private static ?FileUploadValidator $validator = null;
    private static ?S3ObjectKeyGenerator $keyGenerator = null;
    private static ?FileStorageConfig $config = null;

    public static function storage(): S3FileStorage
    {
        return self::$storage ??= new S3FileStorage(
            S3ClientFactory::create(self::config()),
            self::config(),
            new SystemLogger()
        );
    }

    public static function validator(): FileUploadValidator
    {
        return self::$validator ??= new FileUploadValidator(self::config());
    }

    public static function keyGenerator(): S3ObjectKeyGenerator
    {
        return self::$keyGenerator ??= new S3ObjectKeyGenerator();
    }

    public static function config(): FileStorageConfig
    {
        return self::$config ??= FileStorageConfig::fromEnvironment();
    }

    public static function environment(): string
    {
        $environment = strtolower(trim((string)($_ENV['OPENEMR__ENVIRONMENT'] ?? 'prod')));
        return $environment !== '' ? $environment : 'prod';
    }

    public static function siteUuid(): string
    {
        return UniqueInstallationUuid::getUniqueInstallationUuid();
    }
}