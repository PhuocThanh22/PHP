<?php

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

function app_db_connect(): mysqli
{
    $host = '127.0.0.1';
    $user = 'root';
    $pass = '';
    $dbName = 'qlshop';

    $conn = @new mysqli($host, $user, $pass, $dbName);
    if ($conn->connect_error) {
        app_json_response([
            'ok' => false,
            'message' => 'Khong ket noi duoc CSDL',
            'error' => $conn->connect_error,
        ], 500);
    }

    $conn->set_charset('utf8mb4');
    return $conn;
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

$api = $_GET['api'] ?? '';
if ($api !== '') {
    $conn = app_db_connect();

    if ($api === 'get_services') {
        $sql = 'SELECT id, tendichvu, giadichvu, thoigiandichvu, trangthaidichvu, ngaytaodichvu FROM dichvu ORDER BY id ASC LIMIT 100';
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

        if (!$hasLoaiThuCung && !$hasLoaiVatThuCung) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay cot loai thu cung trong bang thucung',
            ], 500);
        }

        $petTypeColumn = $hasLoaiThuCung ? 'loaithucung' : 'loaivatthucung';

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

$target = app_base_path() . '/Giao%20Di%E1%BB%87n/user/index.html';
header('Location: ' . $target);
exit;
