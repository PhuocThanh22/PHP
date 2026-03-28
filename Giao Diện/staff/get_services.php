<?php

function app_json_response(array $payload, int $statusCode = 200): void
{
	http_response_code($statusCode);
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode($payload, JSON_UNESCAPED_UNICODE);
	exit;
}

function app_db_connect(): mysqli
{
	$host = getenv('DB_HOST') ?: '127.0.0.1';
	$port = (int) (getenv('DB_PORT') ?: 3306);
	$user = getenv('DB_USER') ?: 'root';
	$pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
	$dbName = getenv('DB_NAME') ?: 'qlshop';

	$conn = @new mysqli($host, $user, $pass, $dbName, $port);
	if ($conn->connect_error) {
		app_json_response([
			'ok' => false,
			'message' => 'Khong ket noi duoc CSDL',
			'db_host' => $host,
			'db_port' => $port,
			'error' => $conn->connect_error,
		], 500);
	}

	$conn->set_charset('utf8mb4');
	return $conn;
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
	$_GET['api'] = $_GET['api'] ?? 'get_services';
	require dirname(__DIR__, 2) . '/index.php';
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
	if (!$hasLoaiThuCung && !$hasLoaiVatThuCung) {
		$conn->close();
		app_json_response(['ok' => false, 'message' => 'Khong tim thay cot loai thu cung trong bang thucung'], 500);
	}
	$petTypeColumn = $hasLoaiThuCung ? 'loaithucung' : 'loaivatthucung';

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
