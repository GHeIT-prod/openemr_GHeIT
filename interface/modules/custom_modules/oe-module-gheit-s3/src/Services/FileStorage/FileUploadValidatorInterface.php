<?php

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

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
