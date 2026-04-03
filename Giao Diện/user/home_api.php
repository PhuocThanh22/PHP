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

        if (app_contains_reserved_account_keyword($name) || app_contains_reserved_account_keyword($email)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Tai khoan chua tu khoa admin/staff khong duoc dang ky tai khu vuc user',
            ], 403);
        }

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
        if (function_exists('app_ensure_category_image_column')) {
            app_ensure_category_image_column($conn);
        }

        $sql = "
            SELECT
                d.id,
                d.tendanhmuc,
                COUNT(s.id) AS soluongsanpham,
                COALESCE(
                    MAX(NULLIF(TRIM(d.hinhanhdanhmuc), '')),
                    MAX(NULLIF(TRIM(s.hinhanhsanpham), '')),
                    ''
                ) AS hinhanh
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
                    COALESCE(
                        MAX(NULLIF(TRIM(d.hinhanhdanhmuc), '')),
                        MAX(NULLIF(TRIM(s.hinhanhsanpham), '')),
                        ''
                    ) AS hinhanh
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

    if ($api === 'social_oauth_start') {
        if (!app_table_exists($conn, 'nguoidung')) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay bang nguoidung',
            ], 500);
        }

        $provider = app_lower(trim((string) ($_GET['provider'] ?? '')));
        if (!in_array($provider, ['google', 'facebook'], true)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Provider khong hop le',
            ], 400);
        }

        app_social_start_session();
        $returnUrl = app_social_sanitize_return_url((string) ($_GET['return'] ?? ''));
        $providerConfig = app_social_get_provider_config($provider);
        if ($providerConfig['client_id'] === '' || $providerConfig['client_secret'] === '') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Chua cau hinh OAuth cho provider nay. Vui long cap nhat file oauth-config.php',
            ], 500);
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state_' . $provider] = $state;
        $_SESSION['oauth_return_' . $provider] = $returnUrl;

        $redirectUri = app_social_callback_url($provider);
        $authUrl = app_social_build_auth_url($provider, $providerConfig['client_id'], $redirectUri, $state);

        $conn->close();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: ' . $authUrl);
        exit;
    }

    if ($api === 'social_oauth_callback') {
        $provider = app_lower(trim((string) ($_GET['provider'] ?? '')));
        if (!in_array($provider, ['google', 'facebook'], true)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Provider khong hop le',
            ], 400);
        }

        app_social_start_session();

        $state = trim((string) ($_GET['state'] ?? ''));
        $code = trim((string) ($_GET['code'] ?? ''));
        $error = trim((string) ($_GET['error'] ?? ''));
        $savedState = (string) ($_SESSION['oauth_state_' . $provider] ?? '');
        $returnUrl = (string) ($_SESSION['oauth_return_' . $provider] ?? app_social_default_return_url());

        unset($_SESSION['oauth_state_' . $provider], $_SESSION['oauth_return_' . $provider]);

        if ($error !== '' || $code === '' || $state === '' || !hash_equals($savedState, $state)) {
            $conn->close();
            app_social_render_bridge(null, $returnUrl, 'Dang nhap bang ' . $provider . ' khong thanh cong.');
        }

        $providerConfig = app_social_get_provider_config($provider);
        if ($providerConfig['client_id'] === '' || $providerConfig['client_secret'] === '') {
            $conn->close();
            app_social_render_bridge(null, $returnUrl, 'Chua cau hinh OAuth cho provider nay. Vui long cap nhat file oauth-config.php.');
        }

        $redirectUri = app_social_callback_url($provider);
        $tokenData = app_social_exchange_token($provider, $providerConfig, $code, $redirectUri);
        if (!$tokenData['ok']) {
            $conn->close();
            app_social_render_bridge(null, $returnUrl, $tokenData['message']);
        }

        $profileData = app_social_fetch_profile($provider, (string) $tokenData['access_token']);
        if (!$profileData['ok']) {
            $conn->close();
            app_social_render_bridge(null, $returnUrl, $profileData['message']);
        }

        $userPayload = app_social_upsert_user($conn, $provider, $profileData['profile']);
        $conn->close();

        if (!$userPayload['ok']) {
            app_social_render_bridge(null, $returnUrl, $userPayload['message']);
        }

        app_social_render_bridge($userPayload['user'], $returnUrl, '');
    }

    if ($api === 'login_user' || $api === 'login_admin_staff') {
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
        $portal = app_lower(trim((string) ($input['portal'] ?? $_GET['portal'] ?? '')));
        if ($api === 'login_admin_staff') {
            $portal = 'admin_staff';
        }
        $isUserPortal = $portal === 'user';
        $isAdminStaffPortal = in_array($portal, ['admin', 'staff', 'admin_staff', 'admin-staff'], true);

        if ($identifier === '' || $password === '') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Vui long nhap tai khoan va mat khau',
            ], 400);
        }

        if ($isUserPortal && app_contains_reserved_account_keyword($identifier)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Tai khoan admin/staff khong the dang nhap vao trang user',
            ], 403);
        }

        $identifierLower = app_lower($identifier);
        $sql = "
            SELECT id, tennguoidung, emailnguoidung, matkhaunguoidung, vaitronguoidung, ngaytaonguoidung
            FROM nguoidung
            WHERE (
                LOWER(COALESCE(emailnguoidung, '')) = ?
                OR LOWER(COALESCE(tennguoidung, '')) = ?
            )
        ";

        if ($isAdminStaffPortal) {
            $sql .= "
              AND LOWER(COALESCE(vaitronguoidung, '')) IN (
                    'admin',
                    'administrator',
                    'quantri',
                    'quan tri',
                    'quan_tri',
                    'quản trị',
                    'quản trị viên',
                    'staff',
                    'nhanvien',
                    'nhan vien',
                    'nhan_vien',
                    'nhân viên'
              )
            ";
        }

        $sql .= "\n            LIMIT 1\n        ";

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
            if ($isAdminStaffPortal) {
                $roleProbeSql = "
                    SELECT vaitronguoidung
                    FROM nguoidung
                    WHERE LOWER(COALESCE(emailnguoidung, '')) = ?
                       OR LOWER(COALESCE(tennguoidung, '')) = ?
                    LIMIT 1
                ";
                $roleProbeStmt = $conn->prepare($roleProbeSql);
                if ($roleProbeStmt) {
                    $roleProbeStmt->bind_param('ss', $identifierLower, $identifierLower);
                    $roleProbeStmt->execute();
                    $roleProbeResult = $roleProbeStmt->get_result();
                    $roleProbeRow = $roleProbeResult ? $roleProbeResult->fetch_assoc() : null;
                    if ($roleProbeResult) {
                        $roleProbeResult->free();
                    }
                    $roleProbeStmt->close();

                    if (is_array($roleProbeRow) && app_normalize_role((string) ($roleProbeRow['vaitronguoidung'] ?? 'user')) === 'user') {
                        $conn->close();
                        app_json_response([
                            'ok' => false,
                            'message' => 'Khach hang khong the dang nhap bang trang nay',
                        ], 403);
                    }
                }
            }

            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Sai tai khoan hoac mat khau',
            ], 401);
        }

        $rowUsername = (string) ($row['tennguoidung'] ?? '');
        $rowEmail = (string) ($row['emailnguoidung'] ?? '');
        $rowRole = app_normalize_role((string) ($row['vaitronguoidung'] ?? 'user'));

        if ($isAdminStaffPortal && $rowRole === 'user') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Tai khoan user khong duoc phep dang nhap tai trang quan tri/nhan vien',
            ], 403);
        }

        if ($isUserPortal && (
            $rowRole !== 'user'
            || app_contains_reserved_account_keyword($rowUsername)
            || app_contains_reserved_account_keyword($rowEmail)
        )) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Tai khoan admin/staff khong the dang nhap vao trang user',
            ], 403);
        }

        $storedPassword = (string) ($row['matkhaunguoidung'] ?? '');
        if (!app_password_verify_compat($password, $storedPassword)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Sai tai khoan hoac mat khau',
            ], 401);
        }

        $role = $rowRole;
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

