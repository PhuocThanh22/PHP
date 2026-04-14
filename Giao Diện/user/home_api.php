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

function app_chat_upload_allowed_types(string $kind): array
{
    $safeKind = strtolower(trim($kind));
    if ($safeKind === 'image') {
        return ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    }
    if ($safeKind === 'video') {
        return ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
    }
    if ($safeKind === 'audio') {
        return ['audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav'];
    }
    return [];
}

function app_chat_upload_extension(string $mimeType, string $fallback = 'bin'): string
{
    $mime = strtolower(trim($mimeType));
    if (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) return 'jpg';
    if (strpos($mime, 'png') !== false) return 'png';
    if (strpos($mime, 'webp') !== false) return 'webp';
    if (strpos($mime, 'gif') !== false) return 'gif';
    if (strpos($mime, 'mp4') !== false) return 'mp4';
    if (strpos($mime, 'webm') !== false) return 'webm';
    if (strpos($mime, 'ogg') !== false) return 'ogg';
    if (strpos($mime, 'mpeg') !== false || strpos($mime, 'mp3') !== false) return 'mp3';
    if (strpos($mime, 'wav') !== false) return 'wav';
    if (strpos($mime, 'quicktime') !== false) return 'mov';
    return $fallback;
}

function app_chat_handle_upload(string $kind): void
{
    $allowedTypes = app_chat_upload_allowed_types($kind);
    if (count($allowedTypes) === 0) {
        app_json_response([
            'ok' => false,
            'message' => 'Loai tep khong hop le',
        ], 400);
    }

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        app_json_response([
            'ok' => false,
            'message' => 'Phuong thuc khong duoc ho tro',
        ], 405);
    }

    if (!isset($_FILES['media']) || !is_array($_FILES['media'])) {
        app_json_response([
            'ok' => false,
            'message' => 'Khong tim thay tep media',
        ], 400);
    }

    $file = $_FILES['media'];
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        $message = 'Tai tep that bai';
        if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
            $message = 'Tep vuot qua gioi han kich thuoc tren may chu';
        } elseif ($errorCode === UPLOAD_ERR_NO_FILE) {
            $message = 'Vui long chon tep de tai len';
        }

        app_json_response([
            'ok' => false,
            'message' => $message,
            'upload_error' => $errorCode,
        ], 400);
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        app_json_response([
            'ok' => false,
            'message' => 'Tep tam khong hop le',
        ], 400);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        app_json_response([
            'ok' => false,
            'message' => 'Tep rong hoac khong hop le',
        ], 400);
    }

    $maxBytes = 100 * 1024 * 1024;
    if ($size > $maxBytes) {
        app_json_response([
            'ok' => false,
            'message' => 'Tep qua lon. Gioi han hien tai la 100MB',
        ], 413);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpPath) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    if ($mimeType === '' || !in_array($mimeType, $allowedTypes, true)) {
        app_json_response([
            'ok' => false,
            'message' => 'Dinh dang tep khong duoc ho tro',
            'mime' => $mimeType,
        ], 415);
    }

    $monthSegment = date('Ym');
    $relativeDir = 'anhdata/chat/' . $monthSegment;
    $absoluteDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        app_json_response([
            'ok' => false,
            'message' => 'Khong the tao thu muc luu tep',
        ], 500);
    }

    $kindPrefix = preg_replace('/[^a-z0-9_\-]/i', '', strtolower($kind)) ?: 'media';
    $extension = app_chat_upload_extension($mimeType, 'bin');
    $fileName = $kindPrefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $extension;
    $targetPath = $absoluteDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        app_json_response([
            'ok' => false,
            'message' => 'Khong the luu tep tren may chu',
        ], 500);
    }

    $basePath = app_base_path();
    $publicUrl = ($basePath !== '' ? $basePath : '') . '/' . str_replace(' ', '%20', str_replace('\\', '/', $relativeDir . '/' . $fileName));

    app_json_response([
        'ok' => true,
        'message' => 'Tai tep thanh cong',
        'data' => [
            'kind' => $kind,
            'url' => $publicUrl,
            'mime' => $mimeType,
            'size' => $size,
            'file_name' => $fileName,
        ],
    ]);
}

function app_user_voucher_games(): array
{
    return [
        'jigsaw_pet' => ['label' => 'Ghép hình thú cưng', 'levels' => ['easy' => 1, 'medium' => 2, 'hard' => 3]],
        'clip_guess_word' => ['label' => 'Xem clip đoán từ', 'levels' => ['easy' => 1, 'medium' => 2, 'hard' => 3]],
        'word_chain' => ['label' => 'Nối từ', 'levels' => ['easy' => 1, 'medium' => 2, 'hard' => 3]],
        'image_puzzle' => ['label' => 'Đuổi hình bắt chữ', 'levels' => ['easy' => 1, 'medium' => 2, 'hard' => 3]],
        'pet_quiz' => ['label' => 'Đố vui thú cưng', 'levels' => ['easy' => 1, 'medium' => 2, 'hard' => 3]],
    ];
}

function app_user_voucher_level_labels(): array
{
    return [
        'easy' => 'Dễ',
        'medium' => 'Trung bình',
        'hard' => 'Khó',
    ];
}

function app_user_voucher_required_score(array $gameConfig, string $level): int
{
    $levelKey = trim($level);
    if ($levelKey === '') {
        $levelKey = 'easy';
    }

    $levels = $gameConfig['levels'] ?? null;
    if (is_array($levels) && array_key_exists($levelKey, $levels)) {
        return max(1, (int) $levels[$levelKey]);
    }

    return max(1, (int) ($gameConfig['minScore'] ?? 1));
}

function app_user_voucher_can_use_now(array $voucherRow): bool
{
    $status = (string) ($voucherRow['trangthai'] ?? 'inactive');
    if ($status !== 'active') {
        return false;
    }

    $now = time();
    $startRaw = trim((string) ($voucherRow['ngaybatdau'] ?? ''));
    $endRaw = trim((string) ($voucherRow['ngayketthuc'] ?? ''));

    if ($startRaw !== '') {
        $startAt = strtotime($startRaw);
        if ($startAt !== false && $now < $startAt) {
            return false;
        }
    }

    if ($endRaw !== '') {
        $endAt = strtotime($endRaw);
        if ($endAt !== false && $now > $endAt) {
            return false;
        }
    }

    $quantity = (int) ($voucherRow['soluong'] ?? 0);
    return $quantity > 0;
}

function app_user_save_avatar_data_url(string $dataUrl): array
{
    $raw = trim($dataUrl);
    if ($raw === '') {
        return [false, '', 'Du lieu anh trong'];
    }

    if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/i', $raw, $matches)) {
        return [false, '', 'Du lieu anh khong hop le'];
    }

    $extMap = [
        'jpeg' => 'jpg',
        'jpg' => 'jpg',
        'png' => 'png',
        'webp' => 'webp',
    ];

    $mimeExt = strtolower((string) ($matches[1] ?? 'jpg'));
    $ext = $extMap[$mimeExt] ?? 'jpg';

    $decoded = base64_decode((string) ($matches[2] ?? ''), true);
    if (!is_string($decoded) || $decoded === '') {
        return [false, '', 'Khong the giai ma anh'];
    }

    $size = strlen($decoded);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        return [false, '', 'Kich thuoc anh khong hop le (toi da 5MB)'];
    }

    $relativeDir = 'anhdata/users/avatars';
    $absoluteDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        return [false, '', 'Khong the tao thu muc luu avatar'];
    }

    try {
        $rand = bin2hex(random_bytes(5));
    } catch (Throwable $e) {
        $rand = (string) mt_rand(100000, 999999);
    }

    $fileName = 'avatar_' . date('YmdHis') . '_' . $rand . '.' . $ext;
    $target = $absoluteDir . DIRECTORY_SEPARATOR . $fileName;

    $written = @file_put_contents($target, $decoded);
    if (!is_int($written) || $written <= 0) {
        return [false, '', 'Khong the luu avatar len may chu'];
    }

    return [true, $relativeDir . '/' . $fileName, ''];
}

