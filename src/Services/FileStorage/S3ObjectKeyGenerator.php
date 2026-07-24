<?php

/**
 * Generates protected-information-free S3 object keys
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final class S3ObjectKeyGenerator
{
    private const PATIENT_KINDS = ['images', 'videos', 'pdfs', 'documents', 'dicom', 'archives', 'other'];
    private const SHARED_NAMESPACES = ['branding', 'billing', 'reports', 'exports', 'communications'];

    public function forPatient(
        string $environment,
        string $siteUuid,
        string $patientUuid,
        string $kind,
        string $fileUuid,
        string $extension,
        ?string $encounterUuid = null
    ): string {
        self::assertAllowed($kind, self::PATIENT_KINDS, 'file kind');
        $segments = [
            self::environment($environment),
            self::uuid($siteUuid),
            'patients',
            self::uuid($patientUuid),
        ];

        if ($encounterUuid === null) {
            $segments[] = 'general';
        } else {
            $segments[] = 'encounters';
            $segments[] = self::uuid($encounterUuid);
        }

        $segments[] = $kind;
        $segments[] = self::filename($fileUuid, $extension);

        return implode('/', $segments);
    }

    public function forUser(
        string $environment,
        string $siteUuid,
        string $userUuid,
        string $kind,
        string $fileUuid,
        string $extension
    ): string {
        self::assertAllowed($kind, self::PATIENT_KINDS, 'file kind');

        return implode('/', [
            self::environment($environment),
            self::uuid($siteUuid),
            'users',
            self::uuid($userUuid),
            $kind,
            self::filename($fileUuid, $extension),
        ]);
    }

    public function forOrganization(
        string $environment,
        string $siteUuid,
        string $organizationUuid,
        string $kind,
        string $fileUuid,
        string $extension
    ): string {
        self::assertAllowed($kind, self::PATIENT_KINDS, 'file kind');

        return implode('/', [
            self::environment($environment),
            self::uuid($siteUuid),
            'organizations',
            self::uuid($organizationUuid),
            $kind,
            self::filename($fileUuid, $extension),
        ]);
    }

    public function forSharedNamespace(
        string $environment,
        string $siteUuid,
        string $namespace,
        string $fileUuid,
        string $extension
    ): string {
        self::assertAllowed($namespace, self::SHARED_NAMESPACES, 'namespace');

        return implode('/', [
            self::environment($environment),
            self::uuid($siteUuid),
            $namespace,
            self::filename($fileUuid, $extension),
        ]);
    }

    public function forDerivative(
        string $environment,
        string $siteUuid,
        string $parentFileUuid,
        string $fileUuid,
        string $extension
    ): string {
        return implode('/', [
            self::environment($environment),
            self::uuid($siteUuid),
            'derivatives',
            self::uuid($parentFileUuid),
            self::filename($fileUuid, $extension),
        ]);
    }

    private static function environment(string $environment): string
    {
        $environment = strtolower(trim($environment));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $environment)) {
            throw new InvalidArgumentException('Invalid storage environment');
        }

        return $environment;
    }

    private static function uuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));
        if (!Uuid::isValid($uuid)) {
            throw new InvalidArgumentException('Invalid storage UUID');
        }

        return $uuid;
    }

    private static function filename(string $fileUuid, string $extension): string
    {
        $extension = strtolower(ltrim(trim($extension), '.'));
        if (!preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            throw new InvalidArgumentException('Invalid storage file extension');
        }

        return self::uuid($fileUuid) . '.' . $extension;
    }

    private static function assertAllowed(string $value, array $allowed, string $label): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Invalid storage ' . $label);
        }
    }
}
