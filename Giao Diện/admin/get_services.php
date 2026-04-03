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

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $rawImage = (string) ($row['hinhanhdanhmuc'] ?? '');
            $data[] = [
                'id' => (int) ($row['id'] ?? 0),
                'tendanhmuc' => (string) ($row['tendanhmuc'] ?? ''),
                'hinhanhdanhmuc' => $rawImage,
                'hinhanh' => app_to_public_image_url($rawImage),
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
            app_ensure_product_subcategory_column($conn);

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

                if ($name === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Ten san pham khong hop le'], 400);
                }

                $danhmucId = $categoryId > 0 ? $categoryId : app_resolve_category_id($conn, $categoryName);
                if ($danhmucId <= 0) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Vui long chon danh muc cho san pham'], 400);
                }

                if ($code === '') {
                    $code = 'SP' . str_pad((string) time(), 10, '0', STR_PAD_LEFT);
                }

                $stmt = $conn->prepare('INSERT INTO sanpham (tensanpham, danhmuc_id, danhmuccon, masanpham, giasanpham, soluongsanpham, trangthaisanpham, hinhanhsanpham) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the them san pham', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sissdiss', $name, $danhmucId, $subcategoryName, $code, $price, $qty, $status, $image);
                $ok = $stmt->execute();
                $newId = (int) $conn->insert_id;
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Them san pham that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query("SELECT s.id, s.tensanpham, s.danhmuc_id, COALESCE(NULLIF(TRIM(s.danhmuccon), ''), '') AS danhmuccon, COALESCE(d.tendanhmuc, 'Chua phan loai') AS tendanhmuc, s.masanpham, s.giasanpham, s.soluongsanpham, s.trangthaisanpham, s.hinhanhsanpham FROM sanpham s LEFT JOIN danhmuc d ON d.id = s.danhmuc_id WHERE s.id = {$newId} LIMIT 1");
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

                if ($id <= 0 || $name === '') {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Du lieu cap nhat san pham khong hop le'], 400);
                }

                $existingResult = $conn->query('SELECT danhmuc_id, masanpham, hinhanhsanpham FROM sanpham WHERE id = ' . $id . ' LIMIT 1');
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

                $stmt = $conn->prepare('UPDATE sanpham SET tensanpham = ?, danhmuc_id = ?, danhmuccon = ?, masanpham = ?, giasanpham = ?, soluongsanpham = ?, trangthaisanpham = ?, hinhanhsanpham = ? WHERE id = ?');
                if (!$stmt) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Khong the cap nhat san pham', 'error' => $conn->error], 500);
                }

                $stmt->bind_param('sissdissi', $name, $danhmucId, $subcategoryName, $code, $price, $qty, $status, $image, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    $conn->close();
                    app_json_response(['ok' => false, 'message' => 'Cap nhat san pham that bai', 'error' => $conn->error], 500);
                }

                $rowResult = $conn->query("SELECT s.id, s.tensanpham, s.danhmuc_id, COALESCE(NULLIF(TRIM(s.danhmuccon), ''), '') AS danhmuccon, COALESCE(d.tendanhmuc, 'Chua phan loai') AS tendanhmuc, s.masanpham, s.giasanpham, s.soluongsanpham, s.trangthaisanpham, s.hinhanhsanpham FROM sanpham s LEFT JOIN danhmuc d ON d.id = s.danhmuc_id WHERE s.id = {$id} LIMIT 1");
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
            SELECT id, donhang_id, madonhang, trangthai, chitiet_json
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
                    throw new RuntimeException('Khong tim thay chi tiet san pham de tru kho cho don online nay');
                }

                [$stockOk, $stockMessage] = app_apply_stock_deduction($conn, $stockItems);
                if (!$stockOk) {
                    throw new RuntimeException($stockMessage !== '' ? $stockMessage : 'Khong the tru ton kho cho don online');
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
            $stmt->close();
            $conn->rollback();
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Cap nhat trang thai don online that bai',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    return false;
}
