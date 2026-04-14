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
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false) {
        $fallback = [
            'ok' => false,
            'message' => 'Phan hoi JSON khong hop le',
        ];
        $encoded = json_encode($fallback, JSON_UNESCAPED_UNICODE);
    }
    echo $encoded;
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

function app_mail_read_reply($socket): string
{
    $reply = '';
    $guard = 0;
    while (!feof($socket) && $guard < 30) {
        $line = fgets($socket, 1024);
        if ($line === false) {
            break;
        }
        $reply .= $line;
        $guard++;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return trim($reply);
}

function app_mail_expect_code($socket, array $acceptedCodes, string &$replyOut = ''): bool
{
    $reply = app_mail_read_reply($socket);
    $replyOut = $reply;
    if ($reply === '' || strlen($reply) < 3) {
        return false;
    }

    $code = (int) substr($reply, 0, 3);
    return in_array($code, $acceptedCodes, true);
}

function app_mail_send_line($socket, string $command): bool
{
    $written = @fwrite($socket, $command . "\r\n");
    return is_int($written) && $written > 0;
}

function app_mail_encode_header(string $text): string
{
    $value = trim($text);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^[\x20-\x7E]+$/', $value) === 1) {
        return $value;
    }

    if (function_exists('mb_encode_mimeheader')) {
        $encoded = @mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        if (is_string($encoded) && $encoded !== '') {
            return $encoded;
        }
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function app_send_email_smtp(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): array
{
    $smtpHost = app_env_value('SMTP_HOST');
    $smtpPort = app_env_int('SMTP_PORT', 587);
    $smtpUser = app_env_value('SMTP_USERNAME');
    $smtpPass = app_env_value('SMTP_PASSWORD');
    $smtpSecure = app_lower(app_env_value('SMTP_SECURE', 'tls'));
    $smtpTimeout = app_env_int('SMTP_TIMEOUT', 15);

    $fromEmail = app_env_value('SMTP_FROM_EMAIL', $smtpUser);
    $fromName = app_env_value('SMTP_FROM_NAME', '3 CHU CUN CON');

    if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '' || $fromEmail === '') {
        return [false, 'Chua cau hinh SMTP_HOST/SMTP_USERNAME/SMTP_PASSWORD/SMTP_FROM_EMAIL'];
    }

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Email nguoi nhan khong hop le'];
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, 'SMTP_FROM_EMAIL khong hop le'];
    }

    $remote = $smtpHost . ':' . $smtpPort;
    if ($smtpSecure === 'ssl') {
        $remote = 'ssl://' . $remote;
    }

    $errorNo = 0;
    $errorText = '';
    $socket = @stream_socket_client($remote, $errorNo, $errorText, max(5, $smtpTimeout));
    if (!$socket) {
        return [false, 'Khong ket noi duoc SMTP: ' . $errorText];
    }

    stream_set_timeout($socket, max(5, $smtpTimeout));
    try {
        $reply = '';
        if (!app_mail_expect_code($socket, [220], $reply)) {
            throw new RuntimeException('SMTP khong san sang: ' . $reply);
        }

        if (!app_mail_send_line($socket, 'EHLO localhost') || !app_mail_expect_code($socket, [250], $reply)) {
            throw new RuntimeException('EHLO that bai: ' . $reply);
        }

        if ($smtpSecure === 'tls') {
            if (!app_mail_send_line($socket, 'STARTTLS') || !app_mail_expect_code($socket, [220], $reply)) {
                throw new RuntimeException('STARTTLS that bai: ' . $reply);
            }

            $cryptoOk = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoOk !== true) {
                throw new RuntimeException('Khong the bat ma hoa TLS cho SMTP');
            }

            if (!app_mail_send_line($socket, 'EHLO localhost') || !app_mail_expect_code($socket, [250], $reply)) {
                throw new RuntimeException('EHLO sau STARTTLS that bai: ' . $reply);
            }
        }

        if (!app_mail_send_line($socket, 'AUTH LOGIN') || !app_mail_expect_code($socket, [334], $reply)) {
            throw new RuntimeException('AUTH LOGIN that bai: ' . $reply);
        }

        if (!app_mail_send_line($socket, base64_encode($smtpUser)) || !app_mail_expect_code($socket, [334], $reply)) {
            throw new RuntimeException('SMTP username khong hop le: ' . $reply);
        }

        if (!app_mail_send_line($socket, base64_encode($smtpPass)) || !app_mail_expect_code($socket, [235], $reply)) {
            throw new RuntimeException('SMTP password khong hop le: ' . $reply);
        }

        if (!app_mail_send_line($socket, 'MAIL FROM:<' . $fromEmail . '>') || !app_mail_expect_code($socket, [250], $reply)) {
            throw new RuntimeException('MAIL FROM that bai: ' . $reply);
        }

        if (!app_mail_send_line($socket, 'RCPT TO:<' . $toEmail . '>') || !app_mail_expect_code($socket, [250, 251], $reply)) {
            throw new RuntimeException('RCPT TO that bai: ' . $reply);
        }

        if (!app_mail_send_line($socket, 'DATA') || !app_mail_expect_code($socket, [354], $reply)) {
            throw new RuntimeException('DATA that bai: ' . $reply);
        }

        $encodedFromName = app_mail_encode_header($fromName);
        $encodedSubject = app_mail_encode_header($subject);
        $boundary = 'b' . bin2hex(random_bytes(8));
        $plain = trim($textBody) !== '' ? trim($textBody) : trim(strip_tags($htmlBody));
        if ($plain === '') {
            $plain = 'Ma xac minh cua ban da duoc tao.';
        }

        $message = '';
        $message .= 'From: ' . ($encodedFromName !== '' ? $encodedFromName . ' ' : '') . '<' . $fromEmail . ">\r\n";
        $message .= 'To: <' . $toEmail . ">\r\n";
        $message .= 'Subject: ' . $encodedSubject . "\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";
        $message .= "\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($plain), 76, "\r\n");
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($htmlBody), 76, "\r\n");
        $message .= '--' . $boundary . "--\r\n";

        $written = @fwrite($socket, $message . "\r\n.\r\n");
        if (!is_int($written) || $written <= 0 || !app_mail_expect_code($socket, [250], $reply)) {
            throw new RuntimeException('Gui noi dung email that bai: ' . $reply);
        }

        app_mail_send_line($socket, 'QUIT');
        fclose($socket);
        return [true, ''];
    } catch (Throwable $e) {
        @fclose($socket);
        return [false, $e->getMessage()];
    }
}

