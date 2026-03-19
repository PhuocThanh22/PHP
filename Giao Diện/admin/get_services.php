<?php
header('Content-Type: application/json; charset=UTF-8');

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbName = 'qlshop';

$conn = @new mysqli($host, $user, $pass, $dbName);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Khong ket noi duoc CSDL',
        'error' => $conn->connect_error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conn->set_charset('utf8mb4');

$sql = 'SELECT id, tendichvu, giadichvu, thoigiandichvu, trangthaidichvu, ngaytaodichvu FROM dichvu ORDER BY id ASC LIMIT 100';
$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Truy van bang dichvu that bai',
        'error' => $conn->error,
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'id' => (int) $row['id'],
        'tendichvu' => (string) $row['tendichvu'],
        'giadichvu' => (float) $row['giadichvu'],
        'thoigiandichvu' => (int) $row['thoigiandichvu'],
        'trangthaidichvu' => (string) $row['trangthaidichvu'],
        'ngaytaodichvu' => (string) $row['ngaytaodichvu'],
    ];
}

$result->free();
$conn->close();

echo json_encode([
    'ok' => true,
    'count' => count($data),
    'data' => $data,
], JSON_UNESCAPED_UNICODE);
