<?php

/**
 * Upload validation contract
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

interface FileUploadValidatorInterface
{
    public function validateUploadedFile(array $file, string $kind): ValidatedUpload;

    public function validateUploadedFileAutoKind(array $file): ValidatedUpload;

    public function validateFile(
        string $path,
        string $originalFilename,
        string $kind,
        ?int $declaredSize = null
    ): ValidatedUpload;

    public function kindForFilename(string $filename): string;
}
