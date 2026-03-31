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

function app_handle_admin_api(mysqli $conn, string $api): bool
{
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

                $danhmucId = app_resolve_category_id($conn, $categoryName);
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

    return false;
}