function app_user_ensure_email_otp_table(mysqli $conn): bool
{
    $sql = "
        CREATE TABLE IF NOT EXISTS email_verification_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL,
            purpose VARCHAR(40) NOT NULL,
            otp_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            verified_at DATETIME NULL,
            consumed_at DATETIME NULL,
            attempt_count INT NOT NULL DEFAULT 0,
            max_attempts INT NOT NULL DEFAULT 8,
            request_ip VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email_otp_lookup (email, purpose, created_at),
            KEY idx_email_otp_expire (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    return (bool) $conn->query($sql);
}

function app_user_generate_otp_code(): string
{
    try {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

function app_user_store_email_otp(mysqli $conn, string $email, string $purpose, string $requestIp = ''): array
{
    if (!app_user_ensure_email_otp_table($conn)) {
        return ['ok' => false, 'message' => 'Khong the tao bang luu ma xac minh'];
    }

    $cleanEmail = trim($email);
    $cleanPurpose = trim($purpose);
    if ($cleanEmail === '' || $cleanPurpose === '') {
        return ['ok' => false, 'message' => 'Du lieu tao ma xac minh khong hop le'];
    }

    $consumeOldStmt = $conn->prepare("UPDATE email_verification_tokens SET consumed_at = NOW() WHERE LOWER(email) = LOWER(?) AND purpose = ? AND consumed_at IS NULL");
    if ($consumeOldStmt) {
        $consumeOldStmt->bind_param('ss', $cleanEmail, $cleanPurpose);
        $consumeOldStmt->execute();
        $consumeOldStmt->close();
    }

    $code = app_user_generate_otp_code();
    $hash = hash('sha256', $code);
    $expiresMinutes = 1;
    $ip = trim($requestIp);
    if ($ip === '') {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    $insertStmt = $conn->prepare('INSERT INTO email_verification_tokens (email, purpose, otp_hash, expires_at, request_ip) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)');
    if (!$insertStmt) {
        return ['ok' => false, 'message' => 'Khong the luu ma xac minh'];
    }

    $insertStmt->bind_param('sssis', $cleanEmail, $cleanPurpose, $hash, $expiresMinutes, $ip);
    $ok = $insertStmt->execute();
    $insertStmt->close();
    if (!$ok) {
        return ['ok' => false, 'message' => 'Khong the luu ma xac minh'];
    }

    return [
        'ok' => true,
        'code' => $code,
        'expires_in' => $expiresMinutes * 60,
    ];
}

function app_user_verify_email_otp(mysqli $conn, string $email, string $purpose, string $otpCode, bool $consumeOnSuccess = true): array
{
    if (!app_user_ensure_email_otp_table($conn)) {
        return ['ok' => false, 'message' => 'He thong ma xac minh chua san sang'];
    }

    $cleanEmail = trim($email);
    $cleanPurpose = trim($purpose);
    $cleanCode = trim($otpCode);
    if ($cleanEmail === '' || $cleanPurpose === '' || $cleanCode === '') {
        return ['ok' => false, 'message' => 'Vui long nhap day du email va ma xac minh'];
    }

    $selectStmt = $conn->prepare("SELECT id, otp_hash, expires_at, attempt_count, max_attempts FROM email_verification_tokens WHERE LOWER(email) = LOWER(?) AND purpose = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1");
    if (!$selectStmt) {
        return ['ok' => false, 'message' => 'Khong the kiem tra ma xac minh'];
    }

    $selectStmt->bind_param('ss', $cleanEmail, $cleanPurpose);
    $selectStmt->execute();
    $result = $selectStmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $selectStmt->close();

    if (!is_array($row)) {
        return ['ok' => false, 'message' => 'Ma xac minh khong ton tai hoac da het han'];
    }

    $tokenId = (int) ($row['id'] ?? 0);
    $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
    $attemptCount = (int) ($row['attempt_count'] ?? 0);
    $maxAttempts = max(1, (int) ($row['max_attempts'] ?? 8));

    if ($expiresAt !== false && $expiresAt < time()) {
        $expireStmt = $conn->prepare('UPDATE email_verification_tokens SET consumed_at = NOW() WHERE id = ? LIMIT 1');
        if ($expireStmt) {
            $expireStmt->bind_param('i', $tokenId);
            $expireStmt->execute();
            $expireStmt->close();
        }
        return ['ok' => false, 'message' => 'Ma xac minh da het han. Vui long gui ma moi'];
    }

    if ($attemptCount >= $maxAttempts) {
        return ['ok' => false, 'message' => 'Ma xac minh da bi khoa do nhap sai qua nhieu lan'];
    }

    $inputHash = hash('sha256', $cleanCode);
    $storedHash = (string) ($row['otp_hash'] ?? '');
    $matched = $storedHash !== '' && hash_equals($storedHash, $inputHash);

    if (!$matched) {
        $updateFailStmt = $conn->prepare('UPDATE email_verification_tokens SET attempt_count = attempt_count + 1 WHERE id = ? LIMIT 1');
        if ($updateFailStmt) {
            $updateFailStmt->bind_param('i', $tokenId);
            $updateFailStmt->execute();
            $updateFailStmt->close();
        }
        return ['ok' => false, 'message' => 'Ma xac minh khong dung'];
    }

    if ($consumeOnSuccess) {
        $updateSuccessStmt = $conn->prepare('UPDATE email_verification_tokens SET verified_at = NOW(), consumed_at = NOW() WHERE id = ? LIMIT 1');
    } else {
        $updateSuccessStmt = $conn->prepare('UPDATE email_verification_tokens SET verified_at = NOW() WHERE id = ? LIMIT 1');
    }

    if ($updateSuccessStmt) {
        $updateSuccessStmt->bind_param('i', $tokenId);
        $updateSuccessStmt->execute();
        $updateSuccessStmt->close();
    }

    return ['ok' => true, 'message' => 'Xac minh thanh cong'];
}

function app_user_build_otp_email_html(string $otpCode, string $purposeLabel, int $expiresMinutes): string
{
    $safeCode = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');
    $safePurpose = htmlspecialchars($purposeLabel, ENT_QUOTES, 'UTF-8');
    $safeMinutes = max(1, (int) $expiresMinutes);

    return '<div style="font-family:Arial,sans-serif;background:#f4f7fb;padding:20px;">'
        . '<div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #dbe4ee;border-radius:14px;overflow:hidden;">'
        . '<div style="padding:14px 18px;background:linear-gradient(135deg,#128a4b,#2dbf6a);color:#fff;font-weight:700;font-size:18px;">3 CHU CUN CON</div>'
        . '<div style="padding:16px 18px;color:#0f172a;line-height:1.6;font-size:14px;">'
        . '<p style="margin:0 0 10px;"><b>Yeu cau:</b> ' . $safePurpose . '</p>'
        . '<p style="margin:0 0 10px;">Ma xac minh cua ban la:</p>'
        . '<div style="display:inline-block;padding:10px 16px;border-radius:10px;background:#0f172a;color:#fff;font-size:28px;letter-spacing:4px;font-weight:700;">' . $safeCode . '</div>'
        . '<p style="margin:12px 0 0;color:#475569;">Ma co hieu luc trong ' . $safeMinutes . ' phut. Vui long khong chia se ma nay cho bat ky ai.</p>'
        . '</div></div></div>';
}

function app_user_ensure_favorites_table(mysqli $conn): bool
{
    $createSql = "
        CREATE TABLE IF NOT EXISTS yeuthich (
            id INT NOT NULL AUTO_INCREMENT,
            khachhang_id INT NULL,
            nguoidung_id INT NULL,
            sanpham_id INT NULL,
            dichvu_id INT NULL,
            thucung_id INT NULL,
            ngaytao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_yeuthich_user (nguoidung_id),
            KEY idx_yeuthich_customer (khachhang_id),
            KEY idx_yeuthich_product (sanpham_id),
            KEY idx_yeuthich_service (dichvu_id),
            KEY idx_yeuthich_pet (thucung_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!$conn->query($createSql)) {
        return false;
    }

    if (!app_column_exists($conn, 'yeuthich', 'khachhang_id')) {
        if (!$conn->query('ALTER TABLE yeuthich ADD COLUMN khachhang_id INT NULL FIRST')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'yeuthich', 'nguoidung_id')) {
        if (!$conn->query('ALTER TABLE yeuthich ADD COLUMN nguoidung_id INT NULL AFTER khachhang_id')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'yeuthich', 'dichvu_id')) {
        if (!$conn->query('ALTER TABLE yeuthich ADD COLUMN dichvu_id INT NULL AFTER sanpham_id')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'yeuthich', 'thucung_id')) {
        if (!$conn->query('ALTER TABLE yeuthich ADD COLUMN thucung_id INT NULL AFTER dichvu_id')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'yeuthich', 'ngaytao')) {
        if (!$conn->query('ALTER TABLE yeuthich ADD COLUMN ngaytao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER thucung_id')) {
            return false;
        }
    }

    if (!app_index_exists($conn, 'yeuthich', 'idx_yeuthich_user')) {
        if (!$conn->query('ALTER TABLE yeuthich ADD INDEX idx_yeuthich_user (nguoidung_id)')) {
            return false;
        }
    }

    if (!app_index_exists($conn, 'yeuthich', 'idx_yeuthich_product')) {
        if (!$conn->query('ALTER TABLE yeuthich ADD INDEX idx_yeuthich_product (sanpham_id)')) {
            return false;
        }
    }

    if (!app_index_exists($conn, 'yeuthich', 'uq_yeuthich_user_product')) {
        if (!$conn->query('ALTER TABLE yeuthich ADD UNIQUE KEY uq_yeuthich_user_product (nguoidung_id, sanpham_id)')) {
            return false;
        }
    }

    return true;
}

function app_user_resolve_product_id(mysqli $conn, int $candidateId, string $productName): int
{
    if (!app_table_exists($conn, 'sanpham')) {
        return 0;
    }

    if ($candidateId > 0) {
        $checkStmt = $conn->prepare('SELECT id FROM sanpham WHERE id = ? LIMIT 1');
        if ($checkStmt) {
            $checkStmt->bind_param('i', $candidateId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $checkRow = $checkResult ? $checkResult->fetch_assoc() : null;
            if ($checkResult) {
                $checkResult->free();
            }
            $checkStmt->close();
            if (is_array($checkRow)) {
                return (int) ($checkRow['id'] ?? 0);
            }
        }
    }

    $name = trim($productName);
    if ($name === '') {
        return 0;
    }

    $exactStmt = $conn->prepare('SELECT id FROM sanpham WHERE LOWER(TRIM(tensanpham)) = LOWER(TRIM(?)) ORDER BY id DESC LIMIT 1');
    if ($exactStmt) {
        $exactStmt->bind_param('s', $name);
        $exactStmt->execute();
        $exactResult = $exactStmt->get_result();
        $exactRow = $exactResult ? $exactResult->fetch_assoc() : null;
        if ($exactResult) {
            $exactResult->free();
        }
        $exactStmt->close();
        if (is_array($exactRow)) {
            return (int) ($exactRow['id'] ?? 0);
        }
    }

    $likeName = '%' . $name . '%';
    $likeStmt = $conn->prepare('SELECT id FROM sanpham WHERE LOWER(tensanpham) LIKE LOWER(?) ORDER BY id DESC LIMIT 1');
    if ($likeStmt) {
        $likeStmt->bind_param('s', $likeName);
        $likeStmt->execute();
        $likeResult = $likeStmt->get_result();
        $likeRow = $likeResult ? $likeResult->fetch_assoc() : null;
        if ($likeResult) {
            $likeResult->free();
        }
        $likeStmt->close();
        if (is_array($likeRow)) {
            return (int) ($likeRow['id'] ?? 0);
        }
    }

    return 0;
}

function app_user_verify_customer_account(mysqli $conn, int $userId, string $userEmail = ''): ?array
{
    if ($userId <= 0 || !app_table_exists($conn, 'nguoidung')) {
        return null;
    }

    $stmt = $conn->prepare('SELECT id, tennguoidung, emailnguoidung, vaitronguoidung FROM nguoidung WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $role = app_normalize_role((string) ($row['vaitronguoidung'] ?? 'user'));
    if ($role !== 'user') {
        return null;
    }

    $dbEmail = app_lower(trim((string) ($row['emailnguoidung'] ?? '')));
    $requestEmail = app_lower(trim($userEmail));
    if ($requestEmail !== '' && $dbEmail !== '' && !hash_equals($dbEmail, $requestEmail)) {
        return null;
    }

    return $row;
}

function app_user_resolve_customer_id(mysqli $conn, int $userId, string $fullName = '', string $email = ''): int
{
    if ($userId <= 0 || !app_table_exists($conn, 'khachhang')) {
        return 0;
    }

    $findStmt = $conn->prepare('SELECT id FROM khachhang WHERE nguoidung_id = ? LIMIT 1');
    if ($findStmt) {
        $findStmt->bind_param('i', $userId);
        $findStmt->execute();
        $findResult = $findStmt->get_result();
        $findRow = $findResult ? $findResult->fetch_assoc() : null;
        if ($findResult) {
            $findResult->free();
        }
        $findStmt->close();

        if (is_array($findRow)) {
            return (int) ($findRow['id'] ?? 0);
        }
    }

    $name = trim($fullName);
    if ($name === '') {
        $name = 'Khach hang #' . $userId;
    }

    $mail = trim($email);
    if ($mail === '') {
        $mail = null;
    }

    $insertStmt = $conn->prepare('INSERT INTO khachhang (nguoidung_id, tenkhachhang, emailkhachhang, kenhkhachhang, ngaytaokhachhang) VALUES (?, ?, ?, "online", NOW())');
    if (!$insertStmt) {
        return 0;
    }

    $insertStmt->bind_param('iss', $userId, $name, $mail);
    $ok = $insertStmt->execute();
    $insertId = (int) $insertStmt->insert_id;
    $insertStmt->close();

    if ($ok && $insertId > 0) {
        return $insertId;
    }

    $retryStmt = $conn->prepare('SELECT id FROM khachhang WHERE nguoidung_id = ? LIMIT 1');
    if (!$retryStmt) {
        return 0;
    }

    $retryStmt->bind_param('i', $userId);
    $retryStmt->execute();
    $retryResult = $retryStmt->get_result();
    $retryRow = $retryResult ? $retryResult->fetch_assoc() : null;
    if ($retryResult) {
        $retryResult->free();
    }
    $retryStmt->close();

    return (int) ($retryRow['id'] ?? 0);
}

function app_user_ensure_cart_tables(mysqli $conn): bool
{
    if (!$conn->query("\n        CREATE TABLE IF NOT EXISTS giohang (\n            id INT NOT NULL AUTO_INCREMENT,\n            nguoidung_id INT NULL,\n            khachhang_id INT NULL,\n            trangthai VARCHAR(30) NOT NULL DEFAULT 'active',\n            ngaytao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            ngaycapnhat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            PRIMARY KEY (id),\n            KEY idx_giohang_user (nguoidung_id),\n            KEY idx_giohang_status (trangthai)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ")) {
        return false;
    }

    if (!app_column_exists($conn, 'giohang', 'nguoidung_id')) {
        if (!$conn->query('ALTER TABLE giohang ADD COLUMN nguoidung_id INT NULL AFTER id')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'giohang', 'khachhang_id')) {
        if (!$conn->query('ALTER TABLE giohang ADD COLUMN khachhang_id INT NULL AFTER nguoidung_id')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'giohang', 'trangthai')) {
        if (!$conn->query("ALTER TABLE giohang ADD COLUMN trangthai VARCHAR(30) NOT NULL DEFAULT 'active'")) {
            return false;
        }
    }


    if (!app_column_exists($conn, 'giohang', 'ngaytao')) {
        if (!$conn->query('ALTER TABLE giohang ADD COLUMN ngaytao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'giohang', 'ngaycapnhat')) {
        if (!$conn->query('ALTER TABLE giohang ADD COLUMN ngaycapnhat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')) {
            return false;
        }
    }

    if (!app_index_exists($conn, 'giohang', 'idx_giohang_user')) {
        if (!$conn->query('ALTER TABLE giohang ADD INDEX idx_giohang_user (nguoidung_id)')) {
            return false;
        }
    }

    if (!app_index_exists($conn, 'giohang', 'idx_giohang_status')) {
        if (!$conn->query('ALTER TABLE giohang ADD INDEX idx_giohang_status (trangthai)')) {
            return false;
        }
    }

    if (!$conn->query("\n        CREATE TABLE IF NOT EXISTS chitietgiohang (\n            id INT NOT NULL AUTO_INCREMENT,\n            giohang_id INT NOT NULL,\n            sanpham_id INT NOT NULL,\n            soluong INT NOT NULL DEFAULT 1,\n            dongia DECIMAL(15,2) NOT NULL DEFAULT 0,\n            tongtien DECIMAL(15,2) NOT NULL DEFAULT 0,\n            ngaytao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            ngaycapnhat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            PRIMARY KEY (id),\n            KEY idx_ctgh_cart (giohang_id),\n            KEY idx_ctgh_product (sanpham_id),\n            UNIQUE KEY uq_ctgh_cart_product (giohang_id, sanpham_id)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ")) {
        return false;
    }

    if (!app_column_exists($conn, 'chitietgiohang', 'giohang_id')) {
        if (!$conn->query('ALTER TABLE chitietgiohang ADD COLUMN giohang_id INT NOT NULL AFTER id')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'chitietgiohang', 'sanpham_id')) {
        if (!$conn->query('ALTER TABLE chitietgiohang ADD COLUMN sanpham_id INT NOT NULL AFTER giohang_id')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'chitietgiohang', 'soluong')) {
        if (!$conn->query('ALTER TABLE chitietgiohang ADD COLUMN soluong INT NOT NULL DEFAULT 1 AFTER sanpham_id')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'chitietgiohang', 'dongia')) {
        if (!$conn->query('ALTER TABLE chitietgiohang ADD COLUMN dongia DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER soluong')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'chitietgiohang', 'tongtien')) {
        if (!$conn->query('ALTER TABLE chitietgiohang ADD COLUMN tongtien DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER dongia')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'chitietgiohang', 'ngaytao')) {
        if (!$conn->query('ALTER TABLE chitietgiohang ADD COLUMN ngaytao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER tongtien')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'chitietgiohang', 'ngaycapnhat')) {
        if (!$conn->query('ALTER TABLE chitietgiohang ADD COLUMN ngaycapnhat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER ngaytao')) {
            return false;
        }
    }

    if (!app_index_exists($conn, 'chitietgiohang', 'idx_ctgh_cart')) {
        if (!$conn->query('ALTER TABLE chitietgiohang ADD INDEX idx_ctgh_cart (giohang_id)')) {
            return false;
        }
    }

    if (!app_index_exists($conn, 'chitietgiohang', 'idx_ctgh_product')) {
        if (!$conn->query('ALTER TABLE chitietgiohang ADD INDEX idx_ctgh_product (sanpham_id)')) {
            return false;
        }
    }

    if (!app_index_exists($conn, 'chitietgiohang', 'uq_ctgh_cart_product')) {
        if (!$conn->query('ALTER TABLE chitietgiohang ADD UNIQUE KEY uq_ctgh_cart_product (giohang_id, sanpham_id)')) {
            return false;
        }
    }

    return true;
}

function app_user_format_price_label($price): string
{
    $amount = (float) $price;
    if (!is_finite($amount) || $amount < 0) {
        $amount = 0;
    }
    return number_format((float) round($amount), 0, ',', '.') . ' đ';
}

function app_user_parse_price_number($raw): float
{
    if (is_int($raw) || is_float($raw)) {
        return (float) $raw;
    }

    $text = trim((string) $raw);
    if ($text === '') {
        return 0.0;
    }

    $digits = preg_replace('/[^0-9]/', '', $text);
    if ($digits === null || $digits === '') {
        return 0.0;
    }

    return (float) $digits;
}

function app_user_reset_cart_for_account(mysqli $conn, int $userId = 0, int $customerId = 0): void
{
    if (($userId <= 0 && $customerId <= 0) || !app_table_exists($conn, 'giohang') || !app_table_exists($conn, 'chitietgiohang')) {
        return;
    }

    if ($userId > 0 && $customerId > 0) {
        $deleteDetailStmt = $conn->prepare("DELETE ct FROM chitietgiohang ct INNER JOIN giohang g ON g.id = ct.giohang_id WHERE LOWER(TRIM(g.trangthai)) = 'active' AND (g.nguoidung_id = ? OR g.khachhang_id = ?)");
        if ($deleteDetailStmt) {
            $deleteDetailStmt->bind_param('ii', $userId, $customerId);
            $deleteDetailStmt->execute();
            $deleteDetailStmt->close();
        }

        $deleteCartStmt = $conn->prepare("DELETE FROM giohang WHERE LOWER(TRIM(trangthai)) = 'active' AND (nguoidung_id = ? OR khachhang_id = ?)");
        if ($deleteCartStmt) {
            $deleteCartStmt->bind_param('ii', $userId, $customerId);
            $deleteCartStmt->execute();
            $deleteCartStmt->close();
        }
        return;
    }

    if ($userId > 0) {
        $deleteDetailStmt = $conn->prepare("DELETE ct FROM chitietgiohang ct INNER JOIN giohang g ON g.id = ct.giohang_id WHERE LOWER(TRIM(g.trangthai)) = 'active' AND g.nguoidung_id = ?");
        if ($deleteDetailStmt) {
            $deleteDetailStmt->bind_param('i', $userId);
            $deleteDetailStmt->execute();
            $deleteDetailStmt->close();
        }

        $deleteCartStmt = $conn->prepare("DELETE FROM giohang WHERE LOWER(TRIM(trangthai)) = 'active' AND nguoidung_id = ?");
        if ($deleteCartStmt) {
            $deleteCartStmt->bind_param('i', $userId);
            $deleteCartStmt->execute();
            $deleteCartStmt->close();
        }
        return;
    }

    $deleteDetailStmt = $conn->prepare("DELETE ct FROM chitietgiohang ct INNER JOIN giohang g ON g.id = ct.giohang_id WHERE LOWER(TRIM(g.trangthai)) = 'active' AND g.khachhang_id = ?");
    if ($deleteDetailStmt) {
        $deleteDetailStmt->bind_param('i', $customerId);
        $deleteDetailStmt->execute();
        $deleteDetailStmt->close();
    }

    $deleteCartStmt = $conn->prepare("DELETE FROM giohang WHERE LOWER(TRIM(trangthai)) = 'active' AND khachhang_id = ?");
    if ($deleteCartStmt) {
        $deleteCartStmt->bind_param('i', $customerId);
        $deleteCartStmt->execute();
        $deleteCartStmt->close();
    }
}

function app_handle_user_api(mysqli $conn, string $api): bool
{
    if ($api === 'get_user_collections') {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Phuong thuc khong hop le',
            ], 405);
        }

        if (!app_user_ensure_favorites_table($conn) || !app_user_ensure_cart_tables($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao du lieu yeuthich/giohang',
                'error' => $conn->error,
            ], 500);
        }

        $input = app_input_payload();
        $userId = (int) ($input['user_id'] ?? 0);
        $userEmail = trim((string) ($input['user_email'] ?? ''));
        $userRow = app_user_verify_customer_account($conn, $userId, $userEmail);

        if (!is_array($userRow)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Tai khoan khong hop le',
            ], 403);
        }

        $customerId = app_user_resolve_customer_id(
            $conn,
            $userId,
            (string) ($userRow['tennguoidung'] ?? ''),
            (string) ($userRow['emailnguoidung'] ?? '')
        );
        if ($customerId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the xac dinh khach hang de dong bo du lieu',
            ], 500);
        }

        $wishlist = [];
        $wishlistStmt = $conn->prepare("\n            SELECT s.id AS sanpham_id, s.tensanpham, s.giasanpham, s.hinhanhsanpham\n            FROM yeuthich y\n            INNER JOIN sanpham s ON s.id = y.sanpham_id\n            WHERE y.nguoidung_id = ? OR y.khachhang_id = ?\n            ORDER BY y.id DESC\n        ");
        if ($wishlistStmt) {
            $wishlistStmt->bind_param('ii', $userId, $customerId);
            $wishlistStmt->execute();
            $wishlistResult = $wishlistStmt->get_result();
            while ($wishlistResult && ($row = $wishlistResult->fetch_assoc())) {
                $name = trim((string) ($row['tensanpham'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $wishlist[] = [
                    'id' => (int) ($row['sanpham_id'] ?? 0),
                    'name' => $name,
                    'image' => (string) ($row['hinhanhsanpham'] ?? ''),
                    'price' => app_user_format_price_label((float) ($row['giasanpham'] ?? 0)),
                ];
            }
            if ($wishlistResult) {
                $wishlistResult->free();
            }
            $wishlistStmt->close();
        }

        $cartItems = [];
        $cartStmt = $conn->prepare("\n            SELECT\n                ct.sanpham_id,\n                ct.soluong,\n                ct.dongia,\n                s.tensanpham,\n                s.hinhanhsanpham,\n                s.giasanpham\n            FROM giohang g\n            INNER JOIN chitietgiohang ct ON ct.giohang_id = g.id\n            LEFT JOIN sanpham s ON s.id = ct.sanpham_id\n            WHERE (g.nguoidung_id = ? OR g.khachhang_id = ?) AND LOWER(TRIM(g.trangthai)) = 'active'\n            ORDER BY ct.id ASC\n        ");
        if ($cartStmt) {
            $cartStmt->bind_param('ii', $userId, $customerId);
            $cartStmt->execute();
            $cartResult = $cartStmt->get_result();
            while ($cartResult && ($row = $cartResult->fetch_assoc())) {
                $name = trim((string) ($row['tensanpham'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $qty = max(1, (int) ($row['soluong'] ?? 1));
                $unitPrice = (float) ($row['dongia'] ?? 0);
                if ($unitPrice <= 0) {
                    $unitPrice = (float) ($row['giasanpham'] ?? 0);
                }

                $cartItems[] = [
                    'id' => (int) ($row['sanpham_id'] ?? 0),
                    'name' => $name,
                    'image' => (string) ($row['hinhanhsanpham'] ?? ''),
                    'price' => app_user_format_price_label($unitPrice),
                    'quantity' => $qty,
                ];
            }
            if ($cartResult) {
                $cartResult->free();
            }
            $cartStmt->close();
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'data' => [
                'wishlist' => $wishlist,
                'cart' => $cartItems,
            ],
        ]);
    }

    if ($api === 'sync_user_collections') {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Phuong thuc khong hop le',
            ], 405);
        }

        if (!app_user_ensure_favorites_table($conn) || !app_user_ensure_cart_tables($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao du lieu yeuthich/giohang',
                'error' => $conn->error,
            ], 500);
        }

        $input = app_input_payload();
        $userId = (int) ($input['user_id'] ?? 0);
        $userEmail = trim((string) ($input['user_email'] ?? ''));
        $userRow = app_user_verify_customer_account($conn, $userId, $userEmail);

        if (!is_array($userRow)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Tai khoan khong hop le',
            ], 403);
        }

        $customerId = app_user_resolve_customer_id(
            $conn,
            $userId,
            (string) ($userRow['tennguoidung'] ?? ''),
            (string) ($userRow['emailnguoidung'] ?? '')
        );
        if ($customerId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the xac dinh khach hang de dong bo du lieu',
            ], 500);
        }

        $wishlistInput = $input['wishlist'] ?? [];
        $cartInput = $input['cart'] ?? [];
        $wishlistItems = is_array($wishlistInput) ? $wishlistInput : [];
        $cartItems = is_array($cartInput) ? $cartInput : [];

        $conn->begin_transaction();
        try {
            $deleteWishlistStmt = $conn->prepare('DELETE FROM yeuthich WHERE (nguoidung_id = ? OR khachhang_id = ?) AND sanpham_id IS NOT NULL');
            if (!$deleteWishlistStmt) {
                throw new RuntimeException('Khong the lam moi danh sach yeu thich');
            }
            $deleteWishlistStmt->bind_param('ii', $userId, $customerId);
            $deleteWishlistStmt->execute();
            $deleteWishlistStmt->close();

            $favoriteProductIds = [];
            foreach ($wishlistItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $pid = app_user_resolve_product_id(
                    $conn,
                    (int) ($item['id'] ?? $item['product_id'] ?? 0),
                    trim((string) ($item['name'] ?? $item['product_name'] ?? ''))
                );
                if ($pid <= 0) {
                    continue;
                }

                $favoriteProductIds[$pid] = true;
            }

            if (count($favoriteProductIds) > 0) {
                $insertFavoriteStmt = $conn->prepare('INSERT INTO yeuthich (khachhang_id, nguoidung_id, sanpham_id, ngaytao) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE ngaytao = VALUES(ngaytao), nguoidung_id = VALUES(nguoidung_id)');
                if (!$insertFavoriteStmt) {
                    throw new RuntimeException('Khong the cap nhat yeu thich');
                }

                foreach (array_keys($favoriteProductIds) as $pid) {
                    $productId = (int) $pid;
                    $insertFavoriteStmt->bind_param('iii', $customerId, $userId, $productId);
                    $insertFavoriteStmt->execute();
                }
                $insertFavoriteStmt->close();
            }

            $cartId = 0;
            $findCartStmt = $conn->prepare("SELECT id FROM giohang WHERE (nguoidung_id = ? OR khachhang_id = ?) AND LOWER(TRIM(trangthai)) = 'active' ORDER BY id DESC LIMIT 1");
            if ($findCartStmt) {
                $findCartStmt->bind_param('ii', $userId, $customerId);
                $findCartStmt->execute();
                $findCartResult = $findCartStmt->get_result();
                $findCartRow = $findCartResult ? $findCartResult->fetch_assoc() : null;
                if ($findCartResult) {
                    $findCartResult->free();
                }
                $findCartStmt->close();
                $cartId = (int) ($findCartRow['id'] ?? 0);
            }

            if ($cartId <= 0) {
                $createCartStmt = $conn->prepare("INSERT INTO giohang (khachhang_id, nguoidung_id, trangthai, ngaytao, ngaycapnhat) VALUES (?, ?, 'active', NOW(), NOW())");
                if (!$createCartStmt) {
                    throw new RuntimeException('Khong the tao gio hang');
                }
                $createCartStmt->bind_param('ii', $customerId, $userId);
                $createCartStmt->execute();
                $cartId = (int) $createCartStmt->insert_id;
                $createCartStmt->close();
            } else {
                $touchCartStmt = $conn->prepare("UPDATE giohang SET ngaycapnhat = NOW(), trangthai = 'active', nguoidung_id = ?, khachhang_id = ? WHERE id = ?");
                if ($touchCartStmt) {
                    $touchCartStmt->bind_param('iii', $userId, $customerId, $cartId);
                    $touchCartStmt->execute();
                    $touchCartStmt->close();
                }
            }

            $deleteCartDetailStmt = $conn->prepare('DELETE FROM chitietgiohang WHERE giohang_id = ?');
            if (!$deleteCartDetailStmt) {
                throw new RuntimeException('Khong the lam moi chi tiet gio hang');
            }
            $deleteCartDetailStmt->bind_param('i', $cartId);
            $deleteCartDetailStmt->execute();
            $deleteCartDetailStmt->close();

            if (count($cartItems) > 0) {
                $insertCartDetailStmt = $conn->prepare('INSERT INTO chitietgiohang (giohang_id, sanpham_id, soluong, dongia, tongtien, ngaytao, ngaycapnhat) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
                if (!$insertCartDetailStmt) {
                    throw new RuntimeException('Khong the cap nhat gio hang');
                }

                foreach ($cartItems as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $pid = app_user_resolve_product_id(
                        $conn,
                        (int) ($item['id'] ?? $item['product_id'] ?? 0),
                        trim((string) ($item['name'] ?? $item['product_name'] ?? ''))
                    );
                    if ($pid <= 0) {
                        continue;
                    }

                    $qty = max(1, min(999, (int) ($item['quantity'] ?? 1)));
                    $priceValue = $item['price_value'] ?? ($item['price'] ?? 0);
                    $unitPrice = app_user_parse_price_number($priceValue);
                    if ($unitPrice <= 0) {
                        $priceProbe = $conn->prepare('SELECT giasanpham FROM sanpham WHERE id = ? LIMIT 1');
                        if ($priceProbe) {
                            $priceProbe->bind_param('i', $pid);
                            $priceProbe->execute();
                            $priceProbeResult = $priceProbe->get_result();
                            $priceProbeRow = $priceProbeResult ? $priceProbeResult->fetch_assoc() : null;
                            if ($priceProbeResult) {
                                $priceProbeResult->free();
                            }
                            $priceProbe->close();
                            $unitPrice = (float) ($priceProbeRow['giasanpham'] ?? 0);
                        }
                    }

                    $lineTotal = (float) ($unitPrice * $qty);
                    $productId = (int) $pid;
                    $insertCartDetailStmt->bind_param('iiidd', $cartId, $productId, $qty, $unitPrice, $lineTotal);
                    $insertCartDetailStmt->execute();
                }
                $insertCartDetailStmt->close();
            }

            $conn->commit();
            $conn->close();
            app_json_response([
                'ok' => true,
                'message' => 'Dong bo yeu thich va gio hang thanh cong',
                'data' => [
                    'wishlist_count' => count($favoriteProductIds),
                    'cart_count' => count($cartItems),
                ],
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the dong bo du lieu yeu thich/gio hang',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    if ($api === 'toggle_product_favorite') {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Phuong thuc khong hop le',
            ], 405);
        }

        if (!app_table_exists($conn, 'nguoidung')) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay bang nguoidung',
            ], 500);
        }

        if (!app_user_ensure_favorites_table($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao bang yeuthich',
                'error' => $conn->error,
            ], 500);
        }

        $input = app_input_payload();
        $userId = (int) ($input['user_id'] ?? 0);
        $userEmail = trim((string) ($input['user_email'] ?? ''));
        $productIdInput = (int) ($input['product_id'] ?? 0);
        $productNameInput = trim((string) ($input['product_name'] ?? ''));
        $action = app_lower(trim((string) ($input['action'] ?? 'toggle')));

        if ($userId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Vui long dang nhap de cap nhat yeu thich',
            ], 401);
        }

        $userStmt = $conn->prepare('SELECT id, tennguoidung, emailnguoidung, vaitronguoidung FROM nguoidung WHERE id = ? LIMIT 1');
        if (!$userStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the xac thuc nguoi dung',
                'error' => $conn->error,
            ], 500);
        }

        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $userRow = $userResult ? $userResult->fetch_assoc() : null;
        if ($userResult) {
            $userResult->free();
        }
        $userStmt->close();

        if (!is_array($userRow) || app_normalize_role((string) ($userRow['vaitronguoidung'] ?? 'user')) !== 'user') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Tai khoan khong hop le',
            ], 403);
        }

        $dbEmail = app_lower(trim((string) ($userRow['emailnguoidung'] ?? '')));
        if ($userEmail !== '' && $dbEmail !== '' && !hash_equals($dbEmail, app_lower($userEmail))) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Thong tin xac thuc khong khop',
            ], 403);
        }

        $productId = app_user_resolve_product_id($conn, $productIdInput, $productNameInput);
        if ($productId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay san pham de cap nhat yeu thich',
            ], 404);
        }

        $customerId = app_user_resolve_customer_id(
            $conn,
            $userId,
            (string) ($userRow['tennguoidung'] ?? ''),
            (string) ($userRow['emailnguoidung'] ?? '')
        );
        if ($customerId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the xac dinh khach hang de cap nhat yeu thich',
            ], 500);
        }

        $exists = false;
        $existsStmt = $conn->prepare('SELECT id FROM yeuthich WHERE (nguoidung_id = ? OR khachhang_id = ?) AND sanpham_id = ? LIMIT 1');
        if ($existsStmt) {
            $existsStmt->bind_param('iii', $userId, $customerId, $productId);
            $existsStmt->execute();
            $existsResult = $existsStmt->get_result();
            $exists = (bool) ($existsResult && $existsResult->fetch_assoc());
            if ($existsResult) {
                $existsResult->free();
            }
            $existsStmt->close();
        }

        $nextFavorite = $exists;
        $targetAction = in_array($action, ['add', 'remove', 'toggle'], true) ? $action : 'toggle';

        if ($targetAction === 'add' || ($targetAction === 'toggle' && !$exists)) {
            $insertStmt = $conn->prepare('INSERT INTO yeuthich (khachhang_id, nguoidung_id, sanpham_id, ngaytao) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE ngaytao = VALUES(ngaytao), nguoidung_id = VALUES(nguoidung_id)');
            if (!$insertStmt) {
                $conn->close();
                app_json_response([
                    'ok' => false,
                    'message' => 'Khong the them yeu thich',
                    'error' => $conn->error,
                ], 500);
            }

            $insertStmt->bind_param('iii', $customerId, $userId, $productId);
            $insertStmt->execute();
            $insertStmt->close();
            $nextFavorite = true;
        } elseif ($targetAction === 'remove' || ($targetAction === 'toggle' && $exists)) {
            $deleteStmt = $conn->prepare('DELETE FROM yeuthich WHERE (nguoidung_id = ? OR khachhang_id = ?) AND sanpham_id = ?');
            if (!$deleteStmt) {
                $conn->close();
                app_json_response([
                    'ok' => false,
                    'message' => 'Khong the xoa yeu thich',
                    'error' => $conn->error,
                ], 500);
            }

            $deleteStmt->bind_param('iii', $userId, $customerId, $productId);
            $deleteStmt->execute();
            $deleteStmt->close();
            $nextFavorite = false;
        }

        $countStmt = $conn->prepare('SELECT COUNT(DISTINCT nguoidung_id) AS total FROM yeuthich WHERE sanpham_id = ? AND nguoidung_id IS NOT NULL');
        $favoriteCount = 0;
        if ($countStmt) {
            $countStmt->bind_param('i', $productId);
            $countStmt->execute();
            $countResult = $countStmt->get_result();
            $countRow = $countResult ? $countResult->fetch_assoc() : null;
            if ($countResult) {
                $countResult->free();
            }
            $countStmt->close();
            $favoriteCount = (int) ($countRow['total'] ?? 0);
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'data' => [
                'product_id' => $productId,
                'favorited' => $nextFavorite,
                'favorite_count' => $favoriteCount,
            ],
        ]);
    }

    if ($api === 'update_user_avatar') {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Phuong thuc khong hop le',
            ], 405);
        }

        if (!app_table_exists($conn, 'nguoidung')) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay bang nguoidung',
            ], 500);
        }

        if (!app_column_exists($conn, 'nguoidung', 'anhdaidiennguoidung')) {
            $conn->query("ALTER TABLE nguoidung ADD COLUMN anhdaidiennguoidung VARCHAR(255) NULL AFTER emailnguoidung");
        }

        $input = app_input_payload();
        $userId = (int) ($input['user_id'] ?? 0);
        $userEmail = trim((string) ($input['user_email'] ?? ''));
        $avatarDataUrl = trim((string) ($input['avatar_data_url'] ?? ''));

        if ($userId <= 0 || $avatarDataUrl === '') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Thieu user_id hoac avatar_data_url',
            ], 400);
        }

        $findStmt = $conn->prepare('SELECT id, emailnguoidung FROM nguoidung WHERE id = ? LIMIT 1');
        if (!$findStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the xac thuc nguoi dung',
                'error' => $conn->error,
            ], 500);
        }

        $findStmt->bind_param('i', $userId);
        $findStmt->execute();
        $findResult = $findStmt->get_result();
        $userRow = $findResult ? $findResult->fetch_assoc() : null;
        if ($findResult) {
            $findResult->free();
        }
        $findStmt->close();

        if (!is_array($userRow)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Nguoi dung khong ton tai',
            ], 404);
        }

        $dbEmail = app_lower(trim((string) ($userRow['emailnguoidung'] ?? '')));
        if ($userEmail !== '' && $dbEmail !== '' && !hash_equals($dbEmail, app_lower($userEmail))) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Thong tin xac thuc khong khop',
            ], 403);
        }

        [$savedOk, $relativePath, $saveMessage] = app_user_save_avatar_data_url($avatarDataUrl);
        if (!$savedOk) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => $saveMessage !== '' ? $saveMessage : 'Khong the luu avatar',
            ], 400);
        }

        $updateStmt = $conn->prepare('UPDATE nguoidung SET anhdaidiennguoidung = ? WHERE id = ?');
        if (!$updateStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the cap nhat avatar',
                'error' => $conn->error,
            ], 500);
        }

        $updateStmt->bind_param('si', $relativePath, $userId);
        $ok = $updateStmt->execute();
        $updateStmt->close();

        if (!$ok) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Cap nhat avatar that bai',
                'error' => $conn->error,
            ], 500);
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'data' => [
                'avatar_path' => $relativePath,
                'avatar_url' => app_to_public_image_url($relativePath),
            ],
        ]);
    }

    if ($api === 'upload_chat_media') {
        $kind = trim((string) ($_POST['kind'] ?? $_GET['kind'] ?? ''));
        app_chat_handle_upload($kind);
    }

    if ($api === 'get_voucher_hunt_list') {
        if (!app_ensure_voucher_tables($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao du lieu voucher',
                'error' => $conn->error,
            ], 500);
        }

        $userId = (int) ($_GET['user_id'] ?? 0);

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
                COALESCE(mu.soluong_danhan, 0) AS claimed_count
            FROM magiamgia v
            LEFT JOIN magiamgia_nguoidung mu
                ON mu.magiamgia_id = v.id
               AND mu.nguoidung_id = {$userId}
            ORDER BY v.id DESC
            LIMIT 300
        ";
        $result = $conn->query($sql);
        if (!$result) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tai danh sach voucher',
                'error' => $conn->error,
            ], 500);
        }

        $games = app_user_voucher_games();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $gameKey = (string) ($row['minigame_key'] ?? 'jigsaw_pet');
            if (!isset($games[$gameKey])) {
                $gameKey = 'jigsaw_pet';
            }

            $levelKey = trim((string) ($row['minigame_level'] ?? 'easy'));
            $levelLabels = app_user_voucher_level_labels();
            if (!array_key_exists($levelKey, $levelLabels)) {
                $levelKey = 'easy';
            }

            $maxPerUser = max(1, (int) ($row['toida_sudung_moikhach'] ?? 1));
            $claimedCount = max(0, (int) ($row['claimed_count'] ?? 0));
            $isOpen = app_user_voucher_can_use_now($row);

            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'magiamgia' => (string) ($row['magiamgia'] ?? ''),
                'mota' => (string) ($row['mota'] ?? ''),
                'loaigiamgia' => (string) ($row['loaigiamgia'] ?? 'percent'),
                'giatri' => (float) ($row['giatri'] ?? 0),
                'giatridonhangtoithieu' => (float) ($row['giatridonhangtoithieu'] ?? 0),
                'ngaybatdau' => (string) ($row['ngaybatdau'] ?? ''),
                'ngayketthuc' => (string) ($row['ngayketthuc'] ?? ''),
                'soluong' => (int) ($row['soluong'] ?? 0),
                'toida_sudung_moikhach' => $maxPerUser,
                'trangthai' => (string) ($row['trangthai'] ?? 'inactive'),
                'minigame_key' => $gameKey,
                'minigame_level' => $levelKey,
                'minigame_level_label' => (string) ($levelLabels[$levelKey] ?? 'Dễ'),
                'minigame_label' => (string) ($games[$gameKey]['label'] ?? ''),
                'can_claim' => $isOpen && $claimedCount < $maxPerUser,
                'claimed_count' => $claimedCount,
            ];
        }

        $result->free();
        $conn->close();
        app_json_response([
            'ok' => true,
            'count' => count($items),
            'games' => $games,
            'levels' => app_user_voucher_level_labels(),
            'data' => $items,
        ]);
    }

    if ($api === 'claim_voucher_game') {
        if (!app_ensure_voucher_tables($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao du lieu voucher',
                'error' => $conn->error,
            ], 500);
        }

        $input = app_input_payload();
        $voucherId = (int) ($input['voucher_id'] ?? 0);
        $userId = (int) ($input['user_id'] ?? 0);
        $gameKey = trim((string) ($input['game_key'] ?? ''));
        $gameLevel = trim((string) ($input['game_level'] ?? 'easy'));
        $score = (int) ($input['score'] ?? 0);
        $isWin = (bool) ($input['is_win'] ?? false);

        if ($voucherId <= 0 || $userId <= 0) {
            $conn->close();
            app_json_response(['ok' => false, 'message' => 'Du lieu nhan voucher khong hop le'], 400);
        }

        $games = app_user_voucher_games();
        if (!isset($games[$gameKey])) {
            $conn->close();
            app_json_response(['ok' => false, 'message' => 'Mini game khong hop le'], 400);
        }

        $userSql = 'SELECT id, vaitronguoidung FROM nguoidung WHERE id = ? LIMIT 1';
        $userStmt = $conn->prepare($userSql);
        if (!$userStmt) {
            $conn->close();
            app_json_response(['ok' => false, 'message' => 'Khong the xac thuc nguoi dung', 'error' => $conn->error], 500);
        }
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $userRow = $userResult ? $userResult->fetch_assoc() : null;
        if ($userResult) {
            $userResult->free();
        }
        $userStmt->close();

        if (!is_array($userRow) || app_normalize_role((string) ($userRow['vaitronguoidung'] ?? 'user')) !== 'user') {
            $conn->close();
            app_json_response(['ok' => false, 'message' => 'Tai khoan khong hop le'], 403);
        }

        $minScore = app_user_voucher_required_score($games[$gameKey], $gameLevel);
        if (!$isWin || $score < $minScore) {
            $conn->close();
            app_json_response(['ok' => false, 'message' => 'Ban chua vuot qua mini game'], 400);
        }

        $conn->begin_transaction();
        try {
            $voucherSql = 'SELECT id, magiamgia, mota, loaigiamgia, giatri, giatridonhangtoithieu, ngaybatdau, ngayketthuc, soluong, toida_sudung_moikhach, trangthai, minigame_key, minigame_level FROM magiamgia WHERE id = ? FOR UPDATE';
            $voucherStmt = $conn->prepare($voucherSql);
            if (!$voucherStmt) {
                throw new RuntimeException('Khong the khoa voucher');
            }
            $voucherStmt->bind_param('i', $voucherId);
            $voucherStmt->execute();
            $voucherResult = $voucherStmt->get_result();
            $voucherRow = $voucherResult ? $voucherResult->fetch_assoc() : null;
            if ($voucherResult) {
                $voucherResult->free();
            }
            $voucherStmt->close();

            if (!is_array($voucherRow)) {
                throw new RuntimeException('Voucher khong ton tai');
            }

            $voucherGameKey = trim((string) ($voucherRow['minigame_key'] ?? 'jigsaw_pet'));
            if ($voucherGameKey === '') {
                $voucherGameKey = 'jigsaw_pet';
            }

            if ($voucherGameKey !== $gameKey) {
                throw new RuntimeException('Voucher nay khong danh cho mini game vua choi');
            }

            $voucherGameLevel = trim((string) ($voucherRow['minigame_level'] ?? 'easy'));
            if ($voucherGameLevel === '') {
                $voucherGameLevel = 'easy';
            }

            if ($voucherGameLevel !== $gameLevel) {
                throw new RuntimeException('Muc do mini game khong dung voi voucher nay');
            }

            $requiredScore = app_user_voucher_required_score($games[$gameKey], $voucherGameLevel);
            if ($score < $requiredScore) {
                throw new RuntimeException('Diem mini game chua dat muc yeu cau cua voucher');
            }

            if (!app_user_voucher_can_use_now($voucherRow)) {
                throw new RuntimeException('Voucher da het luot hoac het han');
            }

            $maxPerUser = max(1, (int) ($voucherRow['toida_sudung_moikhach'] ?? 1));

            $claimSql = 'SELECT id, soluong_danhan, diemgame_cao_nhat FROM magiamgia_nguoidung WHERE magiamgia_id = ? AND nguoidung_id = ? FOR UPDATE';
            $claimStmt = $conn->prepare($claimSql);
            if (!$claimStmt) {
                throw new RuntimeException('Khong the kiem tra luot nhan');
            }
            $claimStmt->bind_param('ii', $voucherId, $userId);
            $claimStmt->execute();
            $claimResult = $claimStmt->get_result();
            $claimRow = $claimResult ? $claimResult->fetch_assoc() : null;
            if ($claimResult) {
                $claimResult->free();
            }
            $claimStmt->close();

            $claimed = (int) ($claimRow['soluong_danhan'] ?? 0);
            if ($claimed >= $maxPerUser) {
                throw new RuntimeException('Ban da nhan toi da so luot voucher nay');
            }

            if (is_array($claimRow)) {
                $claimId = (int) ($claimRow['id'] ?? 0);
                $nextCount = $claimed + 1;
                $bestScore = max((int) ($claimRow['diemgame_cao_nhat'] ?? 0), $score);
                $updateClaimStmt = $conn->prepare('UPDATE magiamgia_nguoidung SET soluong_danhan = ?, diemgame_cao_nhat = ? WHERE id = ?');
                if (!$updateClaimStmt) {
                    throw new RuntimeException('Khong the cap nhat luot nhan voucher');
                }
                $updateClaimStmt->bind_param('iii', $nextCount, $bestScore, $claimId);
                $okUpdateClaim = $updateClaimStmt->execute();
                $updateClaimStmt->close();
                if (!$okUpdateClaim) {
                    throw new RuntimeException('Cap nhat luot nhan that bai');
                }
            } else {
                $insertClaimStmt = $conn->prepare('INSERT INTO magiamgia_nguoidung (magiamgia_id, nguoidung_id, soluong_danhan, diemgame_cao_nhat) VALUES (?, ?, 1, ?)');
                if (!$insertClaimStmt) {
                    throw new RuntimeException('Khong the tao luot nhan voucher');
                }
                $insertClaimStmt->bind_param('iii', $voucherId, $userId, $score);
                $okInsertClaim = $insertClaimStmt->execute();
                $insertClaimStmt->close();
                if (!$okInsertClaim) {
                    throw new RuntimeException('Tao luot nhan voucher that bai');
                }
            }

            $decreaseStmt = $conn->prepare('UPDATE magiamgia SET soluong = soluong - 1 WHERE id = ? AND soluong > 0');
            if (!$decreaseStmt) {
                throw new RuntimeException('Khong the cap nhat so luong voucher');
            }
            $decreaseStmt->bind_param('i', $voucherId);
            $decreaseStmt->execute();
            $affected = (int) $decreaseStmt->affected_rows;
            $decreaseStmt->close();

            if ($affected <= 0) {
                throw new RuntimeException('Voucher da het so luong');
            }

            $conn->commit();

            $conn->close();
            app_json_response([
                'ok' => true,
                'message' => 'Chuc mung! Ban da nhan voucher thanh cong',
                'data' => [
                    'id' => (int) ($voucherRow['id'] ?? 0),
                    'code' => (string) ($voucherRow['magiamgia'] ?? ''),
                    'title' => (string) ($voucherRow['mota'] ?? ''),
                    'desc' => (string) ($voucherRow['mota'] ?? ''),
                    'type' => (string) ($voucherRow['loaigiamgia'] ?? 'percent'),
                    'value' => (float) ($voucherRow['giatri'] ?? 0),
                    'minOrder' => (float) ($voucherRow['giatridonhangtoithieu'] ?? 0),
                    'expiry' => (string) ($voucherRow['ngayketthuc'] ?? ''),
                    'status' => 'available',
                    'game' => $gameKey,
                    'gameLevel' => $voucherGameLevel,
                ],
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    if ($api === 'send_email_verification_code') {
        if (!app_table_exists($conn, 'nguoidung')) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay bang nguoidung',
            ], 500);
        }

        $input = app_input_payload();
        $email = trim((string) ($input['email'] ?? ''));
        $purpose = app_lower(trim((string) ($input['purpose'] ?? 'register')));
        $name = trim((string) ($input['name'] ?? ''));

        if (!in_array($purpose, ['register', 'reset_password'], true)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Muc dich gui ma khong hop le',
            ], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Email khong hop le',
            ], 400);
        }

        $emailLower = app_lower($email);
        if ($purpose === 'register') {
            if (app_contains_reserved_account_keyword($email) || app_contains_reserved_account_keyword($name)) {
                $conn->close();
                app_json_response([
                    'ok' => false,
                    'message' => 'Tai khoan chua tu khoa admin/staff khong duoc dang ky tai khu vuc user',
                ], 403);
            }

            $checkStmt = $conn->prepare("SELECT id FROM nguoidung WHERE LOWER(COALESCE(emailnguoidung, '')) = ? LIMIT 1");
            if (!$checkStmt) {
                $conn->close();
                app_json_response([
                    'ok' => false,
                    'message' => 'Khong the kiem tra email',
                ], 500);
            }
            $checkStmt->bind_param('s', $emailLower);
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
                    'message' => 'Email da ton tai',
                ], 409);
            }
        }

        if ($purpose === 'reset_password') {
            $checkStmt = $conn->prepare("SELECT id, vaitronguoidung FROM nguoidung WHERE LOWER(COALESCE(emailnguoidung, '')) = ? LIMIT 1");
            if (!$checkStmt) {
                $conn->close();
                app_json_response([
                    'ok' => false,
                    'message' => 'Khong the kiem tra email',
                ], 500);
            }
            $checkStmt->bind_param('s', $emailLower);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $row = $checkResult ? $checkResult->fetch_assoc() : null;
            if ($checkResult) {
                $checkResult->free();
            }
            $checkStmt->close();

            if (!is_array($row) || app_normalize_role((string) ($row['vaitronguoidung'] ?? 'user')) !== 'user') {
                $conn->close();
                app_json_response([
                    'ok' => false,
                    'message' => 'Khong tim thay tai khoan user voi email nay',
                ], 404);
            }
        }

        if (!app_user_ensure_email_otp_table($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao he thong ma xac minh',
            ], 500);
        }

        $latestStmt = $conn->prepare('SELECT consumed_at, GREATEST(TIMESTAMPDIFF(SECOND, NOW(), expires_at), 0) AS remaining_seconds FROM email_verification_tokens WHERE LOWER(email) = LOWER(?) AND purpose = ? ORDER BY id DESC LIMIT 1');
        if ($latestStmt) {
            $latestStmt->bind_param('ss', $email, $purpose);
            $latestStmt->execute();
            $latestResult = $latestStmt->get_result();
            $latestRow = $latestResult ? $latestResult->fetch_assoc() : null;
            if ($latestResult) {
                $latestResult->free();
            }
            $latestStmt->close();

            if (is_array($latestRow)) {
                $consumedAt = trim((string) ($latestRow['consumed_at'] ?? ''));
                $remain = max(0, (int) ($latestRow['remaining_seconds'] ?? 0));
                if ($consumedAt === '' && $remain > 0) {
                    $minutes = (int) floor($remain / 60);
                    $seconds = (int) ($remain % 60);
                    $remainText = $minutes > 0
                        ? ($minutes . ' phut ' . $seconds . ' giay')
                        : ($seconds . ' giay');

                    $conn->close();
                    app_json_response([
                        'ok' => false,
                        'message' => 'Ma vua gui van con hieu luc. Vui long dung ma hien tai (' . $remainText . ')',
                        'active_code_remaining' => $remain,
                    ], 409);
                }
            }
        }

        $storeResult = app_user_store_email_otp($conn, $email, $purpose, trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
        if (!$storeResult['ok']) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => (string) ($storeResult['message'] ?? 'Khong tao duoc ma xac minh'),
            ], 500);
        }

        $otpCode = (string) ($storeResult['code'] ?? '');
        $expiresIn = (int) ($storeResult['expires_in'] ?? 60);
        $expiresMinutes = max(1, (int) floor($expiresIn / 60));
        $purposeLabel = $purpose === 'register' ? 'Dang ky tai khoan moi' : 'Dat lai mat khau';
        $subject = $purpose === 'register'
            ? 'Ma xac minh dang ky tai khoan - 3 CHU CUN CON'
            : 'Ma xac minh dat lai mat khau - 3 CHU CUN CON';
        $htmlBody = app_user_build_otp_email_html($otpCode, $purposeLabel, $expiresMinutes);
        $textBody = 'Ma xac minh cua ban la: ' . $otpCode . '. Ma co hieu luc trong ' . $expiresMinutes . ' phut.';

        [$sent, $sendMessage] = app_send_email($email, $subject, $htmlBody, $textBody);
        if (!$sent) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Gui email that bai: ' . $sendMessage,
            ], 500);
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'message' => 'Da gui ma xac minh ve email',
            'data' => [
                'expires_in' => $expiresIn,
                    'active_code_remaining' => $expiresIn,
            ],
        ]);
    }

    if ($api === 'verify_email_verification_code') {
        if (!app_table_exists($conn, 'nguoidung')) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay bang nguoidung',
            ], 500);
        }

        $input = app_input_payload();
        $email = trim((string) ($input['email'] ?? ''));
        $purpose = app_lower(trim((string) ($input['purpose'] ?? 'reset_password')));
        $code = trim((string) ($input['verification_code'] ?? $input['otp'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));

        if (!in_array($purpose, ['register', 'reset_password'], true)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Muc dich xac minh khong hop le',
            ], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Email khong hop le',
            ], 400);
        }

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Ma xac minh phai gom 6 chu so',
            ], 400);
        }

        $emailLower = app_lower($email);
        if ($purpose === 'register') {
            if (app_contains_reserved_account_keyword($email) || app_contains_reserved_account_keyword($name)) {
                $conn->close();
                app_json_response([
                    'ok' => false,
                    'message' => 'Tai khoan chua tu khoa admin/staff khong duoc dang ky tai khu vuc user',
                ], 403);
            }

            $existsStmt = $conn->prepare("SELECT id FROM nguoidung WHERE LOWER(COALESCE(emailnguoidung, '')) = ? LIMIT 1");
            if ($existsStmt) {
                $existsStmt->bind_param('s', $emailLower);
                $existsStmt->execute();
                $existsResult = $existsStmt->get_result();
                $existsRow = $existsResult ? $existsResult->fetch_assoc() : null;
                if ($existsResult) {
                    $existsResult->free();
                }
                $existsStmt->close();

                if (is_array($existsRow)) {
                    $conn->close();
                    app_json_response([
                        'ok' => false,
                        'message' => 'Email da ton tai',
                    ], 409);
                }
            }
        }

        if ($purpose === 'reset_password') {
            $findStmt = $conn->prepare("SELECT id, vaitronguoidung FROM nguoidung WHERE LOWER(COALESCE(emailnguoidung, '')) = ? LIMIT 1");
            if (!$findStmt) {
                $conn->close();
                app_json_response([
                    'ok' => false,
                    'message' => 'Khong the kiem tra tai khoan',
                ], 500);
            }

            $findStmt->bind_param('s', $emailLower);
            $findStmt->execute();
            $findResult = $findStmt->get_result();
            $foundUser = $findResult ? $findResult->fetch_assoc() : null;
            if ($findResult) {
                $findResult->free();
            }
            $findStmt->close();

            if (!is_array($foundUser) || app_normalize_role((string) ($foundUser['vaitronguoidung'] ?? 'user')) !== 'user') {
                $conn->close();
                app_json_response([
                    'ok' => false,
                    'message' => 'Khong tim thay tai khoan user voi email nay',
                ], 404);
            }
        }

        $verifyResult = app_user_verify_email_otp($conn, $email, $purpose, $code, false);
        if (!$verifyResult['ok']) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => (string) ($verifyResult['message'] ?? 'Ma xac minh khong hop le'),
                'data' => [
                    'verified' => false,
                ],
            ], 400);
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'message' => 'Ma xac minh hop le',
            'data' => [
                'verified' => true,
            ],
        ]);
    }

    if ($api === 'reset_password_with_code') {
        if (!app_table_exists($conn, 'nguoidung')) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay bang nguoidung',
            ], 500);
        }

        $input = app_input_payload();
        $email = trim((string) ($input['email'] ?? ''));
        $code = trim((string) ($input['verification_code'] ?? $input['otp'] ?? ''));
        $newPassword = (string) ($input['new_password'] ?? $input['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Email khong hop le',
            ], 400);
        }

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Ma xac minh phai gom 6 chu so',
            ], 400);
        }

        if (strlen($newPassword) < 6) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Mat khau moi phai co it nhat 6 ky tu',
            ], 400);
        }

        $emailLower = app_lower($email);
        $findStmt = $conn->prepare("SELECT id, vaitronguoidung FROM nguoidung WHERE LOWER(COALESCE(emailnguoidung, '')) = ? LIMIT 1");
        if (!$findStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tim tai khoan',
            ], 500);
        }

        $findStmt->bind_param('s', $emailLower);
        $findStmt->execute();
        $findResult = $findStmt->get_result();
        $userRow = $findResult ? $findResult->fetch_assoc() : null;
        if ($findResult) {
            $findResult->free();
        }
        $findStmt->close();

        if (!is_array($userRow) || app_normalize_role((string) ($userRow['vaitronguoidung'] ?? 'user')) !== 'user') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay tai khoan user voi email nay',
            ], 404);
        }

        $verifyResult = app_user_verify_email_otp($conn, $email, 'reset_password', $code, true);
        if (!$verifyResult['ok']) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => (string) ($verifyResult['message'] ?? 'Ma xac minh khong hop le'),
            ], 400);
        }

        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $updateStmt = $conn->prepare('UPDATE nguoidung SET matkhaunguoidung = ? WHERE id = ? LIMIT 1');
        if (!$updateStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the cap nhat mat khau',
            ], 500);
        }

        $userId = (int) ($userRow['id'] ?? 0);
        $updateStmt->bind_param('si', $passwordHash, $userId);
        $ok = $updateStmt->execute();
        $affected = (int) $updateStmt->affected_rows;
        $updateStmt->close();

        if (!$ok || $affected < 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Cap nhat mat khau that bai',
            ], 500);
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'message' => 'Dat lai mat khau thanh cong',
        ]);
    }

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
        $verificationCode = trim((string) ($input['verification_code'] ?? $input['otp'] ?? ''));

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

        if (preg_match('/^\d{6}$/', $verificationCode) !== 1) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Vui long nhap ma xac minh 6 chu so',
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

        if ($phone !== '' && app_user_phone_exists($conn, $phone)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'So dien thoai da duoc dang ky',
            ], 409);
        }

        $verifyResult = app_user_verify_email_otp($conn, $email, 'register', $verificationCode, true);
        if (!$verifyResult['ok']) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => (string) ($verifyResult['message'] ?? 'Ma xac minh khong hop le'),
            ], 400);
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
            $customerId = app_find_or_create_customer_for_identity($conn, $newUserId, $email, $phone);
            if ($customerId > 0) {
                app_sync_customer_identity($conn, $customerId, $name, $email, $phone);
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

        $subcategoryMap = [];
        if (app_table_exists($conn, 'danhmuccon')) {
            $subResult = $conn->query("SELECT danhmuc_id, COALESCE(NULLIF(TRIM(tendanhmuccon), ''), '') AS tendanhmuccon FROM danhmuccon ORDER BY danhmuc_id ASC, tendanhmuccon ASC");
            if ($subResult) {
                while ($subRow = $subResult->fetch_assoc()) {
                    $categoryId = (int) ($subRow['danhmuc_id'] ?? 0);
                    $subName = (string) ($subRow['tendanhmuccon'] ?? '');
                    if ($categoryId <= 0 || $subName === '') {
                        continue;
                    }

                    if (!isset($subcategoryMap[$categoryId])) {
                        $subcategoryMap[$categoryId] = [];
                    }
                    $subcategoryMap[$categoryId][] = $subName;
                }
                $subResult->free();
            }
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
            $categoryId = (int) ($row['id'] ?? 0);
            $subcategories = $subcategoryMap[$categoryId] ?? [];
            $subcategories = array_values(array_unique(array_map(static function ($value) {
                return trim((string) $value);
            }, $subcategories)));

            $data[] = [
                'id' => $categoryId,
                'tendanhmuc' => (string) ($row['tendanhmuc'] ?? ''),
                'soluongsanpham' => (int) ($row['soluongsanpham'] ?? 0),
                'hinhanh' => app_to_public_image_url((string) ($row['hinhanh'] ?? '')),
                'danhmuccon' => $subcategories,
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
                        'danhmuccon' => [],
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
        $_SESSION['oauth_provider_active'] = $provider;

        $redirectUri = app_social_callback_url($provider);
        $authUrl = app_social_build_auth_url($provider, $providerConfig['client_id'], $redirectUri, $state);

        $conn->close();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: ' . $authUrl);
        exit;
    }

    if ($api === 'social_oauth_callback') {
        app_social_start_session();

        $provider = app_lower(trim((string) ($_GET['provider'] ?? '')));
        if ($provider === '') {
            $provider = app_lower(trim((string) ($_SESSION['oauth_provider_active'] ?? '')));
        }
        if (!in_array($provider, ['google', 'facebook'], true)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Provider khong hop le',
            ], 400);
        }

        $state = trim((string) ($_GET['state'] ?? ''));
        $code = trim((string) ($_GET['code'] ?? ''));
        $error = trim((string) ($_GET['error'] ?? ''));
        $savedState = (string) ($_SESSION['oauth_state_' . $provider] ?? '');
        $returnUrl = (string) ($_SESSION['oauth_return_' . $provider] ?? app_social_default_return_url());

        unset($_SESSION['oauth_state_' . $provider], $_SESSION['oauth_return_' . $provider], $_SESSION['oauth_provider_active']);

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

        if (isset($userPayload['pending']) && is_array($userPayload['pending'])) {
            app_social_start_session();
            $_SESSION['oauth_pending_profile'] = $userPayload['pending'];
            app_social_render_bridge(null, $returnUrl, '', $userPayload['pending']);
        }

        app_social_render_bridge($userPayload['user'], $returnUrl, '');
    }

    if ($api === 'get_social_pending_profile') {
        app_social_start_session();
        $pending = $_SESSION['oauth_pending_profile'] ?? null;
        if (!is_array($pending)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong co ho so social cho bo sung',
            ], 404);
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'data' => [
                'provider' => (string) ($pending['provider'] ?? ''),
                'email' => (string) ($pending['email'] ?? ''),
                'name' => (string) ($pending['name'] ?? ''),
                'avatar_url' => (string) ($pending['avatar_url'] ?? ''),
            ],
        ]);
    }

    if ($api === 'complete_social_signup') {
        if (!app_table_exists($conn, 'nguoidung')) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay bang nguoidung',
            ], 500);
        }

        app_social_start_session();
        $pending = $_SESSION['oauth_pending_profile'] ?? null;
        if (!is_array($pending)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong co du lieu social de hoan tat tai khoan',
            ], 400);
        }

        $input = app_input_payload();
        $username = trim((string) ($input['username'] ?? $input['name'] ?? ''));
        $displayName = trim((string) ($input['display_name'] ?? $pending['name'] ?? $username));
        $phone = app_digits_only((string) ($input['phone'] ?? $input['sodienthoai'] ?? ''));
        $avatarDataUrl = trim((string) ($input['avatar_data_url'] ?? ''));
        $email = trim((string) ($pending['email'] ?? ''));

        if ($username === '') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Vui long nhap ten tai khoan',
            ], 400);
        }

        if (strlen($username) < 3) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Ten tai khoan phai tu 3 ky tu tro len',
            ], 400);
        }

        if ($phone === '' || strlen($phone) < 9 || strlen($phone) > 11) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'So dien thoai khong hop le',
            ], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Email social khong hop le',
            ], 400);
        }

        if (app_contains_reserved_account_keyword($username) || app_contains_reserved_account_keyword($displayName) || app_contains_reserved_account_keyword($email)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Tai khoan admin/staff khong duoc tao o khu vuc user',
            ], 403);
        }

        $emailLower = app_lower($email);
        $usernameLower = app_lower($username);
        $existStmt = $conn->prepare("SELECT id FROM nguoidung WHERE LOWER(COALESCE(emailnguoidung, '')) = ? OR LOWER(COALESCE(tennguoidung, '')) = ? LIMIT 1");
        if (!$existStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the kiem tra du lieu tai khoan',
            ], 500);
        }

        $existStmt->bind_param('ss', $emailLower, $usernameLower);
        $existStmt->execute();
        $existResult = $existStmt->get_result();
        $exists = $existResult ? $existResult->fetch_assoc() : null;
        if ($existResult) {
            $existResult->free();
        }
        $existStmt->close();

        if (is_array($exists)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Email hoac ten tai khoan da ton tai',
            ], 409);
        }

        if (app_user_phone_exists($conn, $phone)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'So dien thoai da duoc dang ky',
            ], 409);
        }

        if (!app_column_exists($conn, 'nguoidung', 'sodienthoainguoidung')) {
            $conn->query("ALTER TABLE nguoidung ADD COLUMN sodienthoainguoidung VARCHAR(20) NULL AFTER emailnguoidung");
        }

        $passwordHash = password_hash(bin2hex(random_bytes(18)), PASSWORD_BCRYPT);
        $insertStmt = $conn->prepare("INSERT INTO nguoidung (tennguoidung, emailnguoidung, sodienthoainguoidung, matkhaunguoidung, vaitronguoidung) VALUES (?, ?, ?, ?, 'user')");
        if (!$insertStmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tao tai khoan social',
            ], 500);
        }

        $insertStmt->bind_param('ssss', $username, $email, $phone, $passwordHash);
        $okInsert = $insertStmt->execute();
        $newUserId = (int) $insertStmt->insert_id;
        $insertStmt->close();

        if (!$okInsert || $newUserId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Tao tai khoan social that bai',
            ], 500);
        }

        $avatarPath = '';
        if ($avatarDataUrl !== '') {
            [$savedOk, $relativePath] = app_user_save_avatar_data_url($avatarDataUrl);
            if ($savedOk && $relativePath !== '') {
                $avatarPath = $relativePath;
                $avatarStmt = $conn->prepare('UPDATE nguoidung SET anhdaidiennguoidung = ? WHERE id = ? LIMIT 1');
                if ($avatarStmt) {
                    $avatarStmt->bind_param('si', $avatarPath, $newUserId);
                    $avatarStmt->execute();
                    $avatarStmt->close();
                }
            }
        }

        if (app_table_exists($conn, 'khachhang')) {
            $customerId = app_find_or_create_customer_for_identity($conn, $newUserId, $email, $phone);
            if ($customerId > 0) {
                app_sync_customer_identity($conn, $customerId, $displayName !== '' ? $displayName : $username, $email, $phone);
            }
        }

        unset($_SESSION['oauth_pending_profile']);

        $conn->close();
        app_json_response([
            'ok' => true,
            'message' => 'Hoan tat ho so social thanh cong',
            'data' => [
                'id' => $newUserId,
                'tennguoidung' => $username,
                'emailnguoidung' => $email,
                'sodienthoainguoidung' => $phone,
                'vaitronguoidung' => 'user',
                'anhdaidiennguoidung' => $avatarPath,
                'anhdaidiennguoidung_url' => $avatarPath !== '' ? app_to_public_image_url($avatarPath) : '',
            ],
        ]);
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
            SELECT id, tennguoidung, emailnguoidung, matkhaunguoidung, vaitronguoidung, ngaytaonguoidung, anhdaidiennguoidung
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
        $avatarPath = trim((string) ($row['anhdaidiennguoidung'] ?? ''));
        $userPayload = [
            'id' => (int) ($row['id'] ?? 0),
            'tennguoidung' => (string) ($row['tennguoidung'] ?? ''),
            'emailnguoidung' => (string) ($row['emailnguoidung'] ?? ''),
            'vaitronguoidung' => $role,
            'ngaytaonguoidung' => (string) ($row['ngaytaonguoidung'] ?? ''),
            'anhdaidiennguoidung' => $avatarPath,
            'anhdaidiennguoidung_url' => $avatarPath !== '' ? app_to_public_image_url($avatarPath) : '',
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
                'avatar' => $userPayload['anhdaidiennguoidung'],
                'avatar_url' => $userPayload['anhdaidiennguoidung_url'],
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
        $paymentMethod = app_normalize_payment_method((string) ($input['payment_method'] ?? 'tien_mat'));
        $items = $input['items'] ?? [];
        $normalizedItems = app_prepare_order_items(is_array($items) ? $items : []);
        $accountUserId = (int) ($input['user_id'] ?? 0);
        $accountName = '';
        $accountEmail = '';
        $accountPhone = '';

        if ($accountUserId > 0) {
            $hasUserPhoneColumn = app_column_exists($conn, 'nguoidung', 'sodienthoainguoidung');
            $phoneFieldSql = $hasUserPhoneColumn ? 'sodienthoainguoidung' : "'' AS sodienthoainguoidung";
            $userCheckSql = "
                SELECT id, tennguoidung, emailnguoidung, {$phoneFieldSql}, vaitronguoidung
                FROM nguoidung
                WHERE id = ?
                LIMIT 1
            ";
            $userCheckStmt = $conn->prepare($userCheckSql);
            if (!$userCheckStmt) {
                $prepareError = $conn->error;
                $conn->close();
                app_json_response([
                    'ok' => false,
                    'message' => 'Khong the xac thuc tai khoan',
                    'error' => $prepareError,
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

            $accountName = trim((string) ($userRow['tennguoidung'] ?? ''));
            $accountEmail = trim((string) ($userRow['emailnguoidung'] ?? ''));
            $accountPhone = app_digits_only((string) ($userRow['sodienthoainguoidung'] ?? ''));

            if ($accountName !== '' && $customerName === '') {
                $customerName = $accountName;
            }
            if ($accountEmail !== '' && $customerEmail === '') {
                $customerEmail = $accountEmail;
            }
            if ($accountPhone !== '' && $customerPhone === '') {
                $customerPhone = $accountPhone;
            }
        }

        if ($customerName === '' || $customerPhone === '' || $address === '' || $total <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Thong tin dat hang online chua day du',
            ], 400);
        }

        if (!app_ensure_order_tables($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao bang donhang/donhang_chitiet',
                'error' => $conn->error,
            ], 500);
        }

        if (count($normalizedItems) === 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Don hang online chua co san pham hop le',
            ], 400);
        }

        $orderCode = app_generate_online_order_code();
        $itemsJson = json_encode(is_array($items) ? $items : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($itemsJson)) {
            $itemsJson = '[]';
        }

        $customerId = 0;
        if (app_table_exists($conn, 'khachhang')) {
            if ($accountUserId > 0) {
                $identityEmail = $accountEmail !== '' ? $accountEmail : $customerEmail;
                $identityPhone = $accountPhone;
                $identityName = $accountName !== '' ? $accountName : $customerName;
                $customerId = app_find_or_create_customer_for_identity($conn, $accountUserId, $identityEmail, $identityPhone);
                if ($customerId > 0) {
                    app_sync_customer_identity($conn, $customerId, $identityName, $identityEmail, $identityPhone);
                }
            } else {
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
        }

        $internalOrderId = 0;
        $newId = 0;

        $conn->begin_transaction();
        try {
            $orderStatus = app_map_online_to_order_status('cho_duyet');
            $insertDonHangSql = "
                INSERT INTO donhang
                    (khachhang_id, madonhang, ngaydatdonhang, tongtiendonhang, trangthaidonhang, nguondonhang, phuongthucthanhtoan, tennhanvien, ghichudonhang)
                VALUES
                    (NULLIF(?, 0), ?, NOW(), ?, ?, 'online', ?, 'Online', ?)
            ";
            $insertDonHangStmt = $conn->prepare($insertDonHangSql);
            if (!$insertDonHangStmt) {
                throw new RuntimeException('Khong the tao don hang tong');
            }

            $customerIdForOrder = $customerId > 0 ? $customerId : 0;
            $orderNote = $note !== '' ? $note : 'Don online cho duyet';
            $insertDonHangStmt->bind_param('isdsss', $customerIdForOrder, $orderCode, $total, $orderStatus, $paymentMethod, $orderNote);
            $insertDonHangStmt->execute();
            $internalOrderId = (int) $insertDonHangStmt->insert_id;
            $insertDonHangStmt->close();

            if ($internalOrderId <= 0) {
                throw new RuntimeException('Khong the tao don hang online');
            }

            $detailStmt = $conn->prepare('INSERT INTO donhang_chitiet (donhang_id, sanpham_id, masanpham, tensanpham, soluong, dongia, thanhtien) VALUES (?, ?, ?, ?, ?, ?, ?)');
            if (!$detailStmt) {
                throw new RuntimeException('Khong the luu chi tiet don hang');
            }

            foreach ($normalizedItems as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 0);
                $price = (float) ($item['price'] ?? 0);
                $lineTotal = $price * $qty;
                $code = (string) ($item['code'] ?? '');
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    $name = 'San pham #' . $productId;
                }

                $detailStmt->bind_param('iissidd', $internalOrderId, $productId, $code, $name, $qty, $price, $lineTotal);
                $detailStmt->execute();
            }
            $detailStmt->close();

            if (app_table_exists($conn, 'lichsudonhang')) {
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
                throw new RuntimeException('Khong the tao don online');
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

            if (!$ok || $newId <= 0) {
                throw new RuntimeException('Khong the luu don online');
            }

            if ($accountUserId > 0 || $customerId > 0) {
                app_user_reset_cart_for_account($conn, $accountUserId, $customerId);
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the luu don online',
                'error' => $e->getMessage(),
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

    if ($api === 'get_order_reviews') {
        if (!app_ensure_order_review_schema($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao bang danh gia',
                'error' => $conn->error,
            ], 500);
        }

        $input = app_input_payload();
        $userId = (int) ($_GET['user_id'] ?? $input['user_id'] ?? 0);
        $userEmail = trim((string) ($_GET['user_email'] ?? $input['user_email'] ?? ''));
        $userPhone = app_digits_only((string) ($_GET['user_phone'] ?? $input['user_phone'] ?? ''));

        $customerId = app_find_customer_id_for_identity($conn, $userId, $userEmail, $userPhone);
        if ($customerId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => true,
                'count' => 0,
                'data' => [],
            ]);
        }

                $sql = "
                        SELECT dg.madonhang, dg.sosao, dg.noidung, dg.ngaytao
                        FROM danhgiasanpham dg
                        INNER JOIN (
                                SELECT madonhang, MAX(id) AS max_id
                                FROM danhgiasanpham
                                WHERE khachhang_id = ?
                                    AND madonhang IS NOT NULL
                                    AND TRIM(madonhang) <> ''
                                GROUP BY madonhang
                        ) latest ON latest.max_id = dg.id
                        ORDER BY dg.ngaytao DESC, dg.id DESC
                        LIMIT 300
                ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the tai danh gia',
                'error' => $conn->error,
            ], 500);
        }

        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = [
                'order_id' => (string) ($row['madonhang'] ?? ''),
                'rating' => (int) ($row['sosao'] ?? 0),
                'comment' => (string) ($row['noidung'] ?? ''),
                'created_at' => (string) ($row['ngaytao'] ?? ''),
            ];
        }

        if ($result) {
            $result->free();
        }
        $stmt->close();

        $conn->close();
        app_json_response([
            'ok' => true,
            'count' => count($rows),
            'data' => $rows,
        ]);
    }

    if ($api === 'save_order_review') {
        if (!app_ensure_order_review_schema($conn)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the khoi tao bang danh gia',
                'error' => $conn->error,
            ], 500);
        }

        $input = app_input_payload();
        $orderCode = trim((string) ($input['order_id'] ?? ''));
        $rating = (int) ($input['rating'] ?? 0);
        $comment = trim((string) ($input['comment'] ?? ''));
        $userId = (int) ($input['user_id'] ?? 0);
        $userEmail = trim((string) ($input['user_email'] ?? ''));
        $userPhone = app_digits_only((string) ($input['user_phone'] ?? ''));

        if ($orderCode === '') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Thieu ma don hang',
            ], 400);
        }

        if ($rating < 1 || $rating > 5) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'So sao phai tu 1 den 5',
            ], 400);
        }

        if ($comment === '') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Vui long nhap noi dung danh gia',
            ], 400);
        }

        $customerId = app_find_or_create_customer_for_identity($conn, $userId, $userEmail, $userPhone);
        if ($customerId <= 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong xac dinh duoc khach hang',
            ], 403);
        }

        $ownedOrder = app_find_online_order_for_review($conn, $orderCode, $userEmail, $userPhone);
        if (!is_array($ownedOrder)) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay don hang hop le de danh gia',
            ], 404);
        }

        $orderStatus = app_lower(trim((string) ($ownedOrder['trangthai'] ?? '')));
        if ($orderStatus !== 'da_duyet') {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Chi duoc danh gia don da hoan thanh',
            ], 400);
        }

        $orderOnlineId = (int) ($ownedOrder['id'] ?? 0);
        $productIds = app_find_online_order_product_ids_for_review($conn, $ownedOrder);
        if (count($productIds) === 0) {
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong tim thay san pham hop le trong don hang de danh gia',
            ], 400);
        }

        $conn->begin_transaction();
        try {
            $cleanupSql = "
                DELETE FROM danhgiasanpham
                WHERE khachhang_id = ?
                  AND madonhang = ?
                  AND sanpham_id IS NULL
            ";
            $cleanupStmt = $conn->prepare($cleanupSql);
            if (!$cleanupStmt) {
                throw new RuntimeException('Khong the don du lieu danh gia cu');
            }
            $cleanupStmt->bind_param('is', $customerId, $orderCode);
            $cleanupStmt->execute();
            $cleanupStmt->close();

            $checkSql = "
                SELECT id
                FROM danhgiasanpham
                WHERE khachhang_id = ?
                  AND madonhang = ?
                  AND sanpham_id = ?
                LIMIT 1
            ";
            $checkStmt = $conn->prepare($checkSql);
            if (!$checkStmt) {
                throw new RuntimeException('Khong the kiem tra danh gia hien tai');
            }

            $updateSql = "
                UPDATE danhgiasanpham
                SET sosao = ?,
                    noidung = ?,
                    trangthai = 'approved',
                    donhang_online_id = NULLIF(?, 0),
                    ngaytao = NOW()
                WHERE id = ?
                LIMIT 1
            ";
            $updateStmt = $conn->prepare($updateSql);
            if (!$updateStmt) {
                $checkStmt->close();
                throw new RuntimeException('Khong the cap nhat danh gia');
            }

            $insertSql = "
                INSERT INTO danhgiasanpham
                    (sanpham_id, donhang_online_id, madonhang, khachhang_id, sosao, noidung, trangthai)
                VALUES
                    (?, NULLIF(?, 0), ?, ?, ?, ?, 'approved')
            ";
            $insertStmt = $conn->prepare($insertSql);
            if (!$insertStmt) {
                $checkStmt->close();
                $updateStmt->close();
                throw new RuntimeException('Khong the tao danh gia');
            }

            foreach ($productIds as $productId) {
                $checkStmt->bind_param('isi', $customerId, $orderCode, $productId);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $existing = $checkResult ? $checkResult->fetch_assoc() : null;
                if ($checkResult) {
                    $checkResult->free();
                }

                if (is_array($existing)) {
                    $reviewId = (int) ($existing['id'] ?? 0);
                    $updateStmt->bind_param('isii', $rating, $comment, $orderOnlineId, $reviewId);
                    if (!$updateStmt->execute()) {
                        throw new RuntimeException('Cap nhat danh gia that bai');
                    }
                } else {
                    $insertStmt->bind_param('iisiis', $productId, $orderOnlineId, $orderCode, $customerId, $rating, $comment);
                    if (!$insertStmt->execute()) {
                        throw new RuntimeException('Luu danh gia that bai');
                    }
                }
            }

            $checkStmt->close();
            $updateStmt->close();
            $insertStmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            $conn->close();
            app_json_response([
                'ok' => false,
                'message' => 'Khong the luu danh gia don hang',
                'error' => $e->getMessage(),
            ], 500);
        }

        $conn->close();
        app_json_response([
            'ok' => true,
            'message' => 'Da luu danh gia don hang',
            'data' => [
                'order_id' => $orderCode,
                'product_count' => count($productIds),
                'rating' => $rating,
                'comment' => $comment,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    return false;
}

function app_digits_only(string $value): string
{
    return preg_replace('/[^0-9]/', '', $value) ?? '';
}

function app_user_phone_exists(mysqli $conn, string $phone, int $excludeUserId = 0): bool
{
    $digits = app_digits_only($phone);
    if ($digits === '') {
        return false;
    }

    if (!app_column_exists($conn, 'nguoidung', 'sodienthoainguoidung')) {
        return false;
    }

    $sql = "
        SELECT id
        FROM nguoidung
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(sodienthoainguoidung, ''), ' ', ''), '.', ''), '-', ''), '+', '') = ?
    ";

    if ($excludeUserId > 0) {
        $sql .= ' AND id <> ?';
    }

    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    if ($excludeUserId > 0) {
        $stmt->bind_param('si', $digits, $excludeUserId);
    } else {
        $stmt->bind_param('s', $digits);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    return is_array($row);
}

