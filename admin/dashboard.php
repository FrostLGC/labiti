<?php
require '../config/database.php';
require '../config/auth.php';
requireRole(['admin']);

$user = currentUser($pdo);
$hari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$bulan = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$tanggalHari = $hari[date('w')] . ', ' . date('d') . ' ' . $bulan[date('m')-1] . ' ' . date('Y');

$totalMenunggu = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status_booking = 'menunggu_verifikasi'")->fetchColumn();
$totalBayar = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status_booking = 'menunggu_pembayaran'")->fetchColumn();
$totalInputAdmin = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status_booking = 'menunggu_input_admin'")->fetchColumn();
$totalValidasiHasil = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status_booking = 'validasi_hasil'")->fetchColumn();
$totalBerjalan = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status_booking IN ('booking_diterima','jadwal_ditentukan','menunggu_diuji','sedang_diuji')")->fetchColumn();
$totalSelesai = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status_booking = 'selesai'")->fetchColumn();
$totalUser = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalDitolak = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status_booking = 'ditolak'")->fetchColumn();
$totalBooking = $totalMenunggu + $totalBayar + $totalInputAdmin + $totalValidasiHasil + $totalBerjalan + $totalSelesai + $totalDitolak;
$totalOnline = $pdo->query("SELECT COUNT(*) FROM bookings WHERE tipe_booking = 'online'")->fetchColumn();
$totalOffline = $pdo->query("SELECT COUNT(*) FROM bookings WHERE tipe_booking = 'offline'")->fetchColumn();

$bookingTerbaru = $pdo->query(
    "SELECT b.*, j.nama_pengujian, u.nama_lengkap as nama_pelanggan
     FROM bookings b
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     JOIN users u ON b.user_id = u.id
     ORDER BY b.created_at DESC LIMIT 5"
)->fetchAll();

$pengujianHariIni = $pdo->query(
    "SELECT p.*, b.kode_booking, j.nama_pengujian, u.nama_lengkap as nama_teknisi
     FROM penjadwalan p
     JOIN bookings b ON p.booking_id = b.id
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     JOIN users u ON p.teknisi_id = u.id
     WHERE p.tanggal_jadwal = CURDATE() AND b.status_booking != 'ditolak'
     ORDER BY p.jam_mulai ASC"
)->fetchAll();

