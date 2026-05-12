<?php
const MAX_ERROR_BODY_LENGTH = 1000;

function log_backend_event($message, $context = [])
{
    $json_context = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_context === false) {
        $json_context = '{"encoding_error":"unable to encode log context"}';
    }
    error_log('[getbook] ' . $message . ' ' . $json_context);
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
    log_backend_event('Missing query string', []);
    fail_with_error(400, 'Missing query string for Calameo API request.');
}

$url = 'https://d.calameo.com/pinwheel/viewer/book/get?' . $_SERVER['QUERY_STRING'];
$headers = [
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

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . DIRECTORY_SEPARATOR . 'cert.cer');
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
if ($response === false) {
    $curl_error = curl_error($ch);
    curl_close($ch);
    log_backend_event('Calameo request failed', [
        'curl_error' => $curl_error,
        'url' => 'https://d.calameo.com/pinwheel/viewer/book/get',
    ]);
    fail_with_error(502, 'Calameo request failed.', [
        'curl_error' => $curl_error,
        'url' => 'https://d.calameo.com/pinwheel/viewer/book/get',
    ]);
}
$curl_info = curl_getinfo($ch);
curl_close($ch);
$header_size = $curl_info['header_size'];
$http_response_code = $curl_info['http_code'];
$headers_str = substr($response, 0, $header_size);
$headers = explode("\r\n", $headers_str);
$body = substr($response, $header_size);
$content_type = '';

header_remove();
foreach ($headers as $header) {
    if ($header === '' || strpos($header, 'HTTP/') === 0) {
        continue;
    }
    if (stripos($header, 'Content-Type:') === 0) {
        $content_type = trim(substr($header, strlen('Content-Type:')));
    }
    header($header, false);
}
header('Access-Control-Allow-Origin: *', true, $http_response_code);

if ($http_response_code >= 400) {
    $decoded_body = json_decode($body, true);
    $json_error = json_last_error();
    $sanitized_body = strlen($body) > MAX_ERROR_BODY_LENGTH ? substr($body, 0, MAX_ERROR_BODY_LENGTH) . '…(truncated)' : $body;
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

$decoded_body = json_decode($body, true);
$json_error = json_last_error();
if ($json_error !== JSON_ERROR_NONE) {
    $details = [
        'http_status' => $http_response_code,
        'content_type' => $content_type,
        'json_decode_error' => json_last_error_msg(),
        'calameo_body' => strlen($body) > MAX_ERROR_BODY_LENGTH ? substr($body, 0, MAX_ERROR_BODY_LENGTH) . '…(truncated)' : $body,
    ];
    log_backend_event('Calameo returned non-JSON body', $details);
    fail_with_error(502, 'Calameo returned an invalid JSON response.', $details);
}

echo $body;
