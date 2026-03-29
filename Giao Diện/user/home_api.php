<?php

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_OFF);

function user_api_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function user_api_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value !== false && trim((string) $value) !== '') {
        return trim((string) $value);
    }

    if (isset($_ENV[$key]) && trim((string) $_ENV[$key]) !== '') {
        return trim((string) $_ENV[$key]);
    }

    if (isset($_SERVER[$key]) && trim((string) $_SERVER[$key]) !== '') {
        return trim((string) $_SERVER[$key]);
    }

    return $default;
}

function user_api_env_int(string $key, int $default = 0): int
{
    $value = user_api_env($key);
    if ($value === '') {
        return $default;
    }

    $intValue = (int) $value;
    return $intValue > 0 ? $intValue : $default;
}

function user_api_detect_ports(): array
{
    $candidates = [
        user_api_env_int('DB_PORT', 0),
        user_api_env_int('MYSQL_PORT', 0),
        user_api_env_int('MYSQL_TCP_PORT', 0),
        3306,
        3307,
    ];

    $ports = [];
    foreach ($candidates as $port) {
        if ($port > 0 && !in_array($port, $ports, true)) {
            $ports[] = $port;
        }
    }

    return $ports;
}

function user_api_db_has_tables(mysqli $conn, string $dbName): bool
{
    $dbEsc = $conn->real_escape_string($dbName);
    $sql = "
        SELECT COUNT(DISTINCT table_name) AS matched
        FROM information_schema.tables
        WHERE table_schema = '{$dbEsc}'
          AND table_name IN ('danhmuc', 'sanpham')
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return false;
    }

    $row = $result->fetch_assoc();
    $result->free();
    return (int) ($row['matched'] ?? 0) >= 1;
}

function user_api_resolve_db_name(mysqli $serverConn, string $preferredDb): string
{
    if ($preferredDb !== '' && user_api_db_has_tables($serverConn, $preferredDb)) {
        return $preferredDb;
    }

    $commonNames = ['qlshop', 'petshop', 'shop', 'pet_store', 'phpchinh'];
    foreach ($commonNames as $name) {
        if (user_api_db_has_tables($serverConn, $name)) {
            return $name;
        }
    }

    $dbList = $serverConn->query('SHOW DATABASES');
    if ($dbList) {
        while ($row = $dbList->fetch_row()) {
            $dbName = (string) ($row[0] ?? '');
            if ($dbName !== '' && user_api_db_has_tables($serverConn, $dbName)) {
                $dbList->free();
                return $dbName;
            }
        }
        $dbList->free();
    }

    return $preferredDb;
}

function user_api_db_connect(): mysqli
{
    $host = user_api_env('DB_HOST', '127.0.0.1');
    $user = user_api_env('DB_USER', 'root');
    $pass = user_api_env('DB_PASS', '');
    $dbName = user_api_env('DB_NAME', 'qlshop');
    $ports = user_api_detect_ports();
    $lastError = 'Unknown connection error';

    foreach ($ports as $port) {
        try {
            $serverConn = @new mysqli($host, $user, $pass, '', $port);
            if ($serverConn->connect_error) {
                $lastError = $serverConn->connect_error;
                continue;
            }

            $resolvedDb = user_api_resolve_db_name($serverConn, $dbName);
            $serverConn->close();

            $conn = @new mysqli($host, $user, $pass, $resolvedDb, $port);
            if (!$conn->connect_error) {
                $conn->set_charset('utf8mb4');
                return $conn;
            }

            $lastError = $conn->connect_error;
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
        }
    }

    user_api_json([
        'ok' => false,
        'message' => 'Khong ket noi duoc CSDL. Kiem tra Laragon/MySQL.',
        'error' => $lastError,
        'tried_ports' => $ports,
    ], 500);
}

$api = trim((string) ($_GET['api'] ?? ''));
if ($api === '') {
    user_api_json([
        'ok' => false,
        'message' => 'Thieu tham so api',
    ], 400);
}

$conn = user_api_db_connect();

if ($api === 'get_home_categories') {
    $sql = "
        SELECT
            d.id,
            d.tendanhmuc,
            COUNT(s.id) AS soluongsanpham,
            COALESCE(MAX(NULLIF(TRIM(s.hinhanhsanpham), '')), '') AS hinhanh
        FROM danhmuc d
        LEFT JOIN sanpham s ON s.danhmuc_id = d.id
        GROUP BY d.id, d.tendanhmuc
        ORDER BY d.id ASC
        LIMIT 200
    ";

    $result = $conn->query($sql);
    if (!$result) {
        $conn->close();
        user_api_json([
            'ok' => false,
            'message' => 'Truy van danh muc that bai',
            'error' => $conn->error,
        ], 500);
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'id' => (int) ($row['id'] ?? 0),
            'tendanhmuc' => (string) ($row['tendanhmuc'] ?? ''),
            'soluongsanpham' => (int) ($row['soluongsanpham'] ?? 0),
            'hinhanh' => (string) ($row['hinhanh'] ?? ''),
        ];
    }
    $result->free();

    if (count($data) === 0) {
        $fallbackSql = "
            SELECT
                COALESCE(NULLIF(TRIM(d.tendanhmuc), ''), 'Chua phan loai') AS tendanhmuc,
                COUNT(s.id) AS soluongsanpham,
                COALESCE(MAX(NULLIF(TRIM(s.hinhanhsanpham), '')), '') AS hinhanh
            FROM sanpham s
            LEFT JOIN danhmuc d ON d.id = s.danhmuc_id
            GROUP BY COALESCE(NULLIF(TRIM(d.tendanhmuc), ''), 'Chua phan loai')
            ORDER BY tendanhmuc ASC
            LIMIT 200
        ";

        $fallbackResult = $conn->query($fallbackSql);
        if ($fallbackResult) {
            while ($row = $fallbackResult->fetch_assoc()) {
                $data[] = [
                    'id' => 0,
                    'tendanhmuc' => (string) ($row['tendanhmuc'] ?? ''),
                    'soluongsanpham' => (int) ($row['soluongsanpham'] ?? 0),
                    'hinhanh' => (string) ($row['hinhanh'] ?? ''),
                ];
            }
            $fallbackResult->free();
        }
    }

    $signature = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $conn->close();
    user_api_json([
        'ok' => true,
        'count' => count($data),
        'signature' => $signature,
        'updated_at' => date(DATE_ATOM),
        'data' => $data,
    ]);
}

$conn->close();
user_api_json([
    'ok' => false,
    'message' => 'API khong hop le',
], 404);
