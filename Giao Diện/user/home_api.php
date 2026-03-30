<?php

declare(strict_types=1);

function user_api_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$api = trim((string) ($_GET['api'] ?? ''));
if ($api === '') {
    user_api_json([
        'ok' => false,
        'message' => 'Thieu tham so api',
    ], 400);
}

$_GET['api'] = $api;
require dirname(__DIR__, 2) . '/index.php';
exit;
