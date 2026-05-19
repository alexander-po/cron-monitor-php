<?php

declare(strict_types=1);

/*
 * Built-in `php -S` router used by CurlPsr18ClientTest. Echoes the parsed
 * request back as JSON so the curl-client round-trip can be asserted against
 * a known shape without depending on any third-party HTTP server.
 *
 * Status code can be overridden via `?status=NNN` (used to verify status
 * propagation independent of curl error paths).
 */

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$uri = $_SERVER['REQUEST_URI'] ?? '';
$body = file_get_contents('php://input');
if (false === $body) {
    $body = '';
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (\is_string($key) && str_starts_with($key, 'HTTP_')) {
        $name = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$name] = (string) $value;
    }
}

$status = isset($_GET['status']) ? (int) $_GET['status'] : 200;
http_response_code($status);

header('Content-Type: application/json');
header('X-Echo-Method: '.$method);
header('X-Echo-Path: '.$uri);

echo json_encode([
    'method' => $method,
    'uri' => $uri,
    'body' => $body,
    'headers' => $headers,
], \JSON_THROW_ON_ERROR);
