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

function user_api_table_exists(mysqli $conn, string $tableName): bool
{
    $tableEsc = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$tableEsc}'");
    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function user_api_column_exists(mysqli $conn, string $tableName, string $columnName): bool
{
    $tableEsc = $conn->real_escape_string($tableName);
    $columnEsc = $conn->real_escape_string($columnName);
    $result = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function user_api_input(): array
{
    $rawBody = (string) file_get_contents('php://input');
    $json = json_decode($rawBody, true);
    if (is_array($json)) {
        return $json;
    }

    return $_POST;
}

function user_api_lower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function user_api_password_verify(string $plain, string $stored): bool
{
    if ($plain === '' || $stored === '') {
        return false;
    }

    if (hash_equals($stored, $plain)) {
        return true;
    }

    if (strlen($stored) >= 60 && substr($stored, 0, 2) === '$2') {
        return password_verify($plain, $stored);
    }

    if (preg_match('/^[a-f0-9]{32}$/i', $stored) === 1) {
        return hash_equals(strtolower($stored), md5($plain));
    }

    if (preg_match('/^[a-f0-9]{40}$/i', $stored) === 1) {
        return hash_equals(strtolower($stored), sha1($plain));
    }

    return false;
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

if ($api === 'login_user') {
    $input = user_api_input();
    $identifier = trim((string) ($input['identifier'] ?? $input['username'] ?? $input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');

    if ($identifier === '' || $password === '') {
        $conn->close();
        user_api_json([
            'ok' => false,
            'message' => 'Vui long nhap tai khoan va mat khau',
        ], 400);
    }

    $identifierLower = user_api_lower($identifier);

    // 1) Ưu tiên bảng taikhoan (nếu có)
    if (user_api_table_exists($conn, 'taikhoan')) {
        $columns = [
            'id' => user_api_column_exists($conn, 'taikhoan', 'id'),
            'hoten' => user_api_column_exists($conn, 'taikhoan', 'hoten'),
            'tenhienthi' => user_api_column_exists($conn, 'taikhoan', 'tenhienthi'),
            'role' => user_api_column_exists($conn, 'taikhoan', 'role'),
            'vaitro' => user_api_column_exists($conn, 'taikhoan', 'vaitro'),
            'matkhau' => user_api_column_exists($conn, 'taikhoan', 'matkhau'),
            'password' => user_api_column_exists($conn, 'taikhoan', 'password'),
            'tendangnhap' => user_api_column_exists($conn, 'taikhoan', 'tendangnhap'),
            'username' => user_api_column_exists($conn, 'taikhoan', 'username'),
            'email' => user_api_column_exists($conn, 'taikhoan', 'email'),
            'sodienthoai' => user_api_column_exists($conn, 'taikhoan', 'sodienthoai'),
            'trangthai' => user_api_column_exists($conn, 'taikhoan', 'trangthai'),
        ];

        $accountFields = [];
        if ($columns['tendangnhap']) $accountFields[] = 'LOWER(tendangnhap) = ?';
        if ($columns['username']) $accountFields[] = 'LOWER(username) = ?';
        if ($columns['email']) $accountFields[] = 'LOWER(email) = ?';
        if ($columns['sodienthoai']) $accountFields[] = 'LOWER(sodienthoai) = ?';

        $passwordField = $columns['matkhau'] ? 'matkhau' : ($columns['password'] ? 'password' : '');

        if (count($accountFields) > 0 && $passwordField !== '') {
            $nameExpr = $columns['hoten']
                ? 'hoten'
                : ($columns['tenhienthi'] ? 'tenhienthi' : 'COALESCE(tendangnhap, username, email, sodienthoai, "Nguoi dung")');
            $roleExpr = $columns['role'] ? 'role' : ($columns['vaitro'] ? 'vaitro' : '"user"');
            $idExpr = $columns['id'] ? 'id' : '0';

            $whereSql = '(' . implode(' OR ', $accountFields) . ')';
            if ($columns['trangthai']) {
                $whereSql .= " AND (trangthai IS NULL OR LOWER(trangthai) IN ('active', 'hoatdong', '1'))";
            }

            $sql = "
                SELECT {$idExpr} AS id, {$nameExpr} AS tennguoidung, {$roleExpr} AS role, {$passwordField} AS matkhau
                FROM taikhoan
                WHERE {$whereSql}
                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $bindTypes = str_repeat('s', count($accountFields));
                $bindValues = array_fill(0, count($accountFields), $identifierLower);
                $stmt->bind_param($bindTypes, ...$bindValues);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                if ($result) {
                    $result->free();
                }
                $stmt->close();

                if (is_array($row) && user_api_password_verify($password, (string) ($row['matkhau'] ?? ''))) {
                    $conn->close();
                    user_api_json([
                        'ok' => true,
                        'message' => 'Dang nhap thanh cong',
                        'user' => [
                            'id' => (int) ($row['id'] ?? 0),
                            'name' => (string) ($row['tennguoidung'] ?? $identifier),
                            'identifier' => $identifier,
                            'role' => (string) ($row['role'] ?? 'user'),
                        ],
                    ]);
                }
            }
        }
    }

    // 2) Fallback bảng khachhang (mật khẩu mặc định: 123456)
    if (user_api_table_exists($conn, 'khachhang')) {
        $sql = "
            SELECT id, tenkhachhang, emailkhachhang, sodienthoaikhachhang
            FROM khachhang
            WHERE LOWER(COALESCE(emailkhachhang, '')) = ?
               OR LOWER(COALESCE(sodienthoaikhachhang, '')) = ?
               OR LOWER(COALESCE(tenkhachhang, '')) = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('sss', $identifierLower, $identifierLower, $identifierLower);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ($result) {
                $result->free();
            }
            $stmt->close();

            if (is_array($row) && $password === '123456') {
                $conn->close();
                user_api_json([
                    'ok' => true,
                    'message' => 'Dang nhap thanh cong',
                    'user' => [
                        'id' => (int) ($row['id'] ?? 0),
                        'name' => (string) ($row['tenkhachhang'] ?? 'Khach hang'),
                        'identifier' => $identifier,
                        'role' => 'user',
                    ],
                    'note' => 'Dang dung mat khau mac dinh cho khachhang: 123456',
                ]);
            }
        }
    }

    // 3) Demo account
    if ($identifierLower === 'thucung' && $password === '123456') {
        $conn->close();
        user_api_json([
            'ok' => true,
            'message' => 'Dang nhap thanh cong',
            'user' => [
                'id' => 0,
                'name' => 'Thucung Demo',
                'identifier' => 'thucung',
                'role' => 'user',
            ],
        ]);
    }

    $conn->close();
    user_api_json([
        'ok' => false,
        'message' => 'Sai tai khoan hoac mat khau',
    ], 401);
}

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

if ($api === 'get_products') {
    $sql = "
        SELECT
            s.id,
            s.tensanpham,
            s.danhmuc_id,
            COALESCE(NULLIF(TRIM(d.tendanhmuc), ''), 'Chua phan loai') AS tendanhmuc,
            s.masanpham,
            s.giasanpham,
            s.soluongsanpham,
            s.trangthaisanpham,
            COALESCE(NULLIF(TRIM(s.hinhanhsanpham), ''), '') AS hinhanhsanpham
        FROM sanpham s
        LEFT JOIN danhmuc d ON d.id = s.danhmuc_id
        ORDER BY s.id DESC
        LIMIT 1000
    ";

    $result = $conn->query($sql);
    if (!$result) {
        $conn->close();
        user_api_json([
            'ok' => false,
            'message' => 'Truy van sanpham that bai',
            'error' => $conn->error,
        ], 500);
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'id' => (int) ($row['id'] ?? 0),
            'tensanpham' => (string) ($row['tensanpham'] ?? ''),
            'danhmuc_id' => (int) ($row['danhmuc_id'] ?? 0),
            'tendanhmuc' => (string) ($row['tendanhmuc'] ?? 'Chua phan loai'),
            'masanpham' => (string) ($row['masanpham'] ?? ''),
            'giasanpham' => (float) ($row['giasanpham'] ?? 0),
            'soluongsanpham' => (int) ($row['soluongsanpham'] ?? 0),
            'trangthaisanpham' => (string) ($row['trangthaisanpham'] ?? ''),
            'hinhanhsanpham' => (string) ($row['hinhanhsanpham'] ?? ''),
        ];
    }
    $result->free();

    $conn->close();
    user_api_json([
        'ok' => true,
        'count' => count($data),
        'data' => $data,
    ]);
}

$conn->close();
user_api_json([
    'ok' => false,
    'message' => 'API khong hop le',
], 404);
