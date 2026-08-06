<?php

/**
 * S3 object key generator tests
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\GheitS3\Tests\Isolated\Services\FileStorage;

use InvalidArgumentException;
use OpenEMR\Modules\GheitS3\Services\FileStorage\S3ObjectKeyGenerator;
use PHPUnit\Framework\TestCase;

final class S3ObjectKeyGeneratorTest extends TestCase
{
    private const SITE_UUID = '11111111-1111-4111-8111-111111111111';
    private const PATIENT_UUID = '22222222-2222-4222-8222-222222222222';
    private const ENCOUNTER_UUID = '33333333-3333-4333-8333-333333333333';
    private const FILE_UUID = '44444444-4444-4444-8444-444444444444';
    private const PARENT_UUID = '55555555-5555-4555-8555-555555555555';

    private S3ObjectKeyGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new S3ObjectKeyGenerator();
    }

    public function testGeneratesPatientGeneralKeyWithoutPhi(): void
    {
        $key = $this->generator->forPatient(
            'production',
            self::SITE_UUID,
            self::PATIENT_UUID,
            'pdfs',
            self::FILE_UUID,
            '.PDF'
        );

        $this->assertSame(
            'production/' . self::SITE_UUID . '/patients/' . self::PATIENT_UUID
                . '/general/pdfs/' . self::FILE_UUID . '.pdf',
            $key
        );
        $this->assertStringNotContainsString('patient-name', $key);
    }

    public function testGeneratesEncounterScopedPatientKey(): void
    {
        $key = $this->generator->forPatient(
            'staging',
            self::SITE_UUID,
            self::PATIENT_UUID,
            'images',
            self::FILE_UUID,
            'jpg',
            self::ENCOUNTER_UUID
        );

        $this->assertSame(
            'staging/' . self::SITE_UUID . '/patients/' . self::PATIENT_UUID
                . '/encounters/' . self::ENCOUNTER_UUID . '/images/' . self::FILE_UUID . '.jpg',
            $key
        );
    }

    public function testGeneratesSharedAndDerivativeKeys(): void
    {
        $this->assertSame(
            'production/' . self::SITE_UUID . '/reports/' . self::FILE_UUID . '.pdf',
            $this->generator->forSharedNamespace(
                'production',
                self::SITE_UUID,
                'reports',
                self::FILE_UUID,
                'pdf'
            )
        );
        $this->assertSame(
            'production/' . self::SITE_UUID . '/derivatives/' . self::PARENT_UUID
                . '/' . self::FILE_UUID . '.webp',
            $this->generator->forDerivative(
                'production',
                self::SITE_UUID,
                self::PARENT_UUID,
                self::FILE_UUID,
                'webp'
            )
        );
    }

    public function testRejectsNumericPatientIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid storage UUID');

        $this->generator->forPatient(
            'production',
            self::SITE_UUID,
            '12345',
            'documents',
            self::FILE_UUID,
            'docx'
        );
    }

    public function testRejectsUnsafeExtension(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid storage file extension');

        $this->generator->forPatient(
            'production',
            self::SITE_UUID,
            self::PATIENT_UUID,
            'documents',
            self::FILE_UUID,
            'pdf.exe'
        );
    }

    public function testRejectsUnapprovedNamespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid storage namespace');

        $this->generator->forSharedNamespace(
            'production',
            self::SITE_UUID,
            'patient-name',
            self::FILE_UUID,
            'pdf'
        );
    }
}