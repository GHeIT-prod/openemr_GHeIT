<?php

/**
 * PendingFile
 *
 * Immutable value object describing a file before it has been validated
 * or written to storage. Wraps either a local temp path (the common case
 * for $_FILES uploads) or an in-memory stream/resource, so callers coming
 * from very different entry points (legacy multipart form post, REST API
 * base64 payload, inbound fax media download) can all funnel through the
 * same validation + storage pipeline.
 *
 * @package   OpenEMR\Modules\GheitS3\Services\FileStorage
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

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