require '../config/labels.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin</title>
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

    <div class="page-container">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
            <div>
                <h4 class="mb-1">Selamat datang, <?= htmlspecialchars($user['nama_lengkap']) ?>!</h4>
                <p class="text-muted mb-0"><i class="bi bi-calendar3"></i> <?= $tanggalHari ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="kelola_booking.php" class="btn btn-primary btn-sm"><i class="bi bi-folder2-open"></i> Kelola Booking</a>
                <a href="booking_manual.php" class="btn btn-success btn-sm"><i class="bi bi-person-plus"></i> Booking Manual</a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="card stat-card stat-secondary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3 class="mb-0"><?= $totalMenunggu ?></h3><p class="mb-0 mt-1">Menunggu Verifikasi</p></div>
                            <i class="bi bi-clock-history" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card stat-card stat-warning">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3 class="mb-0"><?= $totalBayar ?></h3><p class="mb-0 mt-1">Menunggu Pembayaran</p></div>
                            <i class="bi bi-wallet2" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card stat-card stat-info">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3 class="mb-0"><?= $totalInputAdmin ?></h3><p class="mb-0 mt-1">Input Nilai</p></div>
                            <i class="bi bi-pencil-square" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card stat-card stat-warning">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3 class="mb-0"><?= $totalValidasiHasil ?></h3><p class="mb-0 mt-1">Validasi Hasil</p></div>
                            <i class="bi bi-clipboard-check" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="card stat-card stat-primary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3 class="mb-0"><?= $totalBerjalan ?></h3><p class="mb-0 mt-1">Sedang Berjalan</p></div>
                            <i class="bi bi-arrow-repeat" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card stat-card stat-success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3 class="mb-0"><?= $totalSelesai ?></h3><p class="mb-0 mt-1">Selesai</p></div>
                            <i class="bi bi-check-circle" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card stat-card stat-danger">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3 class="mb-0"><?= $totalDitolak ?></h3><p class="mb-0 mt-1">Ditolak</p></div>
                            <i class="bi bi-x-circle" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card stat-card stat-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3 class="mb-0"><?= $totalBooking ?></h3><p class="mb-0 mt-1">Total Booking</p></div>
                            <i class="bi bi-folder" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($pengujianHariIni)): ?>
        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar-check text-success"></i> <strong>Jadwal Pengujian Hari Ini</strong></span>
                <span class="badge bg-success"><?= count($pengujianHariIni) ?> Pengujian</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Waktu</th><th>Kode</th><th>Jenis Uji</th><th>Teknisi</th></tr></thead>
                        <tbody>
                            <?php foreach ($pengujianHariIni as $p): ?>
                            <tr>
                                <td><span class="badge bg-success"><?= date('H:i', strtotime($p['jam_mulai'])) ?> - <?= date('H:i', strtotime($p['jam_selesai'])) ?></span></td>
                                <td><strong><?= htmlspecialchars($p['kode_booking']) ?></strong></td>
                                <td><?= htmlspecialchars($p['nama_pengujian']) ?></td>
                                <td><?= htmlspecialchars($p['nama_teknisi']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-clock text-primary"></i> <strong>Booking Terbaru</strong></span>
                        <a href="kelola_booking.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>Kode</th><th>Tipe</th><th>Pelanggan</th><th>Jenis Uji</th><th>Status</th><th></th></tr></thead>
                                <tbody>
                                    <?php foreach ($bookingTerbaru as $b): [$label, $warna] = labelStatus($b['status_booking']); ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($b['kode_booking']) ?></strong></td>
                                        <td>
                                            <?php if (($b['tipe_booking'] ?? 'online') === 'online'): ?>
                                                <span class="badge bg-success" style="font-size: 0.65rem;"><i class="bi bi-globe"></i> Online</span>
                                            <?php else: ?>
                                                <span class="badge bg-info" style="font-size: 0.65rem;"><i class="bi bi-building"></i> Offline</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($b['nama_pelanggan']) ?></td>
                                        <td><?= htmlspecialchars($b['nama_pengujian']) ?></td>
                                        <td><span class="badge bg-<?= $warna ?>"><?= $label ?></span></td>
                                        <td><a href="kelola_booking.php" class="btn btn-sm btn-outline-primary">Proses</a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><strong><i class="bi bi-graph-up"></i> Ringkasan</strong></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small class="text-muted">Verifikasi</small>
                                <small><strong><?= $totalMenunggu ?></strong></small>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-secondary" style="width: <?= $totalBooking > 0 ? ($totalMenunggu/$totalBooking*100) : 0 ?>%"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small class="text-muted">Input Nilai</small>
                                <small><strong><?= $totalInputAdmin ?></strong></small>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-info" style="width: <?= $totalBooking > 0 ? ($totalInputAdmin/$totalBooking*100) : 0 ?>%"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small class="text-muted">Berjalan</small>
                                <small><strong><?= $totalBerjalan ?></strong></small>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-primary" style="width: <?= $totalBooking > 0 ? ($totalBerjalan/$totalBooking*100) : 0 ?>%"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small class="text-muted">Selesai</small>
                                <small><strong><?= $totalSelesai ?></strong></small>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-success" style="width: <?= $totalBooking > 0 ? ($totalSelesai/$totalBooking*100) : 0 ?>%"></div>
                            </div>
                        </div>
                        <hr>
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="border rounded py-2">
                                    <h5 class="mb-0 text-success"><?= $totalOnline ?></h5>
                                    <small class="text-muted">Online</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded py-2">
                                    <h5 class="mb-0 text-info"><?= $totalOffline ?></h5>
                                    <small class="text-muted">Offline</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
