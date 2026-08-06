<?php

/**
 * FileStorageException
 *
 * Base runtime exception for the S3 file storage subsystem. Thrown for any
 * low-level storage-driver failure (connection, permissions, missing key,
 * upstream S3 error, etc). Callers that need to distinguish validation
 * failures should catch FileValidationException instead, and metadata
 * persistence failures should catch FileMetadataException instead — both
 * extend this class so a broad catch (FileStorageException $e) still works.
 *
 * @package   OpenEMR\Modules\GheitS3\Services\FileStorage
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

use RuntimeException;

final class FileStorageException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self('File storage ' . $operation . ' failed');
    }
}
