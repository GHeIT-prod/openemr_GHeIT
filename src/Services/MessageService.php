<?php

/**
 * MessageService
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Matthew Vita <matthewvita48@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2018 Matthew Vita <matthewvita48@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use Particle\Validator\Validator;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class MessageService
{
    public function __construct()
    {
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

    public function s3DocumentHandler($pid, array $input)
    {
        // Placeholder for S3 document handling logic
        // ---------- CONFIG ----------
        $bucket = $_ENV['AWS_BUCKET_NAME'];
        $region = $_ENV['AWS_DEFAULT_REGION'];
        $maxSize = 10 * 1024 * 1024; // 10 MB

        $allowedMimeTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
            'image/jpeg',
            'image/png'
        ];

        $allowedExtensions = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','jpg','jpeg','png'];

        // ---------- INPUT ----------

        if (!$input) {
            http_response_code(400);
            exit(json_encode(['error' => 'Invalid JSON']));
        }

        if (!isset($_FILES['file'])) {
            return ['error' => 'No file uploaded'];
        }

        $file = $_FILES['file'];

        $filename    = $file['name'];
        $contentType = mime_content_type($file['tmp_name']);
        $fileSize    = $file['size'];

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($contentType, $allowedMimeTypes)) {
            http_response_code(415);
            exit(json_encode(['error' => 'Unsupported file type']));
        }

        if (!in_array($extension, $allowedExtensions)) {
            http_response_code(415);
            exit(json_encode(['error' => 'Invalid file extension']));
        }

        if ($fileSize > $maxSize) {
            http_response_code(413);
            exit(json_encode(['error' => 'File too large']));
        }

        // ---------- S3 CLIENT ----------
        $s3 = new S3Client([
            'version' => 'latest',
            'region'  => $region,
            'credentials' => [
                'key'    => $_ENV['AWS_ACCESS_KEY_ID'],
                'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
            ],
        ]);

        // ---------- OBJECT KEY ----------
        $uuid = bin2hex(random_bytes(16));
        $key = "attachments/patients/{$pid}/documents/{$uuid}.{$extension}";

        // ---------- PRESIGNED REQUEST ----------
        try {

            $s3->putObject([
                'Bucket' => $bucket,
                'Key'    => $key,
                'Body'   => fopen($file['tmp_name'], 'r'),
                'ContentType' => mime_content_type($file['tmp_name']),
                'ServerSideEncryption' => 'AES256'
            ]);

            // //View URL (GET)
            $getCmd = $s3->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key' => $key,
            ]);

            $viewRequest = $s3->createPresignedRequest($getCmd, '+60 minutes');
            $viewUrl = (string) $viewRequest->getUri();

            return [
                'view_url'   => $viewUrl,
                's3_key'     => $key
            ];

        } catch (AwsException $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Failed to generate presigned URL',
                'details' => $e->getMessage()
            ]);
        }
    }
}
