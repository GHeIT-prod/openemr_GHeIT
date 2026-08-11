<?php

require_once(__DIR__ . '/../globals.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\GheitS3\Services\FileStorage\BrandingAssetStorageServiceFactory;
use OpenEMR\Modules\GheitS3\Services\FileStorage\FileStorageException;
use OpenEMR\Modules\GheitS3\Services\FileStorage\FileUploadValidator;
use OpenEMR\Modules\GheitS3\Services\FileStorage\FileValidationException;
use Ramsey\Uuid\Uuid;

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed.";
    exit;
}

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo "Not authorized.";
    exit;
}

if (empty($_POST['csrf_token_form']) || !CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'], 'default')) {
    http_response_code(400);
    echo "Invalid CSRF token.";
    exit;
}

// Must match, exactly, whatever appName the SMART client is registered
// under — smart_launch.html.twig looks logos up by client.getName().
$appName = trim((string)($_POST['appName'] ?? ''));
if ($appName === '' || preg_match('/[\/\\\\\0]/', $appName)) {
    http_response_code(400);
    echo "A valid appName is required.";
    exit;
}

try {
    $validated = BrandingAssetStorageServiceFactory::validator()->validateUploadedFile(
        $_FILES['file'] ?? [],
        FileUploadValidator::KIND_IMAGE
    );

    if ($validated->getExtension() !== 'png') {
        http_response_code(400);
        echo "File Extension .png required";
        exit;
    }

    $key = BrandingAssetStorageServiceFactory::keyGenerator()->forSharedNamespace(
        BrandingAssetStorageServiceFactory::environment(),
        BrandingAssetStorageServiceFactory::siteUuid(),
        'branding',
        Uuid::uuid4()->toString(),
        $validated->getExtension()
    );

    $storedFile = BrandingAssetStorageServiceFactory::storage()->upload(
        $validated->getPath(),
        $key,
        $validated->getOriginalFilename(),
        $validated->getMimeType()
    );

    sqlStatement(
        "REPLACE INTO globals SET gl_name = ?, gl_value = ?",
        [
            'gheit_s3_smart_logo_' . $appName,
            json_encode([
                'key' => $storedFile->getKey(),
                'version_id' => $storedFile->getVersionId(),
                'mimetype' => $storedFile->getMimeType(),
            ]),
        ]
    );

    echo "File uploaded successfully.";
} catch (FileValidationException $e) {
    http_response_code(400);
    echo "File Extension .png required";
} catch (FileStorageException $e) {
    (new SystemLogger())->error('Smart logo upload failed', ['exception' => $e->getMessage()]);
    http_response_code(500);
    echo "Failed to upload file.";
}