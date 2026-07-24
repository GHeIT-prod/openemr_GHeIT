<?php

/**
 * Permanent file storage failure
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

use RuntimeException;

final class FileStorageException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self('File storage ' . $operation . ' failed');
    }
}
