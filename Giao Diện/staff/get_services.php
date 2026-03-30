<?php

declare(strict_types=1);

function staff_api_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_api_forward_to_index(string $api): void
{
    $_GET['api'] = $api;
    require dirname(__DIR__, 2) . '/index.php';
    exit;
}

$api = trim((string) ($_GET['api'] ?? 'get_services'));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'POST') {
    staff_api_forward_to_index($api);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$action = trim((string) ($input['action'] ?? ''));
$entity = trim((string) ($input['entity'] ?? ''));

if ($action !== '' || $entity !== '') {
    $_GET['api'] = 'manage_entity';
    require dirname(__DIR__, 2) . '/index.php';
    exit;
}

staff_api_json([
    'ok' => false,
    'message' => 'Yeu cau POST khong hop le',
], 400);
