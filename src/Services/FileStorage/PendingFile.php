<?php

/**
 * Pending file metadata identity
 *
 * @package OpenEMR
 * @link https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FileStorage;

final class PendingFile
{
    public function __construct(
        private readonly int $id,
        private readonly string $uuid
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }
}
