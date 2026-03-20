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

	$conn->close();
	app_json_response([
		'ok' => false,
		'message' => 'API khong hop le',
	], 404);
}

// Redirect default project URL to the UI login page.
$target = app_base_path() . '/Giao%20Di%E1%BB%87n/user/index.html';
header('Location: ' . $target);
exit;
