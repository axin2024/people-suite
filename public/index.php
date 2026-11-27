<?php

declare(strict_types=1);

use PeopleSuite\BusinessError;
use PeopleSuite\CsvDownload;
use PeopleSuite\Database;
use PeopleSuite\HttpApi;

require __DIR__ . '/../src/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$workspaceFiles = [
    '/' => ['workspace.html', 'text/html'],
    '/workspace.js' => ['workspace.js', 'text/javascript'],
    '/workspace-ui.js' => ['workspace-ui.js', 'text/javascript'],
    '/attendance-workspace.js' => ['attendance-workspace.js', 'text/javascript'],
    '/employment-workspace.js' => ['employment-workspace.js', 'text/javascript'],
    '/payroll-workspace.js' => ['payroll-workspace.js', 'text/javascript'],
    '/performance-workspace.js' => ['performance-workspace.js', 'text/javascript'],
    '/talent-workspace.js' => ['talent-workspace.js', 'text/javascript'],
    '/workforce-workspace.js' => ['workforce-workspace.js', 'text/javascript'],
    '/recruitment-workspace.js' => ['recruitment-workspace.js', 'text/javascript'],
    '/workspace.css' => ['workspace.css', 'text/css'],
];
if ($method === 'GET' && isset($workspaceFiles[$path])) {
    [$file, $type] = $workspaceFiles[$path];
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; "
        . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header("Content-Type: {$type}; charset=utf-8");
    readfile(__DIR__ . '/' . $file);
    return;
}

header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: application/json; charset=utf-8');

try {
    [$status, $body] = (new HttpApi(Database::connect()))->handle($method, $path);
} catch (BusinessError $error) {
    $status = $error->status;
    $body = ['error' => $error->reason, 'details' => $error->details];
} catch (Throwable $error) {
    error_log('HCM request failed: ' . $error::class . ' (' . $error->getCode() . ')');
    $status = $path === '/healthz' ? 503 : 500;
    $body = $path === '/healthz' ? ['ready' => false] : ['error' => 'request_failed'];
}
http_response_code($status);
if ($body instanceof CsvDownload) {
    $body->send();
    return;
}
echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
