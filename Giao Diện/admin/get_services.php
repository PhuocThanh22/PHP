<?php

if (!defined('APP_RUNNING_FROM_INDEX')) {
    $api = trim((string) ($_GET['api'] ?? 'get_services'));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'POST') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }

        $action = trim((string) ($input['action'] ?? ''));
        $entity = trim((string) ($input['entity'] ?? ''));
        if ($action !== '' || $entity !== '') {
            $api = 'manage_entity';
        }
    }

    $_GET['api'] = $api;
    require dirname(__DIR__, 2) . '/index.php';
    exit;
}

function app_ensure_category_image_column(mysqli $conn): bool
{
    if (app_column_exists($conn, 'danhmuc', 'hinhanhdanhmuc')) {
        return true;
    }

    $alterSql = "ALTER TABLE danhmuc ADD COLUMN hinhanhdanhmuc VARCHAR(255) NULL AFTER tendanhmuc";
    return (bool) $conn->query($alterSql);
}

function app_admin_reset_cart_for_customer(mysqli $conn, int $customerId): void
{
    if ($customerId <= 0 || !app_table_exists($conn, 'giohang') || !app_table_exists($conn, 'chitietgiohang')) {
        return;
    }

    $userId = 0;
    if (app_table_exists($conn, 'khachhang') && app_column_exists($conn, 'khachhang', 'nguoidung_id')) {
        $customerStmt = $conn->prepare('SELECT nguoidung_id FROM khachhang WHERE id = ? LIMIT 1');
        if ($customerStmt) {
            $customerStmt->bind_param('i', $customerId);
            $customerStmt->execute();
            $customerResult = $customerStmt->get_result();
            $customerRow = $customerResult ? $customerResult->fetch_assoc() : null;
            if ($customerResult) {
                $customerResult->free();
            }
            $customerStmt->close();
            $userId = (int) ($customerRow['nguoidung_id'] ?? 0);
        }
    }

    if ($userId > 0) {
        $deleteDetailStmt = $conn->prepare("DELETE ct FROM chitietgiohang ct INNER JOIN giohang g ON g.id = ct.giohang_id WHERE g.khachhang_id = ? OR g.nguoidung_id = ?");
        if ($deleteDetailStmt) {
            $deleteDetailStmt->bind_param('ii', $customerId, $userId);
            $deleteDetailStmt->execute();
            $deleteDetailStmt->close();
        }

        $deleteCartStmt = $conn->prepare('DELETE FROM giohang WHERE khachhang_id = ? OR nguoidung_id = ?');
        if ($deleteCartStmt) {
            $deleteCartStmt->bind_param('ii', $customerId, $userId);
            $deleteCartStmt->execute();
            $deleteCartStmt->close();
        }
        return;
    }

    $deleteDetailStmt = $conn->prepare("DELETE ct FROM chitietgiohang ct INNER JOIN giohang g ON g.id = ct.giohang_id WHERE g.khachhang_id = ?");
    if ($deleteDetailStmt) {
        $deleteDetailStmt->bind_param('i', $customerId);
        $deleteDetailStmt->execute();
        $deleteDetailStmt->close();
    }

    $deleteCartStmt = $conn->prepare('DELETE FROM giohang WHERE khachhang_id = ?');
    if ($deleteCartStmt) {
        $deleteCartStmt->bind_param('i', $customerId);
        $deleteCartStmt->execute();
        $deleteCartStmt->close();
    }
}

function app_ensure_category_subcategory_table(mysqli $conn): bool
{
    if (!app_table_exists($conn, 'danhmuccon')) {
        $createSql = "
            CREATE TABLE danhmuccon (
                id INT NOT NULL AUTO_INCREMENT,
                danhmuc_id INT NOT NULL,
                tendanhmuccon VARCHAR(120) NOT NULL,
                ngaytao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_danhmuccon_unique (danhmuc_id, tendanhmuccon),
                KEY idx_danhmuccon_danhmuc (danhmuc_id),
                CONSTRAINT fk_danhmuccon_danhmuc
                    FOREIGN KEY (danhmuc_id) REFERENCES danhmuc(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ";

        if (!$conn->query($createSql)) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'danhmuccon', 'ngaytao')) {
        if (!$conn->query('ALTER TABLE danhmuccon ADD COLUMN ngaytao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP')) {
            return false;
        }
    }

    return true;
}

function app_get_category_subcategories_map(mysqli $conn): array
{
    if (!app_table_exists($conn, 'danhmuccon')) {
        return [];
    }

    $sql = "
        SELECT danhmuc_id, COALESCE(NULLIF(TRIM(tendanhmuccon), ''), '') AS tendanhmuccon
        FROM danhmuccon
        ORDER BY danhmuc_id ASC, tendanhmuccon ASC
        LIMIT 5000
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $map = [];
    while ($row = $result->fetch_assoc()) {
        $categoryId = (int) ($row['danhmuc_id'] ?? 0);
        $name = (string) ($row['tendanhmuccon'] ?? '');
        if ($categoryId <= 0 || $name === '') {
            continue;
        }

        if (!isset($map[$categoryId])) {
            $map[$categoryId] = [];
        }

        $map[$categoryId][] = $name;
    }
    $result->free();

    foreach ($map as $categoryId => $values) {
        $map[$categoryId] = array_values(array_unique($values));
    }

    return $map;
}

function app_get_service_categories(mysqli $conn): array
{
    if (!app_table_exists($conn, 'danhmucdichvu')) {
        return [];
    }

    $sql = "
        SELECT
            dm.id,
            dm.tendanhmucdichvu,
            COUNT(d.id) AS so_dich_vu
        FROM danhmucdichvu dm
        LEFT JOIN dichvu d ON d.danhmucdichvu_id = dm.id
        GROUP BY dm.id, dm.tendanhmucdichvu
        ORDER BY dm.tendanhmucdichvu ASC
        LIMIT 1000
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'tendanhmucdichvu' => (string) ($row['tendanhmucdichvu'] ?? ''),
            'so_dich_vu' => (int) ($row['so_dich_vu'] ?? 0),
        ];
    }
    $result->free();

    return $rows;
}

function app_resolve_service_category_id(mysqli $conn, $value): int
{
    $id = (int) $value;
    if ($id > 0) {
        return $id;
    }

    $name = trim((string) $value);
    if ($name === '') {
        return 0;
    }

    $stmt = $conn->prepare('SELECT id FROM danhmucdichvu WHERE tendanhmucdichvu = ? LIMIT 1');
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('s', $name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    return (int) ($row['id'] ?? 0);
}

function app_admin_upload_image(array $file, string $group): array
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return [false, '', 'Chua chon tep anh'];
        }
        return [false, '', 'Tai tep len that bai'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return [false, '', 'Khong doc duoc tep anh tai len'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        return [false, '', 'Kich thuoc anh khong hop le (toi da 5MB)'];
    }

    $mime = '';
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detected = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);
        $mime = is_string($detected) ? $detected : '';
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        return [false, '', 'Dinh dang anh khong ho tro (chi cho phep JPG, PNG, WEBP, GIF)'];
    }

    $folder = 'products';
    if ($group === 'categories') {
        $folder = 'categories';
    } elseif ($group === 'services') {
        $folder = 'services';
    }

    $uploadDir = dirname(__DIR__, 2) . '/anhdata/' . $folder;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        return [false, '', 'Khong the tao thu muc luu anh'];
    }

    try {
        $rand = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $rand = (string) mt_rand(100000, 999999);
    }

    $extension = $allowed[$mime];
    $fileName = $folder . '_' . date('YmdHis') . '_' . $rand . '.' . $extension;
    $targetPath = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return [false, '', 'Khong the luu tep anh'];
    }

    $relativePath = 'anhdata/' . $folder . '/' . $fileName;
    return [true, $relativePath, ''];
}

