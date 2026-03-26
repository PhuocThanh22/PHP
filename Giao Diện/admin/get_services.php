<?php

mysqli_report(MYSQLI_REPORT_OFF);

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

function app_request_json(): array
{
	$raw = file_get_contents('php://input');
	if ($raw === false || trim($raw) === '') {
		return [];
	}

	$data = json_decode($raw, true);
	return is_array($data) ? $data : [];
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
			if ($row) {
				return (int) $row['id'];
			}
		}
	}

	if ($fallback > 0) {
		return $fallback;
	}

	$result = $conn->query('SELECT id FROM danhmuc ORDER BY id ASC LIMIT 1');
	if ($result && ($row = $result->fetch_assoc())) {
		$id = (int) $row['id'];
		$result->free();
		return $id;
	}

	if ($result) {
		$result->free();
	}

	return 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
	$apiParam = rawurlencode((string) ($_GET['api'] ?? 'get_services'));
	header('Location: ../../index.php?api=' . $apiParam);
	exit;
}

$input = app_request_json();
$action = (string) ($input['action'] ?? '');
$entity = (string) ($input['entity'] ?? '');
$api = (string) ($_GET['api'] ?? '');

if ($entity === '') {
	if ($api === 'get_products') {
		$entity = 'products';
	} elseif ($api === 'get_customers') {
		$entity = 'customers';
	} elseif ($api === 'get_pets') {
		$entity = 'pets';
	} else {
		$entity = 'services';
	}
}

$conn = app_db_connect();

