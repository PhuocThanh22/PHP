<?php

mysqli_report(MYSQLI_REPORT_OFF);

$appLocalConfig = [];
$appConfigPath = __DIR__ . '/oauth-config.php';
if (is_file($appConfigPath)) {
    $loadedConfig = require $appConfigPath;
    if (is_array($loadedConfig)) {
        $appLocalConfig = $loadedConfig;
    }
}

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
    global $appLocalConfig;

    if (is_array($appLocalConfig) && isset($appLocalConfig[$key]) && trim((string) $appLocalConfig[$key]) !== '') {
        return trim((string) $appLocalConfig[$key]);
    }

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

function app_ensure_product_subcategory_column(mysqli $conn): bool
{
    if (!app_table_exists($conn, 'sanpham')) {
        return false;
    }

    if (app_column_exists($conn, 'sanpham', 'danhmuccon')) {
        return true;
    }

    return (bool) $conn->query("ALTER TABLE sanpham ADD COLUMN danhmuccon VARCHAR(120) NULL AFTER danhmuc_id");
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

    if (
        $normalized === 'admin' ||
        $normalized === 'administrator' ||
        $normalized === 'quantri' ||
        $normalized === 'quan tri' ||
        $normalized === 'quan_tri' ||
        $normalized === 'quản trị' ||
        $normalized === 'quản trị viên'
    ) {
        return 'admin';
    }

    if (
        $normalized === 'staff' ||
        $normalized === 'nhanvien' ||
        $normalized === 'nhan vien' ||
        $normalized === 'nhan_vien' ||
        $normalized === 'nhân viên'
    ) {
        return 'staff';
    }

    if ($normalized === 'khachhang' || $normalized === 'khach hang' || $normalized === 'khách hàng') {
        return 'user';
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
    if (!defined('APP_RUNNING_FROM_INDEX')) {
        define('APP_RUNNING_FROM_INDEX', true);
    }

    require_once __DIR__ . '/Giao Diện/admin/get_services.php';
    require_once __DIR__ . '/Giao Diện/staff/get_services.php';
    require_once __DIR__ . '/Giao Diện/user/home_api.php';

    $conn = app_db_connect();

    $handled = app_handle_admin_api($conn, $api)
        || app_handle_staff_api($conn, $api)
        || app_handle_user_api($conn, $api);

    if (!$handled) {
        $conn->close();
        app_json_response([
            'ok' => false,
            'message' => 'API khong hop le',
        ], 404);
    }
}

$target = app_base_path() . '/Giao%20Di%E1%BB%87n/dang-nhap.html';
header('Location: ' . $target);
exit;
