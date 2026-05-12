<?php
const MAX_ERROR_BODY_LENGTH = 1000;
ini_set('display_errors', '0');
ini_set('html_errors', '0');

function log_backend_event($message, $context = [])
{
    $json_context = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_context === false) {
        $json_context = '{"encoding_error":"unable to encode log context"}';
    }
    error_log('[getbook] ' . $message . ' ' . $json_context);
}


function truncate_error_body($body, $max_length = MAX_ERROR_BODY_LENGTH)
{
    return strlen($body) > $max_length ? substr($body, 0, $max_length) . '…(truncated)' : $body;
}

function fail_with_error($http_status_code, $error, $details = [])
{
    header_remove();
    http_response_code($http_status_code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'status' => 'error',
        'error' => $error,
        'details' => $details,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function ($exception) {
    fail_with_error(500, 'PHP runtime error.', [
        'exception' => get_class($exception),
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);
});

register_shutdown_function(function () {
    $last_error = error_get_last();
    if ($last_error === null) {
        return;
    }
    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (in_array($last_error['type'], $fatal_types, true)) {
        fail_with_error(500, 'PHP fatal error.', $last_error);
    }
});

if (!isset($_SERVER['QUERY_STRING']) || trim($_SERVER['QUERY_STRING']) === '') {
    fail_with_error(400, 'Missing query string for Calameo API request.');
}

$url = 'https://d.calameo.com/pinwheel/viewer/book/get?' . $_SERVER['QUERY_STRING'];
$request_headers = [
    'Cache-Control: no-cache',
    'Connection: keep-alive',
    'DNT: 1',
    'Origin: https://www.calameo.com',
    'Pragma: no-cache',
    'Referer: https://www.calameo.com/',
    'Sec-Fetch-Dest: empty',
    'Sec-Fetch-Mode: cors',
    'Sec-Fetch-Site: same-site',
];
$response_headers = [];
$http_response_code = 0;
$body = '';

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    if ($ch === false) {
        fail_with_error(502, 'Calameo request initialization failed.');
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
    curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . DIRECTORY_SEPARATOR . 'cert.cer');
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    if ($response === false) {
        $curl_error = curl_error($ch);
        curl_close($ch);
        log_backend_event('Calameo request failed', [
            'transport' => 'curl',
            'curl_error' => $curl_error,
            'url' => 'https://d.calameo.com/pinwheel/viewer/book/get',
        ]);
        fail_with_error(502, 'Calameo request failed.', [
            'transport' => 'curl',
            'curl_error' => $curl_error,
            'url' => 'https://d.calameo.com/pinwheel/viewer/book/get',
        ]);
    }
    $curl_info = curl_getinfo($ch);
    curl_close($ch);
    $header_size = $curl_info['header_size'];
    $http_response_code = $curl_info['http_code'];
    $headers_str = substr($response, 0, $header_size);
    $response_headers = explode("\r\n", $headers_str);
    $body = substr($response, $header_size);
} else {
    unset($http_response_header);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $request_headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 30,
        ],
    ]);
    error_clear_last();
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $last_error = error_get_last();
        log_backend_event('Calameo request failed', [
            'transport' => 'stream',
            'message' => $last_error['message'] ?? 'Unknown stream error',
            'url' => 'https://d.calameo.com/pinwheel/viewer/book/get',
        ]);
        fail_with_error(502, 'Calameo request failed.', [
            'transport' => 'stream',
            'message' => $last_error['message'] ?? 'Unknown stream error',
            'url' => 'https://d.calameo.com/pinwheel/viewer/book/get',
        ]);
    }
    $response_headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    // Redirect chains can include multiple HTTP status lines; the last one is the final response.
    for ($i = count($response_headers) - 1; $i >= 0; $i--) {
        if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $response_headers[$i], $matches)) {
            $http_response_code = (int) $matches[1];
            break;
        }
    }
    if ($http_response_code <= 0) {
        $http_response_code = 502;
    }
}

$content_type = '';

header_remove();
foreach ($response_headers as $header) {
    if ($header === '' || strpos($header, 'HTTP/') === 0) {
        continue;
    }
    if (stripos($header, 'Content-Type:') === 0) {
        $content_type = trim(substr($header, strlen('Content-Type:')));
    }
    header($header, false);
}
header('Access-Control-Allow-Origin: *', true, $http_response_code);

$decoded_body = json_decode($body, true);
$json_error = json_last_error();

if ($http_response_code >= 400) {
    $sanitized_body = truncate_error_body($body);
    $details = [
        'http_status' => $http_response_code,
        'calameo_body' => $sanitized_body,
    ];
    if ($json_error !== JSON_ERROR_NONE) {
        $details['json_decode_error'] = json_last_error_msg();
    } else {
        $details['calameo_json'] = $decoded_body;
    }
    log_backend_event('Calameo returned HTTP error', $details);
    fail_with_error($http_response_code, 'Calameo returned an error response.', $details);
}

if ($json_error !== JSON_ERROR_NONE) {
    $details = [
        'http_status' => $http_response_code,
        'content_type' => $content_type,
        'json_decode_error' => json_last_error_msg(),
        'calameo_body' => truncate_error_body($body),
    ];
    log_backend_event('Calameo returned non-JSON body', $details);
    fail_with_error(502, 'Calameo returned an invalid JSON response.', $details);
}

echo $body;
