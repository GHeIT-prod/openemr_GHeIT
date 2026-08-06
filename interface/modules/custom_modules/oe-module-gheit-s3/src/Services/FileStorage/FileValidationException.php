<?php

/**
 * FileValidationException
 *
 * Thrown by FileUploadValidator when an uploaded file fails size, MIME
 * type, extension, or content-sniffing checks before it is ever handed
 * to the storage driver.
 *
 * @package   OpenEMR\Modules\GheitS3\Services\FileStorage
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

use RuntimeException;

final class FileValidationException extends RuntimeException
{
}
