<?php
require '../config/database.php';
require '../config/auth.php';
requireRole(['pelanggan']);

$user = currentUser($pdo);
$hari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$bulanArr = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$tanggalHari = $hari[date('w')] . ', ' . date('d') . ' ' . $bulanArr[date('m')-1] . ' ' . date('Y');

$stmt = $pdo->prepare("SELECT status_booking, COUNT(*) as jumlah FROM bookings WHERE user_id = ? GROUP BY status_booking");
$stmt->execute([$user['id']]);
$ringkasan = $stmt->fetchAll();

$totalBooking = array_sum(array_column($ringkasan, 'jumlah'));

$totalProses = 0;
foreach ($ringkasan as $r) {
    if (!in_array($r['status_booking'], ['selesai', 'ditolak'], true)) {
        $totalProses += (int) $r['jumlah'];
    }
}

$bookingAktif = $pdo->prepare(
    "SELECT b.*, j.nama_pengujian FROM bookings b
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     WHERE b.user_id = ? AND b.status_booking NOT IN ('selesai','ditolak')
     ORDER BY b.created_at DESC LIMIT 1"
);
$bookingAktif->execute([$user['id']]);
$aktif = $bookingAktif->fetch();

$bookingSelesai = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status_booking = 'selesai'");
$bookingSelesai->execute([$user['id']]);
$totalSelesai = $bookingSelesai->fetchColumn();

require '../config/labels.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Pelanggan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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

    <div class="page-container">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
            <div>
                <h4 class="mb-1">Selamat datang, <?= htmlspecialchars($user['nama_lengkap']) ?>!</h4>
                <p class="text-muted mb-0"><i class="bi bi-calendar3"></i> <?= $tanggalHari ?></p>
            </div>
            <a href="booking.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Booking Baru</a>
        </div>

        <?php if ($aktif): [$label, $warna] = labelStatus($aktif['status_booking']); ?>
        <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #7c2d12, #c2410c); color: #fff;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="opacity-75">Booking Aktif</small>
                        <h5 class="mb-1"><?= htmlspecialchars($aktif['kode_booking']) ?></h5>
                        <small class="opacity-75"><?= htmlspecialchars($aktif['nama_pengujian']) ?> &bull; <?= date('d M Y', strtotime($aktif['tanggal_pengujian_diminta'])) ?></small>
                    </div>
                    <div class="text-end">
                        <?php if (($aktif['tipe_booking'] ?? 'online') === 'online'): ?>
                            <span class="badge bg-success mb-1" style="font-size: 0.65rem;"><i class="bi bi-globe"></i> Online</span>
                        <?php else: ?>
                            <span class="badge bg-info mb-1" style="font-size: 0.65rem;"><i class="bi bi-building"></i> Offline</span>
                        <?php endif; ?>
                        <br>
                        <span class="badge bg-light" style="color: #c2410c;"><?= $label ?></span>
                        <br>
                        <a href="detail_booking.php?id=<?= $aktif['id'] ?>" class="btn btn-sm btn-light mt-2">Lihat Detail</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-4">
                <div class="card stat-card stat-secondary">
                    <div class="card-body text-center">
                        <i class="bi bi-folder" style="font-size: 1.5rem; opacity: 0.5;"></i>
                        <h3 class="mb-0 mt-1"><?= $totalBooking ?></h3>
                        <p>Total Booking</p>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="card stat-card stat-warning">
                    <div class="card-body text-center">
                        <i class="bi bi-arrow-repeat" style="font-size: 1.5rem; opacity: 0.5;"></i>
                        <h3 class="mb-0 mt-1"><?= $totalProses ?></h3>
                        <p>Proses</p>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="card stat-card stat-success">
                    <div class="card-body text-center">
                        <i class="bi bi-check-circle" style="font-size: 1.5rem; opacity: 0.5;"></i>
                        <h3 class="mb-0 mt-1"><?= $totalSelesai ?></h3>
                        <p>Selesai</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock text-primary"></i> <strong>Booking Terbaru</strong></span>
                <a href="riwayat.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($aktif)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                        <p class="text-muted mt-2 mb-3">Belum ada booking.</p>
                        <a href="booking.php" class="btn btn-primary btn-sm">Buat Sekarang</a>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Kode</th><th>Tipe</th><th>Jenis Pengujian</th><th>Tanggal</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <?php
                            $stmtAll = $pdo->prepare(
                                "SELECT b.*, j.nama_pengujian FROM bookings b
                                 JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
                                 WHERE b.user_id = ? ORDER BY b.created_at DESC LIMIT 5"
                            );
                            $stmtAll->execute([$user['id']]);
                            foreach ($stmtAll->fetchAll() as $b): [$label, $warna] = labelStatus($b['status_booking']);
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($b['kode_booking']) ?></strong></td>
                                <td>
                                    <?php if (($b['tipe_booking'] ?? 'online') === 'online'): ?>
                                        <span class="badge bg-success" style="font-size: 0.65rem;"><i class="bi bi-globe"></i> Online</span>
                                    <?php else: ?>
                                        <span class="badge bg-info" style="font-size: 0.65rem;"><i class="bi bi-building"></i> Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($b['nama_pengujian']) ?></td>
                                <td><small class="text-muted"><?= date('d M Y', strtotime($b['created_at'])) ?></small></td>
                                <td><span class="badge bg-<?= $warna ?>"><?= $label ?></span></td>
                                <td><a href="detail_booking.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary">Detail</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