function app_index_exists(mysqli $conn, string $table, string $indexName): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeIndex = $conn->real_escape_string($indexName);
    $sql = "SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'";
    $result = $conn->query($sql);
    if (!$result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function app_ensure_order_review_schema(mysqli $conn): bool
{
    if (!app_table_exists($conn, 'danhgiasanpham')) {
        $createSql = "
            CREATE TABLE danhgiasanpham (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                sanpham_id INT NULL,
                donhang_online_id INT NULL,
                madonhang VARCHAR(50) NULL,
                khachhang_id INT NOT NULL,
                sosao TINYINT NOT NULL,
                noidung TEXT NULL,
                trangthai ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
                ngaytao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_danhgiasanpham_khachhang (khachhang_id),
                KEY idx_danhgiasanpham_madonhang (madonhang),
                UNIQUE KEY uq_danhgia_order_customer_product (khachhang_id, madonhang, sanpham_id),
                CONSTRAINT fk_danhgiasanpham_khachhang FOREIGN KEY (khachhang_id)
                    REFERENCES khachhang(id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT danhgiasanpham_chk_1 CHECK (sosao BETWEEN 1 AND 5)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        return (bool) $conn->query($createSql);
    }

    if (app_column_exists($conn, 'danhgiasanpham', 'sanpham_id')) {
        if (!$conn->query('ALTER TABLE danhgiasanpham MODIFY COLUMN sanpham_id INT NULL')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'danhgiasanpham', 'donhang_online_id')) {
        if (!$conn->query('ALTER TABLE danhgiasanpham ADD COLUMN donhang_online_id INT NULL AFTER sanpham_id')) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'danhgiasanpham', 'madonhang')) {
        if (!$conn->query('ALTER TABLE danhgiasanpham ADD COLUMN madonhang VARCHAR(50) NULL AFTER donhang_online_id')) {
            return false;
        }
    }

    if (!app_index_exists($conn, 'danhgiasanpham', 'idx_danhgiasanpham_madonhang')) {
        if (!$conn->query('ALTER TABLE danhgiasanpham ADD INDEX idx_danhgiasanpham_madonhang (madonhang)')) {
            return false;
        }
    }

    if (app_index_exists($conn, 'danhgiasanpham', 'uq_danhgia_order_customer')) {
        if (!$conn->query('ALTER TABLE danhgiasanpham DROP INDEX uq_danhgia_order_customer')) {
            return false;
        }
    }

    if (!app_index_exists($conn, 'danhgiasanpham', 'uq_danhgia_order_customer_product')) {
        if (!$conn->query('ALTER TABLE danhgiasanpham ADD UNIQUE KEY uq_danhgia_order_customer_product (khachhang_id, madonhang, sanpham_id)')) {
            return false;
        }
    }

    return true;
}

function app_find_customer_id_for_identity(mysqli $conn, int $userId, string $userEmail, string $userPhone): int
{
    if (!app_table_exists($conn, 'khachhang')) {
        return 0;
    }

    $email = trim($userEmail);
    $phone = app_digits_only($userPhone);
    $accountName = '';

    if ($userId > 0 && app_table_exists($conn, 'nguoidung')) {
        $userSql = 'SELECT tennguoidung, emailnguoidung FROM nguoidung WHERE id = ? LIMIT 1';
        $userStmt = $conn->prepare($userSql);
        if ($userStmt) {
            $userStmt->bind_param('i', $userId);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            $userRow = $userResult ? $userResult->fetch_assoc() : null;
            if ($userResult) {
                $userResult->free();
            }
            $userStmt->close();
            if (is_array($userRow)) {
                $accountName = trim((string) ($userRow['tennguoidung'] ?? ''));
                if ($email === '') {
                    $email = trim((string) ($userRow['emailnguoidung'] ?? ''));
                }
            }
        }
    }

    if ($email === '' && $phone === '' && $accountName === '') {
        return 0;
    }

    $sql = "
        SELECT id
        FROM khachhang
        WHERE ((emailkhachhang IS NOT NULL AND LOWER(emailkhachhang) = LOWER(?))
            OR (REPLACE(REPLACE(REPLACE(COALESCE(sodienthoaikhachhang, ''), ' ', ''), '.', ''), '-', '') = ?)
            OR (? <> '' AND LOWER(COALESCE(tenkhachhang, '')) = LOWER(?)))
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('ssss', $email, $phone, $accountName, $accountName);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
}

function app_find_or_create_customer_for_identity(mysqli $conn, int $userId, string $userEmail, string $userPhone): int
{
    $existingId = app_find_customer_id_for_identity($conn, $userId, $userEmail, $userPhone);
    if ($existingId > 0) {
        return $existingId;
    }

    if (!app_table_exists($conn, 'khachhang')) {
        return 0;
    }

    $name = 'Khach hang';
    if ($userId > 0 && app_table_exists($conn, 'nguoidung')) {
        $userSql = 'SELECT tennguoidung FROM nguoidung WHERE id = ? LIMIT 1';
        $userStmt = $conn->prepare($userSql);
        if ($userStmt) {
            $userStmt->bind_param('i', $userId);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            $userRow = $userResult ? $userResult->fetch_assoc() : null;
            if ($userResult) {
                $userResult->free();
            }
            $userStmt->close();
            if (is_array($userRow)) {
                $name = trim((string) ($userRow['tennguoidung'] ?? '')) ?: $name;
            }
        }
    }

    $email = trim($userEmail);
    $phone = app_digits_only($userPhone);
    if ($email === '' && $phone === '') {
        return 0;
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
    $insertStmt->bind_param('sss', $name, $phone, $email);
    $ok = $insertStmt->execute();
    $newId = (int) $insertStmt->insert_id;
    $insertStmt->close();

    if (!$ok || $newId <= 0) {
        return app_find_customer_id_for_identity($conn, $userId, $userEmail, $userPhone);
    }

    return $newId;
}

function app_sync_customer_identity(mysqli $conn, int $customerId, string $name, string $email, string $phone): bool
{
    if ($customerId <= 0 || !app_table_exists($conn, 'khachhang')) {
        return false;
    }

    $cleanName = trim($name);
    $cleanEmail = trim($email);
    $cleanPhone = app_digits_only($phone);

    $updateSql = "
        UPDATE khachhang
        SET tenkhachhang = CASE
                WHEN ? <> '' AND COALESCE(NULLIF(TRIM(tenkhachhang), ''), '') = '' THEN ?
                ELSE tenkhachhang
            END,
            emailkhachhang = CASE
                WHEN ? <> '' AND COALESCE(NULLIF(TRIM(emailkhachhang), ''), '') = '' THEN ?
                ELSE emailkhachhang
            END,
            sodienthoaikhachhang = CASE
                WHEN ? <> '' AND COALESCE(NULLIF(TRIM(sodienthoaikhachhang), ''), '') = '' THEN ?
                ELSE sodienthoaikhachhang
            END
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($updateSql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ssssssi', $cleanName, $cleanName, $cleanEmail, $cleanEmail, $cleanPhone, $cleanPhone, $customerId);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool) $ok;
}

function app_increment_customer_spending(mysqli $conn, int $customerId, float $amount): bool
{
    if ($customerId <= 0 || $amount <= 0 || !app_table_exists($conn, 'khachhang')) {
        return false;
    }

    $updateSql = "
        UPDATE khachhang
        SET tongchitieukhachhang = COALESCE(tongchitieukhachhang, 0) + ?
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($updateSql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('di', $amount, $customerId);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool) $ok;
}

function app_find_online_order_for_review(mysqli $conn, string $orderCode, string $userEmail, string $userPhone): ?array
{
    if (!app_table_exists($conn, 'donhang_online')) {
        return null;
    }

    $sql = "
        SELECT id, donhang_id, madonhang, email, sodienthoai, trangthai, chitiet_json
        FROM donhang_online
        WHERE madonhang = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $orderCode);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $orderEmail = app_lower(trim((string) ($row['email'] ?? '')));
    $orderPhone = app_digits_only((string) ($row['sodienthoai'] ?? ''));
    $identityEmail = app_lower(trim($userEmail));
    $identityPhone = app_digits_only($userPhone);

    $matchedByEmail = ($identityEmail !== '' && $orderEmail !== '' && hash_equals($orderEmail, $identityEmail));
    $matchedByPhone = ($identityPhone !== '' && $orderPhone !== '' && hash_equals($orderPhone, $identityPhone));

    if (!$matchedByEmail && !$matchedByPhone) {
        return null;
    }

    return $row;
}

function app_find_online_order_product_ids_for_review(mysqli $conn, array $onlineOrder): array
{
    $productIds = [];
    $productNames = [];

    $donhangId = (int) ($onlineOrder['donhang_id'] ?? 0);
    if ($donhangId > 0 && app_table_exists($conn, 'donhang_chitiet')) {
        $sql = "
            SELECT DISTINCT sanpham_id
            FROM donhang_chitiet
            WHERE donhang_id = ?
              AND sanpham_id IS NOT NULL
              AND sanpham_id > 0
        ";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $donhangId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $productId = (int) ($row['sanpham_id'] ?? 0);
                if ($productId > 0) {
                    $productIds[] = $productId;
                }
            }
            if ($result) {
                $result->free();
            }
            $stmt->close();
        }

        if (count($productIds) === 0) {
            $nameSql = "
                SELECT DISTINCT tensanpham
                FROM donhang_chitiet
                WHERE donhang_id = ?
                  AND TRIM(COALESCE(tensanpham, '')) <> ''
            ";
            $nameStmt = $conn->prepare($nameSql);
            if ($nameStmt) {
                $nameStmt->bind_param('i', $donhangId);
                $nameStmt->execute();
                $nameResult = $nameStmt->get_result();
                while ($nameResult && ($nameRow = $nameResult->fetch_assoc())) {
                    $name = trim((string) ($nameRow['tensanpham'] ?? ''));
                    if ($name !== '') {
                        $productNames[] = $name;
                    }
                }
                if ($nameResult) {
                    $nameResult->free();
                }
                $nameStmt->close();
            }
        }
    }

    if (count($productIds) === 0) {
        $rawItems = (string) ($onlineOrder['chitiet_json'] ?? '[]');
        $decodedItems = json_decode($rawItems, true);
        if (is_array($decodedItems)) {
            foreach ($decodedItems as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);
                if ($productId > 0) {
                    $productIds[] = $productId;
                    continue;
                }

                $name = trim((string) ($item['name'] ?? ''));
                if ($name !== '') {
                    $productNames[] = $name;
                }
            }
        }
    }

    if (count($productIds) === 0 && count($productNames) > 0 && app_table_exists($conn, 'sanpham')) {
        $normalizedNames = array_values(array_unique(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $productNames), static function ($value): bool {
            return $value !== '';
        })));

        foreach ($normalizedNames as $name) {
            $sql = "
                SELECT MAX(id) AS id
                FROM sanpham
                WHERE LOWER(TRIM(tensanpham)) = LOWER(TRIM(?))
                LIMIT 1
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ($result) {
                $result->free();
            }
            $stmt->close();

            $productId = (int) ($row['id'] ?? 0);
            if ($productId > 0) {
                $productIds[] = $productId;
            }
        }
    }

    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static function ($value): bool {
        return $value > 0;
    })));

    return $productIds;
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
    $provider = app_lower(trim($provider));
    if ($provider === 'google') {
        $configured = trim(app_env_value('GOOGLE_OAUTH_REDIRECT_URI', ''));
        if ($configured !== '') {
            return $configured;
        }
    }

    if ($provider === 'facebook') {
        $configured = trim(app_env_value('FACEBOOK_OAUTH_REDIRECT_URI', ''));
        if ($configured !== '') {
            return $configured;
        }
    }

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
        'scope' => 'public_profile',
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
    $findSql = "SELECT id, tennguoidung, emailnguoidung, vaitronguoidung, ngaytaonguoidung, anhdaidiennguoidung FROM nguoidung WHERE LOWER(COALESCE(emailnguoidung, '')) = ? LIMIT 1";
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
            'anhdaidiennguoidung' => (string) ($existing['anhdaidiennguoidung'] ?? ''),
        ];

        if (app_table_exists($conn, 'khachhang')) {
            $customerId = app_find_or_create_customer_for_identity($conn, (int) $user['id'], (string) $user['emailnguoidung'], '');
            if ($customerId > 0) {
                app_sync_customer_identity($conn, $customerId, (string) $user['tennguoidung'], (string) $user['emailnguoidung'], '');
            }
        }

        return ['ok' => true, 'user' => $user];
    }

    return [
        'ok' => true,
        'pending' => [
            'provider' => $provider,
            'provider_id' => $providerId,
            'email' => $email,
            'name' => $name,
            'avatar_url' => trim((string) ($profile['picture'] ?? '')),
        ],
    ];
}