function app_admin_normalize_datetime_input(string $value): ?string
{
    $raw = trim($value);
    if ($raw === '') {
        return null;
    }

    $normalized = str_replace('T', ' ', $raw);
    if (strlen($normalized) === 16) {
        $normalized .= ':00';
    }

    $timestamp = strtotime($normalized);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function app_admin_resolve_product_status(int $qty, string $requestedStatus = 'conhang'): string
{
    if ($qty <= 0) {
        return 'hethang';
    }

    if ($qty < 5) {
        return 'saphet';
    }

    $status = trim($requestedStatus);
    if (!in_array($status, ['conhang', 'saphet', 'hethang'], true)) {
        return 'conhang';
    }

    return $status;
}

function app_admin_voucher_games(): array
{
    return [
        'jigsaw_pet' => 'Ghép hình thú cưng',
        'clip_guess_word' => 'Xem clip đoán từ',
        'word_chain' => 'Nối từ',
        'image_puzzle' => 'Đuổi hình bắt chữ',
        'pet_quiz' => 'Đố vui thú cưng',
    ];
}

function app_admin_voucher_levels(): array
{
    return [
        'easy' => 'Dễ',
        'medium' => 'Trung bình',
        'hard' => 'Khó',
    ];
}

function app_admin_customer_tier_key(float $spending): string
{
    $value = max(0, $spending);
    if ($value >= 60000000) {
        return 'kim_cuong';
    }
    if ($value >= 30000000) {
        return 'bach_kim';
    }
    if ($value >= 15000000) {
        return 'vang';
    }
    if ($value >= 5000000) {
        return 'bac';
    }
    return 'dong';
}

function app_admin_resolve_customer_type_for_storage(mysqli $conn, string $candidate): string
{
    $value = trim($candidate);
    if ($value === '') {
        $value = 'dong';
    }

    if (!app_table_exists($conn, 'khachhang') || !app_column_exists($conn, 'khachhang', 'loaikhachhang')) {
        return $value;
    }

    $columnResult = $conn->query("SHOW COLUMNS FROM khachhang LIKE 'loaikhachhang'");
    $columnRow = $columnResult ? $columnResult->fetch_assoc() : null;
    if ($columnResult) {
        $columnResult->free();
    }
    if (!is_array($columnRow)) {
        return $value;
    }

    $columnType = app_lower((string) ($columnRow['Type'] ?? ''));
    if (strpos($columnType, 'enum(') !== 0) {
        return $value;
    }

    $allowed = [];
    if (preg_match_all("/'([^']+)'/", $columnType, $matches) > 0 && isset($matches[1])) {
        foreach ($matches[1] as $enumValue) {
            $enumText = app_lower(trim((string) $enumValue));
            if ($enumText !== '') {
                $allowed[] = $enumText;
            }
        }
    }
    $allowed = array_values(array_unique($allowed));
    if (count($allowed) === 0) {
        return $value;
    }

    $normalized = app_lower($value);
    if (in_array($normalized, $allowed, true)) {
        return $normalized;
    }

    $vipTier = in_array($normalized, ['vang', 'bach_kim', 'kim_cuong'], true);
    if ($vipTier && in_array('vip', $allowed, true)) {
        return 'vip';
    }
    if (in_array('thuong', $allowed, true)) {
        return 'thuong';
    }

    return $allowed[0];
}

function app_admin_parse_id_list($value): array
{
    if (is_array($value)) {
        $rawList = $value;
    } else {
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }
        $rawList = explode(',', $raw);
    }

    $ids = [];
    foreach ($rawList as $item) {
        $id = (int) $item;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    return array_keys($ids);
}

function app_admin_sync_voucher_products(mysqli $conn, int $voucherId, array $productIds): bool
{
    // Voucher is now global for all products/services, so product mapping table is deprecated.
    return true;
}

function app_recalculate_customer_spending_from_orders(mysqli $conn, int $customerId): bool
{
    if ($customerId <= 0) {
        return false;
    }

    if (!app_table_exists($conn, 'donhang') || !app_table_exists($conn, 'khachhang')) {
        return false;
    }

    $sumSql = "
        SELECT COALESCE(SUM(COALESCE(tongtiendonhang, 0)), 0) AS tong
        FROM donhang
        WHERE khachhang_id = ?
          AND LOWER(TRIM(COALESCE(trangthaidonhang, ''))) IN ('hoanthanh', 'hoan_tat', 'hoan tat')
    ";
    $sumStmt = $conn->prepare($sumSql);
    if (!$sumStmt) {
        return false;
    }

    $sumStmt->bind_param('i', $customerId);
    $sumStmt->execute();
    $sumResult = $sumStmt->get_result();
    $sumRow = $sumResult ? $sumResult->fetch_assoc() : null;
    if ($sumResult) {
        $sumResult->free();
    }
    $sumStmt->close();

    $total = is_array($sumRow) ? (float) ($sumRow['tong'] ?? 0) : 0;

    $updateStmt = $conn->prepare('UPDATE khachhang SET tongchitieukhachhang = ? WHERE id = ? LIMIT 1');
    if (!$updateStmt) {
        return false;
    }

    $updateStmt->bind_param('di', $total, $customerId);
    $ok = $updateStmt->execute();
    $updateStmt->close();

    return $ok;
}

function app_handle_admin_api(mysqli $conn, string $api): bool
{
    if ($api === 'upload_image') {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Chi ho tro POST khi upload anh',
            ], 405);
        }

        $type = trim((string) ($_POST['type'] ?? $_GET['type'] ?? 'products'));
        if (!in_array($type, ['products', 'categories', 'services'], true)) {
            $type = 'products';
        }

        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Thieu tep anh de tai len',
            ], 400);
        }

        [$ok, $path, $message] = app_admin_upload_image($_FILES['image'], $type);
        if (!$ok) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => $message,
            ], 400);
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'data' => [
                'path' => $path,
                'url' => app_to_public_image_url($path),
            ],
        ]);
    }

    if ($api === 'get_categories') {
        app_ensure_category_image_column($conn);
        app_ensure_category_subcategory_table($conn);

        $sql = "
            SELECT
                id,
                tendanhmuc,
                COALESCE(NULLIF(TRIM(hinhanhdanhmuc), ''), '') AS hinhanhdanhmuc
            FROM danhmuc
            ORDER BY id ASC
            LIMIT 500
        ";

        $result = $conn->query($sql);
        if (!$result) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the lay danh muc',
                'error' => $conn->error,
            ], 500);
        }

        $subcategoryMap = app_get_category_subcategories_map($conn);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $rawImage = (string) ($row['hinhanhdanhmuc'] ?? '');
            $categoryId = (int) ($row['id'] ?? 0);
            $data[] = [
                'id' => $categoryId,
                'tendanhmuc' => (string) ($row['tendanhmuc'] ?? ''),
                'hinhanhdanhmuc' => $rawImage,
                'hinhanh' => app_to_public_image_url($rawImage),
                'danhmuccon' => $subcategoryMap[$categoryId] ?? [],
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

    if ($api === 'get_home_overview') {
        $countRows = static function (mysqli $connRef, string $table, string $whereSql = '1=1') : int {
            if (!app_table_exists($connRef, $table)) {
                return 0;
            }

            $result = $connRef->query("SELECT COUNT(*) AS total FROM {$table} WHERE {$whereSql}");
            if (!$result) {
                return 0;
            }

            $row = $result->fetch_assoc();
            $result->free();
            return (int) ($row['total'] ?? 0);
        };

        $invoiceCount = $countRows($conn, 'donhang');
        $productCount = $countRows($conn, 'sanpham');
        $customerCount = $countRows($conn, 'khachhang');
        $petCount = $countRows($conn, 'thucung');

        $pendingOrders = $countRows($conn, 'donhang', "LOWER(TRIM(COALESCE(trangthaidonhang, ''))) IN ('cho_duyet', 'choduyet', 'dang_xu_ly', 'dangxuly')");
        $pendingBookings = $countRows($conn, 'lichhen', "LOWER(TRIM(COALESCE(trangthailichhen, ''))) = 'choduyet'");
        $lowStockProducts = $countRows($conn, 'sanpham', 'COALESCE(soluongsanpham, 0) <= 5');
        $todayAfternoonBookings = $countRows($conn, 'lichhen', "DATE(thoigianhen) = CURDATE() AND HOUR(thoigianhen) >= 12 AND LOWER(TRIM(COALESCE(trangthailichhen, ''))) <> 'huy'");
        $callbackCustomers = $countRows($conn, 'donhang', "LOWER(TRIM(COALESCE(nguondonhang, ''))) = 'online' AND LOWER(TRIM(COALESCE(trangthaidonhang, ''))) IN ('cho_duyet', 'choduyet', 'dang_xu_ly', 'dangxuly')");

        $todoItems = [
            [
                'icon' => 'bi-cart-check',
                'label' => 'đơn hàng chờ xác nhận',
                'count' => $pendingOrders,
            ],
            [
                'icon' => 'bi-scissors',
                'label' => 'lịch grooming buổi chiều',
                'count' => $todayAfternoonBookings,
            ],
            [
                'icon' => 'bi-telephone',
                'label' => 'khách cần gọi lại',
                'count' => $callbackCustomers,
            ],
        ];

        $todoTotal = 0;
        foreach ($todoItems as $todoItem) {
            if ((int) ($todoItem['count'] ?? 0) > 0) {
                $todoTotal++;
            }
        }

        $notifications = [];
        if ($pendingOrders > 0) {
            $notifications[] = [
                'type' => 'order',
                'title' => 'Đơn hàng cần xử lý',
                'message' => $pendingOrders . ' đơn đang chờ xác nhận hoặc xử lý',
            ];
        }
        if ($pendingBookings > 0) {
            $notifications[] = [
                'type' => 'booking',
                'title' => 'Lịch hẹn chờ duyệt',
                'message' => $pendingBookings . ' lịch hẹn đang chờ duyệt',
            ];
        }
        if ($lowStockProducts > 0) {
            $notifications[] = [
                'type' => 'inventory',
                'title' => 'Cảnh báo tồn kho',
                'message' => $lowStockProducts . ' sản phẩm đang ở ngưỡng sắp hết/hết hàng',
            ];
        }

        if (count($notifications) === 0) {
            $notifications[] = [
                'type' => 'system',
                'title' => 'Hệ thống ổn định',
                'message' => 'Không có cảnh báo mới cần xử lý ngay',
            ];
        }

        $calendar = [];
        $topServices = [];
        if (app_table_exists($conn, 'lichhen')) {
            $topServiceSql = "
                SELECT
                    COALESCE(NULLIF(TRIM(COALESCE(l.tendichvu, d.tendichvu)), ''), 'Dịch vụ') AS service_name,
                    COUNT(*) AS total_bookings
                FROM lichhen l
                LEFT JOIN dichvu d ON d.id = l.dichvu_id
                WHERE YEARWEEK(l.thoigianhen, 1) = YEARWEEK(CURDATE(), 1)
                  AND LOWER(TRIM(COALESCE(l.trangthailichhen, ''))) <> 'huy'
                GROUP BY service_name
                ORDER BY total_bookings DESC, service_name ASC
                LIMIT 3
            ";
            $topServiceResult = $conn->query($topServiceSql);
            if ($topServiceResult) {
                while ($topRow = $topServiceResult->fetch_assoc()) {
                    $topServices[] = [
                        'name' => (string) ($topRow['service_name'] ?? 'Dịch vụ'),
                        'count' => (int) ($topRow['total_bookings'] ?? 0),
                    ];
                }
                $topServiceResult->free();
            }
        }

        if (app_table_exists($conn, 'lichhen')) {
            $calendarSql = "
                SELECT
                    COALESCE(NULLIF(TRIM(tendichvu), ''), 'Dịch vụ') AS tendichvu,
                    COALESCE(NULLIF(TRIM(tenkhachhang), ''), 'Khách hàng') AS tenkhachhang,
                    thoigianhen,
                    COALESCE(NULLIF(TRIM(trangthailichhen), ''), 'choduyet') AS trangthailichhen
                FROM lichhen
                WHERE thoigianhen IS NOT NULL
                                    AND DATE(thoigianhen) = CURDATE()
                  AND LOWER(TRIM(COALESCE(trangthailichhen, ''))) <> 'huy'
                ORDER BY thoigianhen ASC
                LIMIT 8
            ";
            $calendarResult = $conn->query($calendarSql);
            if ($calendarResult) {
                while ($row = $calendarResult->fetch_assoc()) {
                    $timeValue = (string) ($row['thoigianhen'] ?? '');
                    $formattedTime = $timeValue !== '' ? date('d/m/Y H:i', strtotime($timeValue)) : 'Chưa rõ thời gian';
                    $calendar[] = [
                        'service' => (string) ($row['tendichvu'] ?? 'Dịch vụ'),
                        'customer' => (string) ($row['tenkhachhang'] ?? 'Khách hàng'),
                        'time' => $formattedTime,
                        'status' => (string) ($row['trangthailichhen'] ?? 'choduyet'),
                    ];
                }
                $calendarResult->free();
            }
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'data' => [
                'invoices' => $invoiceCount,
                'products' => $productCount,
                'customers' => $customerCount,
                'pets' => $petCount,
                'notifications' => $notifications,
                'calendar' => $calendar,
                'todo_items' => $todoItems,
                'todo_total' => $todoTotal,
                'top_services' => $topServices,
            ],
        ]);
    }

    if ($api === 'get_voucher_games') {
        $conn->close();
        app_json_response([
            'ok' => true,
            'data' => app_admin_voucher_games(),
            'levels' => app_admin_voucher_levels(),
        ]);
    }

    if ($api === 'get_vouchers') {
        if (!app_ensure_voucher_tables($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao bang voucher',
                'error' => $conn->error,
            ], 500);
        }

        $sql = "
            SELECT
                v.id,
                v.magiamgia,
                COALESCE(v.mota, '') AS mota,
                v.loaigiamgia,
                v.giatri,
                v.giatridonhangtoithieu,
                v.ngaybatdau,
                v.ngayketthuc,
                v.soluong,
                v.toida_sudung_moikhach,
                v.trangthai,
                COALESCE(v.minigame_key, 'jigsaw_pet') AS minigame_key,
                COALESCE(v.minigame_level, 'easy') AS minigame_level,
                v.ngaytao,
                '' AS product_ids,
                '' AS product_names
            FROM magiamgia v
            ORDER BY v.id DESC
            LIMIT 500
        ";
        $result = $conn->query($sql);
        if (!$result) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the lay danh sach voucher',
                'error' => $conn->error,
            ], 500);
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'magiamgia' => (string) ($row['magiamgia'] ?? ''),
                'mota' => (string) ($row['mota'] ?? ''),
                'loaigiamgia' => (string) ($row['loaigiamgia'] ?? 'percent'),
                'giatri' => (float) ($row['giatri'] ?? 0),
                'giatridonhangtoithieu' => (float) ($row['giatridonhangtoithieu'] ?? 0),
                'ngaybatdau' => (string) ($row['ngaybatdau'] ?? ''),
                'ngayketthuc' => (string) ($row['ngayketthuc'] ?? ''),
                'soluong' => (int) ($row['soluong'] ?? 0),
                'toida_sudung_moikhach' => (int) ($row['toida_sudung_moikhach'] ?? 1),
                'trangthai' => (string) ($row['trangthai'] ?? 'active'),
                'minigame_key' => (string) ($row['minigame_key'] ?? 'jigsaw_pet'),
                'minigame_level' => (string) ($row['minigame_level'] ?? 'easy'),
                'ngaytao' => (string) ($row['ngaytao'] ?? ''),
                'product_ids' => app_admin_parse_id_list((string) ($row['product_ids'] ?? '')),
                'product_names' => (string) ($row['product_names'] ?? ''),
            ];
        }

        $result->free();
        $conn->close();
        app_json_response([
            'ok' => true,
            'count' => count($rows),
            'data' => $rows,
            'games' => app_admin_voucher_games(),
            'levels' => app_admin_voucher_levels(),
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

        if ($entity === 'vouchers') {
            if (!app_ensure_voucher_tables($conn)) {
                $conn->close();
                app_json_response(['ok' => false, 'message' => 'Khong the khoi tao bang voucher', 'error' => $conn->error], 500);
            }

            $games = app_admin_voucher_games();
            $levels = app_admin_voucher_levels();

            if ($action === 'create' || $action === 'update') {
                $id = (int) ($input['id'] ?? 0);
                $code = strtoupper(trim((string) ($input['magiamgia'] ?? '')));
                $desc = trim((string) ($input['mota'] ?? ''));
                $type = trim((string) ($input['loaigiamgia'] ?? 'percent'));
                $value = (float) ($input['giatri'] ?? 0);
                $minOrder = (float) ($input['giatridonhangtoithieu'] ?? 0);
                $start = app_admin_normalize_datetime_input((string) ($input['ngaybatdau'] ?? ''));
                $end = app_admin_normalize_datetime_input((string) ($input['ngayketthuc'] ?? ''));
                $quantity = (int) ($input['soluong'] ?? 0);
                $maxPerUser = (int) ($input['toida_sudung_moikhach'] ?? 1);
                $status = trim((string) ($input['trangthai'] ?? 'active'));
                $miniGameKey = trim((string) ($input['minigame_key'] ?? 'jigsaw_pet'));
                $miniGameLevel = trim((string) ($input['minigame_level'] ?? 'easy'));
                $productIds = app_admin_parse_id_list($input['product_ids'] ?? []);

                if ($code === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Ma voucher khong duoc de trong'], 400);
                }

                if (!in_array($type, ['percent', 'fixed'], true)) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Loai giam gia khong hop le'], 400);
                }

                if ($type === 'percent' && ($value <= 0 || $value > 100)) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Voucher theo phan tram phai > 0 va <= 100'], 400);
                }

                if ($type === 'fixed' && $value <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Voucher giam tien phai > 0'], 400);
                }

                if ($start !== null && $end !== null && $start >= $end) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Ngay ket thuc phai lon hon ngay bat dau'], 400);
                }

                if ($quantity < 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'So luong voucher khong hop le'], 400);
                }

                if ($maxPerUser <= 0) {
                    $maxPerUser = 1;
                }

                if (!in_array($status, ['active', 'inactive', 'expired'], true)) {
                    $status = 'active';
                }

                if (!array_key_exists($miniGameKey, $games)) {
                    $miniGameKey = 'jigsaw_pet';
                }

                if (!array_key_exists($miniGameLevel, $levels)) {
                    $miniGameLevel = 'easy';
                }

                if ($action === 'create') {
                    $stmt = $conn->prepare('INSERT INTO magiamgia (magiamgia, mota, loaigiamgia, giatri, giatridonhangtoithieu, ngaybatdau, ngayketthuc, soluong, toida_sudung_moikhach, trangthai, minigame_key, minigame_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    if (!$stmt) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'Khong the tao voucher', 'error' => $conn->error], 500);
                    }

                    $stmt->bind_param('sssddssiisss', $code, $desc, $type, $value, $minOrder, $start, $end, $quantity, $maxPerUser, $status, $miniGameKey, $miniGameLevel);
                    $ok = $stmt->execute();
                    $voucherId = (int) $conn->insert_id;
                    $stmt->close();

                    if (!$ok || $voucherId <= 0) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'Tao voucher that bai', 'error' => $conn->error], 500);
                    }

                    if (!app_admin_sync_voucher_products($conn, $voucherId, $productIds)) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'Khong the luu danh sach san pham voucher', 'error' => $conn->error], 500);
                    }

                    $conn->close();
                    app_json_response(['ok' => true]);
                }

                if ($id <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'ID voucher khong hop le'], 400);
                }

                $stmt = $conn->prepare('UPDATE magiamgia SET magiamgia = ?, mota = ?, loaigiamgia = ?, giatri = ?, giatridonhangtoithieu = ?, ngaybatdau = ?, ngayketthuc = ?, soluong = ?, toida_sudung_moikhach = ?, trangthai = ?, minigame_key = ?, minigame_level = ? WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the cap nhat voucher', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sssddssiisssi', $code, $desc, $type, $value, $minOrder, $start, $end, $quantity, $maxPerUser, $status, $miniGameKey, $miniGameLevel, $id);
                $ok = $stmt->execute();
                $stmt->close();

                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Cap nhat voucher that bai', 'error' => $conn->error], 500);
                }

                if (!app_admin_sync_voucher_products($conn, $id, $productIds)) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the cap nhat san pham voucher', 'error' => $conn->error], 500);
                }

                $conn->close();
                app_json_response(['ok' => true]);
            }

            if ($action === 'delete') {
                $id = (int) ($input['id'] ?? 0);
                if ($id <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'ID voucher khong hop le'], 400);
                }

                $stmt = $conn->prepare('DELETE FROM magiamgia WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the xoa voucher', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('i', $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Xoa voucher that bai', 'error' => $conn->error], 500);
                }

                $conn->close();
                app_json_response(['ok' => true]);
            }
        }

        if ($entity === 'services') {
            app_ensure_dichvu_image_column($conn);
            app_ensure_dichvu_info_column($conn);
            app_ensure_service_category_table($conn);
            app_ensure_dichvu_category_column($conn);

            if ($action === 'create') {
                $name = trim((string) ($input['tendichvu'] ?? ''));
                $price = (float) ($input['giadichvu'] ?? 0);
                $duration = (int) ($input['thoigiandichvu'] ?? 0);
                $serviceCategoryId = app_resolve_service_category_id($conn, $input['danhmucdichvu_id'] ?? 0);
                $status = trim((string) ($input['trangthaidichvu'] ?? 'hoatdong'));
                $image = trim((string) ($input['hinhanhdichvu'] ?? ''));
                $info = trim((string) ($input['thongtin'] ?? ''));

                if ($name === '' || $duration <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu dich vu khong hop le'], 400);
                }

                $stmt = $conn->prepare('INSERT INTO dichvu (tendichvu, giadichvu, thoigiandichvu, danhmucdichvu_id, trangthaidichvu, hinhanhdichvu, thongtin) VALUES (?, ?, ?, ?, ?, ?, ?)');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the them dich vu', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sdiisss', $name, $price, $duration, $serviceCategoryId, $status, $image, $info);
                $ok = $stmt->execute();
                $newId = (int) $conn->insert_id;
                $stmt->close();

                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Them dich vu that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query('SELECT d.id, d.tendichvu, d.giadichvu, d.thoigiandichvu, d.danhmucdichvu_id, COALESCE(dm.tendanhmucdichvu, "") AS tendanhmucdichvu, d.trangthaidichvu, d.hinhanhdichvu, COALESCE(d.thongtin, "") AS thongtin, d.ngaytaodichvu FROM dichvu d LEFT JOIN danhmucdichvu dm ON dm.id = d.danhmucdichvu_id WHERE d.id = ' . $newId . ' LIMIT 1');
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
                $serviceCategoryId = app_resolve_service_category_id($conn, $input['danhmucdichvu_id'] ?? 0);
                $status = trim((string) ($input['trangthaidichvu'] ?? 'hoatdong'));
                $image = trim((string) ($input['hinhanhdichvu'] ?? ''));
                $info = trim((string) ($input['thongtin'] ?? ''));

                if ($id <= 0 || $name === '' || $duration <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu cap nhat dich vu khong hop le'], 400);
                }

                $stmt = $conn->prepare('UPDATE dichvu SET tendichvu = ?, giadichvu = ?, thoigiandichvu = ?, danhmucdichvu_id = ?, trangthaidichvu = ?, hinhanhdichvu = ?, thongtin = ? WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the cap nhat dich vu', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sdiisssi', $name, $price, $duration, $serviceCategoryId, $status, $image, $info, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Cap nhat dich vu that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query('SELECT d.id, d.tendichvu, d.giadichvu, d.thoigiandichvu, d.danhmucdichvu_id, COALESCE(dm.tendanhmucdichvu, "") AS tendanhmucdichvu, d.trangthaidichvu, d.hinhanhdichvu, COALESCE(d.thongtin, "") AS thongtin, d.ngaytaodichvu FROM dichvu d LEFT JOIN danhmucdichvu dm ON dm.id = d.danhmucdichvu_id WHERE d.id = ' . $id . ' LIMIT 1');
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

        if ($entity === 'service_categories') {
            if (!app_ensure_service_category_table($conn)) {
                $conn->close();
                app_json_response(['ok' => false, 'message' => 'Khong the khoi tao bang danh muc dich vu', 'error' => $conn->error], 500);
            }
            app_ensure_dichvu_category_column($conn);

            if ($action === 'create') {
                $name = trim((string) ($input['tendanhmucdichvu'] ?? ''));
                if ($name === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Ten danh muc dich vu khong hop le'], 400);
                }

                $stmt = $conn->prepare('INSERT INTO danhmucdichvu (tendanhmucdichvu) VALUES (?)');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the them danh muc dich vu', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('s', $name);
                $ok = $stmt->execute();
                $newId = (int) $conn->insert_id;
                $stmt->close();

                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Them danh muc dich vu that bai (co the bi trung)', 'error' => $conn->error], 500);
                }

                $rows = app_get_service_categories($conn);
                $created = null;
                foreach ($rows as $row) {
                    if ((int) ($row['id'] ?? 0) === $newId) {
                        $created = $row;
                        break;
                    }
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $created]);
            }

            if ($action === 'delete') {
                $id = (int) ($input['id'] ?? 0);
                if ($id <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'ID danh muc dich vu khong hop le'], 400);
                }

                $conn->query('UPDATE dichvu SET danhmucdichvu_id = NULL WHERE danhmucdichvu_id = ' . $id);

                $stmt = $conn->prepare('DELETE FROM danhmucdichvu WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the xoa danh muc dich vu', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('i', $id);
                $ok = $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();

                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Xoa danh muc dich vu that bai', 'error' => $conn->error], 500);
                }

                $conn->close();
                app_json_response(['ok' => true, 'deleted' => max(0, (int) $affected)]);
            }
        }

        if ($entity === 'products') {
            app_ensure_product_subcategory_column($conn);
            app_ensure_product_discount_columns($conn);
            app_ensure_product_info_column($conn);

            if ($action === 'create') {
                $name = trim((string) ($input['tensanpham'] ?? ''));
                $code = trim((string) ($input['masanpham'] ?? ''));
                $categoryId = (int) ($input['danhmuc_id'] ?? 0);
                $categoryName = trim((string) ($input['tendanhmuc'] ?? ''));
                $subcategoryName = trim((string) ($input['danhmuccon'] ?? ''));
                $price = (float) ($input['giasanpham'] ?? 0);
                $qty = (int) ($input['soluongsanpham'] ?? 0);
                $status = trim((string) ($input['trangthaisanpham'] ?? 'conhang'));
                $image = trim((string) ($input['hinhanhsanpham'] ?? ''));
                $info = trim((string) ($input['thongtin'] ?? ''));
                $createStorePetRaw = $input['them_vao_danh_sach_thu_cung'] ?? false;
                $createStorePet = false;
                if (is_bool($createStorePetRaw)) {
                    $createStorePet = $createStorePetRaw;
                } else {
                    $createStorePet = in_array(strtolower(trim((string) $createStorePetRaw)), ['1', 'true', 'on', 'yes'], true);
                }
                $discountPercent = (float) ($input['phantramgiamgia'] ?? 0);
                $discountStart = app_admin_normalize_datetime_input((string) ($input['thoigianbatdaugiam'] ?? ''));
                $discountEnd = app_admin_normalize_datetime_input((string) ($input['thoigianketthucgiam'] ?? ''));

                if ($name === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Ten san pham khong hop le'], 400);
                }

                if ($discountPercent < 0 || $discountPercent > 100) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Phan tram giam gia phai trong khoang 0-100'], 400);
                }

                if ($discountPercent <= 0) {
                    $discountPercent = 0;
                    $discountStart = null;
                    $discountEnd = null;
                } else {
                    if ($discountStart === null || $discountEnd === null) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'Vui long nhap day du thoi gian bat dau va ket thuc giam gia'], 400);
                    }

                    if ($discountStart >= $discountEnd) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'Thoi gian ket thuc giam gia phai lon hon thoi gian bat dau'], 400);
                    }
                }

                $danhmucId = $categoryId > 0 ? $categoryId : app_resolve_category_id($conn, $categoryName);
                if ($danhmucId <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Vui long chon danh muc cho san pham'], 400);
                }

                if ($code === '') {
                    $code = 'SP' . str_pad((string) time(), 10, '0', STR_PAD_LEFT);
                }

                $status = app_admin_resolve_product_status($qty, $status);

                $stmt = $conn->prepare('INSERT INTO sanpham (tensanpham, danhmuc_id, danhmuccon, masanpham, giasanpham, phantramgiamgia, thoigianbatdaugiam, thoigianketthucgiam, soluongsanpham, trangthaisanpham, hinhanhsanpham, thongtin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the them san pham', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sissddssisss', $name, $danhmucId, $subcategoryName, $code, $price, $discountPercent, $discountStart, $discountEnd, $qty, $status, $image, $info);
                $ok = $stmt->execute();
                $newId = (int) $conn->insert_id;
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Them san pham that bai', 'error' => $conn->error], 500);
                }

                if ($createStorePet && app_table_exists($conn, 'thucung')) {
                    $petTypeColumn = app_detect_pet_type_column($conn);
                    if ($petTypeColumn !== '') {
                        $hasPetSourceColumn = app_column_exists($conn, 'thucung', 'nguon_thucung');
                        $hasPetProductColumn = app_column_exists($conn, 'thucung', 'sanpham_id');

                        $petTypeValue = trim($categoryName) !== '' ? $categoryName : 'Thu cung';
                        $petBreedValue = 'Khong ro';
                        $petStatusValue = 'Dang ban';
                        $petNoteValue = 'Tu dong tao tu san pham cua cua hang';
                        $petRegDateValue = date('Y-m-d');
                        $petOwnerId = null;
                        $petSourceValue = 'cua_hang';

                        $petInsertColumns = "tenthucung, {$petTypeColumn}, giongthucung, chusohuu_id";
                        $petInsertValues = '?, ?, ?, ?';
                        $petInsertTypes = 'sssi';
                        if ($hasPetSourceColumn) {
                            $petInsertColumns .= ', nguon_thucung';
                            $petInsertValues .= ', ?';
                            $petInsertTypes .= 's';
                        }
                        if ($hasPetProductColumn) {
                            $petInsertColumns .= ', sanpham_id';
                            $petInsertValues .= ', ?';
                            $petInsertTypes .= 'i';
                        }
                        $petInsertColumns .= ', trangthaithucung, thongtin, ngaydangkythucung';
                        $petInsertValues .= ', ?, ?, ?';
                        $petInsertTypes .= 'sss';

                        $petInsertSql = "INSERT INTO thucung ({$petInsertColumns}) VALUES ({$petInsertValues})";
                        $petStmt = $conn->prepare($petInsertSql);
                        if ($petStmt) {
                            $petBindValues = [$name, $petTypeValue, $petBreedValue, $petOwnerId];
                            if ($hasPetSourceColumn) {
                                $petBindValues[] = $petSourceValue;
                            }
                            if ($hasPetProductColumn) {
                                $petBindValues[] = $newId;
                            }
                            $petBindValues[] = $petStatusValue;
                            $petBindValues[] = $petNoteValue;
                            $petBindValues[] = $petRegDateValue;
                            $petStmt->bind_param($petInsertTypes, ...$petBindValues);
                            $petStmt->execute();
                            $petStmt->close();
                        }
                    }
                }

                $rowResult = $conn->query("SELECT s.id, s.tensanpham, s.danhmuc_id, COALESCE(NULLIF(TRIM(s.danhmuccon), ''), '') AS danhmuccon, COALESCE(d.tendanhmuc, 'Chua phan loai') AS tendanhmuc, s.masanpham, s.giasanpham, COALESCE(s.phantramgiamgia, 0) AS phantramgiamgia, s.thoigianbatdaugiam, s.thoigianketthucgiam, s.soluongsanpham, s.trangthaisanpham, s.hinhanhsanpham, COALESCE(s.thongtin, '') AS thongtin FROM sanpham s LEFT JOIN danhmuc d ON d.id = s.danhmuc_id WHERE s.id = {$newId} LIMIT 1");
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
                $categoryId = (int) ($input['danhmuc_id'] ?? 0);
                $categoryName = trim((string) ($input['tendanhmuc'] ?? ''));
                $subcategoryName = trim((string) ($input['danhmuccon'] ?? ''));
                $price = (float) ($input['giasanpham'] ?? 0);
                $qty = (int) ($input['soluongsanpham'] ?? 0);
                $status = trim((string) ($input['trangthaisanpham'] ?? 'conhang'));
                $image = trim((string) ($input['hinhanhsanpham'] ?? ''));
                $hasInfoField = is_array($input) && array_key_exists('thongtin', $input);
                $info = trim((string) ($input['thongtin'] ?? ''));
                $discountPercent = (float) ($input['phantramgiamgia'] ?? 0);
                $discountStart = app_admin_normalize_datetime_input((string) ($input['thoigianbatdaugiam'] ?? ''));
                $discountEnd = app_admin_normalize_datetime_input((string) ($input['thoigianketthucgiam'] ?? ''));

                if ($id <= 0 || $name === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu cap nhat san pham khong hop le'], 400);
                }

                if ($discountPercent < 0 || $discountPercent > 100) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Phan tram giam gia phai trong khoang 0-100'], 400);
                }

                if ($discountPercent <= 0) {
                    $discountPercent = 0;
                    $discountStart = null;
                    $discountEnd = null;
                } else {
                    if ($discountStart === null || $discountEnd === null) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'Vui long nhap day du thoi gian bat dau va ket thuc giam gia'], 400);
                    }

                    if ($discountStart >= $discountEnd) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'Thoi gian ket thuc giam gia phai lon hon thoi gian bat dau'], 400);
                    }
                }

                $existingResult = $conn->query('SELECT danhmuc_id, masanpham, hinhanhsanpham, COALESCE(thongtin, "") AS thongtin, COALESCE(phantramgiamgia, 0) AS phantramgiamgia, thoigianbatdaugiam, thoigianketthucgiam FROM sanpham WHERE id = ' . $id . ' LIMIT 1');
                $existing = $existingResult ? $existingResult->fetch_assoc() : null;
                if ($existingResult) {
                    $existingResult->free();
                }
                if (!$existing) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong tim thay san pham'], 404);
                }

                $fallbackCategory = (int) ($existing['danhmuc_id'] ?? 0);
                $danhmucId = $categoryId > 0
                    ? $categoryId
                    : app_resolve_category_id($conn, $categoryName, $fallbackCategory);
                if ($danhmucId <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Vui long chon danh muc cho san pham'], 400);
                }

                if ($code === '') {
                    $code = (string) ($existing['masanpham'] ?? '');
                }

                if ($image === '') {
                    $image = (string) ($existing['hinhanhsanpham'] ?? '');
                }

                if (!$hasInfoField) {
                    $info = (string) ($existing['thongtin'] ?? '');
                }

                $status = app_admin_resolve_product_status($qty, $status);

                $stmt = $conn->prepare('UPDATE sanpham SET tensanpham = ?, danhmuc_id = ?, danhmuccon = ?, masanpham = ?, giasanpham = ?, phantramgiamgia = ?, thoigianbatdaugiam = ?, thoigianketthucgiam = ?, soluongsanpham = ?, trangthaisanpham = ?, hinhanhsanpham = ?, thongtin = ? WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the cap nhat san pham', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sissddssisssi', $name, $danhmucId, $subcategoryName, $code, $price, $discountPercent, $discountStart, $discountEnd, $qty, $status, $image, $info, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Cap nhat san pham that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query("SELECT s.id, s.tensanpham, s.danhmuc_id, COALESCE(NULLIF(TRIM(s.danhmuccon), ''), '') AS danhmuccon, COALESCE(d.tendanhmuc, 'Chua phan loai') AS tendanhmuc, s.masanpham, s.giasanpham, COALESCE(s.phantramgiamgia, 0) AS phantramgiamgia, s.thoigianbatdaugiam, s.thoigianketthucgiam, s.soluongsanpham, s.trangthaisanpham, s.hinhanhsanpham, COALESCE(s.thongtin, '') AS thongtin FROM sanpham s LEFT JOIN danhmuc d ON d.id = s.danhmuc_id WHERE s.id = {$id} LIMIT 1");
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

        if ($entity === 'categories') {
            app_ensure_category_image_column($conn);
            app_ensure_category_subcategory_table($conn);

            if ($action === 'create') {
                $name = trim((string) ($input['tendanhmuc'] ?? ''));
                $image = trim((string) ($input['hinhanhdanhmuc'] ?? ''));

                if ($name === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Ten danh muc khong hop le'], 400);
                }

                $stmt = $conn->prepare('INSERT INTO danhmuc (tendanhmuc, hinhanhdanhmuc) VALUES (?, ?)');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the them danh muc', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('ss', $name, $image);
                $ok = $stmt->execute();
                $newId = (int) $conn->insert_id;
                $stmt->close();

                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Them danh muc that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query('SELECT id, tendanhmuc, COALESCE(NULLIF(TRIM(hinhanhdanhmuc), ""), "") AS hinhanhdanhmuc FROM danhmuc WHERE id = ' . $newId . ' LIMIT 1');
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }
                if (is_array($row)) {
                    $row['hinhanh'] = app_to_public_image_url((string) ($row['hinhanhdanhmuc'] ?? ''));
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $row]);
            }

            if ($action === 'update') {
                $id = (int) ($input['id'] ?? 0);
                $name = trim((string) ($input['tendanhmuc'] ?? ''));
                $image = trim((string) ($input['hinhanhdanhmuc'] ?? ''));

                if ($id <= 0 || $name === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu cap nhat danh muc khong hop le'], 400);
                }

                if ($image === '') {
                    $oldRes = $conn->query('SELECT hinhanhdanhmuc FROM danhmuc WHERE id = ' . $id . ' LIMIT 1');
                    $oldRow = $oldRes ? $oldRes->fetch_assoc() : null;
                    if ($oldRes) {
                        $oldRes->free();
                    }
                    $image = is_array($oldRow) ? (string) ($oldRow['hinhanhdanhmuc'] ?? '') : '';
                }

                $stmt = $conn->prepare('UPDATE danhmuc SET tendanhmuc = ?, hinhanhdanhmuc = ? WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the cap nhat danh muc', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('ssi', $name, $image, $id);
                $ok = $stmt->execute();
                $stmt->close();

                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Cap nhat danh muc that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query('SELECT id, tendanhmuc, COALESCE(NULLIF(TRIM(hinhanhdanhmuc), ""), "") AS hinhanhdanhmuc FROM danhmuc WHERE id = ' . $id . ' LIMIT 1');
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }
                if (is_array($row)) {
                    $row['hinhanh'] = app_to_public_image_url((string) ($row['hinhanhdanhmuc'] ?? ''));
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $row]);
            }

            if ($action === 'delete') {
                $id = (int) ($input['id'] ?? 0);
                if ($id <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'ID danh muc khong hop le'], 400);
                }

                if (app_table_exists($conn, 'danhmuccon')) {
                    $conn->query('DELETE FROM danhmuccon WHERE danhmuc_id = ' . $id);
                }

                $stmt = $conn->prepare('DELETE FROM danhmuc WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the xoa danh muc', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('i', $id);
                $ok = $stmt->execute();
                $stmt->close();

                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Xoa danh muc that bai. Co the danh muc dang duoc su dung.', 'error' => $conn->error], 500);
                }

                $conn->close();
                app_json_response(['ok' => true]);
            }
        }

        if ($entity === 'category_subcategories') {
            if (!app_ensure_category_subcategory_table($conn)) {
                $conn->close();
                app_json_response(['ok' => false, 'message' => 'Khong the khoi tao bang danh muc con', 'error' => $conn->error], 500);
            }

            if ($action === 'create') {
                $categoryId = (int) ($input['danhmuc_id'] ?? 0);
                $name = trim((string) ($input['tendanhmuccon'] ?? ''));

                if ($categoryId <= 0 || $name === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu danh muc con khong hop le'], 400);
                }

                $checkCategory = $conn->query('SELECT id FROM danhmuc WHERE id = ' . $categoryId . ' LIMIT 1');
                $existsCategory = $checkCategory && $checkCategory->num_rows > 0;
                if ($checkCategory) {
                    $checkCategory->free();
                }
                if (!$existsCategory) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong tim thay danh muc cha'], 404);
                }

                $stmt = $conn->prepare('INSERT INTO danhmuccon (danhmuc_id, tendanhmuccon) VALUES (?, ?)');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the them danh muc con', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('is', $categoryId, $name);
                $ok = $stmt->execute();
                $newId = (int) $conn->insert_id;
                $stmt->close();

                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Them danh muc con that bai (co the bi trung)', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query('SELECT id, danhmuc_id, tendanhmuccon FROM danhmuccon WHERE id = ' . $newId . ' LIMIT 1');
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $row]);
            }

            if ($action === 'delete') {
                $id = (int) ($input['id'] ?? 0);
                $categoryId = (int) ($input['danhmuc_id'] ?? 0);
                $name = trim((string) ($input['tendanhmuccon'] ?? ''));

                if ($id <= 0 && ($categoryId <= 0 || $name === '')) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Thieu thong tin de xoa danh muc con'], 400);
                }

                if ($id > 0) {
                    $stmt = $conn->prepare('DELETE FROM danhmuccon WHERE id = ?');
                    if (!$stmt) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'Khong the xoa danh muc con', 'error' => $conn->error], 500);
                    }
                    $stmt->bind_param('i', $id);
                } else {
                    $stmt = $conn->prepare('DELETE FROM danhmuccon WHERE danhmuc_id = ? AND tendanhmuccon = ?');
                    if (!$stmt) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'Khong the xoa danh muc con', 'error' => $conn->error], 500);
                    }
                    $stmt->bind_param('is', $categoryId, $name);
                }

                $ok = $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();

                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Xoa danh muc con that bai', 'error' => $conn->error], 500);
                }

                $conn->close();
                app_json_response(['ok' => true, 'deleted' => max(0, (int) $affected)]);
            }
        }

        if ($entity === 'customers') {
            if ($action === 'create') {
                $name = trim((string) ($input['tenkhachhang'] ?? ''));
                $phone = trim((string) ($input['sodienthoaikhachhang'] ?? ''));
                $email = trim((string) ($input['emailkhachhang'] ?? ''));
                $spending = max(0, (float) ($input['tongchitieukhachhang'] ?? 0));
                $type = app_admin_resolve_customer_type_for_storage($conn, app_admin_customer_tier_key($spending));

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

                $rowResult = $conn->query('SELECT id, tenkhachhang, sodienthoaikhachhang, emailkhachhang, tongchitieukhachhang, loaikhachhang, ngaytaokhachhang, (SELECT COALESCE(NULLIF(TRIM(u.anhdaidiennguoidung), ""), "") FROM nguoidung u WHERE LOWER(TRIM(COALESCE(u.emailnguoidung, ""))) = LOWER(TRIM(COALESCE(khachhang.emailkhachhang, ""))) ORDER BY u.id DESC LIMIT 1) AS anhdaidiennguoidung FROM khachhang WHERE id = ' . $newId . ' LIMIT 1');
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }
                if (is_array($row)) {
                    $row['so_thu_cung'] = 0;
                    $row['anhdaidiennguoidung_url'] = app_to_public_image_url((string) ($row['anhdaidiennguoidung'] ?? ''));
                }

                $conn->close();
                app_json_response(['ok' => true, 'data' => $row]);
            }

            if ($action === 'update') {
                $id = (int) ($input['id'] ?? 0);
                $name = trim((string) ($input['tenkhachhang'] ?? ''));
                $phone = trim((string) ($input['sodienthoaikhachhang'] ?? ''));
                $email = trim((string) ($input['emailkhachhang'] ?? ''));
                $spending = max(0, (float) ($input['tongchitieukhachhang'] ?? 0));
                $type = app_admin_resolve_customer_type_for_storage($conn, app_admin_customer_tier_key($spending));

                if ($id <= 0 || $name === '' || $phone === '' || $email === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu cap nhat khach hang khong hop le'], 400);
                }

                $stmt = $conn->prepare('UPDATE khachhang SET tenkhachhang = ?, sodienthoaikhachhang = ?, emailkhachhang = ?, tongchitieukhachhang = ?, loaikhachhang = ? WHERE id = ?');
                if (!$stmt) {
                    $prepareError = $conn->error;
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the cap nhat khach hang', 'error' => $prepareError], 500);
                }
                $stmt->bind_param('sssdsi', $name, $phone, $email, $spending, $type, $id);
                $ok = $stmt->execute();
                $executeError = $stmt->error;
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Cap nhat khach hang that bai', 'error' => $executeError], 500);
                }

                $rowResult = $conn->query('SELECT k.id, k.tenkhachhang, k.sodienthoaikhachhang, k.emailkhachhang, k.tongchitieukhachhang, k.loaikhachhang, k.ngaytaokhachhang, (SELECT COALESCE(NULLIF(TRIM(u.anhdaidiennguoidung), ""), "") FROM nguoidung u WHERE LOWER(TRIM(COALESCE(u.emailnguoidung, ""))) = LOWER(TRIM(COALESCE(k.emailkhachhang, ""))) ORDER BY u.id DESC LIMIT 1) AS anhdaidiennguoidung, COUNT(t.id) AS so_thu_cung FROM khachhang k LEFT JOIN thucung t ON t.chusohuu_id = k.id AND COALESCE(t.nguon_thucung, "khach_hang") = "khach_hang" WHERE k.id = ' . $id . ' GROUP BY k.id, k.tenkhachhang, k.sodienthoaikhachhang, k.emailkhachhang, k.tongchitieukhachhang, k.loaikhachhang, k.ngaytaokhachhang LIMIT 1');
                $row = $rowResult ? $rowResult->fetch_assoc() : null;
                if ($rowResult) {
                    $rowResult->free();
                }
                if (is_array($row)) {
                    $row['anhdaidiennguoidung_url'] = app_to_public_image_url((string) ($row['anhdaidiennguoidung'] ?? ''));
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

            $hasPetSourceColumn = app_column_exists($conn, 'thucung', 'nguon_thucung');
            $hasPetProductColumn = app_column_exists($conn, 'thucung', 'sanpham_id');

            if ($action === 'create') {
                $name = trim((string) ($input['tenthucung'] ?? ''));
                $type = trim((string) ($input['loaithucung'] ?? ''));
                $breed = trim((string) ($input['giongthucung'] ?? ''));
                $ownerId = (int) ($input['chusohuu_id'] ?? 0);
                $source = trim((string) ($input['nguon_thucung'] ?? ''));
                $productId = (int) ($input['sanpham_id'] ?? 0);
                $status = trim((string) ($input['trangthaithucung'] ?? ''));
                $note = trim((string) ($input['thongtin'] ?? ''));
                $regDate = trim((string) ($input['ngaydangkythucung'] ?? ''));

                if ($name === '' || $type === '' || $breed === '' || $status === '' || $regDate === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu thu cung khong hop le'], 400);
                }

                if (!in_array($source, ['khach_hang', 'cua_hang'], true)) {
                    $source = $ownerId > 0 ? 'khach_hang' : 'cua_hang';
                }

                if ($source === 'khach_hang') {
                    if ($ownerId <= 0) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'Vui long chon chu so huu cho thu cung cua khach hang'], 400);
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
                } else {
                    $ownerId = null;
                }

                if ($source === 'cua_hang' && $productId > 0 && app_table_exists($conn, 'sanpham')) {
                    $productCheck = $conn->query('SELECT id FROM sanpham WHERE id = ' . $productId . ' LIMIT 1');
                    $productRow = $productCheck ? $productCheck->fetch_assoc() : null;
                    if ($productCheck) {
                        $productCheck->free();
                    }
                    if (!$productRow) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'San pham lien ket khong ton tai'], 400);
                    }
                } else {
                    $productId = 0;
                }

                $insertColumns = "tenthucung, {$petTypeColumn}, giongthucung, chusohuu_id";
                $insertValues = '?, ?, ?, ?';
                $insertTypes = 'sssi';
                if ($hasPetSourceColumn) {
                    $insertColumns .= ', nguon_thucung';
                    $insertValues .= ', ?';
                    $insertTypes .= 's';
                }
                if ($hasPetProductColumn) {
                    $insertColumns .= ', sanpham_id';
                    $insertValues .= ', ?';
                    $insertTypes .= 'i';
                }
                $insertColumns .= ', trangthaithucung, thongtin, ngaydangkythucung';
                $insertValues .= ', ?, ?, ?';
                $insertTypes .= 'sss';

                $sqlInsert = "INSERT INTO thucung ({$insertColumns}) VALUES ({$insertValues})";
                $stmt = $conn->prepare($sqlInsert);
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the them thu cung', 'error' => $conn->error], 500);
                }

                $bindValues = [$name, $type, $breed, $ownerId];
                if ($hasPetSourceColumn) {
                    $bindValues[] = $source;
                }
                if ($hasPetProductColumn) {
                    $bindValues[] = $productId > 0 ? $productId : null;
                }
                $bindValues[] = $status;
                $bindValues[] = $note;
                $bindValues[] = $regDate;
                $stmt->bind_param($insertTypes, ...$bindValues);
                $ok = $stmt->execute();
                $newId = (int) $conn->insert_id;
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Them thu cung that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query("SELECT t.id, t.tenthucung, t.{$petTypeColumn} AS loaithucung, t.giongthucung, t.chusohuu_id, COALESCE(NULLIF(TRIM(t.nguon_thucung), ''), CASE WHEN COALESCE(t.chusohuu_id, 0) > 0 THEN 'khach_hang' ELSE 'cua_hang' END) AS nguon_thucung, t.sanpham_id, k.tenkhachhang AS tenchusohuu, COALESCE(sp.tensanpham, '') AS tensanpham_lienket, COALESCE(sp.hinhanhsanpham, '') AS hinhanhsanpham_lienket, t.trangthaithucung, t.thongtin, t.ngaydangkythucung FROM thucung t LEFT JOIN khachhang k ON k.id = t.chusohuu_id LEFT JOIN sanpham sp ON sp.id = t.sanpham_id WHERE t.id = {$newId} LIMIT 1");
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
                $source = trim((string) ($input['nguon_thucung'] ?? ''));
                $productId = (int) ($input['sanpham_id'] ?? 0);
                $status = trim((string) ($input['trangthaithucung'] ?? ''));
                $note = trim((string) ($input['thongtin'] ?? ''));
                $regDate = trim((string) ($input['ngaydangkythucung'] ?? ''));

                if ($id <= 0 || $name === '' || $type === '' || $breed === '' || $status === '' || $regDate === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu cap nhat thu cung khong hop le'], 400);
                }

                if (!in_array($source, ['khach_hang', 'cua_hang'], true)) {
                    $source = $ownerId > 0 ? 'khach_hang' : 'cua_hang';
                }

                if ($source === 'khach_hang') {
                    if ($ownerId <= 0) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'Vui long chon chu so huu cho thu cung cua khach hang'], 400);
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
                } else {
                    $ownerId = null;
                }

                if ($source === 'cua_hang' && $productId > 0 && app_table_exists($conn, 'sanpham')) {
                    $productCheck = $conn->query('SELECT id FROM sanpham WHERE id = ' . $productId . ' LIMIT 1');
                    $productRow = $productCheck ? $productCheck->fetch_assoc() : null;
                    if ($productCheck) {
                        $productCheck->free();
                    }
                    if (!$productRow) {
                        $conn->close();
                        app_json_response(['ok' => false, 'message' => 'San pham lien ket khong ton tai'], 400);
                    }
                } else {
                    $productId = 0;
                }

                $setParts = [
                    'tenthucung = ?',
                    "{$petTypeColumn} = ?",
                    'giongthucung = ?',
                    'chusohuu_id = ?',
                ];
                $updateTypes = 'sssi';
                $updateValues = [$name, $type, $breed, $ownerId];

                if ($hasPetSourceColumn) {
                    $setParts[] = 'nguon_thucung = ?';
                    $updateTypes .= 's';
                    $updateValues[] = $source;
                }

                if ($hasPetProductColumn) {
                    $setParts[] = 'sanpham_id = ?';
                    $updateTypes .= 'i';
                    $updateValues[] = $productId > 0 ? $productId : null;
                }

                $setParts[] = 'trangthaithucung = ?';
                $setParts[] = 'thongtin = ?';
                $setParts[] = 'ngaydangkythucung = ?';
                $updateTypes .= 'sssi';
                $updateValues[] = $status;
                $updateValues[] = $note;
                $updateValues[] = $regDate;
                $updateValues[] = $id;

                $sqlUpdate = 'UPDATE thucung SET ' . implode(', ', $setParts) . ' WHERE id = ?';
                $stmt = $conn->prepare($sqlUpdate);
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the cap nhat thu cung', 'error' => $conn->error], 500);
                }

                $stmt->bind_param($updateTypes, ...$updateValues);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Cap nhat thu cung that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query("SELECT t.id, t.tenthucung, t.{$petTypeColumn} AS loaithucung, t.giongthucung, t.chusohuu_id, COALESCE(NULLIF(TRIM(t.nguon_thucung), ''), CASE WHEN COALESCE(t.chusohuu_id, 0) > 0 THEN 'khach_hang' ELSE 'cua_hang' END) AS nguon_thucung, t.sanpham_id, k.tenkhachhang AS tenchusohuu, COALESCE(sp.tensanpham, '') AS tensanpham_lienket, COALESCE(sp.hinhanhsanpham, '') AS hinhanhsanpham_lienket, t.trangthaithucung, t.thongtin, t.ngaydangkythucung FROM thucung t LEFT JOIN khachhang k ON k.id = t.chusohuu_id LEFT JOIN sanpham sp ON sp.id = t.sanpham_id WHERE t.id = {$id} LIMIT 1");
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

    if ($api === 'get_online_orders') {
        if (!app_ensure_online_orders_table($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the truy cap bang donhang_online',
                'error' => $conn->error,
            ], 500);
        }

        if (!app_column_exists($conn, 'donhang_online', 'da_cong_chi_tieu')) {
            $conn->query("ALTER TABLE donhang_online ADD COLUMN da_cong_chi_tieu TINYINT(1) NOT NULL DEFAULT 0 AFTER nguoiduyet");
        }

        if (app_table_exists($conn, 'donhang') && app_table_exists($conn, 'khachhang')) {
            $reconcileSql = "
                SELECT
                    o.id,
                    o.donhang_id,
                    COALESCE(o.tongtien, 0) AS tongtien_online,
                    COALESCE(d.khachhang_id, 0) AS khachhang_id,
                    COALESCE(d.tongtiendonhang, 0) AS tongtien_donhang
                FROM donhang_online o
                LEFT JOIN donhang d ON d.id = o.donhang_id
                WHERE o.trangthai = 'da_duyet'
                  AND COALESCE(o.da_cong_chi_tieu, 0) = 0
                ORDER BY o.id ASC
                LIMIT 500
            ";
            $reconcileResult = $conn->query($reconcileSql);
            if ($reconcileResult) {
                while ($reconcileRow = $reconcileResult->fetch_assoc()) {
                    $onlineIdToSync = (int) ($reconcileRow['id'] ?? 0);
                    $internalOrderIdToSync = (int) ($reconcileRow['donhang_id'] ?? 0);
                    $customerIdToSync = (int) ($reconcileRow['khachhang_id'] ?? 0);
                    $orderAmountToSync = (float) ($reconcileRow['tongtien_donhang'] ?? 0);
                    $onlineAmountToSync = (float) ($reconcileRow['tongtien_online'] ?? 0);
                    $spendingToSync = $orderAmountToSync > 0 ? $orderAmountToSync : $onlineAmountToSync;

                    if ($internalOrderIdToSync > 0) {
                        $syncOrderStmt = $conn->prepare("UPDATE donhang SET trangthaidonhang = 'hoanthanh' WHERE id = ? LIMIT 1");
                        if ($syncOrderStmt) {
                            $syncOrderStmt->bind_param('i', $internalOrderIdToSync);
                            $syncOrderStmt->execute();
                            $syncOrderStmt->close();
                        }
                    }

                    if ($customerIdToSync > 0 && $spendingToSync > 0) {
                        app_recalculate_customer_spending_from_orders($conn, $customerIdToSync);
                    }

                    if ($onlineIdToSync > 0) {
                        $syncFlagStmt = $conn->prepare('UPDATE donhang_online SET da_cong_chi_tieu = 1 WHERE id = ? LIMIT 1');
                        if ($syncFlagStmt) {
                            $syncFlagStmt->bind_param('i', $onlineIdToSync);
                            $syncFlagStmt->execute();
                            $syncFlagStmt->close();
                        }
                    }
                }
                $reconcileResult->free();
            }

            $recalcCustomerSql = "
                SELECT DISTINCT COALESCE(d.khachhang_id, 0) AS khachhang_id
                FROM donhang_online o
                INNER JOIN donhang d ON d.id = o.donhang_id
                WHERE o.trangthai = 'da_duyet'
                  AND COALESCE(d.khachhang_id, 0) > 0
                LIMIT 2000
            ";
            $recalcCustomerResult = $conn->query($recalcCustomerSql);
            if ($recalcCustomerResult) {
                while ($recalcRow = $recalcCustomerResult->fetch_assoc()) {
                    $recalcCustomerId = (int) ($recalcRow['khachhang_id'] ?? 0);
                    if ($recalcCustomerId > 0) {
                        app_recalculate_customer_spending_from_orders($conn, $recalcCustomerId);
                    }
                }
                $recalcCustomerResult->free();
            }
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
                COALESCE(da_cong_chi_tieu, 0) AS da_cong_chi_tieu,
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

        $rows = [];
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

            $rows[] = [
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
                'chitiet_json' => (string) ($row['chitiet_json'] ?? '[]'),
                'ngaytao' => (string) ($row['ngaytao'] ?? ''),
                'ngaycapnhat' => (string) ($row['ngaycapnhat'] ?? ''),
            ];
        }

        $result->free();
        $stmt->close();

        $detailByOrderId = [];
        $orderIds = array_values(array_unique(array_filter(array_map(static function ($row) {
            return (int) ($row['donhang_id'] ?? 0);
        }, $rows))));

        if (count($orderIds) > 0 && app_table_exists($conn, 'donhang_chitiet') && app_table_exists($conn, 'sanpham')) {
            $inList = implode(',', array_map('intval', $orderIds));
            $detailSql = "
                SELECT
                    ct.donhang_id,
                    ct.sanpham_id,
                    ct.masanpham,
                    ct.tensanpham,
                    ct.soluong,
                    ct.dongia,
                    COALESCE(
                        NULLIF(TRIM(s.hinhanhsanpham), ''),
                        (
                            SELECT s2.hinhanhsanpham
                            FROM sanpham s2
                            WHERE ct.masanpham IS NOT NULL
                              AND TRIM(ct.masanpham) <> ''
                              AND s2.masanpham = ct.masanpham
                            LIMIT 1
                        ),
                        (
                            SELECT s3.hinhanhsanpham
                            FROM sanpham s3
                            WHERE ct.tensanpham IS NOT NULL
                              AND TRIM(ct.tensanpham) <> ''
                              AND LOWER(s3.tensanpham) = LOWER(ct.tensanpham)
                            LIMIT 1
                        ),
                        ''
                    ) AS hinhanh
                FROM donhang_chitiet ct
                LEFT JOIN sanpham s ON s.id = ct.sanpham_id
                WHERE ct.donhang_id IN ({$inList})
                ORDER BY ct.id ASC
            ";

            $detailResult = $conn->query($detailSql);
            if ($detailResult) {
                while ($detailRow = $detailResult->fetch_assoc()) {
                    $detailOrderId = (int) ($detailRow['donhang_id'] ?? 0);
                    if ($detailOrderId <= 0) {
                        continue;
                    }

                    if (!isset($detailByOrderId[$detailOrderId])) {
                        $detailByOrderId[$detailOrderId] = [];
                    }

                    $qty = (int) ($detailRow['soluong'] ?? 0);
                    $price = (float) ($detailRow['dongia'] ?? 0);

                    $detailByOrderId[$detailOrderId][] = [
                        'id' => (int) ($detailRow['sanpham_id'] ?? 0),
                        'name' => (string) ($detailRow['tensanpham'] ?? 'San pham'),
                        'quantity' => $qty > 0 ? $qty : 1,
                        'price' => $price,
                        'image' => app_to_public_image_url((string) ($detailRow['hinhanh'] ?? '')),
                    ];
                }
                $detailResult->free();
            }
        }

        $data = [];
        foreach ($rows as $row) {
            $orderId = (int) ($row['donhang_id'] ?? 0);
            $items = [];

            if ($orderId > 0 && isset($detailByOrderId[$orderId])) {
                $items = $detailByOrderId[$orderId];
            } else {
                $legacyItems = json_decode((string) ($row['chitiet_json'] ?? '[]'), true);
                if (is_array($legacyItems)) {
                    foreach ($legacyItems as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $items[] = [
                            'id' => (int) ($item['id'] ?? $item['product_id'] ?? $item['sanpham_id'] ?? 0),
                            'name' => (string) ($item['name'] ?? $item['product_name'] ?? $item['title'] ?? $item['tensanpham'] ?? 'San pham'),
                            'quantity' => (int) ($item['quantity'] ?? $item['qty'] ?? $item['soluong'] ?? 1),
                            'price' => (float) ($item['price'] ?? $item['dongia'] ?? 0),
                            'image' => app_to_public_image_url((string) ($item['image'] ?? $item['hinhanh'] ?? $item['hinhanhsanpham'] ?? '')),
                        ];
                    }
                }
            }

            $data[] = [
                'id' => (int) ($row['id'] ?? 0),
                'donhang_id' => $orderId,
                'madonhang' => (string) ($row['madonhang'] ?? ''),
                'tenkhachhang' => (string) ($row['tenkhachhang'] ?? ''),
                'sodienthoai' => (string) ($row['sodienthoai'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'diachi' => (string) ($row['diachi'] ?? ''),
                'ghichu' => (string) ($row['ghichu'] ?? ''),
                'tongtien' => (float) ($row['tongtien'] ?? 0),
                'trangthai' => (string) ($row['trangthai'] ?? 'cho_duyet'),
                'ldotuchoi' => (string) ($row['ldotuchoi'] ?? ''),
                'nguoiduyet' => (string) ($row['nguoiduyet'] ?? ''),
                'nguon' => (string) ($row['nguon'] ?? 'online'),
                'items' => $items,
                'ngaytao' => (string) ($row['ngaytao'] ?? ''),
                'ngaycapnhat' => (string) ($row['ngaycapnhat'] ?? ''),
            ];
        }

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

        if (!app_column_exists($conn, 'donhang_online', 'da_cong_chi_tieu')) {
            $conn->query("ALTER TABLE donhang_online ADD COLUMN da_cong_chi_tieu TINYINT(1) NOT NULL DEFAULT 0 AFTER nguoiduyet");
        }

        if (!app_ensure_order_tables($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the truy cap bang donhang/donhang_chitiet',
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
            SELECT id, donhang_id, madonhang, trangthai, tongtien, COALESCE(da_cong_chi_tieu, 0) AS da_cong_chi_tieu, chitiet_json
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
        $oldStatus = trim((string) ($targetRow['trangthai'] ?? 'cho_duyet'));
        $onlineTotal = (float) ($targetRow['tongtien'] ?? 0);
        $spendingAlreadyApplied = ((int) ($targetRow['da_cong_chi_tieu'] ?? 0)) === 1;
        $itemsFromOnline = json_decode((string) ($targetRow['chitiet_json'] ?? '[]'), true);
        if (!is_array($itemsFromOnline)) {
            $itemsFromOnline = [];
        }

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

        $conn->begin_transaction();
        try {
            $isTransitionToApproved = ($status === 'da_duyet' && $oldStatus !== 'da_duyet');
            if ($isTransitionToApproved) {
                $stockItems = app_prepare_order_items($itemsFromOnline);

                if (count($stockItems) === 0 && $internalOrderId > 0 && app_table_exists($conn, 'donhang_chitiet')) {
                    $detailSql = "
                        SELECT sanpham_id, soluong, dongia, masanpham, tensanpham
                        FROM donhang_chitiet
                        WHERE donhang_id = ?
                    ";
                    $detailStmt = $conn->prepare($detailSql);
                    if ($detailStmt) {
                        $detailStmt->bind_param('i', $internalOrderId);
                        $detailStmt->execute();
                        $detailResult = $detailStmt->get_result();
                        while ($detailResult && ($detailRow = $detailResult->fetch_assoc())) {
                            $stockItems[] = [
                                'product_id' => (int) ($detailRow['sanpham_id'] ?? 0),
                                'quantity' => (int) ($detailRow['soluong'] ?? 0),
                                'price' => (float) ($detailRow['dongia'] ?? 0),
                                'code' => (string) ($detailRow['masanpham'] ?? ''),
                                'name' => (string) ($detailRow['tensanpham'] ?? ''),
                            ];
                        }
                        if ($detailResult) {
                            $detailResult->free();
                        }
                        $detailStmt->close();
                    }
                }

                if (count($stockItems) === 0) {
                    throw new RuntimeException('Không tìm thấy chi tiết sản phẩm để trừ kho cho đơn online này.');
                }

                [$stockOk, $stockMessage] = app_apply_stock_deduction($conn, $stockItems);
                if (!$stockOk) {
                    $detail = trim((string) $stockMessage);
                    if ($detail === '') {
                        $detail = 'Không thể trừ tồn kho cho đơn online.';
                    }
                    throw new RuntimeException($detail);
                }
            }

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

            if ($status === 'da_duyet' && $internalOrderId > 0 && app_table_exists($conn, 'donhang') && app_table_exists($conn, 'khachhang')) {
                $orderInfoSql = "
                    SELECT khachhang_id, COALESCE(tongtiendonhang, 0) AS tongtiendonhang
                    FROM donhang
                    WHERE id = ?
                    LIMIT 1
                ";
                $orderInfoStmt = $conn->prepare($orderInfoSql);
                if ($orderInfoStmt) {
                    $orderInfoStmt->bind_param('i', $internalOrderId);
                    $orderInfoStmt->execute();
                    $orderInfoResult = $orderInfoStmt->get_result();
                    $orderInfoRow = $orderInfoResult ? $orderInfoResult->fetch_assoc() : null;
                    if ($orderInfoResult) {
                        $orderInfoResult->free();
                    }
                    $orderInfoStmt->close();

                    $orderCustomerId = is_array($orderInfoRow) ? (int) ($orderInfoRow['khachhang_id'] ?? 0) : 0;
                    $orderTotal = is_array($orderInfoRow) ? (float) ($orderInfoRow['tongtiendonhang'] ?? 0) : 0;
                    $spendingAmount = $orderTotal > 0 ? $orderTotal : $onlineTotal;

                    if ($orderCustomerId > 0 && $spendingAmount > 0) {
                        app_recalculate_customer_spending_from_orders($conn, $orderCustomerId);
                    }

                    if ($orderCustomerId > 0) {
                        app_admin_reset_cart_for_customer($conn, $orderCustomerId);
                    }

                    if (!$spendingAlreadyApplied) {
                        $appliedFlagStmt = $conn->prepare('UPDATE donhang_online SET da_cong_chi_tieu = 1 WHERE id = ? LIMIT 1');
                        if ($appliedFlagStmt) {
                            $appliedFlagStmt->bind_param('i', $onlineId);
                            $appliedFlagStmt->execute();
                            $appliedFlagStmt->close();
                        }
                    }
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

            $conn->commit();
            $conn->close();

            app_json_response([
                'ok' => true,
                'message' => 'Cap nhat trang thai don online thanh cong',
            ]);
        } catch (Throwable $e) {
            try {
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->close();
                }
            } catch (Throwable $_) {
                // Keep error response path stable.
            }

            try {
                if ($conn instanceof mysqli) {
                    $conn->rollback();
                }
            } catch (Throwable $_) {
                // Ignore rollback errors and continue returning JSON.
            }

            $conn->close();

            $detailReasonRaw = trim((string) $e->getMessage());
            $detailReason = $detailReasonRaw;
            if ($detailReason !== '') {
                $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $detailReason);
                if (is_string($sanitized) && trim($sanitized) !== '') {
                    $detailReason = trim($sanitized);
                }
            }

            $reasonLower = app_lower($detailReasonRaw);
            if (
                $detailReason === '' ||
                strpos($reasonLower, 'khong du ton kho') !== false ||
                strpos($reasonLower, 'khong du thu cung') !== false ||
                strpos($reasonLower, 'het hang') !== false
            ) {
                $detailReason = 'Sản phẩm đã hết hàng hoặc không đủ tồn kho để duyệt đơn.';
            }

            $userMessage = 'Cập nhật trạng thái đơn online thất bại.';
            if ($detailReason !== '') {
                $userMessage .= ' Lý do: ' . $detailReason;
            }

            app_json_response([
                'ok' => false,
                'message' => $userMessage,
                'error' => $detailReason,
            ], 400);
        }
    }

    return false;
}
