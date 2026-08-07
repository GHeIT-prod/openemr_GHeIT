<?php

/**
 * FileMetadataException
 *
 * Thrown when the file_storage metadata row cannot be written, read, or
 * reconciled with the object actually present in the storage backend.
 *
 * @package   OpenEMR\Modules\GheitS3\Services\FileStorage
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

use RuntimeException;

final class FileMetadataException extends RuntimeException
{
}
