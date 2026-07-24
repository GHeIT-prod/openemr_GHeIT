<?php

/**
 * Validated local upload metadata
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

final class ValidatedUpload
{
    public function __construct(
        private readonly string $path,
        private readonly string $originalFilename,
        private readonly string $mimeType,
        private readonly int $size,
        private readonly string $extension,
        private readonly string $kind
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function getKind(): string
    {
        return $this->kind;
    }
}
