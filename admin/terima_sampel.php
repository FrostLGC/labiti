<?php
require '../config/database.php';
require '../config/auth.php';
require_once '../config/labels.php';
requireRole(['admin']);

$user = currentUser($pdo);
$bookingId = (int) ($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT b.*, j.nama_pengujian, j.satuan_sampel, u.nama_lengkap
     FROM bookings b
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     JOIN users u ON b.user_id = u.id
     WHERE b.id = ? AND b.status_booking = 'jadwal_ditentukan'"
);
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    die('Booking tidak ditemukan atau statusnya bukan "Jadwal Ditentukan".');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deskripsiSampel = trim($_POST['deskripsi_sampel'] ?? '');
    $jumlahSampel = (int) $booking['jumlah_sampel'];

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    $kodeDasar = 'SPL-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

    $stmtSampel = $pdo->prepare(
        "INSERT INTO sampel (booking_id, kode_sampel, deskripsi_sampel, tanggal_diterima, diterima_oleh, status_sampel)
         VALUES (?, ?, ?, CURDATE(), ?, 'diterima')"
    );

    for ($i = 1; $i <= $jumlahSampel; $i++) {
        $kodeSampel = $kodeDasar . '-' . str_pad($i, 2, '0', STR_PAD_LEFT);
        $deskripsiUnit = $deskripsiSampel . " (Sampel ke-$i dari $jumlahSampel)";
        $stmtSampel->execute([$bookingId, $kodeSampel, $deskripsiUnit, $user['id']]);
    }

    $stmtUpdate = $pdo->prepare("UPDATE bookings SET status_booking = 'menunggu_diuji' WHERE id = ?");
    $stmtUpdate->execute([$bookingId]);

    $pdo->prepare(
        "INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan)
         VALUES (?, 'sampel_diterima', ?, ?)"
    )->execute([$bookingId, $user['id'], "Sampel diterima oleh Admin (Kode: $kodeSampel)."]);

    $pdo->prepare(
        "INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan)
         VALUES (?, 'menunggu_diuji', ?, ?)"
    )->execute([$bookingId, $user['id'], "Menunggu pengujian oleh teknisi."]);



    header('Location: kelola_booking.php?msg=sampel_diterima');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terima Sampel - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-custom navbar-admin">
        <div class="container">
            <a class="navbar-brand text-white" href="dashboard.php"><img src="../img1.jpeg" alt="Logo"> Laboratorium PT ITI Utama</a>
            <button class="navbar-toggler text-white border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navAdmin">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navAdmin">
                <div class="ms-auto d-flex align-items-center gap-2 nav-actions">
                    <a href="dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
                    <a href="kelola_booking.php" class="btn btn-outline-light btn-sm">Booking</a>
                    <a href="booking_manual.php" class="btn btn-outline-light btn-sm">Booking Manual</a>
                    <a href="input_historis.php" class="btn btn-outline-light btn-sm">Input Historis</a>
                    <a href="kelola_user.php" class="btn btn-outline-light btn-sm">User</a>
                    <a href="../auth/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="page-container" style="max-width: 650px;">
        <a href="kelola_booking.php" class="text-decoration-none text-muted">&larr; Kembali ke Kelola Booking</a>

        <div class="page-header mt-2">
            <h4>Terima Sampel</h4>
            <p>Konfirmasi penerimaan sampel fisik dari pelanggan</p>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>Detail Booking</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Kode Booking</small>
                        <strong><?= htmlspecialchars($booking['kode_booking']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Pelanggan</small>
                        <strong><?= htmlspecialchars($booking['nama_lengkap']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Jenis Pengujian</small>
                        <strong><?= htmlspecialchars($booking['nama_pengujian']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Jumlah Sampel</small>
                        <strong><?= $booking['jumlah_sampel'] ?> <?= $booking['satuan_sampel'] ?></strong>
                    </div>
                    <?php if (!empty($booking['tambahan_cetak'])): ?>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Layanan Tambahan</small>
                        <strong>Pemotongan, Setting &amp; Cetak Benda Uji
                            <span class="text-danger">+Rp <?= number_format(HARGA_TAMBAHAN_CETAK * (int)$booking['jumlah_sampel'], 0, ',', '.') ?></span>
                            <small class="text-muted">(<?= number_format(HARGA_TAMBAHAN_CETAK, 0, ',', '.') ?> × <?= (int)$booking['jumlah_sampel'] ?> sampel)</small>
                        </strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($booking['tambahan_setting'])): ?>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Layanan Tambahan</small>
                        <strong>Setting Ukuran Baja Tulangan
                            <span class="text-danger">+Rp <?= number_format(HARGA_TAMBAHAN_SETTING * (int)$booking['jumlah_sampel'], 0, ',', '.') ?></span>
                            <small class="text-muted">(<?= number_format(HARGA_TAMBAHAN_SETTING, 0, ',', '.') ?> × <?= (int)$booking['jumlah_sampel'] ?> sampel)</small>
                        </strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong><i class="bi bi-box-seam"></i> Deskripsi Sampel</strong></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label">Deskripsi Sampel Fisik <span class="text-danger">*</span></label>
                        <textarea name="deskripsi_sampel" class="form-control" rows="3"
                                  placeholder="Contoh: 3 silinder beton diameter 15cm tinggi 30cm, kondisi baik" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2">Konfirmasi Sampel Diterima</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
