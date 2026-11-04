<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$roleUser = $_SESSION['user_role'] ?? '';
if (!in_array($roleUser, ['pelanggan', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require '../config/database.php';

$tanggal = $_GET['tanggal'] ?? '';

if (empty($tanggal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    echo json_encode(['error' => 'Tanggal tidak valid']);
    exit;
}

// Hitung booking yang aktif (bukan ditolak/selesai) di tanggal itu
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM bookings
     WHERE tanggal_pengujian_diminta = ?
     AND status_booking NOT IN ('ditolak', 'selesai')"
);
$stmt->execute([$tanggal]);
$jumlah = (int) $stmt->fetchColumn();

// Breakdown per status
$stmt2 = $pdo->prepare(
    "SELECT status_booking, COUNT(*) as jumlah FROM bookings
     WHERE tanggal_pengujian_diminta = ?
     AND status_booking NOT IN ('ditolak', 'selesai')
     GROUP BY status_booking"
);
$stmt2->execute([$tanggal]);
$detail = $stmt2->fetchAll(PDO::FETCH_KEY_PAIR);

echo json_encode([
    'tanggal' => $tanggal,
    'total' => $jumlah,
    'detail' => $detail
]);
