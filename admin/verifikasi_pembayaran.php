<?php
require '../config/database.php';
require '../config/auth.php';
requireRole(['admin']);

$user = currentUser($pdo);
$success = '';
$error = '';
$bookingId = (int) ($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT b.*, j.nama_pengujian, j.satuan_sampel, u.nama_lengkap as nama_pelanggan, u.email as email_pelanggan
     FROM bookings b
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     JOIN users u ON b.user_id = u.id
     WHERE b.id = ? AND b.status_booking = 'menunggu_pembayaran'"
);
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    die('Booking tidak ditemukan atau statusnya bukan "Menunggu Pembayaran".');
}

// ============ VERIFIKASI PEMBAYARAN ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verifikasi_bayar'])) {
    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    $isOffline = ($booking['tipe_booking'] ?? 'online') === 'offline';

    if (!$isOffline && empty($booking['bukti_transfer'])) {
        $error = 'Pelanggan belum mengunggah bukti transfer. Verifikasi tidak dapat dilakukan.';
    } else {
        $pdo->prepare("UPDATE bookings SET status_booking = 'selesai', diverifikasi_pembayaran_oleh = ?, tanggal_pembayaran = NOW(), bukti_transfer = NULL WHERE id = ?")
             ->execute([$user['id'], $bookingId]);

        $ket = $isOffline ? "Pembayaran di loket diverifikasi oleh Admin. Booking selesai." : "Pembayaran diverifikasi oleh Admin. Booking selesai.";
        $pdo->prepare("INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan) VALUES (?, 'selesai', ?, ?)")
             ->execute([$bookingId, $user['id'], $ket]);

        if ($booking['bukti_transfer'] && file_exists('../' . $booking['bukti_transfer'])) {
            unlink('../' . $booking['bukti_transfer']);
        }

        header('Location: kelola_booking.php?msg=pembayaran_berhasil');
        exit;
    }
}

// ============ TOLAK PEMBAYARAN ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tolak_bayar'])) {
    $alasan = trim($_POST['alasan_tolak'] ?? '');

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    if ($alasan === '') {
        $error = 'Alasan penolakan wajib diisi.';
    } else {
        // Hapus file bukti transfer dari server
        if ($booking['bukti_transfer'] && file_exists('../' . $booking['bukti_transfer'])) {
            unlink('../' . $booking['bukti_transfer']);
        }

        $pdo->prepare("UPDATE bookings SET status_booking = 'menunggu_pembayaran', bukti_transfer = NULL, alasan_ditolak = ? WHERE id = ?")
             ->execute([$alasan, $bookingId]);

        $pdo->prepare("INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan) VALUES (?, 'menunggu_pembayaran', ?, ?)")
             ->execute([$bookingId, $user['id'], 'Bukti transfer ditolak oleh Admin: ' . $alasan]);

        header('Location: kelola_booking.php?msg=pembayaran_ditolak');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Pembayaran - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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

    <div class="page-container" style="max-width: 700px;">
        <a href="kelola_booking.php" class="text-decoration-none text-muted">&larr; Kembali ke Kelola Booking</a>

        <div class="page-header mt-2">
            <h4>Verifikasi Pembayaran</h4>
            <p>Verifikasi pembayaran pelanggan</p>
            <?php if (($booking['tipe_booking'] ?? 'online') === 'offline'): ?>
                <span class="badge bg-info" style="font-size: 0.75rem;"><i class="bi bi-building"></i> Offline — Bayar di Loket</span>
            <?php else: ?>
                <span class="badge bg-success" style="font-size: 0.75rem;"><i class="bi bi-globe"></i> Online — Bukti Transfer</span>
            <?php endif; ?>
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
                        <strong><?= htmlspecialchars($booking['nama_pelanggan']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Jenis Pengujian</small>
                        <strong><?= htmlspecialchars($booking['nama_pengujian']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Estimasi Biaya</small>
                        <strong class="text-danger">Rp <?= number_format($booking['estimasi_biaya'], 0, ',', '.') ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <?php if (($booking['tipe_booking'] ?? 'online') === 'offline'): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>Pembayaran Offline</strong></div>
            <div class="card-body text-center">
                <i class="bi bi-building text-info" style="font-size: 2rem;"></i>
                <p class="mt-2 mb-0">Pembayaran dilakukan di <strong>loket kasir</strong> laboratorium.</p>
                <small class="text-muted">Konfirmasi bahwa pelanggan sudah membayar.</small>
            </div>
        </div>
        <?php else: ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>Bukti Transfer</strong></div>
            <div class="card-body text-center">
                <?php if ($booking['bukti_transfer']): ?>
                    <?php
                    $buktiPath = '../' . htmlspecialchars($booking['bukti_transfer']);
                    $ext = strtolower(pathinfo($booking['bukti_transfer'], PATHINFO_EXTENSION));
                    ?>
                    <?php if (in_array($ext, ['jpg', 'jpeg', 'png'])): ?>
                        <img src="<?= $buktiPath ?>" class="img-fluid rounded" style="max-height: 500px;" alt="Bukti Transfer">
                    <?php elseif ($ext === 'pdf'): ?>
                        <iframe src="<?= $buktiPath ?>" style="width:100%; height:500px; border:1px solid #ddd; border-radius:8px;"></iframe>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted">Bukti transfer belum diunggah.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>Aksi Verifikasi</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <?php if ($booking['status_booking'] === 'menunggu_pembayaran'): ?>
                    <div class="col-md-6">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                            <button type="submit" name="verifikasi_bayar" class="btn btn-success w-100 py-2"
                                    onclick="return confirm('Verifikasi pembayaran ini?')">
                                <i class="bi bi-check-circle"></i> Verifikasi Bayar
                            </button>
                        </form>
                    </div>
                    <?php if (($booking['tipe_booking'] ?? 'online') === 'online'): ?>
                    <div class="col-md-6">
                        <button type="button" class="btn btn-danger w-100 py-2" data-bs-toggle="modal" data-bs-target="#modalTolak">
                            Tolak Bukti Transfer
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL TOLAK -->
    <div class="modal fade" id="modalTolak" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="modal-header">
                        <h6 class="modal-title">Tolak Bukti Transfer</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                        <label class="form-label">Alasan Penolakan</label>
                        <textarea name="alasan_tolak" class="form-control" rows="3" required placeholder="Contoh: Bukti transfer tidak terbaca, nominal tidak sesuai..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="tolak_bayar" class="btn btn-danger btn-sm">Tolak</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
