<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
requireRole(['admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);

$csrfToken = $payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !is_string($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token CSRF tidak valid']);
    exit;
}

$hasilId    = (int) ($payload['id'] ?? 0);
$kodeGrup   = $payload['kode'] ?? '';
$userTarget = (int) ($payload['user_id'] ?? 0);
$statusBaru = $payload['status'] ?? '';

if (!in_array($statusBaru, ['terverifikasi', 'belum_diverifikasi'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Parameter tidak valid']);
    exit;
}

$userId = $_SESSION['user_id'];

if ($hasilId > 0) {
    $stmt = $pdo->prepare(
        "UPDATE hasil_pengujian
         SET status_verifikasi = ?, diverifikasi_oleh = ?, diverifikasi_pada = NOW()
         WHERE id = ? AND sampel_id IS NULL"
    );
    $stmt->execute([$statusBaru, $userId, $hasilId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['ok' => false, 'msg' => 'Data tidak ditemukan atau bukan data historis']);
        exit;
    }
} elseif ($kodeGrup !== '' && $userTarget > 0) {
    $cek = $pdo->prepare(
        "SELECT COUNT(*) FROM hasil_pengujian WHERE user_id = ? AND kode = ? AND sampel_id IS NULL"
    );
    $cek->execute([$userTarget, $kodeGrup]);
    if ((int) $cek->fetchColumn() === 0) {
        echo json_encode(['ok' => false, 'msg' => 'Data historis dengan nomor laporan tersebut tidak ditemukan']);
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE hasil_pengujian
         SET status_verifikasi = ?, diverifikasi_oleh = ?, diverifikasi_pada = NOW()
         WHERE user_id = ? AND kode = ? AND sampel_id IS NULL"
    );
    $stmt->execute([$statusBaru, $userId, $userTarget, $kodeGrup]);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Parameter tidak valid']);
    exit;
}

$label = $statusBaru === 'terverifikasi' ? 'Terverifikasi' : 'Belum Diverifikasi';
echo json_encode(['ok' => true, 'label' => $label, 'status' => $statusBaru]);
