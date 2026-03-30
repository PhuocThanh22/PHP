<?php

mysqli_report(MYSQLI_REPORT_OFF);

function app_base_path(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    return $basePath === '/' ? '' : $basePath;
}

function app_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function app_env_value(string $key, string $default = ''): string
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

function app_env_int(string $key, int $default = 0): int
{
    $value = app_env_value($key);
    if ($value === '') {
        return $default;
    }

    $intValue = (int) $value;
    return $intValue > 0 ? $intValue : $default;
}

function app_detect_db_ports(): array
{
    $candidates = [
        app_env_int('DB_PORT', 0),
        app_env_int('MYSQL_PORT', 0),
        app_env_int('MYSQL_TCP_PORT', 0),
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

function app_db_has_required_tables(mysqli $conn, string $dbName): bool
{
    $dbEsc = $conn->real_escape_string($dbName);
    $sql = "
        SELECT COUNT(DISTINCT table_name) AS matched
        FROM information_schema.tables
        WHERE table_schema = '{$dbEsc}'
          AND table_name IN ('dichvu', 'sanpham', 'khachhang', 'thucung', 'danhmuc')
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return false;
    }

    $row = $result->fetch_assoc();
    $result->free();
    return (int) ($row['matched'] ?? 0) >= 3;
}

function app_resolve_db_name(mysqli $serverConn, string $preferredDbName): string
{
    if ($preferredDbName !== '' && app_db_has_required_tables($serverConn, $preferredDbName)) {
        return $preferredDbName;
    }

    $commonNames = ['qlshop', 'petshop', 'shop', 'pet_store', 'phpchinh'];
    foreach ($commonNames as $name) {
        if (app_db_has_required_tables($serverConn, $name)) {
            return $name;
        }
    }

    $dbList = $serverConn->query('SHOW DATABASES');
    if ($dbList) {
        while ($row = $dbList->fetch_row()) {
            $dbName = (string) ($row[0] ?? '');
            if ($dbName !== '' && app_db_has_required_tables($serverConn, $dbName)) {
                $dbList->free();
                return $dbName;
            }
        }
        $dbList->free();
    }

    return $preferredDbName;
}

function app_db_connect(): mysqli
{
    $host = app_env_value('DB_HOST', '127.0.0.1');
    $user = app_env_value('DB_USER', 'root');
    $pass = app_env_value('DB_PASS', '');
    $dbName = app_env_value('DB_NAME', 'qlshop');
    $ports = app_detect_db_ports();
    $lastError = 'Unknown connection error';

    foreach ($ports as $port) {
        try {
            $serverConn = @new mysqli($host, $user, $pass, '', $port);
            if ($serverConn->connect_error) {
                $lastError = $serverConn->connect_error;
                continue;
            }

            $resolvedDbName = app_resolve_db_name($serverConn, $dbName);
            $serverConn->close();

            $conn = @new mysqli($host, $user, $pass, $resolvedDbName, $port);
            if (!$conn->connect_error) {
                $conn->set_charset('utf8mb4');
                return $conn;
            }

            $lastError = $conn->connect_error;
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
        }
    }

    app_json_response([
        'ok' => false,
        'message' => 'Khong ket noi duoc CSDL. Kiem tra DB_HOST/DB_PORT tren may nay.',
        'error' => $lastError,
        'tried_ports' => $ports,
    ], 500);

    throw new RuntimeException('Khong ket noi duoc CSDL');
}

function app_column_exists(mysqli $conn, string $table, string $column): bool
{
    $tableEsc = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'";
    $result = $conn->query($sql);
    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function app_table_exists(mysqli $conn, string $table): bool
{
    $tableEsc = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$tableEsc}'");
    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function app_input_payload(): array
{
    $rawBody = (string) file_get_contents('php://input');
    $json = json_decode($rawBody, true);
    if (is_array($json)) {
        return $json;
    }

    return $_POST;
}

function app_lower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function app_password_verify_compat(string $plain, string $stored): bool
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

function app_ensure_online_orders_table(mysqli $conn): bool
{
    $sql = "
        CREATE TABLE IF NOT EXISTS donhang_online (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            donhang_id INT NULL,
            madonhang VARCHAR(40) NOT NULL,
            tenkhachhang VARCHAR(120) NOT NULL,
            sodienthoai VARCHAR(30) NOT NULL,
            email VARCHAR(190) NULL,
            diachi VARCHAR(255) NOT NULL,
            ghichu TEXT NULL,
            tongtien DECIMAL(14,2) NOT NULL DEFAULT 0,
            trangthai VARCHAR(40) NOT NULL DEFAULT 'cho_duyet',
            ldotuchoi TEXT NULL,
            nguoiduyet VARCHAR(120) NULL,
            nguon VARCHAR(20) NOT NULL DEFAULT 'online',
            chitiet_json LONGTEXT NULL,
            ngaytao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ngaycapnhat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_donhang_online_ma (madonhang),
            KEY idx_donhang_online_donhang (donhang_id),
            KEY idx_donhang_online_status (trangthai),
            KEY idx_donhang_online_phone (sodienthoai),
            KEY idx_donhang_online_created (ngaytao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    $created = (bool) $conn->query($sql);
    if (!$created) {
        return false;
    }

    if (!app_column_exists($conn, 'donhang_online', 'donhang_id')) {
        $conn->query("ALTER TABLE donhang_online ADD COLUMN donhang_id INT NULL AFTER id");
    }

    if (!app_column_exists($conn, 'donhang_online', 'nguon')) {
        $conn->query("ALTER TABLE donhang_online ADD COLUMN nguon VARCHAR(20) NOT NULL DEFAULT 'online' AFTER nguoiduyet");
    }

    return true;
}

function app_generate_online_order_code(): string
{
    return 'DHO' . date('YmdHis') . strtoupper(bin2hex(random_bytes(2)));
}

function app_map_online_to_order_status(string $onlineStatus): string
{
    $status = trim(strtolower($onlineStatus));
    if ($status === 'da_duyet') {
        return 'danggiaohang';
    }

    if ($status === 'tu_choi') {
        return 'huy';
    }

    return 'dangxuly';
}

function app_starts_with(string $text, string $prefix): bool
{
    return strncmp($text, $prefix, strlen($prefix)) === 0;
}

function app_normalize_role(string $role): string
{
    $normalized = app_lower(trim($role));

    if ($normalized === 'admin' || $normalized === 'administrator' || $normalized === 'quantri' || $normalized === 'quan tri') {
        return 'admin';
    }

    if (
        $normalized === 'staff' ||
        $normalized === 'nhanvien' ||
        $normalized === 'nhan vien' ||
        $normalized === 'bacsi' ||
        $normalized === 'bac si' ||
        $normalized === 'doctor'
    ) {
        return 'staff';
    }

    return 'user';
}

function app_service_default_image_path(string $serviceName, int $serviceId): string
{
    $name = mb_strtolower(trim($serviceName), 'UTF-8');

    if (strpos($name, 'spa') !== false || strpos($name, 'tạo kiểu') !== false) {
        return 'anhdata/services/service_001.jpg';
    }

    if (strpos($name, 'khách sạn') !== false || strpos($name, 'lưu trú') !== false) {
        return 'anhdata/services/service_002.jpg';
    }

    if (strpos($name, 'tắm') !== false || strpos($name, 'sấy') !== false) {
        return 'anhdata/services/service_003.jpg';
    }

    if (strpos($name, 'vệ sinh tai') !== false || strpos($name, 'tai') !== false) {
        return 'anhdata/services/service_004.jpg';
    }

    if (strpos($name, 'cắt móng') !== false || strpos($name, 'móng') !== false) {
        return 'anhdata/services/service_005.jpg';
    }

    $index = 1 + ($serviceId % 10);
    return 'anhdata/services/service_' . str_pad((string) $index, 3, '0', STR_PAD_LEFT) . '.jpg';
}

function app_to_public_image_url(string $imagePath): string
{
    $path = trim(str_replace('\\', '/', $imagePath));
    if ($path === '') {
        return '';
    }

    if (preg_match('~^(?:https?:)?//~i', $path) || app_starts_with($path, 'data:') || app_starts_with($path, '/')) {
        return $path;
    }

    if (app_starts_with($path, './')) {
        $path = substr($path, 2);
    }

    if (app_starts_with($path, 'Giao Diện/user/anhdata/')) {
        $path = substr($path, strlen('Giao Diện/user/'));
    } elseif (app_starts_with($path, 'user/anhdata/')) {
        $path = substr($path, strlen('user/'));
    }

    $basePath = app_base_path();
    $encodedPath = str_replace(' ', '%20', ltrim($path, '/'));
    return ($basePath !== '' ? $basePath : '') . '/' . $encodedPath;
}

function app_ensure_dichvu_image_column(mysqli $conn): bool
{
    if (app_column_exists($conn, 'dichvu', 'hinhanhdichvu')) {
        return true;
    }

    $alterSql = "ALTER TABLE dichvu ADD COLUMN hinhanhdichvu VARCHAR(255) NULL AFTER trangthaidichvu";
    return (bool) $conn->query($alterSql);
}

function app_resolve_category_id(mysqli $conn, string $categoryName, int $fallback = 0): int
{
    $name = trim($categoryName);
    if ($name !== '') {
        $stmt = $conn->prepare('SELECT id FROM danhmuc WHERE tendanhmuc = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ($result) {
                $result->free();
            }
            $stmt->close();
            if (is_array($row)) {
                return (int) ($row['id'] ?? 0);
            }
        }
    }

    if ($fallback > 0) {
        return $fallback;
    }

    $result = $conn->query('SELECT id FROM danhmuc ORDER BY id ASC LIMIT 1');
    if ($result && ($row = $result->fetch_assoc())) {
        $id = (int) ($row['id'] ?? 0);
        $result->free();
        return $id;
    }

    if ($result) {
        $result->free();
    }

    return 0;
}

function app_detect_pet_type_column(mysqli $conn): string
{
    if (app_column_exists($conn, 'thucung', 'loaithucung')) {
        return 'loaithucung';
    }

    if (app_column_exists($conn, 'thucung', 'loaivatthucung')) {
        return 'loaivatthucung';
    }

    if (app_column_exists($conn, 'thucung', 'loaivattthucung')) {
        return 'loaivattthucung';
    }

    return '';
}

function app_seed_dichvu_image_column(mysqli $conn): void
{
    if (!app_ensure_dichvu_image_column($conn)) {
        return;
    }

    $missingSql = "
        SELECT id, tendichvu
        FROM dichvu
        WHERE TRIM(COALESCE(hinhanhdichvu, '')) = ''
        ORDER BY id ASC
        LIMIT 1000
    ";

    $missingResult = $conn->query($missingSql);
    if (!$missingResult) {
        return;
    }

    $update = $conn->prepare('UPDATE dichvu SET hinhanhdichvu = ? WHERE id = ?');
    if (!$update) {
        $missingResult->free();
        return;
    }

    while ($row = $missingResult->fetch_assoc()) {
        $serviceId = (int) ($row['id'] ?? 0);
        $serviceName = (string) ($row['tendichvu'] ?? '');
        if ($serviceId <= 0) {
            continue;
        }

        $imagePath = app_service_default_image_path($serviceName, $serviceId);
        $update->bind_param('si', $imagePath, $serviceId);
        $update->execute();
    }

    $update->close();
    $missingResult->free();
}

$api = $_GET['api'] ?? '';
if ($api !== '') {
    $conn = app_db_connect();

    if ($api === 'get_services') {
        app_seed_dichvu_image_column($conn);

        $sql = "
            SELECT
                d.id,
                d.tendichvu,
                d.giadichvu,
                d.thoigiandichvu,
                d.trangthaidichvu,
                d.ngaytaodichvu,
                COALESCE(NULLIF(TRIM(d.hinhanhdichvu), ''), '') AS hinhanh
            FROM dichvu d
            ORDER BY d.id ASC
            LIMIT 100
        ";
        $result = $conn->query($sql);

        if (!$result) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Truy van bang dichvu that bai',
                'error' => $conn->error,
            ], 500);
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
                'hinhanh' => app_to_public_image_url((string) ($row['hinhanh'] ?? '')),
            ];
        }

        $result->free();
        $conn->close();

        app_json_response([
            'ok' => true,
            'count' => count($data),
            'data' => $data,
        ]);
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
            app_json_response([
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
                'hinhanh' => app_to_public_image_url((string) ($row['hinhanh'] ?? '')),
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
                        'hinhanh' => app_to_public_image_url((string) ($row['hinhanh'] ?? '')),
                    ];
                }
                $fallbackResult->free();
            }
        }

        $signature = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $conn->close();
        app_json_response([
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
                COALESCE(d.tendanhmuc, 'Chua phan loai') AS tendanhmuc,
                s.masanpham,
                s.giasanpham,
                s.soluongsanpham,
                s.trangthaisanpham,
                s.hinhanhsanpham
            FROM sanpham s
            LEFT JOIN danhmuc d ON d.id = s.danhmuc_id
            ORDER BY s.id DESC
            LIMIT 100
        ";
        $result = $conn->query($sql);

        if (!$result) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Truy van bang sanpham that bai',
                'error' => $conn->error,
            ], 500);
        }

        $data = [];
        $lowStock = 0;
        $categorySet = [];

        while ($row = $result->fetch_assoc()) {
            $qty = (int) $row['soluongsanpham'];
            if ($qty > 0 && $qty <= 5) {
                $lowStock++;
            }

            $categorySet[(string) $row['danhmuc_id']] = true;

            $data[] = [
                'id' => (int) $row['id'],
                'tensanpham' => (string) $row['tensanpham'],
                'danhmuc_id' => (int) $row['danhmuc_id'],
                'tendanhmuc' => (string) $row['tendanhmuc'],
                'masanpham' => (string) $row['masanpham'],
                'giasanpham' => (float) $row['giasanpham'],
                'soluongsanpham' => $qty,
                'trangthaisanpham' => (string) $row['trangthaisanpham'],
                'hinhanhsanpham' => app_to_public_image_url((string) ($row['hinhanhsanpham'] ?? '')),
            ];
        }

        $result->free();
        $conn->close();

        app_json_response([
            'ok' => true,
            'count' => count($data),
            'summary' => [
                'total' => count($data),
                'low_stock' => $lowStock,
                'category_count' => count($categorySet),
            ],
            'data' => $data,
        ]);
    }

    if ($api === 'get_customers') {
        $sql = "
            SELECT
                k.id,
                k.tenkhachhang,
                k.sodienthoaikhachhang,
                k.emailkhachhang,
                k.tongchitieukhachhang,
                k.loaikhachhang,
                k.ngaytaokhachhang,
                COUNT(t.id) AS so_thu_cung
            FROM khachhang k
            LEFT JOIN thucung t ON t.chusohuu_id = k.id
            GROUP BY
                k.id,
                k.tenkhachhang,
                k.sodienthoaikhachhang,
                k.emailkhachhang,
                k.tongchitieukhachhang,
                k.loaikhachhang,
                k.ngaytaokhachhang
            ORDER BY k.id DESC
            LIMIT 200
        ";

        $result = $conn->query($sql);
        if (!$result) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Truy van bang khachhang that bai',
                'error' => $conn->error,
            ], 500);
        }

        $data = [];
        $vipCount = 0;
        $totalSpending = 0.0;

        while ($row = $result->fetch_assoc()) {
            $isVip = (string) $row['loaikhachhang'] === 'vip';
            if ($isVip) {
                $vipCount++;
            }

            $spending = (float) $row['tongchitieukhachhang'];
            $totalSpending += $spending;

            $data[] = [
                'id' => (int) $row['id'],
                'tenkhachhang' => (string) $row['tenkhachhang'],
                'sodienthoaikhachhang' => (string) $row['sodienthoaikhachhang'],
                'emailkhachhang' => (string) $row['emailkhachhang'],
                'tongchitieukhachhang' => $spending,
                'loaikhachhang' => (string) $row['loaikhachhang'],
                'ngaytaokhachhang' => (string) $row['ngaytaokhachhang'],
                'so_thu_cung' => (int) $row['so_thu_cung'],
            ];
        }

        $result->free();
        $conn->close();

        app_json_response([
            'ok' => true,
            'count' => count($data),
            'summary' => [
                'total' => count($data),
                'vip' => $vipCount,
                'thuong' => count($data) - $vipCount,
                'total_spending' => $totalSpending,
            ],
            'data' => $data,
        ]);
    }

    if ($api === 'get_pets') {
        $hasLoaiThuCung = app_column_exists($conn, 'thucung', 'loaithucung');
        $hasLoaiVatThuCung = app_column_exists($conn, 'thucung', 'loaivatthucung');
        $hasLoaiVatTThuCung = app_column_exists($conn, 'thucung', 'loaivattthucung');

        if (!$hasLoaiThuCung && !$hasLoaiVatThuCung && !$hasLoaiVatTThuCung) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay cot loai thu cung trong bang thucung',
            ], 500);
        }

        $petTypeColumn = $hasLoaiThuCung
            ? 'loaithucung'
            : ($hasLoaiVatThuCung ? 'loaivatthucung' : 'loaivattthucung');

        $sql = "
            SELECT
                t.id,
                t.tenthucung,
                t.{$petTypeColumn} AS loaithucung,
                t.giongthucung,
                t.chusohuu_id,
                k.tenkhachhang AS tenchusohuu,
                t.trangthaithucung,
                t.ngaydangkythucung
            FROM thucung t
            LEFT JOIN khachhang k ON k.id = t.chusohuu_id
            ORDER BY t.id DESC
            LIMIT 300
        ";

        $result = $conn->query($sql);
        if (!$result) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Truy van bang thucung that bai',
                'error' => $conn->error,
            ], 500);
        }

        $data = [];
        $healthyCount = 0;

        while ($row = $result->fetch_assoc()) {
            $status = (string) $row['trangthaithucung'];
            if (
                stripos($status, 'khoe') !== false ||
                stripos($status, 'khoẻ') !== false ||
                stripos($status, 'tiem') !== false ||
                stripos($status, 'tiêm') !== false
            ) {
                $healthyCount++;
            }

            $data[] = [
                'id' => (int) $row['id'],
                'tenthucung' => (string) $row['tenthucung'],
                'loaithucung' => (string) $row['loaithucung'],
                'giongthucung' => (string) $row['giongthucung'],
                'chusohuu_id' => (int) $row['chusohuu_id'],
                'tenchusohuu' => (string) ($row['tenchusohuu'] ?? ''),
                'trangthaithucung' => $status,
                'ngaydangkythucung' => (string) $row['ngaydangkythucung'],
            ];
        }

        $result->free();
        $conn->close();

        app_json_response([
            'ok' => true,
            'count' => count($data),
            'summary' => [
                'total' => count($data),
                'healthy_like' => $healthyCount,
                'need_follow_up' => count($data) - $healthyCount,
            ],
            'data' => $data,
        ]);
    }

    if ($api === 'get_users') {
        if (!app_table_exists($conn, 'nguoidung')) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay bang nguoidung',
            ], 500);
        }

        $sql = "
            SELECT
                id,
                tennguoidung,
                emailnguoidung,
                vaitronguoidung,
                ngaytaonguoidung
            FROM nguoidung
            ORDER BY id ASC
            LIMIT 500
        ";

        $result = $conn->query($sql);
        if (!$result) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Truy van bang nguoidung that bai',
                'error' => $conn->error,
            ], 500);
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'id' => (int) ($row['id'] ?? 0),
                'tennguoidung' => (string) ($row['tennguoidung'] ?? ''),
                'emailnguoidung' => (string) ($row['emailnguoidung'] ?? ''),
                'vaitronguoidung' => app_normalize_role((string) ($row['vaitronguoidung'] ?? 'user')),
                'ngaytaonguoidung' => (string) ($row['ngaytaonguoidung'] ?? ''),
            ];
        }

        $result->free();
        $conn->close();

        app_json_response([
            'ok' => true,
            'count' => count($data),
            'data' => $data,
        ]);
    }

    if ($api === 'login_user') {
        if (!app_table_exists($conn, 'nguoidung')) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay bang nguoidung',
            ], 500);
        }

        $input = app_input_payload();
        $identifier = trim((string) ($input['identifier'] ?? $input['email'] ?? $input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($identifier === '' || $password === '') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Vui long nhap tai khoan va mat khau',
            ], 400);
        }

        $identifierLower = app_lower($identifier);
        $sql = "
            SELECT id, tennguoidung, emailnguoidung, matkhaunguoidung, vaitronguoidung, ngaytaonguoidung
            FROM nguoidung
            WHERE LOWER(COALESCE(emailnguoidung, '')) = ?
               OR LOWER(COALESCE(tennguoidung, '')) = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the xu ly dang nhap',
                'error' => $conn->error,
            ], 500);
        }

        $stmt->bind_param('ss', $identifierLower, $identifierLower);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();

        if (!is_array($row)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Sai tai khoan hoac mat khau',
            ], 401);
        }

        $storedPassword = (string) ($row['matkhaunguoidung'] ?? '');
        if (!app_password_verify_compat($password, $storedPassword)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Sai tai khoan hoac mat khau',
            ], 401);
        }

        $role = app_normalize_role((string) ($row['vaitronguoidung'] ?? 'user'));
        $userPayload = [
            'id' => (int) ($row['id'] ?? 0),
            'tennguoidung' => (string) ($row['tennguoidung'] ?? ''),
            'emailnguoidung' => (string) ($row['emailnguoidung'] ?? ''),
            'vaitronguoidung' => $role,
            'ngaytaonguoidung' => (string) ($row['ngaytaonguoidung'] ?? ''),
        ];

        $conn->close();
        app_json_response([
            'ok' => true,
            'message' => 'Dang nhap thanh cong',
            'data' => $userPayload,
            'user' => [
                'id' => $userPayload['id'],
                'name' => $userPayload['tennguoidung'],
                'identifier' => $userPayload['emailnguoidung'] !== '' ? $userPayload['emailnguoidung'] : $userPayload['tennguoidung'],
                'role' => $role,
            ],
            'token' => '',
        ]);
    }

    if ($api === 'manage_entity') {
        $input = app_input_payload();
        $action = trim((string) ($input['action'] ?? ''));
        $entity = trim((string) ($input['entity'] ?? ''));

        if ($action === '' || $entity === '') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Thieu action hoac entity',
            ], 400);
        }

        if (!in_array($action, ['create', 'update', 'delete'], true)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Action khong hop le',
            ], 400);
        }

        if ($entity === 'services') {
            app_ensure_dichvu_image_column($conn);

            if ($action === 'create') {
                $name = trim((string) ($input['tendichvu'] ?? ''));
                $price = (float) ($input['giadichvu'] ?? 0);
                $duration = (int) ($input['thoigiandichvu'] ?? 0);
                $status = trim((string) ($input['trangthaidichvu'] ?? 'hoatdong'));
                $image = trim((string) ($input['hinhanhdichvu'] ?? ''));

                if ($name === '' || $duration <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu dich vu khong hop le'], 400);
                }

                $stmt = $conn->prepare('INSERT INTO dichvu (tendichvu, giadichvu, thoigiandichvu, trangthaidichvu, hinhanhdichvu) VALUES (?, ?, ?, ?, ?)');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the them dich vu', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sdiss', $name, $price, $duration, $status, $image);
                $ok = $stmt->execute();
                $newId = (int) $conn->insert_id;
                $stmt->close();

                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Them dich vu that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query('SELECT id, tendichvu, giadichvu, thoigiandichvu, trangthaidichvu, hinhanhdichvu, ngaytaodichvu FROM dichvu WHERE id = ' . $newId . ' LIMIT 1');
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $row]);
            }

            if ($action === 'update') {
                $id = (int) ($input['id'] ?? 0);
                $name = trim((string) ($input['tendichvu'] ?? ''));
                $price = (float) ($input['giadichvu'] ?? 0);
                $duration = (int) ($input['thoigiandichvu'] ?? 0);
                $status = trim((string) ($input['trangthaidichvu'] ?? 'hoatdong'));
                $image = trim((string) ($input['hinhanhdichvu'] ?? ''));

                if ($id <= 0 || $name === '' || $duration <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu cap nhat dich vu khong hop le'], 400);
                }

                $stmt = $conn->prepare('UPDATE dichvu SET tendichvu = ?, giadichvu = ?, thoigiandichvu = ?, trangthaidichvu = ?, hinhanhdichvu = ? WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the cap nhat dich vu', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sdissi', $name, $price, $duration, $status, $image, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Cap nhat dich vu that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query('SELECT id, tendichvu, giadichvu, thoigiandichvu, trangthaidichvu, hinhanhdichvu, ngaytaodichvu FROM dichvu WHERE id = ' . $id . ' LIMIT 1');
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $row]);
            }

            if ($action === 'delete') {
                $id = (int) ($input['id'] ?? 0);
                if ($id <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'ID dich vu khong hop le'], 400);
                }

                $stmt = $conn->prepare('DELETE FROM dichvu WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the xoa dich vu', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('i', $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Xoa dich vu that bai', 'error' => $conn->error], 500);
                }

                $conn->close();
                app_json_response(['ok' => true]);
            }
        }

        if ($entity === 'products') {
            if ($action === 'create') {
                $name = trim((string) ($input['tensanpham'] ?? ''));
                $code = trim((string) ($input['masanpham'] ?? ''));
                $categoryName = trim((string) ($input['tendanhmuc'] ?? ''));
                $price = (float) ($input['giasanpham'] ?? 0);
                $qty = (int) ($input['soluongsanpham'] ?? 0);
                $status = trim((string) ($input['trangthaisanpham'] ?? 'conhang'));
                $image = trim((string) ($input['hinhanhsanpham'] ?? ''));

                if ($name === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Ten san pham khong hop le'], 400);
                }

                $danhmucId = app_resolve_category_id($conn, $categoryName);
                if ($code === '') {
                    $code = 'SP' . str_pad((string) time(), 10, '0', STR_PAD_LEFT);
                }

                $stmt = $conn->prepare('INSERT INTO sanpham (tensanpham, danhmuc_id, masanpham, giasanpham, soluongsanpham, trangthaisanpham, hinhanhsanpham) VALUES (?, ?, ?, ?, ?, ?, ?)');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the them san pham', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sisdiss', $name, $danhmucId, $code, $price, $qty, $status, $image);
                $ok = $stmt->execute();
                $newId = (int) $conn->insert_id;
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Them san pham that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query("SELECT s.id, s.tensanpham, s.danhmuc_id, COALESCE(d.tendanhmuc, 'Chua phan loai') AS tendanhmuc, s.masanpham, s.giasanpham, s.soluongsanpham, s.trangthaisanpham, s.hinhanhsanpham FROM sanpham s LEFT JOIN danhmuc d ON d.id = s.danhmuc_id WHERE s.id = {$newId} LIMIT 1");
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $row]);
            }

            if ($action === 'update') {
                $id = (int) ($input['id'] ?? 0);
                $name = trim((string) ($input['tensanpham'] ?? ''));
                $code = trim((string) ($input['masanpham'] ?? ''));
                $categoryName = trim((string) ($input['tendanhmuc'] ?? ''));
                $price = (float) ($input['giasanpham'] ?? 0);
                $qty = (int) ($input['soluongsanpham'] ?? 0);
                $status = trim((string) ($input['trangthaisanpham'] ?? 'conhang'));
                $image = trim((string) ($input['hinhanhsanpham'] ?? ''));

                if ($id <= 0 || $name === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu cap nhat san pham khong hop le'], 400);
                }

                $existingResult = $conn->query('SELECT danhmuc_id, masanpham FROM sanpham WHERE id = ' . $id . ' LIMIT 1');
                $existing = $existingResult ? $existingResult->fetch_assoc() : null;
                if ($existingResult) {
                    $existingResult->free();
                }
                if (!$existing) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong tim thay san pham'], 404);
                }

                $danhmucId = app_resolve_category_id($conn, $categoryName, (int) ($existing['danhmuc_id'] ?? 0));
                if ($code === '') {
                    $code = (string) ($existing['masanpham'] ?? '');
                }

                $stmt = $conn->prepare('UPDATE sanpham SET tensanpham = ?, danhmuc_id = ?, masanpham = ?, giasanpham = ?, soluongsanpham = ?, trangthaisanpham = ?, hinhanhsanpham = ? WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the cap nhat san pham', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sisdissi', $name, $danhmucId, $code, $price, $qty, $status, $image, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Cap nhat san pham that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query("SELECT s.id, s.tensanpham, s.danhmuc_id, COALESCE(d.tendanhmuc, 'Chua phan loai') AS tendanhmuc, s.masanpham, s.giasanpham, s.soluongsanpham, s.trangthaisanpham, s.hinhanhsanpham FROM sanpham s LEFT JOIN danhmuc d ON d.id = s.danhmuc_id WHERE s.id = {$id} LIMIT 1");
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $row]);
            }

            if ($action === 'delete') {
                $id = (int) ($input['id'] ?? 0);
                if ($id <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'ID san pham khong hop le'], 400);
                }

                $stmt = $conn->prepare('DELETE FROM sanpham WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the xoa san pham', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('i', $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Xoa san pham that bai', 'error' => $conn->error], 500);
                }

                $conn->close();
                app_json_response(['ok' => true]);
            }
        }

        if ($entity === 'customers') {
            if ($action === 'create') {
                $name = trim((string) ($input['tenkhachhang'] ?? ''));
                $phone = trim((string) ($input['sodienthoaikhachhang'] ?? ''));
                $email = trim((string) ($input['emailkhachhang'] ?? ''));
                $spending = (float) ($input['tongchitieukhachhang'] ?? 0);
                $type = trim((string) ($input['loaikhachhang'] ?? 'thuong'));

                if ($name === '' || $phone === '' || $email === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu khach hang khong hop le'], 400);
                }

                $stmt = $conn->prepare('INSERT INTO khachhang (tenkhachhang, sodienthoaikhachhang, emailkhachhang, tongchitieukhachhang, loaikhachhang) VALUES (?, ?, ?, ?, ?)');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the them khach hang', 'error' => $conn->error], 500);
                }
                $stmt->bind_param('sssds', $name, $phone, $email, $spending, $type);
                $ok = $stmt->execute();
                $newId = (int) $conn->insert_id;
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Them khach hang that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query('SELECT id, tenkhachhang, sodienthoaikhachhang, emailkhachhang, tongchitieukhachhang, loaikhachhang, ngaytaokhachhang FROM khachhang WHERE id = ' . $newId . ' LIMIT 1');
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }
                if (is_array($row)) {
                    $row['so_thu_cung'] = 0;
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $row]);
            }

            if ($action === 'update') {
                $id = (int) ($input['id'] ?? 0);
                $name = trim((string) ($input['tenkhachhang'] ?? ''));
                $phone = trim((string) ($input['sodienthoaikhachhang'] ?? ''));
                $email = trim((string) ($input['emailkhachhang'] ?? ''));
                $spending = (float) ($input['tongchitieukhachhang'] ?? 0);
                $type = trim((string) ($input['loaikhachhang'] ?? 'thuong'));

                if ($id <= 0 || $name === '' || $phone === '' || $email === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu cap nhat khach hang khong hop le'], 400);
                }

                $stmt = $conn->prepare('UPDATE khachhang SET tenkhachhang = ?, sodienthoaikhachhang = ?, emailkhachhang = ?, tongchitieukhachhang = ?, loaikhachhang = ? WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the cap nhat khach hang', 'error' => $conn->error], 500);
                }
                $stmt->bind_param('sssdsi', $name, $phone, $email, $spending, $type, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Cap nhat khach hang that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query('SELECT k.id, k.tenkhachhang, k.sodienthoaikhachhang, k.emailkhachhang, k.tongchitieukhachhang, k.loaikhachhang, k.ngaytaokhachhang, COUNT(t.id) AS so_thu_cung FROM khachhang k LEFT JOIN thucung t ON t.chusohuu_id = k.id WHERE k.id = ' . $id . ' GROUP BY k.id, k.tenkhachhang, k.sodienthoaikhachhang, k.emailkhachhang, k.tongchitieukhachhang, k.loaikhachhang, k.ngaytaokhachhang LIMIT 1');
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $row]);
            }

            if ($action === 'delete') {
                $id = (int) ($input['id'] ?? 0);
                if ($id <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'ID khach hang khong hop le'], 400);
                }

                $stmt = $conn->prepare('DELETE FROM khachhang WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the xoa khach hang', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('i', $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Xoa khach hang that bai. Co the khach hang da duoc lien ket voi thu cung.', 'error' => $conn->error], 500);
                }

                $conn->close();
                app_json_response(['ok' => true]);
            }
        }

        if ($entity === 'pets') {
            $petTypeColumn = app_detect_pet_type_column($conn);
            if ($petTypeColumn === '') {
                $conn->close();
                app_json_response(['ok' => false, 'message' => 'Khong tim thay cot loai thu cung trong bang thucung'], 500);
            }

            if ($action === 'create') {
                $name = trim((string) ($input['tenthucung'] ?? ''));
                $type = trim((string) ($input['loaithucung'] ?? ''));
                $breed = trim((string) ($input['giongthucung'] ?? ''));
                $ownerId = (int) ($input['chusohuu_id'] ?? 0);
                $status = trim((string) ($input['trangthaithucung'] ?? ''));
                $regDate = trim((string) ($input['ngaydangkythucung'] ?? ''));

                if ($name === '' || $type === '' || $breed === '' || $ownerId <= 0 || $status === '' || $regDate === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu thu cung khong hop le'], 400);
                }

                $ownerCheck = $conn->query('SELECT id FROM khachhang WHERE id = ' . $ownerId . ' LIMIT 1');
                $ownerRow = $ownerCheck ? $ownerCheck->fetch_assoc() : null;
                if ($ownerCheck) {
                    $ownerCheck->free();
                }
                if (!$ownerRow) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Chu so huu khong ton tai'], 400);
                }

                $sqlInsert = "INSERT INTO thucung (tenthucung, {$petTypeColumn}, giongthucung, chusohuu_id, trangthaithucung, ngaydangkythucung) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sqlInsert);
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the them thu cung', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sssiss', $name, $type, $breed, $ownerId, $status, $regDate);
                $ok = $stmt->execute();
                $newId = (int) $conn->insert_id;
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Them thu cung that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query("SELECT t.id, t.tenthucung, t.{$petTypeColumn} AS loaithucung, t.giongthucung, t.chusohuu_id, k.tenkhachhang AS tenchusohuu, t.trangthaithucung, t.ngaydangkythucung FROM thucung t LEFT JOIN khachhang k ON k.id = t.chusohuu_id WHERE t.id = {$newId} LIMIT 1");
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $row]);
            }

            if ($action === 'update') {
                $id = (int) ($input['id'] ?? 0);
                $name = trim((string) ($input['tenthucung'] ?? ''));
                $type = trim((string) ($input['loaithucung'] ?? ''));
                $breed = trim((string) ($input['giongthucung'] ?? ''));
                $ownerId = (int) ($input['chusohuu_id'] ?? 0);
                $status = trim((string) ($input['trangthaithucung'] ?? ''));
                $regDate = trim((string) ($input['ngaydangkythucung'] ?? ''));

                if ($id <= 0 || $name === '' || $type === '' || $breed === '' || $ownerId <= 0 || $status === '' || $regDate === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu cap nhat thu cung khong hop le'], 400);
                }

                $ownerCheck = $conn->query('SELECT id FROM khachhang WHERE id = ' . $ownerId . ' LIMIT 1');
                $ownerRow = $ownerCheck ? $ownerCheck->fetch_assoc() : null;
                if ($ownerCheck) {
                    $ownerCheck->free();
                }
                if (!$ownerRow) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Chu so huu khong ton tai'], 400);
                }

                $sqlUpdate = "UPDATE thucung SET tenthucung = ?, {$petTypeColumn} = ?, giongthucung = ?, chusohuu_id = ?, trangthaithucung = ?, ngaydangkythucung = ? WHERE id = ?";
                $stmt = $conn->prepare($sqlUpdate);
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the cap nhat thu cung', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sssissi', $name, $type, $breed, $ownerId, $status, $regDate, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Cap nhat thu cung that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query("SELECT t.id, t.tenthucung, t.{$petTypeColumn} AS loaithucung, t.giongthucung, t.chusohuu_id, k.tenkhachhang AS tenchusohuu, t.trangthaithucung, t.ngaydangkythucung FROM thucung t LEFT JOIN khachhang k ON k.id = t.chusohuu_id WHERE t.id = {$id} LIMIT 1");
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $row]);
            }

            if ($action === 'delete') {
                $id = (int) ($input['id'] ?? 0);
                if ($id <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'ID thu cung khong hop le'], 400);
                }

                $stmt = $conn->prepare('DELETE FROM thucung WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the xoa thu cung', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('i', $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Xoa thu cung that bai', 'error' => $conn->error], 500);
                }

                $conn->close();
                app_json_response(['ok' => true]);
            }
        }

        $conn->close();
        app_json_response([
            'ok' => false,
            'message' => 'Entity khong hop le',
        ], 400);
    }

    if ($api === 'create_online_order') {
        if (!app_ensure_online_orders_table($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tao bang donhang_online',
                'error' => $conn->error,
            ], 500);
        }

        $input = app_input_payload();
        $customerName = trim((string) ($input['customer_name'] ?? ''));
        $customerPhone = trim((string) ($input['customer_phone'] ?? ''));
        $customerEmail = trim((string) ($input['customer_email'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));
        $total = (float) ($input['total'] ?? 0);
        $items = $input['items'] ?? [];
        $accountUserId = (int) ($input['user_id'] ?? 0);

        if ($customerName === '' || $customerPhone === '' || $address === '' || $total <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Thong tin dat hang online chua day du',
            ], 400);
        }

        $orderCode = app_generate_online_order_code();
        $itemsJson = json_encode(is_array($items) ? $items : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($itemsJson)) {
            $itemsJson = '[]';
        }

        $customerId = 0;
        if (app_table_exists($conn, 'khachhang')) {
            $findCustomerSql = "
                SELECT id
                FROM khachhang
                WHERE (emailkhachhang IS NOT NULL AND LOWER(emailkhachhang) = LOWER(?))
                   OR (sodienthoaikhachhang IS NOT NULL AND sodienthoaikhachhang = ?)
                LIMIT 1
            ";

            $findStmt = $conn->prepare($findCustomerSql);
            if ($findStmt) {
                $findStmt->bind_param('ss', $customerEmail, $customerPhone);
                $findStmt->execute();
                $findResult = $findStmt->get_result();
                $findRow = $findResult ? $findResult->fetch_assoc() : null;
                if ($findResult) {
                    $findResult->free();
                }
                $findStmt->close();
                if (is_array($findRow)) {
                    $customerId = (int) ($findRow['id'] ?? 0);
                }
            }

            if ($customerId <= 0) {
                $insertCustomerSql = "
                    INSERT INTO khachhang
                        (tenkhachhang, sodienthoaikhachhang, emailkhachhang, tongchitieukhachhang, loaikhachhang)
                    VALUES
                        (?, ?, ?, 0, 'thuong')
                ";

                $insertCustomerStmt = $conn->prepare($insertCustomerSql);
                if ($insertCustomerStmt) {
                    $insertCustomerStmt->bind_param('sss', $customerName, $customerPhone, $customerEmail);
                    $insertCustomerStmt->execute();
                    $customerId = (int) $insertCustomerStmt->insert_id;
                    $insertCustomerStmt->close();
                }
            }
        }

        $internalOrderId = 0;
        if (app_table_exists($conn, 'donhang')) {
            $orderStatus = app_map_online_to_order_status('cho_duyet');
            $insertDonHangSql = "
                INSERT INTO donhang
                    (khachhang_id, ngaydatdonhang, tongtiendonhang, trangthaidonhang)
                VALUES
                    (NULLIF(?, 0), CURDATE(), ?, ?)
            ";
            $insertDonHangStmt = $conn->prepare($insertDonHangSql);
            if ($insertDonHangStmt) {
                $customerIdForOrder = $customerId > 0 ? $customerId : 0;
                $insertDonHangStmt->bind_param('ids', $customerIdForOrder, $total, $orderStatus);
                $insertDonHangStmt->execute();
                $internalOrderId = (int) $insertDonHangStmt->insert_id;
                $insertDonHangStmt->close();
            }
        }

        if ($internalOrderId > 0 && app_table_exists($conn, 'lichsudonhang')) {
            $historyNote = 'Don online moi tao';
            if ($accountUserId > 0) {
                $historyNote .= ' - nguoidung_id: ' . $accountUserId;
            }
            $insertHistorySql = "
                INSERT INTO lichsudonhang (donhang_id, trangthai, ghichu)
                VALUES (?, 'dangxuly', ?)
            ";
            $insertHistoryStmt = $conn->prepare($insertHistorySql);
            if ($insertHistoryStmt) {
                $insertHistoryStmt->bind_param('is', $internalOrderId, $historyNote);
                $insertHistoryStmt->execute();
                $insertHistoryStmt->close();
            }
        }

        $insertSql = "
            INSERT INTO donhang_online
                (donhang_id, madonhang, tenkhachhang, sodienthoai, email, diachi, ghichu, tongtien, trangthai, nguon, chitiet_json)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, 'cho_duyet', 'online', ?)
        ";

        $stmt = $conn->prepare($insertSql);
        if (!$stmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tao don online',
                'error' => $conn->error,
            ], 500);
        }

        $stmt->bind_param(
            'issssssds',
            $internalOrderId,
            $orderCode,
            $customerName,
            $customerPhone,
            $customerEmail,
            $address,
            $note,
            $total,
            $itemsJson
        );

        $ok = $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        if (!$ok) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the luu don online',
                'error' => $conn->error,
            ], 500);
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'message' => 'Tao don online thanh cong',
            'data' => [
                'id' => $newId,
                'donhang_id' => $internalOrderId,
                'madonhang' => $orderCode,
                'trangthai' => 'cho_duyet',
            ],
        ]);
    }

    if ($api === 'get_online_orders') {
        if (!app_ensure_online_orders_table($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the truy cap bang donhang_online',
                'error' => $conn->error,
            ], 500);
        }

        $status = trim((string) ($_GET['status'] ?? ''));
        $keyword = trim((string) ($_GET['keyword'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 300);
        if ($limit <= 0 || $limit > 1000) {
            $limit = 300;
        }

        $where = [];
        $params = [];
        $types = '';

        if ($status !== '') {
            $where[] = 'trangthai = ?';
            $types .= 's';
            $params[] = $status;
        }

        if ($keyword !== '') {
            $where[] = '(madonhang LIKE ? OR tenkhachhang LIKE ? OR sodienthoai LIKE ?)';
            $like = '%' . $keyword . '%';
            $types .= 'sss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "
            SELECT
                id,
                donhang_id,
                madonhang,
                tenkhachhang,
                sodienthoai,
                email,
                diachi,
                ghichu,
                tongtien,
                trangthai,
                ldotuchoi,
                nguoiduyet,
                nguon,
                chitiet_json,
                ngaytao,
                ngaycapnhat
            FROM donhang_online
        ";

        if (count($where) > 0) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ?';
        $types .= 'i';
        $params[] = $limit;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the lay danh sach don online',
                'error' => $conn->error,
            ], 500);
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $summary = [
            'cho_duyet' => 0,
            'da_duyet' => 0,
            'tu_choi' => 0,
        ];

        while ($row = $result->fetch_assoc()) {
            $statusKey = (string) ($row['trangthai'] ?? 'cho_duyet');
            if (isset($summary[$statusKey])) {
                $summary[$statusKey]++;
            }

            $items = json_decode((string) ($row['chitiet_json'] ?? '[]'), true);
            if (!is_array($items)) {
                $items = [];
            }

            $data[] = [
                'id' => (int) ($row['id'] ?? 0),
                'donhang_id' => (int) ($row['donhang_id'] ?? 0),
                'madonhang' => (string) ($row['madonhang'] ?? ''),
                'tenkhachhang' => (string) ($row['tenkhachhang'] ?? ''),
                'sodienthoai' => (string) ($row['sodienthoai'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'diachi' => (string) ($row['diachi'] ?? ''),
                'ghichu' => (string) ($row['ghichu'] ?? ''),
                'tongtien' => (float) ($row['tongtien'] ?? 0),
                'trangthai' => $statusKey,
                'ldotuchoi' => (string) ($row['ldotuchoi'] ?? ''),
                'nguoiduyet' => (string) ($row['nguoiduyet'] ?? ''),
                'nguon' => (string) ($row['nguon'] ?? 'online'),
                'items' => $items,
                'ngaytao' => (string) ($row['ngaytao'] ?? ''),
                'ngaycapnhat' => (string) ($row['ngaycapnhat'] ?? ''),
            ];
        }

        $result->free();
        $stmt->close();
        $conn->close();

        app_json_response([
            'ok' => true,
            'count' => count($data),
            'summary' => $summary,
            'data' => $data,
        ]);
    }

    if ($api === 'update_online_order_status') {
        if (!app_ensure_online_orders_table($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the truy cap bang donhang_online',
                'error' => $conn->error,
            ], 500);
        }

        $input = app_input_payload();
        $id = (int) ($input['id'] ?? 0);
        $orderCode = trim((string) ($input['madonhang'] ?? ''));
        $status = trim((string) ($input['status'] ?? ''));
        $reviewer = trim((string) ($input['reviewer'] ?? ''));
        $rejectReason = trim((string) ($input['reject_reason'] ?? ''));

        $allowedStatuses = ['cho_duyet', 'da_duyet', 'tu_choi'];
        if (!in_array($status, $allowedStatuses, true)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Trang thai cap nhat khong hop le',
            ], 400);
        }

        if ($id <= 0 && $orderCode === '') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Thieu id hoac madonhang',
            ], 400);
        }

        $findSql = "
            SELECT id, donhang_id, madonhang
            FROM donhang_online
            WHERE " . ($id > 0 ? 'id = ?' : 'madonhang = ?') . "
            LIMIT 1
        ";

        $findStmt = $conn->prepare($findSql);
        if (!$findStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tim don online',
                'error' => $conn->error,
            ], 500);
        }

        if ($id > 0) {
            $findStmt->bind_param('i', $id);
        } else {
            $findStmt->bind_param('s', $orderCode);
        }
        $findStmt->execute();
        $findResult = $findStmt->get_result();
        $targetRow = $findResult ? $findResult->fetch_assoc() : null;
        if ($findResult) {
            $findResult->free();
        }
        $findStmt->close();

        if (!is_array($targetRow)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay don online de cap nhat',
            ], 404);
        }

        $onlineId = (int) ($targetRow['id'] ?? 0);
        $internalOrderId = (int) ($targetRow['donhang_id'] ?? 0);

        $sql = "
            UPDATE donhang_online
            SET trangthai = ?, ldotuchoi = ?, nguoiduyet = ?
            WHERE id = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the cap nhat don online',
                'error' => $conn->error,
            ], 500);
        }

        $rejectText = $status === 'tu_choi' ? $rejectReason : '';
        $reviewerText = $reviewer !== '' ? $reviewer : 'staff';

        $stmt->bind_param('sssi', $status, $rejectText, $reviewerText, $onlineId);

        $stmt->execute();
        $stmt->close();

        $orderStatus = app_map_online_to_order_status($status);
        if ($internalOrderId > 0 && app_table_exists($conn, 'donhang')) {
            $updateDonHangSql = "UPDATE donhang SET trangthaidonhang = ? WHERE id = ? LIMIT 1";
            $updateDonHangStmt = $conn->prepare($updateDonHangSql);
            if ($updateDonHangStmt) {
                $updateDonHangStmt->bind_param('si', $orderStatus, $internalOrderId);
                $updateDonHangStmt->execute();
                $updateDonHangStmt->close();
            }
        }

        if ($internalOrderId > 0 && app_table_exists($conn, 'lichsudonhang')) {
            $historyNote = $status === 'tu_choi'
                ? ('Nhan vien huy don online. Ly do: ' . ($rejectText !== '' ? $rejectText : 'Khong co'))
                : ('Cap nhat don online boi: ' . $reviewerText);

            $insertHistorySql = "
                INSERT INTO lichsudonhang (donhang_id, trangthai, ghichu)
                VALUES (?, ?, ?)
            ";
            $insertHistoryStmt = $conn->prepare($insertHistorySql);
            if ($insertHistoryStmt) {
                $insertHistoryStmt->bind_param('iss', $internalOrderId, $orderStatus, $historyNote);
                $insertHistoryStmt->execute();
                $insertHistoryStmt->close();
            }
        }

        $conn->close();

        app_json_response([
            'ok' => true,
            'message' => 'Cap nhat trang thai don online thanh cong',
        ]);
    }

    $conn->close();
    app_json_response([
        'ok' => false,
        'message' => 'API khong hop le',
    ], 404);
}

$target = app_base_path() . '/Giao%20Di%E1%BB%87n/dang-nhap.html';
header('Location: ' . $target);
exit;