function app_social_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function app_social_default_return_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . app_base_path() . '/Giao%20Di%E1%BB%87n/user/home.html';
}

function app_social_sanitize_return_url(string $candidate): string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return app_social_default_return_url();
    }

    $parts = parse_url($candidate);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return app_social_default_return_url();
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !hash_equals(app_lower($host), app_lower((string) $parts['host']))) {
        return app_social_default_return_url();
    }

    return $candidate;
}

function app_social_get_provider_config(string $provider): array
{
    if ($provider === 'google') {
        return [
            'client_id' => app_env_value('GOOGLE_OAUTH_CLIENT_ID', ''),
            'client_secret' => app_env_value('GOOGLE_OAUTH_CLIENT_SECRET', ''),
        ];
    }

    return [
        'client_id' => app_env_value('FACEBOOK_OAUTH_CLIENT_ID', ''),
        'client_secret' => app_env_value('FACEBOOK_OAUTH_CLIENT_SECRET', ''),
    ];
}

function app_social_callback_url(string $provider): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . app_base_path() . '/index.php?api=social_oauth_callback&provider=' . rawurlencode($provider);
}

function app_social_build_auth_url(string $provider, string $clientId, string $redirectUri, string $state): string
{
    if ($provider === 'google') {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'prompt' => 'select_account',
            'state' => $state,
        ]);
    }

    return 'https://www.facebook.com/v20.0/dialog/oauth?' . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'email,public_profile',
        'state' => $state,
    ]);
}

