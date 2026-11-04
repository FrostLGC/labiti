<?php
require '../config/database.php';
require '../config/auth.php';
requireRole(['pelanggan']);

$user = currentUser($pdo);
$error = '';
$success = '';

$bookingId = (int) ($_GET['id'] ?? $_POST['booking_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT b.*, j.nama_pengujian, j.satuan_sampel, u.nama_lengkap as nama_pelanggan
     FROM bookings b
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     JOIN users u ON b.user_id = u.id
     WHERE b.id = ? AND b.user_id = ? AND b.status_booking = 'menunggu_pembayaran' AND b.tipe_booking = 'online'"
);
$stmt->execute([$bookingId, $user['id']]);
$booking = $stmt->fetch();

if (!$booking) {
    die('Booking tidak ditemukan, bukan milik Anda, atau bukan booking online.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    if (isset($_FILES['bukti_transfer']) && ($_FILES['bukti_transfer']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['bukti_transfer'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $extensionsDiizinkan = ['jpg', 'jpeg', 'png', 'pdf'];

        $isiCocok = true;
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $isiCocok = @getimagesize($file['tmp_name']) !== false;
        } elseif ($ext === 'pdf') {
            $fh = @fopen($file['tmp_name'], 'rb');
            $head = $fh ? fread($fh, 5) : '';
            if ($fh) fclose($fh);
            $isiCocok = (strncmp($head, '%PDF-', 5) === 0);
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Upload bukti transfer gagal (kemungkinan file terlalu besar). Maksimal 5MB.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $error = 'Ukuran file bukti transfer maksimal 5MB.';
        } elseif (!in_array($ext, $extensionsDiizinkan)) {
            $error = 'Format bukti transfer harus JPG, PNG, atau PDF.';
        } elseif (!$isiCocok) {
            $error = 'Isi file tidak sesuai dengan formatnya. Pastikan file adalah JPG, PNG, atau PDF asli.';
        } else {
            $namaBaru = 'bukti_' . str_replace('/', '-', $booking['kode_booking']) . '_' . time() . '.' . $ext;
            $tujuanFolder = '../uploads/bukti_transfer';

            if (!is_dir($tujuanFolder)) {
                mkdir($tujuanFolder, 0777, true);
            }

            if (move_uploaded_file($_FILES['bukti_transfer']['tmp_name'], $tujuanFolder . '/' . $namaBaru)) {
                $path = 'uploads/bukti_transfer/' . $namaBaru;

                $stmtUpdate = $pdo->prepare(
                    "UPDATE bookings SET bukti_transfer = ? WHERE id = ?"
                );
                $stmtUpdate->execute([$path, $bookingId]);

                $stmtRiwayat = $pdo->prepare(
                    "INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan)
                     VALUES (?, 'menunggu_pembayaran', ?, 'Bukti transfer diunggah oleh pelanggan. Menunggu verifikasi admin.')"
                );
                $stmtRiwayat->execute([$bookingId, $user['id']]);



                $success = 'Bukti transfer berhasil diunggah! Menunggu verifikasi dari admin.';
                // refresh booking data
                $stmt->execute([$bookingId, $user['id']]);
                $booking = $stmt->fetch();
            } else {
                $error = 'Gagal mengunggah bukti transfer. Silakan coba lagi.';
            }
        }
    } else {
        $error = 'Pilih file bukti transfer terlebih dahulu.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Bukti Transfer - Pelanggan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-custom navbar-pelanggan">
        <div class="container">
            <a class="navbar-brand text-white" href="dashboard.php"><img src="../img1.jpeg" alt="Logo"> Laboratorium PT ITI Utama</a>
            <button class="navbar-toggler text-white border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navPelanggan">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navPelanggan">
                <div class="ms-auto d-flex align-items-center gap-2 nav-actions">
                    <a href="dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
                    <a href="booking.php" class="btn btn-outline-light btn-sm">Booking</a>
                    <a href="cek_booking.php" class="btn btn-outline-light btn-sm">Validasi Dokumen</a>
                    <a href="riwayat.php" class="btn btn-outline-light btn-sm">Riwayat</a>
                    <a href="profil.php" class="btn btn-outline-light btn-sm">Profil</a>
                    <a href="../auth/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="page-container" style="max-width: 650px;">
        <a href="detail_booking.php?id=<?= $bookingId ?>" class="text-decoration-none text-muted">&larr; Kembali ke Detail Booking</a>

        <div class="page-header mt-2">
            <h4>Upload Bukti Transfer</h4>
            <p>Unggah bukti transfer pembayaran pengujian</p>
        </div>

        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>Ringkasan Pembayaran</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Kode Booking</small>
                        <strong><?= htmlspecialchars($booking['kode_booking']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Jenis Pengujian</small>
                        <strong><?= htmlspecialchars($booking['nama_pengujian']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Jumlah Sampel</small>
                        <strong><?= $booking['jumlah_sampel'] ?> <?= $booking['satuan_sampel'] ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Total yang Harus Dibayar</small>
                        <strong class="text-danger" style="font-size: 1.1rem;">Rp <?= number_format($booking['estimasi_biaya'], 0, ',', '.') ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($booking['status_booking'] === 'menunggu_pembayaran'): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>Rekening Tujuan Transfer</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-4">
                        <div class="d-flex align-items-center gap-2">
                            <div class="fw-bold" style="color: #003DA5; font-size: 0.9375rem;">BCA</div>
                            <code class="user-select-all" style="font-size: 0.9375rem;">1234567890</code>
                        </div>
                        <small class="text-muted">a.n PT ITI Utama</small>
                    </div>
                    <div class="col-sm-4">
                        <div class="d-flex align-items-center gap-2">
                            <div class="fw-bold" style="color: #CC0000; font-size: 0.9375rem;">BRI</div>
                            <code class="user-select-all" style="font-size: 0.9375rem;">0987654321</code>
                        </div>
                        <small class="text-muted">a.n PT ITI Utama</small>
                    </div>
                    <div class="col-sm-4">
                        <div class="d-flex align-items-center gap-2">
                            <div class="fw-bold" style="color: #00529B; font-size: 0.9375rem;">Mandiri</div>
                            <code class="user-select-all" style="font-size: 0.9375rem;">1122334455</code>
                        </div>
                        <small class="text-muted">a.n PT ITI Utama</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong><i class="bi bi-upload"></i> Unggah Bukti Transfer</strong></div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="booking_id" value="<?= $bookingId ?>">

                    <div class="mb-3">
                        <label class="form-label">File Bukti Transfer (JPG/PNG/PDF, max 5MB)</label>
                        <input type="file" name="bukti_transfer" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2">Upload Bukti Transfer</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
