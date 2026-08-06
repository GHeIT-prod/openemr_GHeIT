<?php

/**
 * FileUploadValidator
 *
 * Centralized, driver-agnostic upload validation. This runs identically
 * whether the file arrived via the legacy patient-documents multipart
 * form, the REST DocumentRestController, a message attachment, or an
 * inbound fax download — so a MIME/extension/size policy tightened here
 * applies everywhere at once instead of being duplicated (and drifting)
 * across each entry point.
 *
 * Checks performed, in order:
 *   1. Reject empty uploads (size === 0)
 *   2. Reject uploads over the category's configured max size
 *   3. Reject extensions not on the category allow-list
 *   4. Sniff the real MIME type from file content (fileinfo/finfo), not
 *      the client-supplied Content-Type header, and reject if it is not
 *      on the category allow-list
 *   5. Reject a small set of always-dangerous extensions regardless of
 *      category, as defense in depth against MIME-sniffing gaps
 *
 * @package   OpenEMR\Modules\GheitS3\Services\FileStorage
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitS3\Services\FileStorage;

use finfo;
use ZipArchive;

final class FileUploadValidator implements FileUploadValidatorInterface
{
    public const KIND_IMAGE = 'images';
    public const KIND_PDF = 'pdfs';
    public const KIND_DOCUMENT = 'documents';
    public const KIND_VIDEO = 'videos';

    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'text/plain' => ['txt', 'csv'],
        'text/csv' => ['csv'],
        'video/mp4' => ['mp4'],
        'video/webm' => ['webm'],
    ];

    public function __construct(private readonly FileStorageConfig $config)
    {
    }

    public function validateUploadedFile(array $file, string $kind): ValidatedUpload
    {
        if (
            ($file['error'] ?? null) !== UPLOAD_ERR_OK
            || !isset($file['tmp_name'], $file['name'])
            || !is_string($file['tmp_name'])
            || !is_string($file['name'])
            || !is_uploaded_file($file['tmp_name'])
        ) {
            throw new FileValidationException('Invalid HTTP file upload');
        }

        $declaredSize = isset($file['size']) && is_numeric($file['size'])
            ? (int)$file['size']
            : null;

        return $this->validateFile($file['tmp_name'], $file['name'], $kind, $declaredSize);
    }

    public function validateUploadedFileAutoKind(array $file): ValidatedUpload
    {
        if (!isset($file['name']) || !is_string($file['name'])) {
            throw new FileValidationException('Invalid HTTP file upload');
        }

        return $this->validateUploadedFile($file, $this->kindForFilename($file['name']));
    }

    public function kindForFilename(string $filename): string
    {
        $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, $this->config->getAllowedImageExtensions(), true)) {
            return self::KIND_IMAGE;
        }
        if (in_array($extension, $this->config->getAllowedPdfExtensions(), true)) {
            return self::KIND_PDF;
        }
        if (in_array($extension, $this->config->getAllowedVideoExtensions(), true)) {
            return self::KIND_VIDEO;
        }
        if (in_array($extension, $this->config->getAllowedDocumentExtensions(), true)) {
            return self::KIND_DOCUMENT;
        }

        throw new FileValidationException('Uploaded file extension is not allowed');
    }

    public function validateFile(
        string $path,
        string $originalFilename,
        string $kind,
        ?int $declaredSize = null
    ): ValidatedUpload {
        if (!is_file($path) || !is_readable($path)) {
            throw new FileValidationException('Uploaded file is not readable');
        }

        $this->validateFilename($originalFilename);
        [$maximumBytes, $allowedMimeTypes, $allowedExtensions] = $this->categoryRules($kind);
        $size = filesize($path);
        if ($size === false || $size < 1) {
            throw new FileValidationException('Uploaded file is empty');
        }
        if ($size > $maximumBytes || ($declaredSize !== null && $declaredSize !== $size)) {
            throw new FileValidationException('Uploaded file size is invalid');
        }

        $extension = strtolower((string)pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new FileValidationException('Uploaded file extension is not allowed');
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $mimeType = is_string($mimeType)
            ? $this->normalizeOfficeMimeType($path, $extension, $mimeType)
            : false;
        if (!is_string($mimeType) || !in_array($mimeType, $allowedMimeTypes, true)) {
            throw new FileValidationException('Uploaded file type is not allowed');
        }

        if (
            isset(self::MIME_EXTENSIONS[$mimeType])
            && !in_array($extension, self::MIME_EXTENSIONS[$mimeType], true)
        ) {
            throw new FileValidationException('Uploaded file extension does not match its content');
        }

        if ($kind === self::KIND_IMAGE) {
            $imageSize = getimagesize($path);
            if ($imageSize === false || $imageSize[0] < 1 || $imageSize[1] < 1) {
                throw new FileValidationException('Uploaded image is invalid');
            }
        }

        if ($kind === self::KIND_PDF) {
            $handle = fopen($path, 'rb');
            $header = $handle === false ? false : fread($handle, 1024);
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!is_string($header) || !preg_match('/\A[\x09\x0A\x0C\x0D\x20]*%PDF-/', $header)) {
                throw new FileValidationException('Uploaded PDF is invalid');
            }
        }

        return new ValidatedUpload(
            $path,
            $originalFilename,
            $mimeType,
            $size,
            $extension,
            $kind
        );
    }

    private function validateFilename(string $filename): void
    {
        if (
            $filename === ''
            || strlen($filename) > 255
            || basename(str_replace('\\', '/', $filename)) !== $filename
            || preg_match('/[\x00-\x1F\x7F]/', $filename)
            || preg_match('/\p{Cf}/u', $filename)
        ) {
            throw new FileValidationException('Uploaded filename is invalid');
        }

        if (preg_match('/\.(php\d*|phtml|phar|exe|js|html?|sh|bat|cmd|com|scr|msi|jar|ps1|vbs|py|pl|cgi)\.[^.]+$/i', $filename)) {
            throw new FileValidationException('Double-extension filenames are not allowed');
        }
    }

    private function categoryRules(string $kind): array
    {
        return match ($kind) {
            self::KIND_IMAGE => [
                $this->config->getMaxImageBytes(),
                $this->config->getAllowedImageMimeTypes(),
                $this->config->getAllowedImageExtensions(),
            ],
            self::KIND_PDF => [
                $this->config->getMaxPdfBytes(),
                $this->config->getAllowedPdfMimeTypes(),
                $this->config->getAllowedPdfExtensions(),
            ],
            self::KIND_DOCUMENT => [
                $this->config->getMaxDocumentBytes(),
                $this->config->getAllowedDocumentMimeTypes(),
                $this->config->getAllowedDocumentExtensions(),
            ],
            self::KIND_VIDEO => [
                $this->config->getMaxVideoBytes(),
                $this->config->getAllowedVideoMimeTypes(),
                $this->config->getAllowedVideoExtensions(),
            ],
            default => throw new FileValidationException('Unknown upload category'),
        };
    }

    private function normalizeOfficeMimeType(string $path, string $extension, string $detectedMimeType): string
    {
        if ($extension === 'docx' || $extension === 'xlsx') {
            if (!class_exists(ZipArchive::class)) {
                return $detectedMimeType;
            }
            $archive = new ZipArchive();
            if ($archive->open($path) !== true) {
                return $detectedMimeType;
            }

            $requiredPart = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            $isExpectedPackage = $archive->locateName('[Content_Types].xml') !== false
                && $archive->locateName($requiredPart) !== false;
            $archive->close();

            if ($isExpectedPackage) {
                return $extension === 'docx'
                    ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }

            return $detectedMimeType;
        }

        if ($extension === 'doc' || $extension === 'xls') {
            $handle = fopen($path, 'rb');
            if ($handle === false || fread($handle, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                return $detectedMimeType;
            }

            $streamName = $extension === 'doc'
                ? "W\0o\0r\0d\0D\0o\0c\0u\0m\0e\0n\0t\0"
                : "W\0o\0r\0k\0b\0o\0o\0k\0";
            rewind($handle);
            $isExpectedDocument = $this->streamContains($handle, $streamName);
            fclose($handle);
            if ($isExpectedDocument) {
                return $extension === 'doc'
                    ? 'application/msword'
                    : 'application/vnd.ms-excel';
            }
        }

        return $detectedMimeType;
    }

    /**
     * @param resource $handle
     */
    private function streamContains($handle, string $needle): bool
    {
        $overlap = '';
        $overlapLength = strlen($needle) - 1;
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                return false;
            }
            $buffer = $overlap . $chunk;
            if (str_contains($buffer, $needle)) {
                return true;
            }
            $overlap = substr($buffer, -$overlapLength);
        }

        return false;
    }
}