function app_social_http_request(string $url, array $options = []): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers'] ?? []);
        if (($options['method'] ?? 'GET') === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) ($options['body'] ?? ''));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw)) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err !== '' ? $err : 'Request failed'];
        }

        return ['ok' => true, 'status' => $status, 'body' => $raw, 'error' => ''];
    }

    $method = $options['method'] ?? 'GET';
    $headers = $options['headers'] ?? [];
    $headerText = '';
    foreach ($headers as $headerLine) {
        $headerText .= $headerLine . "\r\n";
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headerText,
            'content' => (string) ($options['body'] ?? ''),
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw)) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Request failed'];
    }

    $status = 200;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }

    return ['ok' => true, 'status' => $status, 'body' => $raw, 'error' => ''];
}

function app_social_exchange_token(string $provider, array $config, string $code, string $redirectUri): array
{
    if ($provider === 'google') {
        $response = app_social_http_request('https://oauth2.googleapis.com/token', [
            'method' => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'code' => $code,
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]),
        ]);
    } else {
        $url = 'https://graph.facebook.com/v20.0/oauth/access_token?' . http_build_query([
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
        $response = app_social_http_request($url);
    }

    if (!$response['ok'] || $response['status'] < 200 || $response['status'] >= 300) {
        return ['ok' => false, 'message' => 'Khong the lay access token'];
    }

    $json = json_decode((string) $response['body'], true);
    $token = is_array($json) ? (string) ($json['access_token'] ?? '') : '';
    if ($token === '') {
        return ['ok' => false, 'message' => 'Phan hoi token khong hop le'];
    }

    return ['ok' => true, 'access_token' => $token];
}

function app_social_fetch_profile(string $provider, string $accessToken): array
{
    if ($provider === 'google') {
        $response = app_social_http_request('https://www.googleapis.com/oauth2/v3/userinfo', [
            'headers' => ['Authorization: Bearer ' . $accessToken],
        ]);
    } else {
        $response = app_social_http_request('https://graph.facebook.com/me?' . http_build_query([
            'fields' => 'id,name,email',
            'access_token' => $accessToken,
        ]));
    }

    if (!$response['ok'] || $response['status'] < 200 || $response['status'] >= 300) {
        return ['ok' => false, 'message' => 'Khong the lay thong tin tai khoan'];
    }

    $json = json_decode((string) $response['body'], true);
    if (!is_array($json)) {
        return ['ok' => false, 'message' => 'Phan hoi thong tin tai khoan khong hop le'];
    }

    return ['ok' => true, 'profile' => $json];
}

function app_social_upsert_user(mysqli $conn, string $provider, array $profile): array
{
    if (!app_table_exists($conn, 'nguoidung')) {
        return ['ok' => false, 'message' => 'Khong tim thay bang nguoidung'];
    }

    $providerId = trim((string) ($profile['sub'] ?? $profile['id'] ?? ''));
    $email = trim((string) ($profile['email'] ?? ''));
    $name = trim((string) ($profile['name'] ?? ''));

    if ($name === '') {
        $name = ucfirst($provider) . ' User';
    }

    if ($email === '') {
        $suffix = $providerId !== '' ? $providerId : bin2hex(random_bytes(4));
        $email = $provider . '_' . $suffix . '@social.local';
    }

    if (app_contains_reserved_account_keyword($name) || app_contains_reserved_account_keyword($email)) {
        return ['ok' => false, 'message' => 'Tai khoan social admin/staff khong duoc vao trang user'];
    }

    $emailLower = app_lower($email);
    $findSql = "SELECT id, tennguoidung, emailnguoidung, vaitronguoidung, ngaytaonguoidung FROM nguoidung WHERE LOWER(COALESCE(emailnguoidung, '')) = ? LIMIT 1";
    $findStmt = $conn->prepare($findSql);
    if (!$findStmt) {
        return ['ok' => false, 'message' => 'Khong the tim tai khoan'];
    }
    $findStmt->bind_param('s', $emailLower);
    $findStmt->execute();
    $findResult = $findStmt->get_result();
    $existing = $findResult ? $findResult->fetch_assoc() : null;
    if ($findResult) {
        $findResult->free();
    }
    $findStmt->close();

    if (is_array($existing)) {
        $user = [
            'id' => (int) ($existing['id'] ?? 0),
            'tennguoidung' => (string) ($existing['tennguoidung'] ?? $name),
            'emailnguoidung' => (string) ($existing['emailnguoidung'] ?? $email),
            'vaitronguoidung' => app_normalize_role((string) ($existing['vaitronguoidung'] ?? 'user')),
            'ngaytaonguoidung' => (string) ($existing['ngaytaonguoidung'] ?? ''),
        ];
        return ['ok' => true, 'user' => $user];
    }

    $username = $name;
    $passwordHash = password_hash(bin2hex(random_bytes(18)), PASSWORD_BCRYPT);
    $insertSql = "INSERT INTO nguoidung (tennguoidung, emailnguoidung, matkhaunguoidung, vaitronguoidung) VALUES (?, ?, ?, 'user')";
    $insertStmt = $conn->prepare($insertSql);
    if (!$insertStmt) {
        return ['ok' => false, 'message' => 'Khong the tao tai khoan moi'];
    }
    $insertStmt->bind_param('sss', $username, $email, $passwordHash);
    $ok = $insertStmt->execute();
    $newId = (int) $insertStmt->insert_id;
    $insertStmt->close();

    if (!$ok || $newId <= 0) {
        return ['ok' => false, 'message' => 'Tao tai khoan bang social that bai'];
    }

    $user = [
        'id' => $newId,
        'tennguoidung' => $username,
        'emailnguoidung' => $email,
        'vaitronguoidung' => 'user',
        'ngaytaonguoidung' => '',
    ];

    return ['ok' => true, 'user' => $user];
}

function app_social_render_bridge(?array $user, string $returnUrl, string $errorMessage): void
{
    $safeReturnUrl = app_social_sanitize_return_url($returnUrl);
    $userJson = json_encode($user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $returnJson = json_encode($safeReturnUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $errorJson = json_encode($errorMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Đang hoàn tất đăng nhập...</title></head><body>';
    echo '<script>(function(){var user=' . $userJson . ';var returnUrl=' . $returnJson . ';var error=' . $errorJson . ';if(user&&user.id){var sessionPayload={id:Number(user.id||0),role:"user",fullName:user.tennguoidung||"Khach hang",email:user.emailnguoidung||"",identifier:user.emailnguoidung||user.tennguoidung||"",createdAt:new Date().toISOString()};try{sessionStorage.setItem("authUser",JSON.stringify(sessionPayload));}catch(e){};try{localStorage.setItem("userSession",JSON.stringify(sessionPayload));}catch(e){};try{localStorage.setItem("isLoggedIn","true");}catch(e){};}if(error){try{localStorage.setItem("pendingAuthPromptV2",JSON.stringify({error:error,createdAt:new Date().toISOString()}));}catch(e){}}window.location.replace(returnUrl);})();</script>';
    echo '</body></html>';
    exit;
}

function app_contains_reserved_account_keyword(string $value): bool
{
    $v = app_lower(trim($value));
    if ($v === '') {
        return false;
    }

    return strpos($v, 'admin') !== false || strpos($v, 'staff') !== false;
}
