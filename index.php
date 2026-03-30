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

function app_starts_with(string $text, string $prefix): bool
{
    return strncmp($text, $prefix, strlen($prefix)) === 0;
}

function app_service_default_image_path(string $serviceName, int $serviceId): string
{
    $name = mb_strtolower(trim($serviceName), 'UTF-8');

    if (strpos($name, 'spa') !== false || strpos($name, 'tạo kiểu') !== false) {
        return 'Giao Diện/user/anhdata/pet_011.jpg';
    }

    if (strpos($name, 'khách sạn') !== false || strpos($name, 'lưu trú') !== false) {
        return 'Giao Diện/user/anhdata/pet_012.jpg';
    }

    if (strpos($name, 'tắm') !== false || strpos($name, 'sấy') !== false) {
        return 'Giao Diện/user/anhdata/pet_013.jpg';
    }

    if (strpos($name, 'vệ sinh tai') !== false || strpos($name, 'tai') !== false) {
        return 'Giao Diện/user/anhdata/pet_014.jpg';
    }

    if (strpos($name, 'cắt móng') !== false || strpos($name, 'móng') !== false) {
        return 'Giao Diện/user/anhdata/pet_015.jpg';
    }

    $index = 16 + ($serviceId % 10);
    return 'Giao Diện/user/anhdata/pet_' . str_pad((string) $index, 3, '0', STR_PAD_LEFT) . '.jpg';
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

    if (app_starts_with($path, 'anhdata/')) {
        $path = 'Giao Diện/user/' . $path;
    } elseif (app_starts_with($path, 'user/anhdata/')) {
        $path = 'Giao Diện/' . $path;
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
                'hinhanhsanpham' => (string) $row['hinhanhsanpham'],
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

    $conn->close();
    app_json_response([
        'ok' => false,
        'message' => 'API khong hop le',
    ], 404);
}

$target = app_base_path() . '/Giao%20Di%E1%BB%87n/user/home.html';
header('Location: ' . $target);
exit;
