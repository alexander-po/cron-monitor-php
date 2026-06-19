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

$path = parse_url($uri, \PHP_URL_PATH);

// Canned management-API route so MonitorApiClient's create()-factory
// round-trip can be smoke-tested end-to-end against the real cURL
// transport. Everything else falls through to the request-echo used by
// CurlPsr18ClientTest, so adding this branch leaves that test untouched.
if ('/api/v1/monitors' === $path) {
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode([
        'data' => [[
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'name' => 'Smoke monitor',
            'schedule_kind' => 'interval',
            'schedule_expr' => '300',
            'tz' => 'UTC',
            'grace_seconds' => 60,
            'status' => 'new',
            'next_expected_at' => null,
            'last_ping_at' => null,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'ping_url' => 'http://127.0.0.1/ping/550e8400-e29b-41d4-a716-446655440000',
            'badge_url' => 'http://127.0.0.1/badge/550e8400-e29b-41d4-a716-446655440000.svg',
        ]],
        'total' => 1,
        'limit' => 50,
        'offset' => 0,
    ], \JSON_THROW_ON_ERROR);

    return;
}

// Canned account route, so getAccount() can be smoke-tested end-to-end over
// the real cURL transport (nested-object hydration through the wire).
if ('/api/v1/account' === $path) {
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode([
        'plan' => ['key' => 'starter', 'label' => 'Starter', 'monitor_limit' => 50],
        'monitor_budget' => ['used' => 3, 'limit' => 50, 'remaining' => 47],
        'api_rate_limit' => ['limit' => 120, 'remaining' => 119],
    ], \JSON_THROW_ON_ERROR);

    return;
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
