<?php
require '../config/database.php';
require '../config/auth.php';
require_once '../config/labels.php';
requireRole(['admin']);

$user = currentUser($pdo);
$error = '';
$bookingId = (int) ($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT b.*, j.nama_pengujian, u.nama_lengkap, u.nama_perusahaan
     FROM bookings b
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     JOIN users u ON b.user_id = u.id
     WHERE b.id = ? AND b.status_booking = 'booking_diterima'"
);
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    die('Booking tidak ditemukan atau statusnya bukan "Booking Diterima".');
}

$daftarTeknisi = $pdo->query("SELECT id, nama_lengkap FROM users WHERE role = 'teknisi' AND status_aktif = 1")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teknisiId = (int) ($_POST['teknisi_id'] ?? 0);
    $tanggalJadwal = $_POST['tanggal_jadwal'] ?? '';
    $jamMulai = $_POST['jam_mulai'] ?? '';
    $jamSelesai = $_POST['jam_selesai'] ?? '';
    $keterangan = trim($_POST['keterangan'] ?? '');

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    $teknisiValid = false;
    foreach ($daftarTeknisi as $t) {
        if ((int) $t['id'] === $teknisiId) {
            $teknisiValid = true;
            break;
        }
    }

    if (empty($daftarTeknisi)) {
        $error = 'Belum ada teknisi terdaftar. Tambahkan user dengan role teknisi terlebih dahulu.';
    } elseif (!$teknisiValid) {
        $error = 'Teknisi tidak ditemukan atau tidak aktif. Muat ulang halaman.';
    } elseif ($tanggalJadwal === '' || $jamMulai === '' || $jamSelesai === '') {
        $error = 'Tanggal jadwal, jam mulai, dan jam selesai wajib diisi.';
    } elseif (!tanggalValid($tanggalJadwal)) {
        $error = 'Tanggal jadwal tidak valid.';
    } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jamMulai) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jamSelesai)) {
        $error = 'Format jam mulai dan jam selesai tidak valid.';
    } elseif ($jamSelesai <= $jamMulai) {
        $error = 'Jam selesai harus setelah jam mulai.';
    } else {
        $stmtJadwal = $pdo->prepare(
            "INSERT INTO penjadwalan (booking_id, teknisi_id, tanggal_jadwal, jam_mulai, jam_selesai, dijadwalkan_oleh, keterangan)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmtJadwal->execute([$bookingId, $teknisiId, $tanggalJadwal, $jamMulai, $jamSelesai, $user['id'], $keterangan]);

        $stmtUpdate = $pdo->prepare("UPDATE bookings SET status_booking = 'jadwal_ditentukan' WHERE id = ?");
        $stmtUpdate->execute([$bookingId]);

        $stmtTeknisi = $pdo->prepare("SELECT nama_lengkap FROM users WHERE id = ?");
        $stmtTeknisi->execute([$teknisiId]);
        $namaTeknisi = $stmtTeknisi->fetchColumn();

        $stmtRiwayat = $pdo->prepare(
            "INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan)
             VALUES (?, 'jadwal_ditentukan', ?, ?)"
        );
        $stmtRiwayat->execute([$bookingId, $user['id'], "Jadwal ditentukan: " . date('d-m-Y', strtotime($tanggalJadwal)) . ", Teknisi: $namaTeknisi"]);

        header('Location: kelola_booking.php?msg=jadwal_berhasil');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Jadwal - Admin</title>
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
            <h4>Buat Jadwal Pengujian</h4>
            <p>Tentukan jadwal dan teknisi untuk booking ini</p>
        </div>

        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

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
                        <br><small class="text-muted"><?= htmlspecialchars($booking['nama_perusahaan'] ?? '-') ?></small>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Jenis Pengujian</small>
                        <strong><?= htmlspecialchars($booking['nama_pengujian']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Jumlah Sampel</small>
                        <strong><?= $booking['jumlah_sampel'] ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($daftarTeknisi)): ?>
            <div class="alert alert-warning">
                Belum ada akun teknisi di sistem. Tambahkan user dengan role "teknisi" terlebih dahulu.
            </div>
        <?php else: ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong><i class="bi bi-calendar-check"></i> Formulir Jadwal</strong></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">

                    <div class="mb-3">
                        <label class="form-label">Pilih Teknisi <span class="text-danger">*</span></label>
                        <select name="teknisi_id" class="form-select" required>
                            <option value="">-- Pilih Teknisi --</option>
                            <?php foreach ($daftarTeknisi as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['nama_lengkap']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Tanggal Jadwal <span class="text-danger">*</span></label>
                        <input type="date" name="tanggal_jadwal" class="form-control"
                               value="<?= htmlspecialchars($booking['tanggal_pengujian_diminta']) ?>" required>
                    </div>

                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Jam Mulai</label>
                            <input type="time" name="jam_mulai" class="form-control" value="08:00">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Jam Selesai</label>
                            <input type="time" name="jam_selesai" class="form-control" value="16:00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Keterangan (opsional)</label>
                        <textarea name="keterangan" class="form-control" rows="2" placeholder="Catatan tambahan..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2">Simpan Jadwal</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
