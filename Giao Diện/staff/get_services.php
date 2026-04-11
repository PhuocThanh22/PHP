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

function app_staff_customer_tier_key(float $spending): string
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

function app_booking_ensure_table(mysqli $conn): bool
{
    $sql = "
        CREATE TABLE IF NOT EXISTS lichhen (
            id INT NOT NULL AUTO_INCREMENT,
            khachhang_id INT NULL,
            thucung_id INT NULL,
            dichvu_id INT NULL,
            nhanvien_id INT NULL,
            thoigianhen DATETIME NULL,
            trangthailichhen ENUM('choduyet','hoanthanh','huy') DEFAULT 'choduyet',
            tenkhachhang VARCHAR(120) NULL,
            sodienthoai VARCHAR(20) NULL,
            email VARCHAR(190) NULL,
            tendichvu VARCHAR(190) NULL,
            giadichvu VARCHAR(80) NULL,
            tenthucung VARCHAR(120) NULL,
            loaithucung VARCHAR(60) NULL,
            khunggio VARCHAR(60) NULL,
            ghichu TEXT NULL,
            ghichunhanvien TEXT NULL,
            nguoidung_id INT NULL,
            ngaytao DATETIME NULL,
            ngaycapnhat DATETIME NULL,
            ghichulichhen TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_lichhen_khachhang (khachhang_id),
            KEY idx_lichhen_dichvu (dichvu_id),
            KEY idx_lichhen_nhanvien (nhanvien_id),
            KEY idx_lichhen_thoigian (thoigianhen),
            KEY idx_lichhen_user (nguoidung_id),
            KEY idx_lichhen_created (ngaytao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!$conn->query($sql)) {
        return false;
    }

    $renameMigrations = [
        ['old' => 'customer_name', 'new' => 'tenkhachhang'],
        ['old' => 'customer_phone', 'new' => 'sodienthoai'],
        ['old' => 'customer_email', 'new' => 'email'],
        ['old' => 'service_name', 'new' => 'tendichvu'],
        ['old' => 'service_price', 'new' => 'giadichvu'],
        ['old' => 'pet_name', 'new' => 'tenthucung'],
        ['old' => 'pet_type', 'new' => 'loaithucung'],
        ['old' => 'time_slot', 'new' => 'khunggio'],
        ['old' => 'booking_note', 'new' => 'ghichu'],
        ['old' => 'staff_note', 'new' => 'ghichunhanvien'],
        ['old' => 'user_id', 'new' => 'nguoidung_id'],
        ['old' => 'created_at', 'new' => 'ngaytao'],
        ['old' => 'updated_at', 'new' => 'ngaycapnhat'],
    ];

    foreach ($renameMigrations as $rename) {
        $old = (string) ($rename['old'] ?? '');
        $new = (string) ($rename['new'] ?? '');
        if ($old === '' || $new === '') {
            continue;
        }
        if (app_column_exists($conn, 'lichhen', $old) && !app_column_exists($conn, 'lichhen', $new)) {
            if (!$conn->query("ALTER TABLE lichhen RENAME COLUMN {$old} TO {$new}")) {
                return false;
            }
        }
    }

    $columnMigrations = [
        'tenkhachhang' => "ALTER TABLE lichhen ADD COLUMN tenkhachhang VARCHAR(120) NULL AFTER trangthailichhen",
        'sodienthoai' => "ALTER TABLE lichhen ADD COLUMN sodienthoai VARCHAR(20) NULL AFTER tenkhachhang",
        'email' => "ALTER TABLE lichhen ADD COLUMN email VARCHAR(190) NULL AFTER sodienthoai",
        'tendichvu' => "ALTER TABLE lichhen ADD COLUMN tendichvu VARCHAR(190) NULL AFTER email",
        'giadichvu' => "ALTER TABLE lichhen ADD COLUMN giadichvu VARCHAR(80) NULL AFTER tendichvu",
        'tenthucung' => "ALTER TABLE lichhen ADD COLUMN tenthucung VARCHAR(120) NULL AFTER giadichvu",
        'loaithucung' => "ALTER TABLE lichhen ADD COLUMN loaithucung VARCHAR(60) NULL AFTER tenthucung",
        'khunggio' => "ALTER TABLE lichhen ADD COLUMN khunggio VARCHAR(60) NULL AFTER loaithucung",
        'ghichu' => "ALTER TABLE lichhen ADD COLUMN ghichu TEXT NULL AFTER khunggio",
        'ghichunhanvien' => "ALTER TABLE lichhen ADD COLUMN ghichunhanvien TEXT NULL AFTER ghichu",
        'nguoidung_id' => "ALTER TABLE lichhen ADD COLUMN nguoidung_id INT NULL AFTER ghichunhanvien",
        'ngaytao' => "ALTER TABLE lichhen ADD COLUMN ngaytao DATETIME NULL AFTER nguoidung_id",
        'ngaycapnhat' => "ALTER TABLE lichhen ADD COLUMN ngaycapnhat DATETIME NULL AFTER ngaytao",
    ];

    foreach ($columnMigrations as $column => $migrationSql) {
        if (!app_column_exists($conn, 'lichhen', $column)) {
            if (!$conn->query($migrationSql)) {
                return false;
            }
        }
    }

    if (!app_column_exists($conn, 'lichhen', 'ghichulichhen')) {
        if (!$conn->query("ALTER TABLE lichhen ADD COLUMN ghichulichhen TEXT NULL AFTER ngaycapnhat")) {
            return false;
        }
    }

    return true;
}

function app_booking_decode_note(string $note): array
{
    $parsed = json_decode($note, true);
    return is_array($parsed) ? $parsed : [];
}

function app_booking_resolve_datetime(string $date, string $timeSlot): string
{
    $cleanDate = trim($date);
    if ($cleanDate === '') {
        return date('Y-m-d H:i:s');
    }

    $time = '09:00';
    if (preg_match('/(\d{1,2}:\d{2})/', $timeSlot, $matches) === 1) {
        $time = $matches[1];
    }

    $value = date_create($cleanDate . ' ' . $time);
    if ($value instanceof DateTime) {
        return $value->format('Y-m-d H:i:s');
    }

    return $cleanDate . ' 09:00:00';
}

function app_booking_find_or_create_customer(mysqli $conn, string $name, string $phone, string $email): int
{
    if (!app_table_exists($conn, 'khachhang')) {
        return 0;
    }

    $phone = trim($phone);
    $email = trim($email);

    if ($phone !== '' || $email !== '') {
        $findSql = "
            SELECT id
            FROM khachhang
            WHERE (COALESCE(TRIM(sodienthoaikhachhang), '') <> '' AND sodienthoaikhachhang = ?)
               OR (COALESCE(TRIM(emailkhachhang), '') <> '' AND LOWER(emailkhachhang) = LOWER(?))
            LIMIT 1
        ";
        $findStmt = $conn->prepare($findSql);
        if ($findStmt) {
            $findStmt->bind_param('ss', $phone, $email);
            $findStmt->execute();
            $found = $findStmt->get_result();
            $row = $found ? $found->fetch_assoc() : null;
            if ($found) {
                $found->free();
            }
            $findStmt->close();
            if (is_array($row)) {
                return (int) ($row['id'] ?? 0);
            }
        }
    }

    $insertSql = "
        INSERT INTO khachhang
            (tenkhachhang, sodienthoaikhachhang, emailkhachhang, tongchitieukhachhang, loaikhachhang)
        VALUES
            (?, ?, ?, 0, 'thuong')
    ";
    $insertStmt = $conn->prepare($insertSql);
    if (!$insertStmt) {
        return 0;
    }

    $safeName = $name !== '' ? $name : 'Khach hang online';
    $insertStmt->bind_param('sss', $safeName, $phone, $email);
    $insertStmt->execute();
    $id = (int) $insertStmt->insert_id;
    $insertStmt->close();

    return $id;
}

function app_booking_find_service_id(mysqli $conn, string $serviceName): int
{
    $serviceName = trim($serviceName);
    if ($serviceName === '' || !app_table_exists($conn, 'dichvu')) {
        return 0;
    }

    $exact = $conn->prepare('SELECT id FROM dichvu WHERE LOWER(tendichvu) = LOWER(?) LIMIT 1');
    if ($exact) {
        $exact->bind_param('s', $serviceName);
        $exact->execute();
        $result = $exact->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $exact->close();
        if (is_array($row)) {
            return (int) ($row['id'] ?? 0);
        }
    }

    $keyword = '%' . $serviceName . '%';
    $like = $conn->prepare('SELECT id FROM dichvu WHERE LOWER(tendichvu) LIKE LOWER(?) ORDER BY id ASC LIMIT 1');
    if ($like) {
        $like->bind_param('s', $keyword);
        $like->execute();
        $result = $like->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $like->close();
        if (is_array($row)) {
            return (int) ($row['id'] ?? 0);
        }
    }

    return 0;
}

function app_ensure_revenue_table(mysqli $conn): bool
{
    $sql = "
        CREATE TABLE IF NOT EXISTS doanhthu (
            id INT NOT NULL AUTO_INCREMENT,
            nguondoanhthu ENUM('dichvu','sanpham') DEFAULT 'sanpham',
            nguon_id INT NULL,
            tennguon VARCHAR(190) NULL,
            soluongdoanhthu INT NOT NULL DEFAULT 1,
            sotiendoanhthu DECIMAL(14,2) NOT NULL DEFAULT 0,
            thamchieu VARCHAR(80) NULL,
            ngaytaodoanhthu DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_doanhthu_date (ngaytaodoanhthu),
            KEY idx_doanhthu_source (nguondoanhthu),
            KEY idx_doanhthu_ref (thamchieu)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!(bool) $conn->query($sql)) {
        return false;
    }

    $columnMigrations = [
        'nguon_id' => "ALTER TABLE doanhthu ADD COLUMN nguon_id INT NULL AFTER nguondoanhthu",
        'tennguon' => "ALTER TABLE doanhthu ADD COLUMN tennguon VARCHAR(190) NULL AFTER nguon_id",
        'soluongdoanhthu' => "ALTER TABLE doanhthu ADD COLUMN soluongdoanhthu INT NOT NULL DEFAULT 1 AFTER tennguon",
        'thamchieu' => "ALTER TABLE doanhthu ADD COLUMN thamchieu VARCHAR(80) NULL AFTER sotiendoanhthu",
    ];

    foreach ($columnMigrations as $column => $migrationSql) {
        if (!app_column_exists($conn, 'doanhthu', $column)) {
            $conn->query($migrationSql);
        }
    }

    return true;
}

function app_ensure_favorites_extended(mysqli $conn): void
{
    if (!app_table_exists($conn, 'yeuthich')) {
        return;
    }

    if (!app_column_exists($conn, 'yeuthich', 'dichvu_id')) {
        $conn->query('ALTER TABLE yeuthich ADD COLUMN dichvu_id INT NULL AFTER sanpham_id');
    }

    if (!app_column_exists($conn, 'yeuthich', 'thucung_id')) {
        $conn->query('ALTER TABLE yeuthich ADD COLUMN thucung_id INT NULL AFTER dichvu_id');
    }
}

function app_sync_revenue_table(mysqli $conn): bool
{
    if (!app_ensure_revenue_table($conn)) {
        return false;
    }

    if (!(bool) $conn->query('DELETE FROM doanhthu')) {
        return false;
    }

    if (app_table_exists($conn, 'donhang') && app_table_exists($conn, 'donhang_chitiet')) {
        $detailResult = $conn->query('SELECT COUNT(*) AS total FROM donhang_chitiet');
        $detailCount = 0;
        if ($detailResult) {
            $detailRow = $detailResult->fetch_assoc();
            $detailCount = (int) ($detailRow['total'] ?? 0);
            $detailResult->free();
        }

        if ($detailCount > 0) {
            $conn->query(
                "
                INSERT INTO doanhthu (nguondoanhthu, nguon_id, tennguon, soluongdoanhthu, sotiendoanhthu, thamchieu, ngaytaodoanhthu)
                SELECT
                    'sanpham' AS nguondoanhthu,
                    ct.sanpham_id AS nguon_id,
                    COALESCE(NULLIF(TRIM(ct.tensanpham), ''), NULLIF(TRIM(sp.tensanpham), ''), 'San pham') AS tennguon,
                    COALESCE(ct.soluong, 1) AS soluongdoanhthu,
                    COALESCE(ct.thanhtien, COALESCE(ct.soluong, 1) * COALESCE(ct.dongia, 0)) AS sotiendoanhthu,
                    COALESCE(NULLIF(TRIM(dh.madonhang), ''), CONCAT('DH-', dh.id)) AS thamchieu,
                    COALESCE(dh.ngaydatdonhang, NOW()) AS ngaytaodoanhthu
                FROM donhang_chitiet ct
                INNER JOIN donhang dh ON dh.id = ct.donhang_id
                LEFT JOIN sanpham sp ON sp.id = ct.sanpham_id
                WHERE COALESCE(dh.trangthaidonhang, '') <> 'huy'
                "
            );
        }
    }

    if (
        app_table_exists($conn, 'donhang') &&
        app_table_exists($conn, 'chitietdonhang') &&
        app_table_exists($conn, 'doanhthu')
    ) {
        $hasAnyRevenue = $conn->query('SELECT COUNT(*) AS total FROM doanhthu');
        $revenueCount = 0;
        if ($hasAnyRevenue) {
            $row = $hasAnyRevenue->fetch_assoc();
            $revenueCount = (int) ($row['total'] ?? 0);
            $hasAnyRevenue->free();
        }

        if ($revenueCount === 0) {
            $conn->query(
                "
                INSERT INTO doanhthu (nguondoanhthu, nguon_id, tennguon, soluongdoanhthu, sotiendoanhthu, thamchieu, ngaytaodoanhthu)
                SELECT
                    'sanpham' AS nguondoanhthu,
                    ct.sanpham_id AS nguon_id,
                    COALESCE(NULLIF(TRIM(sp.tensanpham), ''), 'San pham') AS tennguon,
                    COALESCE(ct.soluongchitiet, 1) AS soluongdoanhthu,
                    COALESCE(ct.soluongchitiet, 1) * COALESCE(ct.giachitiet, 0) AS sotiendoanhthu,
                    COALESCE(NULLIF(TRIM(dh.madonhang), ''), CONCAT('DH-', dh.id)) AS thamchieu,
                    COALESCE(dh.ngaydatdonhang, NOW()) AS ngaytaodoanhthu
                FROM chitietdonhang ct
                INNER JOIN donhang dh ON dh.id = ct.donhang_id
                LEFT JOIN sanpham sp ON sp.id = ct.sanpham_id
                WHERE COALESCE(dh.trangthaidonhang, '') <> 'huy'
                "
            );
        }
    }

    if (app_table_exists($conn, 'lichhen')) {
        $conn->query(
            "
            INSERT INTO doanhthu (nguondoanhthu, nguon_id, tennguon, soluongdoanhthu, sotiendoanhthu, thamchieu, ngaytaodoanhthu)
            SELECT
                'dichvu' AS nguondoanhthu,
                l.dichvu_id AS nguon_id,
                COALESCE(NULLIF(TRIM(l.tendichvu), ''), NULLIF(TRIM(d.tendichvu), ''), 'Dich vu') AS tennguon,
                1 AS soluongdoanhthu,
                COALESCE(
                    NULLIF(d.giadichvu, 0),
                    CAST(REPLACE(REPLACE(REPLACE(COALESCE(l.giadichvu, '0'), 'đ', ''), 'VND', ''), ',', '') AS DECIMAL(14,2)),
                    0
                ) AS sotiendoanhthu,
                CONCAT('LH-', l.id) AS thamchieu,
                COALESCE(l.thoigianhen, l.ngaytao, NOW()) AS ngaytaodoanhthu
            FROM lichhen l
            LEFT JOIN dichvu d ON d.id = l.dichvu_id
            WHERE COALESCE(l.trangthailichhen, '') = 'hoanthanh'
            "
        );
    }

    return true;
}

function app_handle_staff_api(mysqli $conn, string $api): bool
{
    if ($api === 'get_revenue_dashboard') {
        if (!app_sync_revenue_table($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the dong bo du lieu doanhthu',
                'error' => $conn->error,
            ], 500);
        }

        app_ensure_favorites_extended($conn);

        $months = [];
        $monthMap = [];
        for ($i = 5; $i >= 0; $i--) {
            $ts = strtotime('-' . $i . ' month');
            $ym = date('Y-m', $ts);
            $months[] = [
                'key' => $ym,
                'label' => 'T' . date('n', $ts),
            ];
            $monthMap[$ym] = [
                'product' => 0.0,
                'service' => 0.0,
            ];
        }

        $fromDate = date('Y-m-01', strtotime('-5 month'));
        $thisMonthKey = date('Y-m');
        $prevMonthKey = date('Y-m', strtotime('-1 month'));

        $monthlyRevenueSql = "
            SELECT
                DATE_FORMAT(ngaytaodoanhthu, '%Y-%m') AS ym,
                COALESCE(nguondoanhthu, 'sanpham') AS source,
                SUM(COALESCE(sotiendoanhthu, 0)) AS revenue
            FROM doanhthu
            WHERE DATE(ngaytaodoanhthu) >= ?
            GROUP BY DATE_FORMAT(ngaytaodoanhthu, '%Y-%m'), COALESCE(nguondoanhthu, 'sanpham')
        ";

        $monthlyStmt = $conn->prepare($monthlyRevenueSql);
        if ($monthlyStmt) {
            $monthlyStmt->bind_param('s', $fromDate);
            $monthlyStmt->execute();
            $result = $monthlyStmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $ym = (string) ($row['ym'] ?? '');
                $source = (string) ($row['source'] ?? 'sanpham');
                if (!isset($monthMap[$ym])) {
                    continue;
                }

                if ($source === 'dichvu') {
                    $monthMap[$ym]['service'] += (float) ($row['revenue'] ?? 0);
                } else {
                    $monthMap[$ym]['product'] += (float) ($row['revenue'] ?? 0);
                }
            }
            if ($result) {
                $result->free();
            }
            $monthlyStmt->close();
        }

        $labels = [];
        $productRevenue = [];
        $serviceRevenue = [];
        foreach ($months as $month) {
            $key = (string) ($month['key'] ?? '');
            $labels[] = (string) ($month['label'] ?? '');
            $productRevenue[] = round((float) ($monthMap[$key]['product'] ?? 0), 2);
            $serviceRevenue[] = round((float) ($monthMap[$key]['service'] ?? 0), 2);
        }

        $thisMonthProduct = (float) ($monthMap[$thisMonthKey]['product'] ?? 0);
        $thisMonthService = (float) ($monthMap[$thisMonthKey]['service'] ?? 0);
        $thisMonthTotal = $thisMonthProduct + $thisMonthService;
        $prevMonthTotal = (float) (($monthMap[$prevMonthKey]['product'] ?? 0) + ($monthMap[$prevMonthKey]['service'] ?? 0));
        $growthPercent = $prevMonthTotal > 0 ? round((($thisMonthTotal - $prevMonthTotal) / $prevMonthTotal) * 100, 2) : 0;

        $completedOrdersThisMonth = 0;
        $ordersSql = "
            SELECT COUNT(DISTINCT thamchieu) AS total
            FROM doanhthu
            WHERE nguondoanhthu = 'sanpham'
              AND DATE_FORMAT(ngaytaodoanhthu, '%Y-%m') = ?
        ";
        $ordersStmt = $conn->prepare($ordersSql);
        if ($ordersStmt) {
            $ordersStmt->bind_param('s', $thisMonthKey);
            $ordersStmt->execute();
            $ordersResult = $ordersStmt->get_result();
            $ordersRow = $ordersResult ? $ordersResult->fetch_assoc() : null;
            if ($ordersResult) {
                $ordersResult->free();
            }
            $ordersStmt->close();
            $completedOrdersThisMonth = (int) ($ordersRow['total'] ?? 0);
        }

        $bestProduct = [
            'name' => 'Chua co du lieu',
            'quantity' => 0,
            'revenue' => 0,
        ];
        $bestProductSql = "
            SELECT
                COALESCE(NULLIF(TRIM(tennguon), ''), 'San pham') AS item_name,
                SUM(COALESCE(soluongdoanhthu, 1)) AS sold_qty,
                SUM(COALESCE(sotiendoanhthu, 0)) AS sold_revenue
            FROM doanhthu
            WHERE nguondoanhthu = 'sanpham'
            GROUP BY item_name
            ORDER BY sold_qty DESC, sold_revenue DESC
            LIMIT 1
        ";
        $bestProductResult = $conn->query($bestProductSql);
        if ($bestProductResult && ($row = $bestProductResult->fetch_assoc())) {
            $bestProduct = [
                'name' => (string) ($row['item_name'] ?? 'San pham'),
                'quantity' => (int) ($row['sold_qty'] ?? 0),
                'revenue' => (float) ($row['sold_revenue'] ?? 0),
            ];
            $bestProductResult->free();
        } elseif ($bestProductResult) {
            $bestProductResult->free();
        }

        $bestService = [
            'name' => 'Chua co du lieu',
            'count' => 0,
            'revenue' => 0,
        ];
        $bestServiceSql = "
            SELECT
                COALESCE(NULLIF(TRIM(tennguon), ''), 'Dich vu') AS item_name,
                SUM(COALESCE(soluongdoanhthu, 1)) AS sold_count,
                SUM(COALESCE(sotiendoanhthu, 0)) AS sold_revenue
            FROM doanhthu
            WHERE nguondoanhthu = 'dichvu'
            GROUP BY item_name
            ORDER BY sold_count DESC, sold_revenue DESC
            LIMIT 1
        ";
        $bestServiceResult = $conn->query($bestServiceSql);
        if ($bestServiceResult && ($row = $bestServiceResult->fetch_assoc())) {
            $bestService = [
                'name' => (string) ($row['item_name'] ?? 'Dich vu'),
                'count' => (int) ($row['sold_count'] ?? 0),
                'revenue' => (float) ($row['sold_revenue'] ?? 0),
            ];
            $bestServiceResult->free();
        } elseif ($bestServiceResult) {
            $bestServiceResult->free();
        }

        $favoriteProduct = [
            'name' => 'Chua co du lieu',
            'count' => 0,
        ];
        if (app_table_exists($conn, 'yeuthich')) {
            $favoriteProductSql = "
                SELECT
                    COALESCE(NULLIF(TRIM(s.tensanpham), ''), 'San pham') AS item_name,
                    COUNT(*) AS like_count
                FROM yeuthich y
                LEFT JOIN sanpham s ON s.id = y.sanpham_id
                WHERE y.sanpham_id IS NOT NULL
                GROUP BY item_name
                ORDER BY like_count DESC, item_name ASC
                LIMIT 1
            ";
            $favoriteProductResult = $conn->query($favoriteProductSql);
            if ($favoriteProductResult && ($row = $favoriteProductResult->fetch_assoc())) {
                $favoriteProduct = [
                    'name' => (string) ($row['item_name'] ?? 'San pham'),
                    'count' => (int) ($row['like_count'] ?? 0),
                ];
                $favoriteProductResult->free();
            } elseif ($favoriteProductResult) {
                $favoriteProductResult->free();
            }
        }

        $favoriteService = [
            'name' => 'Chua co du lieu',
            'count' => 0,
        ];
        if (app_table_exists($conn, 'yeuthich') && app_column_exists($conn, 'yeuthich', 'dichvu_id')) {
            $favoriteServiceSql = "
                SELECT
                    COALESCE(NULLIF(TRIM(d.tendichvu), ''), 'Dich vu') AS item_name,
                    COUNT(*) AS like_count
                FROM yeuthich y
                LEFT JOIN dichvu d ON d.id = y.dichvu_id
                WHERE y.dichvu_id IS NOT NULL
                GROUP BY item_name
                ORDER BY like_count DESC, item_name ASC
                LIMIT 1
            ";
            $favoriteServiceResult = $conn->query($favoriteServiceSql);
            if ($favoriteServiceResult && ($row = $favoriteServiceResult->fetch_assoc())) {
                $favoriteService = [
                    'name' => (string) ($row['item_name'] ?? 'Dich vu'),
                    'count' => (int) ($row['like_count'] ?? 0),
                ];
                $favoriteServiceResult->free();
            } elseif ($favoriteServiceResult) {
                $favoriteServiceResult->free();
            }
        }

        $favoritePet = [
            'name' => 'Chua co du lieu',
            'count' => 0,
        ];
        if (app_table_exists($conn, 'yeuthich') && app_column_exists($conn, 'yeuthich', 'thucung_id')) {
            $favoritePetSql = "
                SELECT
                    COALESCE(NULLIF(TRIM(t.tenthucung), ''), 'Thu cung') AS item_name,
                    COUNT(*) AS like_count
                FROM yeuthich y
                LEFT JOIN thucung t ON t.id = y.thucung_id
                WHERE y.thucung_id IS NOT NULL
                GROUP BY item_name
                ORDER BY like_count DESC, item_name ASC
                LIMIT 1
            ";
            $favoritePetResult = $conn->query($favoritePetSql);
            if ($favoritePetResult && ($row = $favoritePetResult->fetch_assoc())) {
                $favoritePet = [
                    'name' => (string) ($row['item_name'] ?? 'Thu cung'),
                    'count' => (int) ($row['like_count'] ?? 0),
                ];
                $favoritePetResult->free();
            } elseif ($favoritePetResult) {
                $favoritePetResult->free();
            }
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'summary' => [
                'current_month_revenue' => round($thisMonthTotal, 2),
                'current_month_service_revenue' => round($thisMonthService, 2),
                'completed_orders_this_month' => $completedOrdersThisMonth,
                'growth_percent' => $growthPercent,
            ],
            'chart' => [
                'labels' => $labels,
                'service_revenue' => $serviceRevenue,
                'product_revenue' => $productRevenue,
            ],
            'highlights' => [
                'best_selling_product' => $bestProduct,
                'best_selling_service' => $bestService,
                'favorite_product' => $favoriteProduct,
                'favorite_service' => $favoriteService,
                'favorite_pet' => $favoritePet,
            ],
        ]);
    }

    if ($api === 'checkout_pos_order') {
        if (!app_ensure_order_tables($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao bang donhang/donhang_chitiet',
                'error' => $conn->error,
            ], 500);
        }

        $input = app_input_payload();
        $customerName = trim((string) ($input['customer_name'] ?? 'Khach le'));
        $customerPhone = trim((string) ($input['customer_phone'] ?? ''));
        $customerEmail = trim((string) ($input['customer_email'] ?? ''));
        $staffName = trim((string) ($input['staff_name'] ?? 'Nhan vien'));
        $note = trim((string) ($input['note'] ?? ''));
        $paymentMethod = app_normalize_payment_method((string) ($input['payment_method'] ?? 'tien_mat'));
        $items = app_prepare_order_items(is_array($input['items'] ?? null) ? $input['items'] : []);

        if (count($items) === 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Gio hang trong hoac du lieu san pham khong hop le',
            ], 400);
        }

        $customerId = 0;
        if ($customerPhone !== '' || $customerEmail !== '' || $customerName !== '') {
            $customerId = app_booking_find_or_create_customer($conn, $customerName, $customerPhone, $customerEmail);
        }

        $conn->begin_transaction();
        try {
            [$stockOk, $stockMessage] = app_apply_stock_deduction($conn, $items);
            if (!$stockOk) {
                throw new RuntimeException($stockMessage !== '' ? $stockMessage : 'Khong the cap nhat ton kho');
            }

            $total = 0.0;
            foreach ($items as $item) {
                $total += (float) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 0);
            }

            if ($total <= 0) {
                throw new RuntimeException('Tong tien don hang khong hop le');
            }

            $orderCode = app_generate_order_code('POS');
            $insertOrderSql = "
                INSERT INTO donhang
                    (khachhang_id, madonhang, ngaydatdonhang, tongtiendonhang, trangthaidonhang, nguondonhang, phuongthucthanhtoan, tennhanvien, ghichudonhang)
                VALUES
                    (NULLIF(?, 0), ?, NOW(), ?, 'hoanthanh', 'tai_quay', ?, ?, ?)
            ";
            $insertOrderStmt = $conn->prepare($insertOrderSql);
            if (!$insertOrderStmt) {
                throw new RuntimeException('Khong the tao don hang');
            }

            $insertOrderStmt->bind_param('isdsss', $customerId, $orderCode, $total, $paymentMethod, $staffName, $note);
            $insertOrderStmt->execute();
            $orderId = (int) $insertOrderStmt->insert_id;
            $insertOrderStmt->close();

            if ($orderId <= 0) {
                throw new RuntimeException('Khong the luu don hang tai quay');
            }

            $detailStmt = $conn->prepare('INSERT INTO donhang_chitiet (donhang_id, sanpham_id, masanpham, tensanpham, soluong, dongia, thanhtien) VALUES (?, ?, ?, ?, ?, ?, ?)');
            if (!$detailStmt) {
                throw new RuntimeException('Khong the luu chi tiet don hang');
            }

            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 0);
                $price = (float) ($item['price'] ?? 0);
                $lineTotal = $price * $qty;
                $code = (string) ($item['code'] ?? '');
                $name = (string) ($item['name'] ?? 'San pham');
                $detailStmt->bind_param('iissidd', $orderId, $productId, $code, $name, $qty, $price, $lineTotal);
                $detailStmt->execute();
            }
            $detailStmt->close();

            if (app_table_exists($conn, 'lichsudonhang')) {
                $historyNote = 'Thanh toan tai quay - ' . ($staffName !== '' ? $staffName : 'Nhan vien');
                $historyStmt = $conn->prepare('INSERT INTO lichsudonhang (donhang_id, trangthai, ghichu) VALUES (?, ?, ?)');
                if ($historyStmt) {
                    $historyStatus = 'hoanthanh';
                    $historyStmt->bind_param('iss', $orderId, $historyStatus, $historyNote);
                    $historyStmt->execute();
                    $historyStmt->close();
                }
            }

            $conn->commit();

            $responseCustomer = $customerName !== '' ? $customerName : 'Khach le';
            $conn->close();
            app_json_response([
                'ok' => true,
                'message' => 'Thanh toan thanh cong',
                'data' => [
                    'order_id' => $orderId,
                    'mahoadon' => $orderCode,
                    'tenkhachhang' => $responseCustomer,
                    'tennhanvien' => $staffName !== '' ? $staffName : 'Nhan vien',
                    'ngayban' => date('Y-m-d H:i:s'),
                    'tongtien' => $total,
                    'phuongthucthanhtoan' => $paymentMethod,
                    'trangthai' => 'hoan_tat',
                ],
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Thanh toan that bai',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    if ($api === 'get_sales') {
        if (!app_ensure_order_tables($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao bang donhang/donhang_chitiet',
                'error' => $conn->error,
            ], 500);
        }

        $keyword = trim((string) ($_GET['keyword'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 500);
        if ($limit <= 0 || $limit > 2000) {
            $limit = 500;
        }

        $sql = "
            SELECT
                d.id,
                d.madonhang,
                d.ngaydatdonhang,
                d.tongtiendonhang,
                d.trangthaidonhang,
                d.phuongthucthanhtoan,
                d.tennhanvien,
                d.nguondonhang,
                COALESCE(NULLIF(TRIM(k.tenkhachhang), ''), 'Khach le') AS tenkhachhang
            FROM donhang d
            LEFT JOIN khachhang k ON k.id = d.khachhang_id
            ORDER BY d.id DESC
            LIMIT ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tai danh sach don ban',
                'error' => $conn->error,
            ], 500);
        }

        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        $needle = app_lower($keyword);
        while ($row = $result->fetch_assoc()) {
            $maHoaDon = (string) ($row['madonhang'] ?? '');
            if ($maHoaDon === '') {
                $maHoaDon = 'DH' . str_pad((string) ((int) ($row['id'] ?? 0)), 8, '0', STR_PAD_LEFT);
            }

            $status = app_order_status_to_sell_status((string) ($row['trangthaidonhang'] ?? 'dangxuly'));
            $customer = (string) ($row['tenkhachhang'] ?? 'Khach le');
            $staffName = trim((string) ($row['tennhanvien'] ?? ''));
            if ($staffName === '') {
                $staffName = ((string) ($row['nguondonhang'] ?? '') === 'online') ? 'Online' : 'Nhan vien';
            }

            if ($needle !== '') {
                $haystack = app_lower(implode(' ', [
                    $maHoaDon,
                    $customer,
                    $staffName,
                    (string) ($row['ngaydatdonhang'] ?? ''),
                ]));
                if (strpos($haystack, $needle) === false) {
                    continue;
                }
            }

            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'mahoadon' => $maHoaDon,
                'tenkhachhang' => $customer,
                'tennhanvien' => $staffName,
                'ngayban' => (string) ($row['ngaydatdonhang'] ?? ''),
                'tongtien' => (float) ($row['tongtiendonhang'] ?? 0),
                'phuongthucthanhtoan' => (string) ($row['phuongthucthanhtoan'] ?? 'tien_mat'),
                'trangthai' => $status,
                'nguon' => (string) ($row['nguondonhang'] ?? ''),
            ];
        }

        $result->free();
        $stmt->close();
        $conn->close();

        app_json_response([
            'ok' => true,
            'count' => count($rows),
            'data' => array_values($rows),
        ]);
    }

    if ($api === 'get_sale_order_detail') {
        if (!app_ensure_order_tables($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao bang donhang/donhang_chitiet',
                'error' => $conn->error,
            ], 500);
        }

        $id = (int) ($_GET['id'] ?? 0);
        $maHoaDon = trim((string) ($_GET['mahoadon'] ?? ''));

        if ($id <= 0 && $maHoaDon === '') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Thieu id hoac mahoadon',
            ], 400);
        }

        $whereSql = $id > 0 ? 'd.id = ?' : 'd.madonhang = ?';
        $orderSql = "
            SELECT
                d.id,
                d.madonhang,
                d.ngaydatdonhang,
                d.tongtiendonhang,
                d.trangthaidonhang,
                d.phuongthucthanhtoan,
                d.tennhanvien,
                d.nguondonhang,
                d.ghichudonhang,
                COALESCE(NULLIF(TRIM(k.tenkhachhang), ''), 'Khach le') AS tenkhachhang,
                COALESCE(NULLIF(TRIM(k.sodienthoaikhachhang), ''), '') AS sodienthoai,
                COALESCE(NULLIF(TRIM(k.emailkhachhang), ''), '') AS email
            FROM donhang d
            LEFT JOIN khachhang k ON k.id = d.khachhang_id
            WHERE {$whereSql}
            LIMIT 1
        ";

        $orderStmt = $conn->prepare($orderSql);
        if (!$orderStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the lay thong tin don hang',
                'error' => $conn->error,
            ], 500);
        }

        if ($id > 0) {
            $orderStmt->bind_param('i', $id);
        } else {
            $orderStmt->bind_param('s', $maHoaDon);
        }

        $orderStmt->execute();
        $orderResult = $orderStmt->get_result();
        $orderRow = $orderResult ? $orderResult->fetch_assoc() : null;
        if ($orderResult) {
            $orderResult->free();
        }
        $orderStmt->close();

        if (!is_array($orderRow)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay don hang',
            ], 404);
        }

        $orderId = (int) ($orderRow['id'] ?? 0);
        $detailRows = [];

        if ($orderId > 0 && app_table_exists($conn, 'donhang_chitiet')) {
            $detailSql = "
                SELECT
                    ct.sanpham_id,
                    ct.masanpham,
                    ct.tensanpham,
                    ct.soluong,
                    ct.dongia,
                    ct.thanhtien
                FROM donhang_chitiet ct
                WHERE ct.donhang_id = ?
                ORDER BY ct.id ASC
            ";

            $detailStmt = $conn->prepare($detailSql);
            if ($detailStmt) {
                $detailStmt->bind_param('i', $orderId);
                $detailStmt->execute();
                $detailResult = $detailStmt->get_result();
                while ($detailResult && ($row = $detailResult->fetch_assoc())) {
                    $qty = (int) ($row['soluong'] ?? 0);
                    $price = (float) ($row['dongia'] ?? 0);
                    $line = (float) ($row['thanhtien'] ?? ($qty * $price));
                    $detailRows[] = [
                        'id' => (int) ($row['sanpham_id'] ?? 0),
                        'code' => (string) ($row['masanpham'] ?? ''),
                        'name' => (string) ($row['tensanpham'] ?? 'San pham'),
                        'quantity' => $qty,
                        'price' => $price,
                        'line_total' => $line,
                    ];
                }
                if ($detailResult) {
                    $detailResult->free();
                }
                $detailStmt->close();
            }
        }

        if (!$detailRows && $orderId > 0 && app_table_exists($conn, 'chitietdonhang')) {
            $detailSql = "
                SELECT
                    ct.sanpham_id,
                    sp.masanpham,
                    COALESCE(NULLIF(TRIM(sp.tensanpham), ''), 'San pham') AS tensanpham,
                    ct.soluongchitiet,
                    ct.giachitiet
                FROM chitietdonhang ct
                LEFT JOIN sanpham sp ON sp.id = ct.sanpham_id
                WHERE ct.donhang_id = ?
                ORDER BY ct.id ASC
            ";

            $detailStmt = $conn->prepare($detailSql);
            if ($detailStmt) {
                $detailStmt->bind_param('i', $orderId);
                $detailStmt->execute();
                $detailResult = $detailStmt->get_result();
                while ($detailResult && ($row = $detailResult->fetch_assoc())) {
                    $qty = (int) ($row['soluongchitiet'] ?? 0);
                    $price = (float) ($row['giachitiet'] ?? 0);
                    $detailRows[] = [
                        'id' => (int) ($row['sanpham_id'] ?? 0),
                        'code' => (string) ($row['masanpham'] ?? ''),
                        'name' => (string) ($row['tensanpham'] ?? 'San pham'),
                        'quantity' => $qty,
                        'price' => $price,
                        'line_total' => $qty * $price,
                    ];
                }
                if ($detailResult) {
                    $detailResult->free();
                }
                $detailStmt->close();
            }
        }

        $status = app_order_status_to_sell_status((string) ($orderRow['trangthaidonhang'] ?? 'dangxuly'));
        $source = (string) ($orderRow['nguondonhang'] ?? 'tai_quay');
        $ma = trim((string) ($orderRow['madonhang'] ?? ''));
        if ($ma === '') {
            $ma = 'DH' . str_pad((string) $orderId, 8, '0', STR_PAD_LEFT);
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'data' => [
                'id' => $orderId,
                'mahoadon' => $ma,
                'tenkhachhang' => (string) ($orderRow['tenkhachhang'] ?? 'Khach le'),
                'sodienthoai' => (string) ($orderRow['sodienthoai'] ?? ''),
                'email' => (string) ($orderRow['email'] ?? ''),
                'tennhanvien' => (string) ($orderRow['tennhanvien'] ?? ''),
                'ngayban' => (string) ($orderRow['ngaydatdonhang'] ?? ''),
                'tongtien' => (float) ($orderRow['tongtiendonhang'] ?? 0),
                'phuongthucthanhtoan' => (string) ($orderRow['phuongthucthanhtoan'] ?? 'tien_mat'),
                'trangthai' => $status,
                'nguon' => $source,
                'ghichu' => (string) ($orderRow['ghichudonhang'] ?? ''),
                'items' => $detailRows,
            ],
        ]);
    }

    if ($api === 'create_service_booking') {
        if (!app_booking_ensure_table($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao bang lichhen',
                'error' => $conn->error,
            ], 500);
        }

        $input = app_input_payload();
        $customerName = trim((string) ($input['customer_name'] ?? ''));
        $customerPhone = trim((string) ($input['customer_phone'] ?? ''));
        $customerEmail = trim((string) ($input['customer_email'] ?? ''));
        $serviceName = trim((string) ($input['service_name'] ?? ''));
        $servicePrice = trim((string) ($input['service_price'] ?? 'Liên hệ'));
        $petName = trim((string) ($input['pet_name'] ?? ''));
        $petType = trim((string) ($input['pet_type'] ?? ''));
        $appointmentDate = trim((string) ($input['date'] ?? ''));
        $timeSlot = trim((string) ($input['time_slot'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));
        $userId = (int) ($input['user_id'] ?? 0);

        if ($userId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Vui long dang nhap tai khoan de dat lich',
            ], 401);
        }

        $userCheckSql = "
            SELECT id, vaitronguoidung
            FROM nguoidung
            WHERE id = ?
            LIMIT 1
        ";
        $userCheckStmt = $conn->prepare($userCheckSql);
        if (!$userCheckStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the xac thuc tai khoan',
                'error' => $conn->error,
            ], 500);
        }
        $userCheckStmt->bind_param('i', $userId);
        $userCheckStmt->execute();
        $userCheckResult = $userCheckStmt->get_result();
        $userRow = $userCheckResult ? $userCheckResult->fetch_assoc() : null;
        if ($userCheckResult) {
            $userCheckResult->free();
        }
        $userCheckStmt->close();

        if (!is_array($userRow) || app_normalize_role((string) ($userRow['vaitronguoidung'] ?? 'user')) !== 'user') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Tai khoan khong hop le de dat lich',
            ], 403);
        }

        if ($customerName === '' || $customerPhone === '' || $serviceName === '' || $appointmentDate === '' || $timeSlot === '') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Thong tin dat lich chua day du',
            ], 400);
        }

        $customerId = app_booking_find_or_create_customer($conn, $customerName, $customerPhone, $customerEmail);
        $serviceId = app_booking_find_service_id($conn, $serviceName);
        $scheduledAt = app_booking_resolve_datetime($appointmentDate, $timeSlot);

        $notePayload = [
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail,
            'service_name' => $serviceName,
            'service_price' => $servicePrice,
            'pet_name' => $petName,
            'pet_type' => $petType,
            'time_slot' => $timeSlot,
            'note' => $note,
            'user_id' => $userId,
            'created_at' => date(DATE_ATOM),
        ];

        $noteJson = json_encode($notePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($noteJson)) {
            $noteJson = '{}';
        }

        $insertSql = "
            INSERT INTO lichhen (
                khachhang_id,
                dichvu_id,
                thoigianhen,
                trangthailichhen,
                tenkhachhang,
                sodienthoai,
                email,
                tendichvu,
                giadichvu,
                tenthucung,
                loaithucung,
                khunggio,
                ghichu,
                nguoidung_id,
                ghichunhanvien,
                ngaytao,
                ngaycapnhat,
                ghichulichhen
            )
            VALUES (
                NULLIF(?, 0),
                NULLIF(?, 0),
                ?,
                'choduyet',
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                '',
                NOW(),
                NOW(),
                ?
            )
        ";
        $insertStmt = $conn->prepare($insertSql);
        if (!$insertStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tao lich hen',
                'error' => $conn->error,
            ], 500);
        }

        $insertStmt->bind_param(
            'iissssssssssis',
            $customerId,
            $serviceId,
            $scheduledAt,
            $customerName,
            $customerPhone,
            $customerEmail,
            $serviceName,
            $servicePrice,
            $petName,
            $petType,
            $timeSlot,
            $note,
            $userId,
            $noteJson
        );
        $ok = $insertStmt->execute();
        $bookingId = (int) $insertStmt->insert_id;
        $insertStmt->close();

        if (!$ok || $bookingId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Luu lich hen that bai',
                'error' => $conn->error,
            ], 500);
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'message' => 'Dat lich thanh cong',
            'data' => [
                'id' => $bookingId,
                'malichhen' => 'LH' . str_pad((string) $bookingId, 6, '0', STR_PAD_LEFT),
                'status' => 'choduyet',
                'status_label' => 'Chờ duyệt',
                'thoigianhen' => $scheduledAt,
            ],
        ]);
    }

    if ($api === 'get_service_bookings') {
        if (!app_booking_ensure_table($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao bang lichhen',
            ], 500);
        }

        $statusFilter = trim((string) ($_GET['status'] ?? ''));
        $userIdFilter = (int) ($_GET['user_id'] ?? 0);
        $emailFilter = app_lower(trim((string) ($_GET['user_email'] ?? '')));
        $phoneFilter = preg_replace('/[^0-9]/', '', (string) ($_GET['user_phone'] ?? ''));
        $keyword = trim((string) ($_GET['keyword'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 200);
        if ($limit <= 0 || $limit > 500) {
            $limit = 200;
        }

        $sql = "
            SELECT
                l.id,
                l.khachhang_id,
                l.dichvu_id,
                l.nhanvien_id,
                l.thoigianhen,
                l.trangthailichhen,
                l.tenkhachhang,
                l.sodienthoai,
                l.email,
                l.tendichvu,
                l.giadichvu,
                l.tenthucung,
                l.loaithucung,
                l.khunggio,
                l.ghichu,
                l.ghichunhanvien,
                l.nguoidung_id,
                l.ngaytao,
                l.ngaycapnhat,
                l.ghichulichhen,
                k.tenkhachhang,
                k.sodienthoaikhachhang,
                k.emailkhachhang,
                d.tendichvu,
                d.giadichvu,
                n.tennguoidung AS tennhanvien
            FROM lichhen l
            LEFT JOIN khachhang k ON k.id = l.khachhang_id
            LEFT JOIN dichvu d ON d.id = l.dichvu_id
            LEFT JOIN nguoidung n ON n.id = l.nhanvien_id
            ORDER BY l.id DESC
            LIMIT {$limit}
        ";

        $result = $conn->query($sql);
        if (!$result) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tai danh sach lich hen',
                'error' => $conn->error,
            ], 500);
        }

        $statusLabelMap = [
            'choduyet' => 'Chờ duyệt',
            'hoanthanh' => 'Đã duyệt',
            'huy' => 'Đã hủy',
        ];

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $meta = app_booking_decode_note((string) ($row['ghichulichhen'] ?? ''));
            $status = (string) ($row['trangthailichhen'] ?? 'choduyet');
            if (!isset($statusLabelMap[$status])) {
                $status = 'choduyet';
            }

            $customerPhone = (string) (($row['sodienthoai'] ?? '') !== ''
                ? $row['sodienthoai']
                : (($meta['customer_phone'] ?? '') !== '' ? $meta['customer_phone'] : ($row['sodienthoaikhachhang'] ?? '')));
            $customerEmail = (string) (($row['email'] ?? '') !== ''
                ? $row['email']
                : (($meta['customer_email'] ?? '') !== '' ? $meta['customer_email'] : ($row['emailkhachhang'] ?? '')));
            $customerName = (string) (($row['tenkhachhang'] ?? '') !== ''
                ? $row['tenkhachhang']
                : (($meta['customer_name'] ?? '') !== '' ? $meta['customer_name'] : ($row['tenkhachhang'] ?? 'Khach hang')));
            $serviceName = (string) (($row['tendichvu'] ?? '') !== ''
                ? $row['tendichvu']
                : (($meta['service_name'] ?? '') !== '' ? $meta['service_name'] : ($row['tendichvu'] ?? 'Dich vu')));
            $servicePrice = (string) (($row['giadichvu'] ?? '') !== ''
                ? $row['giadichvu']
                : (($meta['service_price'] ?? '') !== '' ? $meta['service_price'] : ((float) ($row['giadichvu'] ?? 0))));
            $petName = (string) (($row['tenthucung'] ?? '') !== '' ? $row['tenthucung'] : ($meta['pet_name'] ?? ''));
            $petType = (string) (($row['loaithucung'] ?? '') !== '' ? $row['loaithucung'] : ($meta['pet_type'] ?? ''));
            $timeSlot = (string) (($row['khunggio'] ?? '') !== '' ? $row['khunggio'] : ($meta['time_slot'] ?? ''));
            $bookingNote = (string) (($row['ghichu'] ?? '') !== '' ? $row['ghichu'] : ($meta['note'] ?? ''));
            $staffNote = (string) (($row['ghichunhanvien'] ?? '') !== '' ? $row['ghichunhanvien'] : ($meta['staff_note'] ?? ''));

            $normalizedPhone = preg_replace('/[^0-9]/', '', $customerPhone);
            $normalizedEmail = app_lower($customerEmail);
            $metaUserId = (int) (($row['nguoidung_id'] ?? 0) > 0 ? ($row['nguoidung_id'] ?? 0) : ($meta['user_id'] ?? 0));

            if ($userIdFilter > 0 && $metaUserId !== $userIdFilter) {
                continue;
            }

            if ($emailFilter !== '' && $normalizedEmail !== '' && $normalizedEmail !== $emailFilter) {
                continue;
            }

            if ($phoneFilter !== '' && $normalizedPhone !== '' && $normalizedPhone !== $phoneFilter) {
                continue;
            }

            if ($statusFilter !== '' && $status !== $statusFilter) {
                continue;
            }

            if ($keyword !== '') {
                $haystack = app_lower(implode(' ', [
                    $customerName,
                    $customerPhone,
                    $customerEmail,
                    $serviceName,
                    (string) ($meta['pet_name'] ?? ''),
                    (string) ($row['id'] ?? ''),
                ]));
                if (strpos($haystack, app_lower($keyword)) === false) {
                    continue;
                }
            }

            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'malichhen' => 'LH' . str_pad((string) ((int) ($row['id'] ?? 0)), 6, '0', STR_PAD_LEFT),
                'khachhang_id' => (int) ($row['khachhang_id'] ?? 0),
                'dichvu_id' => (int) ($row['dichvu_id'] ?? 0),
                'nhanvien_id' => (int) ($row['nhanvien_id'] ?? 0),
                'nhanvien_ten' => (string) ($row['tennhanvien'] ?? ''),
                'tenkhachhang' => $customerName,
                'sodienthoai' => $customerPhone,
                'email' => $customerEmail,
                'tendichvu' => $serviceName,
                'giadichvu' => $servicePrice,
                'ten_thu_cung' => $petName,
                'loai_thu_cung' => $petType,
                'khunggio' => $timeSlot,
                'ghichu' => $bookingNote,
                'ghichu_nhanvien' => $staffNote,
                'thoigianhen' => (string) ($row['thoigianhen'] ?? ''),
                'trangthai' => $status,
                'trangthai_label' => $statusLabelMap[$status],
                'created_at' => (string) (($row['ngaytao'] ?? '') !== '' ? $row['ngaytao'] : ($meta['created_at'] ?? '')),
                'updated_at' => (string) (($row['ngaycapnhat'] ?? '') !== '' ? $row['ngaycapnhat'] : ($meta['updated_at'] ?? '')),
            ];
        }

        $result->free();
        $conn->close();

        app_json_response([
            'ok' => true,
            'count' => count($rows),
            'data' => array_values($rows),
        ]);
    }

    if ($api === 'update_service_booking_status') {
        if (!app_booking_ensure_table($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao bang lichhen',
            ], 500);
        }

        $input = app_input_payload();
        $bookingId = (int) ($input['id'] ?? 0);
        $status = trim((string) ($input['status'] ?? ''));
        $staffId = (int) ($input['staff_id'] ?? 0);
        $staffNote = trim((string) ($input['staff_note'] ?? ''));

        if ($bookingId <= 0 || !in_array($status, ['choduyet', 'hoanthanh', 'huy'], true)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Du lieu cap nhat lich hen khong hop le',
            ], 400);
        }

        $currentStmt = $conn->prepare('SELECT ghichulichhen FROM lichhen WHERE id = ? LIMIT 1');
        if (!$currentStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tai lich hen',
                'error' => $conn->error,
            ], 500);
        }

        $currentStmt->bind_param('i', $bookingId);
        $currentStmt->execute();
        $currentResult = $currentStmt->get_result();
        $currentRow = $currentResult ? $currentResult->fetch_assoc() : null;
        if ($currentResult) {
            $currentResult->free();
        }
        $currentStmt->close();

        if (!is_array($currentRow)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay lich hen',
            ], 404);
        }

        $meta = app_booking_decode_note((string) ($currentRow['ghichulichhen'] ?? ''));
        $meta['staff_note'] = $staffNote;
        $meta['updated_at'] = date(DATE_ATOM);
        $meta['updated_by_staff_id'] = $staffId;
        if (!isset($meta['status_updates']) || !is_array($meta['status_updates'])) {
            $meta['status_updates'] = [];
        }
        $meta['status_updates'][] = [
            'status' => $status,
            'staff_note' => $staffNote,
            'updated_at' => date(DATE_ATOM),
            'staff_id' => $staffId,
        ];

        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metaJson)) {
            $metaJson = '{}';
        }

        $updateSql = "
            UPDATE lichhen
            SET
                trangthailichhen = ?,
                nhanvien_id = CASE WHEN ? > 0 THEN ? ELSE nhanvien_id END,
                ghichunhanvien = ?,
                ngaycapnhat = NOW(),
                ghichulichhen = ?
            WHERE id = ?
        ";
        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the cap nhat lich hen',
                'error' => $conn->error,
            ], 500);
        }

        $updateStmt->bind_param('siissi', $status, $staffId, $staffId, $staffNote, $metaJson, $bookingId);
        $ok = $updateStmt->execute();
        $updateStmt->close();

        if (!$ok) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Cap nhat trang thai lich hen that bai',
                'error' => $conn->error,
            ], 500);
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'message' => 'Cap nhat lich hen thanh cong',
            'data' => [
                'id' => $bookingId,
                'status' => $status,
            ],
        ]);
    }

    if ($api === 'get_services') {
        app_seed_dichvu_image_column($conn);
        app_ensure_dichvu_info_column($conn);

        $sql = "
            SELECT
                d.id,
                d.tendichvu,
                d.giadichvu,
                d.thoigiandichvu,
                d.trangthaidichvu,
                d.ngaytaodichvu,
                COALESCE(d.thongtin, '') AS thongtin,
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
                'thongtin' => (string) ($row['thongtin'] ?? ''),
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
        app_ensure_product_subcategory_column($conn);
        app_ensure_product_discount_columns($conn);
        app_ensure_product_info_column($conn);

        $sql = "
            SELECT
                s.id,
                s.tensanpham,
                s.danhmuc_id,
                COALESCE(NULLIF(TRIM(s.danhmuccon), ''), '') AS danhmuccon,
                COALESCE(d.tendanhmuc, 'Chua phan loai') AS tendanhmuc,
                s.masanpham,
                s.giasanpham,
                COALESCE(s.phantramgiamgia, 0) AS phantramgiamgia,
                s.thoigianbatdaugiam,
                s.thoigianketthucgiam,
                s.soluongsanpham,
                s.trangthaisanpham,
                s.hinhanhsanpham,
                COALESCE(s.thongtin, '') AS thongtin
            FROM sanpham s
            LEFT JOIN danhmuc d ON d.id = s.danhmuc_id
            ORDER BY s.id ASC
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
                'danhmuccon' => (string) ($row['danhmuccon'] ?? ''),
                'tendanhmuc' => (string) $row['tendanhmuc'],
                'masanpham' => (string) $row['masanpham'],
                'giasanpham' => (float) $row['giasanpham'],
                'phantramgiamgia' => (float) ($row['phantramgiamgia'] ?? 0),
                'thoigianbatdaugiam' => (string) ($row['thoigianbatdaugiam'] ?? ''),
                'thoigianketthucgiam' => (string) ($row['thoigianketthucgiam'] ?? ''),
                'soluongsanpham' => $qty,
                'trangthaisanpham' => (string) $row['trangthaisanpham'],
                'hinhanhsanpham' => app_to_public_image_url((string) ($row['hinhanhsanpham'] ?? '')),
                'thongtin' => (string) ($row['thongtin'] ?? ''),
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

    if ($api === 'get_product_reviews') {
        $productId = (int) ($_GET['product_id'] ?? 0);
        $limit = (int) ($_GET['limit'] ?? 20);
        if ($limit <= 0 || $limit > 100) {
            $limit = 20;
        }

        if ($productId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'product_id khong hop le',
            ], 400);
        }

        if (!app_table_exists($conn, 'danhgiasanpham')) {
            $conn->close();
            app_json_response([
                'ok' => true,
                'count' => 0,
                'summary' => [
                    'avg_rating' => 0,
                    'review_count' => 0,
                ],
                'data' => [],
            ]);
        }

        $sql = "
            SELECT
                dg.id,
                dg.sosao,
                dg.noidung,
                dg.trangthai,
                dg.ngaytao,
                COALESCE(NULLIF(TRIM(k.tenkhachhang), ''), 'Khach hang') AS tenkhachhang
            FROM danhgiasanpham dg
            LEFT JOIN khachhang k ON k.id = dg.khachhang_id
            WHERE dg.sanpham_id = ?
              AND dg.sosao BETWEEN 1 AND 5
            ORDER BY dg.ngaytao DESC, dg.id DESC
            LIMIT ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tai danh gia san pham',
                'error' => $conn->error,
            ], 500);
        }

        $stmt->bind_param('ii', $productId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        $sumRating = 0;
        while ($result && ($row = $result->fetch_assoc())) {
            $rating = (int) ($row['sosao'] ?? 0);
            $sumRating += $rating;
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'rating' => $rating,
                'comment' => (string) ($row['noidung'] ?? ''),
                'status' => (string) ($row['trangthai'] ?? ''),
                'created_at' => (string) ($row['ngaytao'] ?? ''),
                'customer_name' => (string) ($row['tenkhachhang'] ?? 'Khach hang'),
            ];
        }

        if ($result) {
            $result->free();
        }
        $stmt->close();
        $conn->close();

        $count = count($rows);
        app_json_response([
            'ok' => true,
            'count' => $count,
            'summary' => [
                'avg_rating' => $count > 0 ? round($sumRating / $count, 1) : 0,
                'review_count' => $count,
            ],
            'data' => $rows,
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
                (
                    SELECT COALESCE(NULLIF(TRIM(u.anhdaidiennguoidung), ''), '')
                    FROM nguoidung u
                    WHERE LOWER(TRIM(COALESCE(u.emailnguoidung, ''))) = LOWER(TRIM(COALESCE(k.emailkhachhang, '')))
                    ORDER BY u.id DESC
                    LIMIT 1
                ) AS anhdaidiennguoidung,
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
        $tierCounts = [
            'dong' => 0,
            'bac' => 0,
            'vang' => 0,
            'bach_kim' => 0,
            'kim_cuong' => 0,
        ];

        while ($row = $result->fetch_assoc()) {
            $spending = (float) $row['tongchitieukhachhang'];
            $totalSpending += $spending;
            $tierKey = app_staff_customer_tier_key($spending);
            if (isset($tierCounts[$tierKey])) {
                $tierCounts[$tierKey]++;
            }
            if (in_array($tierKey, ['vang', 'bach_kim', 'kim_cuong'], true)) {
                $vipCount++;
            }

            $data[] = [
                'id' => (int) $row['id'],
                'tenkhachhang' => (string) $row['tenkhachhang'],
                'sodienthoaikhachhang' => (string) $row['sodienthoaikhachhang'],
                'emailkhachhang' => (string) $row['emailkhachhang'],
                'tongchitieukhachhang' => $spending,
                'loaikhachhang' => $tierKey,
                'ngaytaokhachhang' => (string) $row['ngaytaokhachhang'],
                'anhdaidiennguoidung' => (string) ($row['anhdaidiennguoidung'] ?? ''),
                'anhdaidiennguoidung_url' => app_to_public_image_url((string) ($row['anhdaidiennguoidung'] ?? '')),
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
                'dong' => $tierCounts['dong'],
                'bac' => $tierCounts['bac'],
                'vang' => $tierCounts['vang'],
                'bach_kim' => $tierCounts['bach_kim'],
                'kim_cuong' => $tierCounts['kim_cuong'],
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
                t.thongtin,
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
                'thongtin' => (string) ($row['thongtin'] ?? ''),
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

    return false;
}
