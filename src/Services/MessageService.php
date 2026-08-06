<?php

/**
 * MessageService
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Matthew Vita <matthewvita48@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2018 Matthew Vita <matthewvita48@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\GheitS3\Services\FileStorage\FileStorageException;
use OpenEMR\Modules\GheitS3\Services\FileStorage\FileValidationException;
use OpenEMR\Modules\GheitS3\Services\FileStorage\MessageAttachmentStorageService;
use Particle\Validator\Validator;

class MessageService
{
    private ?MessageAttachmentStorageService $attachmentStorage = null;

    public function __construct(?MessageAttachmentStorageService $attachmentStorage = null)
    {
        $this->attachmentStorage = $attachmentStorage;
    }

    public function validate($message)
    {
        $validator = new Validator();

        $validator->required('body')->lengthBetween(2, 65535);
        $validator->required('to')->lengthBetween(2, 255);
        $validator->required('from')->lengthBetween(2, 255);
        $validator->required('groupname')->lengthBetween(2, 255);
        $validator->required('title')->lengthBetween(2, 255);
        $validator->required('message_status')->lengthBetween(2, 20);

        return $validator->validate($message);
    }

    public function getFormattedMessageBody($from, $to, $body)
    {
        return "\n" . date("Y-m-d H:i") . " (" . $from . " to " . $to . ") " . $body;
    }

    public function insert($pid, $data)
    {
        $sql  = " INSERT INTO pnotes SET";
        $sql .= "     date=NOW(),";
        $sql .= "     activity=1,";
        $sql .= "     authorized=1,";
        $sql .= "     body=?,";
        $sql .= "     pid=?,";
        $sql .= "     groupname=?,";
        $sql .= "     user=?,";
        $sql .= "     assigned_to=?,";
        $sql .= "     message_status=?,";
        $sql .= "     title=?";

        $results = sqlInsert(
            $sql,
            [
                $this->getFormattedMessageBody($data["from"], $data["to"], $data["body"]),
                $pid,
                $data['groupname'],
                $data['from'],
                $data['to'],
                $data['message_status'],
                $data['title']
            ]
        );

        if (!$results) {
            return false;
        }

        return $results;
    }

    public function update($pid, $mid, $data)
    {
        $existingBody = sqlQuery("SELECT body FROM pnotes WHERE pid = ? AND id = ?", [$pid, $mid]);

        $sql  = " UPDATE pnotes SET";
        $sql .= "     body=?,";
        $sql .= "     groupname=?,";
        $sql .= "     user=?,";
        $sql .= "     assigned_to=?,";
        $sql .= "     message_status=?,";
        $sql .= "     title=?";
        $sql .= "     WHERE pid=? AND id=?";

        $results = sqlStatement(
            $sql,
            [
                $existingBody["body"] . $this->getFormattedMessageBody($data["from"], $data["to"], $data["body"]),
                $data['groupname'],
                $data['from'],
                $data['to'],
                $data['message_status'],
                $data['title'],
                $pid,
                $mid
            ]
        );

        if (!$results) {
            return false;
        }

        return $results;
    }

    public function delete($pid, $mid)
    {
        $sql = "UPDATE pnotes SET deleted=1 WHERE pid=? AND id=?";

        return sqlStatement($sql, [$pid, $mid]);
    }

    /**
     * @return array<string, mixed>
     */
    public function s3DocumentHandler($pid, array $input)
    {
        unset($input);

        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            return ['error' => 'No file uploaded'];
        }

        try {
            $session = SessionWrapperFactory::getInstance()->getWrapper();
            $ownerId = (int)$session->get('authUserID');

            return $this->attachmentStorage()->upload((int)$pid, $_FILES['file'], $ownerId);
        } catch (FileValidationException $exception) {
            return ['error' => 'Unsupported file type'];
        } catch (FileStorageException $exception) {
            return ['error' => 'Failed to store attachment'];
        } catch (\Throwable $exception) {
            return ['error' => 'Failed to store attachment'];
        }
    }

    private function attachmentStorage(): MessageAttachmentStorageService
    {
        if ($this->attachmentStorage === null) {
            $this->attachmentStorage = $GLOBALS['kernel']
                ->getContainer()
                ->get(MessageAttachmentStorageService::class);
        }

        return $this->attachmentStorage;
    }
}
