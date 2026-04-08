<?php

/**
 * Rest Dispatch
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Matthew Vita <matthewvita48@gmail.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Matthew Vita <matthewvita48@gmail.com>
 * @copyright Copyright (c) 2020 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2019-2020 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// below brings in autoloader
// require_once "../vendor/autoload.php";

// use OpenEMR\Common\Http\HttpRestRequest;
// use OpenEMR\RestControllers\ApiApplication;
// use Symfony\Component\HttpFoundation\Response;

// // create the Request object
// try {
//     $request = HttpRestRequest::createFromGlobals();
//     $apiApplication = new ApiApplication();
//     $apiApplication->run($request);
// } catch (\Throwable $e) {
//     // should never reach here, but if we do, we can log the error and return a generic error response
//     // we manually handle it as we don't know if something failed in the symfony component or in our code
//     error_log($e->getMessage());
//     error_log($e->getTraceAsString());
//     // should never get here, but if we do, we can return a generic error response
//     if (!headers_sent()) {
//         header('Content-Type: application/json');
//         http_response_code(Response::HTTP_INTERNAL_SERVER_ERROR);
//     }
//     die(json_encode([
//         'error' => 'An error occurred while processing the request.',
//         'message' => $e->getMessage(),
//     ]));
// }


require_once "../vendor/autoload.php";

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\RestControllers\ApiApplication;
use Symfony\Component\HttpFoundation\Response;

// --- CORS Setup ---
$allowedDomains = [
    'localhost:5000',
    'gheit.co'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$corsHeaders = [];

if ($origin) {
    $parsed = parse_url($origin);
    $host = $parsed['host'] ?? '';
    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    $hostPort = $host . $port;

    foreach ($allowedDomains as $allowedDomain) {
        if (str_ends_with($hostPort, $allowedDomain)) {
            $corsHeaders = [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Credentials' => 'true',
                'Access-Control-Allow-Headers' => $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? 'Authorization, Content-Type',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS, PATCH',
                'Access-Control-Expose-Headers' => 'Authorization'
            ];
            break;
        }
    }
}

// Handle OPTIONS preflight immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $optionsResponse = new Response();
    foreach ($corsHeaders as $key => $value) {
        $optionsResponse->headers->set($key, $value);
    }
    $optionsResponse->setStatusCode(Response::HTTP_OK);
    $optionsResponse->send();
    exit;
}

// --- Run OpenEMR API ---
try {
    $request = HttpRestRequest::createFromGlobals();
    $apiApplication = new ApiApplication();
    
    // Capture the response
    $response = $apiApplication->run($request);

    // Attach CORS headers to Symfony Response
    if ($corsHeaders && $response instanceof Response) {
        foreach ($corsHeaders as $key => $value) {
            $response->headers->set($key, $value);
        }
    }

    // Send the final response
    if ($response instanceof Response) {
        $response->send();
    }
} catch (\Throwable $e) {
    error_log($e->getMessage());
    error_log($e->getTraceAsString());

    $errorResponse = new Response(json_encode([
        'error' => 'An error occurred while processing the request.',
        'message' => $e->getMessage(),
    ]), Response::HTTP_INTERNAL_SERVER_ERROR, ['Content-Type' => 'application/json']);

    // Attach CORS headers to error response
    foreach ($corsHeaders as $key => $value) {
        $errorResponse->headers->set($key, $value);
    }

    $errorResponse->send();
    exit;
}
