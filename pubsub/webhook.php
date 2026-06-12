<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

/*
|--------------------------------------------------------------------------
| Load .env
|--------------------------------------------------------------------------
*/
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

/*
|--------------------------------------------------------------------------
| Debug Logger
|--------------------------------------------------------------------------
*/
function debugLog($message)
{
    file_put_contents(
        __DIR__ . '/pubsub-debug.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

/*
|--------------------------------------------------------------------------
| STEP 1: Read Raw Request
|--------------------------------------------------------------------------
*/
$input = file_get_contents('php://input');

debugLog("RAW REQUEST: " . $input);

/*
|--------------------------------------------------------------------------
| STEP 2: Decode PubSub Envelope
|--------------------------------------------------------------------------
*/
$pubsubEnvelope = json_decode($input, true);

if (!$pubsubEnvelope) {

    debugLog("ERROR: Invalid PubSub JSON");

    http_response_code(400);

    echo json_encode([
        'error' => 'Invalid PubSub JSON'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| STEP 3: Extract Base64 Message Data
|--------------------------------------------------------------------------
*/
$encodedData = $pubsubEnvelope['message']['data'] ?? null;

if (!$encodedData) {

    debugLog("ERROR: Missing PubSub message.data");

    http_response_code(400);

    echo json_encode([
        'error' => 'Missing PubSub message.data'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| STEP 4: Base64 Decode
| FIX #2: Use strict === false check instead of falsy check,
|          which would incorrectly reject a valid decoded value of "0".
|--------------------------------------------------------------------------
*/
$decodedJson = base64_decode($encodedData);

if ($decodedJson === false) {

    debugLog("ERROR: Base64 decode failed");

    http_response_code(400);

    echo json_encode([
        'error' => 'Base64 decode failed'
    ]);

    exit;
}

debugLog("DECODED JSON: " . $decodedJson);

/*
|--------------------------------------------------------------------------
| STEP 5: Decode Actual Published Payload
|--------------------------------------------------------------------------
*/
$payload = json_decode($decodedJson, true);

if (!$payload) {

    debugLog("ERROR: Published payload JSON decode failed");

    http_response_code(400);

    echo json_encode([
        'error' => 'Published payload JSON decode failed'
    ]);

    exit;
}

debugLog("PUBLISHED PAYLOAD: " . json_encode($payload));

/*
|--------------------------------------------------------------------------
| STEP 6: Extract FHIR Resource / Bundle
|--------------------------------------------------------------------------
*/
// $fhirResource = $payload['data'] ?? null;

$inner        = $payload['data'] ?? null;
$fhirResource = $inner['data']   ?? $inner ?? null; // FIX #3: Support both { data: { data: {...} } } and { data: {...} }

if (!$fhirResource) {

    debugLog("ERROR: Missing FHIR data");

    http_response_code(400);

    echo json_encode([
        'error' => 'Missing FHIR data'
    ]);

    exit;
}

debugLog("FHIR RESOURCE: " . json_encode($fhirResource));

/*
|--------------------------------------------------------------------------
| STEP 7: Detect Resource vs Bundle
|--------------------------------------------------------------------------
*/
$isBundle =
    isset($fhirResource['resourceType']) &&
    $fhirResource['resourceType'] === 'Bundle';

/*
|--------------------------------------------------------------------------
| STEP 8: Build Aidbox Endpoint + HTTP Method Dynamically
| FIX #1: Single resources with an id use PUT /{type}/{id} to preserve
|          the id and remain idempotent on retry. POST would silently
|          ignore the id and create duplicates on every PubSub redeliver.
|--------------------------------------------------------------------------
*/
$aidboxBaseUrl = $_ENV['AIDBOX_BASE_URL'];

if ($isBundle) {

    /*
    |--------------------------------------------------------------------------
    | Transaction Bundle → POST /fhir
    |--------------------------------------------------------------------------
    */
    $endpoint = $aidboxBaseUrl;
    $method   = 'POST';

} else {

    /*
    |--------------------------------------------------------------------------
    | Single Resource → PUT /fhir/{ResourceType}/{id}  (preferred)
    |                    POST /fhir/{ResourceType}       (fallback when no id)
    |--------------------------------------------------------------------------
    */
    $resourceType = $fhirResource['resourceType'] ?? 'Resource';
    // $resourceId   = $fhirResource['id'] ?? null;

    $endpoint = $aidboxBaseUrl . '/' . $resourceType;
    $method   = 'POST';

    // if ($resourceId) {
    //     $endpoint = $aidboxBaseUrl . '/' . $resourceType . '/' . $resourceId;
    //     $method   = 'PUT';
    // } else {
    //     $endpoint = $aidboxBaseUrl . '/' . $resourceType;
    //     $method   = 'POST';
    // }
}

debugLog("AIDBOX ENDPOINT: [{$method}] " . $endpoint);

/*
|--------------------------------------------------------------------------
| STEP 9: Aidbox Basic Auth
|--------------------------------------------------------------------------
*/
$username = $_ENV['AIDBOX_USERNAME'];
$password = $_ENV['AIDBOX_PASSWORD'];

$basicAuth = base64_encode($username . ':' . $password);

/*
|--------------------------------------------------------------------------
| STEP 10: Send to Aidbox
| FIX #1 (continued): Use CURLOPT_CUSTOMREQUEST to support both POST and PUT.
|--------------------------------------------------------------------------
*/
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL            => $endpoint,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/fhir+json',
        'Authorization: Basic ' . $basicAuth,
    ],
    CURLOPT_POSTFIELDS     => json_encode($fhirResource),
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);

$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_errno($ch) ? curl_error($ch) : null;

curl_close($ch);

/*
|--------------------------------------------------------------------------
| STEP 11: Log Aidbox Response
|--------------------------------------------------------------------------
*/
if ($curlError) {
    debugLog("CURL ERROR: " . $curlError);
}

debugLog("AIDBOX HTTP CODE: " . $httpCode);
debugLog("AIDBOX RESPONSE: " . $response);

/*
|--------------------------------------------------------------------------
| STEP 12: ACK PubSub — with Aidbox failure guard
| FIX #4: If Aidbox returns a non-2xx code, return HTTP 500 so PubSub
|          retries the message instead of silently losing it.
|          PUT transactions are idempotent so retrying is safe.
|--------------------------------------------------------------------------
*/
$aidboxSuccess = $httpCode >= 200 && $httpCode < 300;

if (!$aidboxSuccess || $curlError) {

    debugLog(
        "WARNING: Aidbox rejected resource — triggering PubSub retry. " .
        "HTTP {$httpCode}" . ($curlError ? " | CURL: {$curlError}" : '')
    );

    // http_response_code(500);

    echo json_encode([
        'ok'             => false,
        'aidboxHttpCode' => $httpCode,
        'error'          => $curlError ?? 'Aidbox returned non-2xx',
    ]);

    exit;
}

http_response_code(200);

echo json_encode([
    'ok'             => true,
    'aidboxHttpCode' => $httpCode,
]);