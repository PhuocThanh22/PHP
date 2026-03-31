<?php

if (!defined('APP_RUNNING_FROM_INDEX')) {
    $api = trim((string) ($_GET['api'] ?? ''));
    if ($api === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => false,
            'message' => 'Thieu tham so api',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $_GET['api'] = $api;
    require dirname(__DIR__, 2) . '/index.php';
    exit;
}

function app_handle_user_api(mysqli $conn, string $api): bool
{
    if ($api === 'register_user') {
        if (!app_table_exists($conn, 'nguoidung')) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay bang nguoidung',
            ], 500);
        }

        $input = app_input_payload();
        $name = trim((string) ($input['name'] ?? $input['tennguoidung'] ?? ''));
        $email = trim((string) ($input['email'] ?? $input['emailnguoidung'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? $input['sodienthoai'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Vui long nhap day du ten, email va mat khau',
            ], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Email khong hop le',
            ], 400);
        }

        if (strlen($password) < 6) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Mat khau phai co it nhat 6 ky tu',
            ], 400);
        }

        $emailLower = app_lower($email);
        $checkSql = "
            SELECT id
            FROM nguoidung
            WHERE LOWER(COALESCE(emailnguoidung, '')) = LOWER(?)
               OR LOWER(COALESCE(tennguoidung, '')) = LOWER(?)
            LIMIT 1
        ";
        $checkStmt = $conn->prepare($checkSql);
        if (!$checkStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the kiem tra tai khoan',
                'error' => $conn->error,
            ], 500);
        }
        $checkStmt->bind_param('ss', $emailLower, $emailLower);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $exists = $checkResult ? $checkResult->fetch_assoc() : null;
        if ($checkResult) {
            $checkResult->free();
        }
        $checkStmt->close();

        if (is_array($exists)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Email hoac ten dang nhap da ton tai',
            ], 409);
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $insertUserSql = "
            INSERT INTO nguoidung (tennguoidung, emailnguoidung, matkhaunguoidung, vaitronguoidung)
            VALUES (?, ?, ?, 'user')
        ";
        $insertUserStmt = $conn->prepare($insertUserSql);
        if (!$insertUserStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tao tai khoan',
                'error' => $conn->error,
            ], 500);
        }
        $insertUserStmt->bind_param('sss', $name, $email, $passwordHash);
        $insertOk = $insertUserStmt->execute();
        $newUserId = (int) $insertUserStmt->insert_id;
        $insertUserStmt->close();

        if (!$insertOk || $newUserId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Tao tai khoan that bai',
                'error' => $conn->error,
            ], 500);
        }

        if (app_table_exists($conn, 'khachhang')) {
            $customerId = 0;

            $findCustomerSql = "
                SELECT id
                FROM khachhang
                WHERE LOWER(COALESCE(emailkhachhang, '')) = LOWER(?)
                   OR (sodienthoaikhachhang IS NOT NULL AND sodienthoaikhachhang = ?)
                LIMIT 1
            ";
            $findCustomerStmt = $conn->prepare($findCustomerSql);
            if ($findCustomerStmt) {
                $findCustomerStmt->bind_param('ss', $email, $phone);
                $findCustomerStmt->execute();
                $findCustomerResult = $findCustomerStmt->get_result();
                $foundCustomer = $findCustomerResult ? $findCustomerResult->fetch_assoc() : null;
                if ($findCustomerResult) {
                    $findCustomerResult->free();
                }
                $findCustomerStmt->close();
                if (is_array($foundCustomer)) {
                    $customerId = (int) ($foundCustomer['id'] ?? 0);
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
                    $insertCustomerStmt->bind_param('sss', $name, $phone, $email);
                    $insertCustomerStmt->execute();
                    $insertCustomerStmt->close();
                }
            }
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'message' => 'Dang ky thanh cong',
            'data' => [
                'id' => $newUserId,
                'tennguoidung' => $name,
                'emailnguoidung' => $email,
                'vaitronguoidung' => 'user',
            ],
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

        if ($accountUserId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Vui long dang nhap tai khoan de dat hang',
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
        $userCheckStmt->bind_param('i', $accountUserId);
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
                'message' => 'Tai khoan khong hop le de dat hang online',
            ], 403);
        }

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

    return false;
}