function app_send_email(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): array
{
    $smtpHost = app_env_value('SMTP_HOST');
    if ($smtpHost !== '') {
        return app_send_email_smtp($toEmail, $subject, $htmlBody, $textBody);
    }

    $fromEmail = app_env_value('SMTP_FROM_EMAIL', app_env_value('SMTP_USERNAME'));
    $fromName = app_env_value('SMTP_FROM_NAME', '3 CHU CUN CON');
    $plain = trim($textBody) !== '' ? trim($textBody) : trim(strip_tags($htmlBody));
    if ($plain === '') {
        $plain = 'Ma xac minh cua ban da duoc tao.';
    }

    $encodedSubject = app_mail_encode_header($subject);
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'From: ' . app_mail_encode_header($fromName) . ' <' . $fromEmail . '>';
    }

    $ok = @mail($toEmail, $encodedSubject, $htmlBody, implode("\r\n", $headers));
    if ($ok) {
        return [true, ''];
    }

    return [false, 'Khong gui duoc email. Vui long cau hinh SMTP trong oauth-config.php'];
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

function app_ensure_product_discount_columns(mysqli $conn): bool
{
    if (!app_table_exists($conn, 'sanpham')) {
        return false;
    }

    $ok = true;

    if (!app_column_exists($conn, 'sanpham', 'phantramgiamgia')) {
        $ok = (bool) $conn->query("ALTER TABLE sanpham ADD COLUMN phantramgiamgia DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER giasanpham") && $ok;
    }

    if (!app_column_exists($conn, 'sanpham', 'thoigianbatdaugiam')) {
        $ok = (bool) $conn->query("ALTER TABLE sanpham ADD COLUMN thoigianbatdaugiam DATETIME NULL AFTER phantramgiamgia") && $ok;
    }

    if (!app_column_exists($conn, 'sanpham', 'thoigianketthucgiam')) {
        $ok = (bool) $conn->query("ALTER TABLE sanpham ADD COLUMN thoigianketthucgiam DATETIME NULL AFTER thoigianbatdaugiam") && $ok;
    }

    return $ok;
}

function app_ensure_product_info_column(mysqli $conn): bool
{
    if (!app_table_exists($conn, 'sanpham')) {
        return false;
    }

    if (app_column_exists($conn, 'sanpham', 'thongtin')) {
        return true;
    }

    return (bool) $conn->query("ALTER TABLE sanpham ADD COLUMN thongtin TEXT NULL AFTER hinhanhsanpham");
}

