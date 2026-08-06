<?php

/**
 * StoredFile
 *
 * Immutable value object returned by a FileStorageInterface implementation
 * after a successful write. Carries everything the metadata layer needs
 * to persist a file_storage row without the caller having to know the
 * driver-specific details (bucket name, key layout, etc).
 *
 * @package   OpenEMR\Modules\GheitS3\Services\FileStorage
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

final class StoredFile
{
    public function __construct(
        private readonly string $bucket,
        private readonly string $key,
        private readonly ?string $versionId,
        private readonly ?string $etag,
        private readonly ?string $originalFilename,
        private readonly string $mimeType,
        private readonly int $size,
        private readonly ?string $checksumSha256
    ) {
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getVersionId(): ?string
    {
        return $this->versionId;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function getOriginalFilename(): ?string
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

    public function getChecksumSha256(): ?string
    {
        return $this->checksumSha256;
    }
}