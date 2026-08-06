<?php

require_once(__DIR__ . '/../globals.php');

use OpenEMR\Modules\GheitS3\Services\FileStorage\BrandingAssetStorageServiceFactory;
use OpenEMR\Modules\GheitS3\Services\FileStorage\FileStorageException;

$appName = trim((string)($_GET['appName'] ?? ''));
if ($appName === '') {
    http_response_code(400);
    exit;
}

$row = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = ?", ['gheit_s3_smart_logo_' . $appName]);

if (empty($row['gl_value'])) {
    http_response_code(404);
    exit;
}

$pointer = json_decode($row['gl_value'], true);
if (empty($pointer['key'])) {
    http_response_code(404);
    exit;
}

// A re-uploaded logo gets a new version_id, so this etag is safe to
// cache aggressively without going stale.
$etag = '"' . md5((string)$pointer['key'] . (string)($pointer['version_id'] ?? '')) . '"';
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

$temporaryPath = tempnam($GLOBALS['temporary_files_dir'] ?? sys_get_temp_dir(), 'logo');

try {
    BrandingAssetStorageServiceFactory::storage()->downloadToPath(
        $pointer['key'],
        $temporaryPath,
        $pointer['version_id'] ?? null
    );

    header('Content-Type: ' . ($pointer['mimetype'] ?? 'image/png'));
    header('Cache-Control: private, max-age=86400');
    header('ETag: ' . $etag);
    header('Content-Length: ' . filesize($temporaryPath));
    readfile($temporaryPath);
} catch (FileStorageException $e) {
    http_response_code(404);

} finally {
    if (is_file($temporaryPath)) {
        unlink($temporaryPath);
    }
}