<?php

/**
 * Permanent file storage contract
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

interface FileStorageInterface
{
    public function upload(
        string $sourcePath,
        string $key,
        string $originalFilename,
        string $mimeType
    ): StoredFile;

    public function exists(string $key, ?string $versionId = null): bool;

    public function getMetadata(string $key, ?string $versionId = null): StoredFile;

    public function createViewUrl(
        string $key,
        string $filename,
        string $mimeType,
        ?string $versionId = null
    ): string;

    public function createDownloadUrl(
        string $key,
        string $filename,
        string $mimeType,
        ?string $versionId = null
    ): string;

    public function delete(string $key, ?string $versionId = null): void;

    public function copy(string $sourceKey, string $destinationKey, ?string $sourceVersionId = null): StoredFile;

    public function move(string $sourceKey, string $destinationKey, ?string $sourceVersionId = null): StoredFile;
}