function app_ensure_user_avatar_column(mysqli $conn): bool
{
    if (!app_table_exists($conn, 'nguoidung')) {
        return false;
    }

    if (app_column_exists($conn, 'nguoidung', 'anhdaidiennguoidung')) {
        return true;
    }

    return (bool) $conn->query("ALTER TABLE nguoidung ADD COLUMN anhdaidiennguoidung VARCHAR(255) NULL AFTER emailnguoidung");
}

function app_ensure_voucher_tables(mysqli $conn): bool
{
    $ok = true;

    $ok = (bool) $conn->query("\n        CREATE TABLE IF NOT EXISTS magiamgia (\n            id INT NOT NULL AUTO_INCREMENT,\n            magiamgia VARCHAR(50) NOT NULL,\n            mota VARCHAR(255) DEFAULT NULL,\n            loaigiamgia ENUM('percent','fixed') NOT NULL DEFAULT 'percent',\n            giatri DECIMAL(12,2) NOT NULL,\n            giatridonhangtoithieu DECIMAL(12,2) NOT NULL DEFAULT 0,\n            ngaybatdau DATETIME DEFAULT NULL,\n            ngayketthuc DATETIME DEFAULT NULL,\n            soluong INT NOT NULL DEFAULT 0,\n            toida_sudung_moikhach INT NOT NULL DEFAULT 1,\n            trangthai ENUM('active','inactive','expired') NOT NULL DEFAULT 'active',\n            ngaytao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,\n            PRIMARY KEY (id),\n            UNIQUE KEY uk_magiamgia_code (magiamgia)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ") && $ok;

    if (!app_column_exists($conn, 'magiamgia', 'minigame_key')) {
        $ok = (bool) $conn->query("ALTER TABLE magiamgia ADD COLUMN minigame_key VARCHAR(64) NOT NULL DEFAULT 'quick_click' AFTER trangthai") && $ok;
    }

    if (!app_column_exists($conn, 'magiamgia', 'minigame_level')) {
        $ok = (bool) $conn->query("ALTER TABLE magiamgia ADD COLUMN minigame_level VARCHAR(16) NOT NULL DEFAULT 'easy' AFTER minigame_key") && $ok;
    }

    $ok = (bool) $conn->query("\n        CREATE TABLE IF NOT EXISTS magiamgia_nguoidung (\n            id INT NOT NULL AUTO_INCREMENT,\n            magiamgia_id INT NOT NULL,\n            nguoidung_id INT NOT NULL,\n            soluong_danhan INT NOT NULL DEFAULT 1,\n            diemgame_cao_nhat INT NOT NULL DEFAULT 0,\n            ngaynhan DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            ngaycapnhat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            PRIMARY KEY (id),\n            UNIQUE KEY uk_magiamgia_nguoidung (magiamgia_id, nguoidung_id),\n            KEY idx_magiamgia_nguoidung_user (nguoidung_id),\n            CONSTRAINT fk_magiamgia_nguoidung_magiamgia\n                FOREIGN KEY (magiamgia_id) REFERENCES magiamgia (id)\n                ON DELETE CASCADE ON UPDATE CASCADE,\n            CONSTRAINT fk_magiamgia_nguoidung_nguoidung\n                FOREIGN KEY (nguoidung_id) REFERENCES nguoidung (id)\n                ON DELETE CASCADE ON UPDATE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ") && $ok;

    return $ok;
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

function app_generate_order_code(string $prefix = 'DH'): string
{
    $safePrefix = strtoupper(trim($prefix)) !== '' ? strtoupper(trim($prefix)) : 'DH';
    try {
        $suffix = strtoupper(bin2hex(random_bytes(2)));
    } catch (Throwable $e) {
        $suffix = strtoupper(substr(md5((string) microtime(true)), 0, 4));
    }

    return $safePrefix . date('YmdHis') . $suffix;
}

function app_ensure_order_tables(mysqli $conn): bool
{
    $createOrderSql = "
        CREATE TABLE IF NOT EXISTS donhang (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            khachhang_id INT NULL,
            madonhang VARCHAR(40) NULL,
            ngaydatdonhang DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            tongtiendonhang DECIMAL(14,2) NOT NULL DEFAULT 0,
            trangthaidonhang VARCHAR(40) NOT NULL DEFAULT 'dangxuly',
            nguondonhang VARCHAR(30) NOT NULL DEFAULT 'tai_quay',
            phuongthucthanhtoan VARCHAR(30) NOT NULL DEFAULT 'tien_mat',
            tennhanvien VARCHAR(120) NULL,
            ghichudonhang TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_donhang_ma (madonhang),
            KEY idx_donhang_status (trangthaidonhang),
            KEY idx_donhang_created (ngaydatdonhang),
            KEY idx_donhang_source (nguondonhang),
            KEY idx_donhang_customer (khachhang_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!(bool) $conn->query($createOrderSql)) {
        return false;
    }

    $columnMigrations = [
        'madonhang' => "ALTER TABLE donhang ADD COLUMN madonhang VARCHAR(40) NULL AFTER khachhang_id",
        'nguondonhang' => "ALTER TABLE donhang ADD COLUMN nguondonhang VARCHAR(30) NOT NULL DEFAULT 'tai_quay' AFTER trangthaidonhang",
        'phuongthucthanhtoan' => "ALTER TABLE donhang ADD COLUMN phuongthucthanhtoan VARCHAR(30) NOT NULL DEFAULT 'tien_mat' AFTER nguondonhang",
        'tennhanvien' => "ALTER TABLE donhang ADD COLUMN tennhanvien VARCHAR(120) NULL AFTER phuongthucthanhtoan",
        'ghichudonhang' => "ALTER TABLE donhang ADD COLUMN ghichudonhang TEXT NULL AFTER tennhanvien",
    ];

    foreach ($columnMigrations as $column => $sql) {
        if (!app_column_exists($conn, 'donhang', $column)) {
            $conn->query($sql);
        }
    }

    if (!app_column_exists($conn, 'donhang', 'madonhang')) {
        return false;
    }

    $createDetailSql = "
        CREATE TABLE IF NOT EXISTS donhang_chitiet (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            donhang_id INT UNSIGNED NOT NULL,
            sanpham_id INT UNSIGNED NULL,
            masanpham VARCHAR(80) NULL,
            tensanpham VARCHAR(255) NOT NULL,
            soluong INT NOT NULL DEFAULT 1,
            dongia DECIMAL(14,2) NOT NULL DEFAULT 0,
            thanhtien DECIMAL(14,2) NOT NULL DEFAULT 0,
            ngaytao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_dhct_order (donhang_id),
            KEY idx_dhct_product (sanpham_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    return (bool) $conn->query($createDetailSql);
}

function app_normalize_payment_method(string $value): string
{
    $method = app_lower(trim($value));
    if ($method === 'chuyen_khoan' || $method === 'bank' || $method === 'transfer') {
        return 'chuyen_khoan';
    }

    return 'tien_mat';
}

function app_prepare_order_items(array $items): array
{
    $normalized = [];

    foreach ($items as $raw) {
        if (!is_array($raw)) {
            continue;
        }

        $productId = (int) ($raw['id'] ?? $raw['product_id'] ?? $raw['sanpham_id'] ?? $raw['productId'] ?? 0);
        $qty = (int) ($raw['qty'] ?? $raw['quantity'] ?? $raw['soluong'] ?? 0);
        $price = (float) ($raw['price'] ?? $raw['dongia'] ?? 0);
        $name = trim((string) ($raw['name'] ?? $raw['product_name'] ?? $raw['title'] ?? $raw['tensanpham'] ?? ''));
        $code = trim((string) ($raw['code'] ?? $raw['sku'] ?? $raw['masanpham'] ?? ''));

        if ($qty <= 0) {
            continue;
        }

        if ($productId <= 0 && $code === '' && $name === '') {
            continue;
        }

        $normalized[] = [
            'product_id' => $productId,
            'quantity' => $qty,
            'price' => $price,
            'name' => $name,
            'code' => $code,
        ];
    }

    return $normalized;
}

function app_apply_stock_deduction(mysqli $conn, array &$items): array
{
    if (!app_table_exists($conn, 'sanpham')) {
        return [false, 'Khong tim thay bang sanpham'];
    }

    $stockByIdStmt = $conn->prepare('SELECT id, tensanpham, masanpham, giasanpham, soluongsanpham FROM sanpham WHERE id = ? LIMIT 1 FOR UPDATE');
    $stockByCodeStmt = $conn->prepare('SELECT id, tensanpham, masanpham, giasanpham, soluongsanpham FROM sanpham WHERE masanpham = ? LIMIT 1 FOR UPDATE');
    $stockByNameStmt = $conn->prepare('SELECT id, tensanpham, masanpham, giasanpham, soluongsanpham FROM sanpham WHERE LOWER(tensanpham) = LOWER(?) LIMIT 1 FOR UPDATE');
    $updateStmt = $conn->prepare("UPDATE sanpham SET soluongsanpham = GREATEST(COALESCE(soluongsanpham, 0) - ?, 0), trangthaisanpham = CASE WHEN GREATEST(COALESCE(soluongsanpham, 0) - ?, 0) <= 0 THEN 'hethang' WHEN GREATEST(COALESCE(soluongsanpham, 0) - ?, 0) <= 5 THEN 'saphet' ELSE 'conhang' END WHERE id = ? LIMIT 1");
    if (!$stockByIdStmt || !$stockByCodeStmt || !$stockByNameStmt || !$updateStmt) {
        if ($stockByIdStmt) {
            $stockByIdStmt->close();
        }
        if ($stockByCodeStmt) {
            $stockByCodeStmt->close();
        }
        if ($stockByNameStmt) {
            $stockByNameStmt->close();
        }
        if ($updateStmt) {
            $updateStmt->close();
        }
        return [false, 'Khong the khoa va cap nhat ton kho'];
    }

    $petLookupStmt = null;
    $petMarkSoldStmt = null;
    $petSyncProductStmt = null;
    if (app_table_exists($conn, 'thucung')) {
        $petLookupSql = "
            SELECT id, COALESCE(NULLIF(TRIM(tenthucung), ''), 'Thu cung') AS tenthucung, COALESCE(sanpham_id, 0) AS sanpham_id
            FROM thucung
            WHERE LOWER(TRIM(COALESCE(tenthucung, ''))) = LOWER(TRIM(?))
              AND COALESCE(nguon_thucung, 'cua_hang') = 'cua_hang'
              AND (
                COALESCE(TRIM(trangthaithucung), '') = ''
                OR LOWER(TRIM(COALESCE(trangthaithucung, ''))) IN ('dang ban', 'đang bán', 'con hang', 'còn hàng', 'conhang', 'available')
              )
            ORDER BY id ASC
            LIMIT ?
            FOR UPDATE
        ";
        $petLookupStmt = $conn->prepare($petLookupSql);
        $petMarkSoldStmt = $conn->prepare("UPDATE thucung SET trangthaithucung = 'Đã bán' WHERE id = ? LIMIT 1");
        $petSyncProductStmt = $conn->prepare("UPDATE sanpham SET soluongsanpham = GREATEST(COALESCE(soluongsanpham, 0) - ?, 0), trangthaisanpham = CASE WHEN GREATEST(COALESCE(soluongsanpham, 0) - ?, 0) <= 0 THEN 'hethang' WHEN GREATEST(COALESCE(soluongsanpham, 0) - ?, 0) <= 5 THEN 'saphet' ELSE 'conhang' END WHERE id = ? LIMIT 1");
    }

    $applyPetDeduction = static function (
        ?mysqli_stmt $lookupStmt = null,
        ?mysqli_stmt $markStmt = null,
        ?mysqli_stmt $syncStmt = null,
        string $petName = '',
        int $qty = 0
    ): array {
        if (!$lookupStmt || !$markStmt || $qty <= 0 || trim($petName) === '') {
            return [false, ''];
        }

        $lookupStmt->bind_param('si', $petName, $qty);
        $lookupStmt->execute();
        $petResult = $lookupStmt->get_result();
        $petRows = [];
        while ($petResult && ($petRow = $petResult->fetch_assoc())) {
            $petRows[] = $petRow;
        }
        if ($petResult) {
            $petResult->free();
        }

        if (count($petRows) < $qty) {
            return [false, 'Khong du thu cung trong kho cua hang: ' . $petName];
        }

        foreach ($petRows as $petRow) {
            $petId = (int) ($petRow['id'] ?? 0);
            if ($petId <= 0) {
                continue;
            }

            $markStmt->bind_param('i', $petId);
            $markStmt->execute();

            $linkedProductId = (int) ($petRow['sanpham_id'] ?? 0);
            if ($syncStmt && $linkedProductId > 0) {
                $minusOne = 1;
                $syncStmt->bind_param('iiii', $minusOne, $minusOne, $minusOne, $linkedProductId);
                $syncStmt->execute();
            }
        }

        return [true, ''];
    };

    foreach ($items as $index => $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $productCode = trim((string) ($item['code'] ?? ''));
        $productName = trim((string) ($item['name'] ?? ''));
        $qty = (int) ($item['quantity'] ?? 0);
        if ($qty <= 0) {
            $stockByIdStmt->close();
            $stockByCodeStmt->close();
            $stockByNameStmt->close();
            $updateStmt->close();
            return [false, 'Du lieu san pham khong hop le'];
        }

        $result = null;
        if ($productId > 0) {
            $stockByIdStmt->bind_param('i', $productId);
            $stockByIdStmt->execute();
            $result = $stockByIdStmt->get_result();
        } elseif ($productCode !== '') {
            $stockByCodeStmt->bind_param('s', $productCode);
            $stockByCodeStmt->execute();
            $result = $stockByCodeStmt->get_result();
        } else {
            $stockByNameStmt->bind_param('s', $productName);
            $stockByNameStmt->execute();
            $result = $stockByNameStmt->get_result();
        }

        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }

        if (!is_array($row)) {
            $canTryPetFallback = $productId <= 0 && $productCode === '' && $productName !== '';
            if ($canTryPetFallback) {
                [$petOk, $petMessage] = $applyPetDeduction($petLookupStmt, $petMarkSoldStmt, $petSyncProductStmt, $productName, $qty);
                if ($petOk) {
                    $items[$index]['product_id'] = 0;
                    continue;
                }

                if ($petMessage !== '') {
                    $stockByIdStmt->close();
                    $stockByCodeStmt->close();
                    $stockByNameStmt->close();
                    $updateStmt->close();
                    if ($petLookupStmt) {
                        $petLookupStmt->close();
                    }
                    if ($petMarkSoldStmt) {
                        $petMarkSoldStmt->close();
                    }
                    if ($petSyncProductStmt) {
                        $petSyncProductStmt->close();
                    }
                    return [false, $petMessage];
                }
            }

            $itemLabel = $productName !== '' ? $productName : ($productCode !== '' ? $productCode : ('ID ' . $productId));
            $stockByIdStmt->close();
            $stockByCodeStmt->close();
            $stockByNameStmt->close();
            $updateStmt->close();
            if ($petLookupStmt) {
                $petLookupStmt->close();
            }
            if ($petMarkSoldStmt) {
                $petMarkSoldStmt->close();
            }
            if ($petSyncProductStmt) {
                $petSyncProductStmt->close();
            }
            return [false, 'San pham khong ton tai: ' . $itemLabel];
        }

        $resolvedProductId = (int) ($row['id'] ?? 0);
        $currentStock = (int) ($row['soluongsanpham'] ?? 0);
        if ($currentStock < $qty) {
            $canTryPetFallback = $productId <= 0 && $productCode === '' && $productName !== '';
            if ($canTryPetFallback) {
                [$petOk, $petMessage] = $applyPetDeduction($petLookupStmt, $petMarkSoldStmt, $petSyncProductStmt, $productName, $qty);
                if ($petOk) {
                    $items[$index]['product_id'] = 0;
                    continue;
                }

                if ($petMessage !== '') {
                    $stockByIdStmt->close();
                    $stockByCodeStmt->close();
                    $stockByNameStmt->close();
                    $updateStmt->close();
                    if ($petLookupStmt) {
                        $petLookupStmt->close();
                    }
                    if ($petMarkSoldStmt) {
                        $petMarkSoldStmt->close();
                    }
                    if ($petSyncProductStmt) {
                        $petSyncProductStmt->close();
                    }
                    return [false, $petMessage];
                }
            }

            $name = (string) ($row['tensanpham'] ?? ('ID ' . $productId));
            $stockByIdStmt->close();
            $stockByCodeStmt->close();
            $stockByNameStmt->close();
            $updateStmt->close();
            if ($petLookupStmt) {
                $petLookupStmt->close();
            }
            if ($petMarkSoldStmt) {
                $petMarkSoldStmt->close();
            }
            if ($petSyncProductStmt) {
                $petSyncProductStmt->close();
            }
            return [false, 'Khong du ton kho cho san pham: ' . $name];
        }

        $updateStmt->bind_param('iiii', $qty, $qty, $qty, $resolvedProductId);
        $updateStmt->execute();

        $items[$index]['product_id'] = $resolvedProductId;
        $items[$index]['price'] = (float) ($item['price'] > 0 ? $item['price'] : ($row['giasanpham'] ?? 0));
        $items[$index]['name'] = trim((string) ($item['name'] ?? '')) !== ''
            ? (string) $item['name']
            : (string) ($row['tensanpham'] ?? 'San pham');
        $items[$index]['code'] = trim((string) ($item['code'] ?? '')) !== ''
            ? (string) $item['code']
            : (string) ($row['masanpham'] ?? '');
    }

    $stockByIdStmt->close();
    $stockByCodeStmt->close();
    $stockByNameStmt->close();
    $updateStmt->close();
    if ($petLookupStmt) {
        $petLookupStmt->close();
    }
    if ($petMarkSoldStmt) {
        $petMarkSoldStmt->close();
    }
    if ($petSyncProductStmt) {
        $petSyncProductStmt->close();
    }
    return [true, ''];
}

function app_order_status_to_sell_status(string $status): string
{
    $normalized = app_lower(trim($status));
    if ($normalized === 'hoanthanh' || $normalized === 'hoan_tat' || $normalized === 'hoan tat') {
        return 'hoan_tat';
    }
    if ($normalized === 'huy' || $normalized === 'da_huy') {
        return 'da_huy';
    }
    return 'dang_xu_ly';
}

function app_map_online_to_order_status(string $onlineStatus): string
{
    $status = trim(strtolower($onlineStatus));
    if ($status === 'da_duyet') {
        return 'hoanthanh';
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

    if (preg_match('~^(?:https?:)?//~i', $path) || app_starts_with($path, 'data:')) {
        return $path;
    }

    $querySuffix = '';
    $questionPos = strpos($path, '?');
    if ($questionPos !== false) {
        $querySuffix = substr($path, $questionPos);
        $path = substr($path, 0, $questionPos);
    }

    // Handle legacy file-system paths (e.g. C:/laragon/www/.../anhdata/...).
    $anhdataPos = stripos($path, 'anhdata/');
    if ($anhdataPos !== false) {
        $path = substr($path, $anhdataPos);
    }

    while (app_starts_with($path, './') || app_starts_with($path, '../')) {
        if (app_starts_with($path, './')) {
            $path = substr($path, 2);
            continue;
        }
        $path = substr($path, 3);
    }

    $path = ltrim($path, '/');

    if (app_starts_with($path, 'phuocthanh/PHPCHINH/')) {
        $path = substr($path, strlen('phuocthanh/PHPCHINH/'));
    } elseif (app_starts_with($path, 'PHPCHINH/')) {
        $path = substr($path, strlen('PHPCHINH/'));
    }

    if (app_starts_with($path, 'Giao Diện/user/anhdata/')) {
        $path = substr($path, strlen('Giao Diện/user/'));
    } elseif (app_starts_with($path, 'Giao Diện/admin/anhdata/')) {
        $path = substr($path, strlen('Giao Diện/admin/'));
    } elseif (app_starts_with($path, 'Giao%20Di%E1%BB%87n/user/anhdata/')) {
        $path = substr($path, strlen('Giao%20Di%E1%BB%87n/user/'));
    } elseif (app_starts_with($path, 'Giao%20Di%E1%BB%87n/admin/anhdata/')) {
        $path = substr($path, strlen('Giao%20Di%E1%BB%87n/admin/'));
    } elseif (app_starts_with($path, 'user/anhdata/')) {
        $path = substr($path, strlen('user/'));
    } elseif (app_starts_with($path, 'admin/anhdata/')) {
        $path = substr($path, strlen('admin/'));
    }

    if ($path === '') {
        return '';
    }

    $encodedPath = str_replace(' ', '%20', ltrim($path, '/'));
    $basePath = app_base_path();
    $finalPath = ($basePath !== '' ? $basePath : '') . '/' . $encodedPath;
    return $finalPath . $querySuffix;
}

function app_ensure_dichvu_image_column(mysqli $conn): bool
{
    if (app_column_exists($conn, 'dichvu', 'hinhanhdichvu')) {
        return true;
    }

    $alterSql = "ALTER TABLE dichvu ADD COLUMN hinhanhdichvu VARCHAR(255) NULL AFTER trangthaidichvu";
    return (bool) $conn->query($alterSql);
}

function app_ensure_dichvu_info_column(mysqli $conn): bool
{
    if (app_column_exists($conn, 'dichvu', 'thongtin')) {
        return true;
    }

    $alterSql = "ALTER TABLE dichvu ADD COLUMN thongtin TEXT NULL AFTER hinhanhdichvu";
    return (bool) $conn->query($alterSql);
}

function app_ensure_dichvu_booking_count_column(mysqli $conn): bool
{
    if (!app_table_exists($conn, 'dichvu')) {
        return false;
    }

    if (app_column_exists($conn, 'dichvu', 'soluotdatdichvu')) {
        return true;
    }

    $alterSql = "ALTER TABLE dichvu ADD COLUMN soluotdatdichvu INT NOT NULL DEFAULT 0 AFTER thongtin";
    return (bool) $conn->query($alterSql);
}

function app_ensure_sanpham_purchase_count_column(mysqli $conn): bool
{
    if (!app_table_exists($conn, 'sanpham')) {
        return false;
    }

    if (app_column_exists($conn, 'sanpham', 'soluotmuasanpham')) {
        return true;
    }

    $alterSql = "ALTER TABLE sanpham ADD COLUMN soluotmuasanpham INT NOT NULL DEFAULT 0 AFTER soluongsanpham";
    return (bool) $conn->query($alterSql);
}

function app_ensure_service_category_table(mysqli $conn): bool
{
    if (!app_table_exists($conn, 'danhmucdichvu')) {
        $sql = "
            CREATE TABLE danhmucdichvu (
                id INT NOT NULL AUTO_INCREMENT,
                tendanhmucdichvu VARCHAR(120) NOT NULL,
                ngaytao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_danhmucdichvu_name (tendanhmucdichvu)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ";

        if (!$conn->query($sql)) {
            return false;
        }
    }

    if (!app_column_exists($conn, 'danhmucdichvu', 'ngaytao')) {
        if (!$conn->query('ALTER TABLE danhmucdichvu ADD COLUMN ngaytao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP')) {
            return false;
        }
    }

    return true;
}

function app_ensure_dichvu_category_column(mysqli $conn): bool
{
    if (!app_table_exists($conn, 'dichvu')) {
        return false;
    }

    $ok = true;

    if (!app_column_exists($conn, 'dichvu', 'danhmucdichvu_id')) {
        $ok = (bool) $conn->query('ALTER TABLE dichvu ADD COLUMN danhmucdichvu_id INT NULL AFTER thoigiandichvu') && $ok;
    }

    if (!$ok) {
        return false;
    }

    $hasIndex = false;
    $indexCheck = $conn->query("SHOW INDEX FROM dichvu WHERE Key_name = 'idx_dichvu_danhmucdichvu_id'");
    if ($indexCheck) {
        $hasIndex = $indexCheck->num_rows > 0;
        $indexCheck->free();
    }
    if (!$hasIndex) {
        $conn->query('CREATE INDEX idx_dichvu_danhmucdichvu_id ON dichvu(danhmucdichvu_id)');
    }

    return true;
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

function app_ensure_pet_note_column(mysqli $conn): bool
{
    if (!app_table_exists($conn, 'thucung')) {
        return false;
    }

    if (app_column_exists($conn, 'thucung', 'thongtin')) {
        return true;
    }

    return (bool) $conn->query("ALTER TABLE thucung ADD COLUMN thongtin TEXT NULL AFTER trangthaithucung");
}

function app_ensure_pet_source_columns(mysqli $conn): bool
{
    if (!app_table_exists($conn, 'thucung')) {
        return false;
    }

    $ok = true;

    if (!app_column_exists($conn, 'thucung', 'nguon_thucung')) {
        $ok = (bool) $conn->query("ALTER TABLE thucung ADD COLUMN nguon_thucung ENUM('khach_hang','cua_hang') NOT NULL DEFAULT 'khach_hang' AFTER chusohuu_id") && $ok;
    }

    if (!app_column_exists($conn, 'thucung', 'sanpham_id')) {
        $ok = (bool) $conn->query('ALTER TABLE thucung ADD COLUMN sanpham_id INT NULL AFTER nguon_thucung') && $ok;
    }

    $conn->query("UPDATE thucung SET nguon_thucung = 'khach_hang' WHERE COALESCE(chusohuu_id, 0) > 0");
    $conn->query("UPDATE thucung SET nguon_thucung = 'cua_hang' WHERE COALESCE(chusohuu_id, 0) <= 0");

    $hasSourceIndex = false;
    $indexCheck = $conn->query("SHOW INDEX FROM thucung WHERE Key_name = 'idx_thucung_nguon'");
    if ($indexCheck) {
        $hasSourceIndex = $indexCheck->num_rows > 0;
        $indexCheck->free();
    }
    if (!$hasSourceIndex) {
        $conn->query('CREATE INDEX idx_thucung_nguon ON thucung(nguon_thucung)');
    }

    $hasProductIndex = false;
    $indexCheck = $conn->query("SHOW INDEX FROM thucung WHERE Key_name = 'idx_thucung_sanpham_id'");
    if ($indexCheck) {
        $hasProductIndex = $indexCheck->num_rows > 0;
        $indexCheck->free();
    }
    if (!$hasProductIndex) {
        $conn->query('CREATE INDEX idx_thucung_sanpham_id ON thucung(sanpham_id)');
    }

    return $ok;
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
    app_ensure_user_avatar_column($conn);
    app_ensure_pet_note_column($conn);
    app_ensure_pet_source_columns($conn);
    app_ensure_service_category_table($conn);
    app_ensure_dichvu_category_column($conn);
    app_ensure_dichvu_booking_count_column($conn);
    app_ensure_sanpham_purchase_count_column($conn);

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