if ($entity === 'services') {
	if ($action === 'create') {
		$name = trim((string) ($input['tendichvu'] ?? ''));
		$price = (float) ($input['giadichvu'] ?? 0);
		$duration = (int) ($input['thoigiandichvu'] ?? 0);
		$status = trim((string) ($input['trangthaidichvu'] ?? 'hoatdong'));

		if ($name === '' || $duration <= 0) {
			$conn->close();
			app_json_response(['ok' => false, 'message' => 'Du lieu dich vu khong hop le'], 400);
		}

		$stmt = $conn->prepare('INSERT INTO dichvu (tendichvu, giadichvu, thoigiandichvu, trangthaidichvu) VALUES (?, ?, ?, ?)');
		if (!$stmt) {
			$conn->close();
			app_json_response(['ok' => false, 'message' => 'Khong the them dich vu', 'error' => $conn->error], 500);
		}

		$stmt->bind_param('sdis', $name, $price, $duration, $status);
		$ok = $stmt->execute();
		$newId = (int) $conn->insert_id;
		$stmt->close();

		if (!$ok) {
			$conn->close();
			app_json_response(['ok' => false, 'message' => 'Them dich vu that bai', 'error' => $conn->error], 500);
		}

		$rowResult = $conn->query('SELECT id, tendichvu, giadichvu, thoigiandichvu, trangthaidichvu, ngaytaodichvu FROM dichvu WHERE id = ' . $newId . ' LIMIT 1');
		$row = $rowResult ? $rowResult->fetch_assoc() : null;
		if ($rowResult) $rowResult->free();
		$conn->close();
		app_json_response(['ok' => true, 'data' => $row]);
	}

	if ($action === 'update') {
		$id = (int) ($input['id'] ?? 0);
		$name = trim((string) ($input['tendichvu'] ?? ''));
		$price = (float) ($input['giadichvu'] ?? 0);
		$duration = (int) ($input['thoigiandichvu'] ?? 0);
		$status = trim((string) ($input['trangthaidichvu'] ?? 'hoatdong'));

		if ($id <= 0 || $name === '' || $duration <= 0) {
			$conn->close();
			app_json_response(['ok' => false, 'message' => 'Du lieu cap nhat dich vu khong hop le'], 400);
		}

		$stmt = $conn->prepare('UPDATE dichvu SET tendichvu = ?, giadichvu = ?, thoigiandichvu = ?, trangthaidichvu = ? WHERE id = ?');
		if (!$stmt) {
			$conn->close();
			app_json_response(['ok' => false, 'message' => 'Khong the cap nhat dich vu', 'error' => $conn->error], 500);
		}

		$stmt->bind_param('sdisi', $name, $price, $duration, $status, $id);
		$ok = $stmt->execute();
		$stmt->close();
		if (!$ok) {
			$conn->close();
			app_json_response(['ok' => false, 'message' => 'Cap nhat dich vu that bai', 'error' => $conn->error], 500);
		}

		$rowResult = $conn->query('SELECT id, tendichvu, giadichvu, thoigiandichvu, trangthaidichvu, ngaytaodichvu FROM dichvu WHERE id = ' . $id . ' LIMIT 1');
		$row = $rowResult ? $rowResult->fetch_assoc() : null;
		if ($rowResult) $rowResult->free();
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
	if ($action === 'create') {
		$name = trim((string) ($input['tensanpham'] ?? ''));
		$code = trim((string) ($input['masanpham'] ?? ''));
		$categoryName = trim((string) ($input['tendanhmuc'] ?? ''));
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

		$stmt = $conn->prepare('INSERT INTO sanpham (tensanpham, danhmuc_id, masanpham, giasanpham, soluongsanpham, trangthaisanpham, hinhanhsanpham) VALUES (?, ?, ?, ?, ?, ?, ?)');
		if (!$stmt) {
			$conn->close();
			app_json_response(['ok' => false, 'message' => 'Khong the them san pham', 'error' => $conn->error], 500);
		}

		$stmt->bind_param('sisdiss', $name, $danhmucId, $code, $price, $qty, $status, $image);
		$ok = $stmt->execute();
		$newId = (int) $conn->insert_id;
		$stmt->close();
		if (!$ok) {
			$conn->close();
			app_json_response(['ok' => false, 'message' => 'Them san pham that bai', 'error' => $conn->error], 500);
		}

		$rowResult = $conn->query("SELECT s.id, s.tensanpham, s.danhmuc_id, COALESCE(d.tendanhmuc, 'Chua phan loai') AS tendanhmuc, s.masanpham, s.giasanpham, s.soluongsanpham, s.trangthaisanpham, s.hinhanhsanpham FROM sanpham s LEFT JOIN danhmuc d ON d.id = s.danhmuc_id WHERE s.id = {$newId} LIMIT 1");
		$row = $rowResult ? $rowResult->fetch_assoc() : null;
		if ($rowResult) $rowResult->free();
		$conn->close();
		app_json_response(['ok' => true, 'data' => $row]);
	}

	if ($action === 'update') {
		$id = (int) ($input['id'] ?? 0);
		$name = trim((string) ($input['tensanpham'] ?? ''));
		$code = trim((string) ($input['masanpham'] ?? ''));
		$categoryName = trim((string) ($input['tendanhmuc'] ?? ''));
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
		if ($existingResult) $existingResult->free();
		if (!$existing) {
			$conn->close();
			app_json_response(['ok' => false, 'message' => 'Khong tim thay san pham'], 404);
		}

		$danhmucId = app_resolve_category_id($conn, $categoryName, (int) ($existing['danhmuc_id'] ?? 0));
		if ($code === '') {
			$code = (string) ($existing['masanpham'] ?? '');
		}

		$stmt = $conn->prepare('UPDATE sanpham SET tensanpham = ?, danhmuc_id = ?, masanpham = ?, giasanpham = ?, soluongsanpham = ?, trangthaisanpham = ?, hinhanhsanpham = ? WHERE id = ?');
		if (!$stmt) {
			$conn->close();
			app_json_response(['ok' => false, 'message' => 'Khong the cap nhat san pham', 'error' => $conn->error], 500);
		}

		$stmt->bind_param('sisdissi', $name, $danhmucId, $code, $price, $qty, $status, $image, $id);
		$ok = $stmt->execute();
		$stmt->close();
		if (!$ok) {
			$conn->close();
			app_json_response(['ok' => false, 'message' => 'Cap nhat san pham that bai', 'error' => $conn->error], 500);
		}

		$rowResult = $conn->query("SELECT s.id, s.tensanpham, s.danhmuc_id, COALESCE(d.tendanhmuc, 'Chua phan loai') AS tendanhmuc, s.masanpham, s.giasanpham, s.soluongsanpham, s.trangthaisanpham, s.hinhanhsanpham FROM sanpham s LEFT JOIN danhmuc d ON d.id = s.danhmuc_id WHERE s.id = {$id} LIMIT 1");
		$row = $rowResult ? $rowResult->fetch_assoc() : null;
		if ($rowResult) $rowResult->free();
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
		if ($rowResult) $rowResult->free();
		if ($row) $row['so_thu_cung'] = 0;
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
		if ($rowResult) $rowResult->free();
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
	$hasLoaiThuCung = app_column_exists($conn, 'thucung', 'loaithucung');
	$hasLoaiVatThuCung = app_column_exists($conn, 'thucung', 'loaivatthucung');
	$hasLoaiVatTThuCung = app_column_exists($conn, 'thucung', 'loaivattthucung');
	if (!$hasLoaiThuCung && !$hasLoaiVatThuCung && !$hasLoaiVatTThuCung) {
		$conn->close();
		app_json_response(['ok' => false, 'message' => 'Khong tim thay cot loai thu cung trong bang thucung'], 500);
	}
	$petTypeColumn = $hasLoaiThuCung
		? 'loaithucung'
		: ($hasLoaiVatThuCung ? 'loaivatthucung' : 'loaivattthucung');

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
		if ($ownerCheck) $ownerCheck->free();
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
		if ($rowResult) $rowResult->free();
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
		if ($ownerCheck) $ownerCheck->free();
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
		if ($rowResult) $rowResult->free();
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
app_json_response(['ok' => false, 'message' => 'Action khong hop le'], 400);