function app_social_render_bridge(?array $user, string $returnUrl, string $errorMessage, ?array $pendingSocial = null): void
{
    $safeReturnUrl = app_social_sanitize_return_url($returnUrl);
    $userJson = json_encode($user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pendingJson = json_encode($pendingSocial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $returnJson = json_encode($safeReturnUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $errorJson = json_encode($errorMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Đang hoàn tất đăng nhập...</title></head><body>';
    echo '<script>(function(){var user=' . $userJson . ';var pendingSocial=' . $pendingJson . ';var returnUrl=' . $returnJson . ';var error=' . $errorJson . ';if(user&&user.id){var avatarPath=String(user.anhdaidiennguoidung||"").trim();var avatarUrl=avatarPath!==""?String((window.location.origin||"")+"/phuocthanh/PHPCHINH/"+avatarPath).replace(/([^:]\/)\/+?/g,"$1"):"";var sessionPayload={id:Number(user.id||0),role:"user",fullName:user.tennguoidung||"Khach hang",email:user.emailnguoidung||"",identifier:user.emailnguoidung||user.tennguoidung||"",avatar:avatarUrl,avatar_path:avatarPath,createdAt:new Date().toISOString()};try{sessionStorage.setItem("authUser",JSON.stringify(sessionPayload));sessionStorage.setItem("customerPortalAuth","1");}catch(e){};try{localStorage.setItem("userSession",JSON.stringify(sessionPayload));localStorage.setItem("authUser",JSON.stringify(sessionPayload));localStorage.setItem("customerPortalAuth","1");}catch(e){};try{var oldProfileRaw=localStorage.getItem("userProfile");var oldProfile=oldProfileRaw?JSON.parse(oldProfileRaw):{};var nextProfile=Object.assign({},(oldProfile&&typeof oldProfile==="object")?oldProfile:{},{name:sessionPayload.fullName,email:sessionPayload.email,role:"user"});if(avatarUrl){nextProfile.avatar=avatarUrl;}localStorage.setItem("userProfile",JSON.stringify(nextProfile));}catch(e){};try{localStorage.setItem("isLoggedIn","true");}catch(e){}}if(pendingSocial&&pendingSocial.email){try{localStorage.setItem("pendingSocialSetupV1",JSON.stringify({required:true,email:String(pendingSocial.email||""),provider:String(pendingSocial.provider||""),createdAt:new Date().toISOString()}));}catch(e){}}if(error){try{localStorage.setItem("pendingAuthPromptV2",JSON.stringify({error:error,createdAt:new Date().toISOString()}));}catch(e){}}window.location.replace(returnUrl);})();</script>';
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
