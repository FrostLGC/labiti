<?php
require '../config/database.php';
require '../config/auth.php';
requireRole(['owner']);

$user = currentUser($pdo);
$hari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$bulanArr = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$tanggalHari = $hari[date('w')] . ', ' . date('d') . ' ' . $bulanArr[date('m')-1] . ' ' . date('Y');

$totalBooking = $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$totalSelesai = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status_booking = 'selesai'")->fetchColumn();
$totalBerjalan = $pdo->query(
    "SELECT COUNT(*) FROM bookings WHERE status_booking IN
     ('booking_diterima','jadwal_ditentukan','menunggu_diuji','sedang_diuji','menunggu_input_admin')"
)->fetchColumn();

$totalPendapatan = $pdo->query(
    "SELECT COALESCE(SUM(estimasi_biaya), 0) FROM bookings WHERE status_booking = 'selesai'"
)->fetchColumn();

$pendapatanBulanIni = $pdo->query(
    "SELECT COALESCE(SUM(estimasi_biaya), 0) FROM bookings WHERE status_booking = 'selesai' AND MONTH(updated_at) = MONTH(CURDATE()) AND YEAR(updated_at) = YEAR(CURDATE())"
)->fetchColumn();

$stmtPerBulan = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') as bulan, COUNT(*) as jumlah
     FROM bookings
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY bulan
     ORDER BY bulan ASC"
);
$dataPerBulan = $stmtPerBulan->fetchAll();
$labelBulan = array_column($dataPerBulan, 'bulan');
$jumlahBulan = array_column($dataPerBulan, 'jumlah');

$bulanMap = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun','07'=>'Jul','08'=>'Agu','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];
$labelBulanPretty = array_map(function($b) use ($bulanMap) {
    $parts = explode('-', $b);
    return $bulanMap[$parts[1]] . ' ' . substr($parts[0], 2);
}, $labelBulan);

$stmtPerKategori = $pdo->query(
    "SELECT j.kategori, COUNT(*) as jumlah
     FROM bookings b JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     GROUP BY j.kategori"
);
$dataKategori = $stmtPerKategori->fetchAll();
$labelKategori = array_map('ucfirst', array_column($dataKategori, 'kategori'));
$jumlahKategori = array_column($dataKategori, 'jumlah');

$stmtTeknisi = $pdo->query(
    "SELECT u.nama_lengkap,
            COUNT(DISTINCT CASE WHEN s.status_sampel IN ('diterima','sedang_diuji') THEN s.id END) as tugas_aktif,
            COUNT(DISTINCT CASE WHEN s.status_sampel = 'selesai_diuji' THEN s.id END) as tugas_selesai
     FROM users u
     LEFT JOIN penjadwalan p ON p.teknisi_id = u.id
     LEFT JOIN sampel s ON s.booking_id = p.booking_id
     WHERE u.role = 'teknisi'
     GROUP BY u.id, u.nama_lengkap"
);
$dataTeknisi = $stmtTeknisi->fetchAll();

$bookingTerbaru = $pdo->query(
    "SELECT b.*, j.nama_pengujian, u.nama_lengkap as nama_pelanggan
     FROM bookings b
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     JOIN users u ON b.user_id = u.id
     ORDER BY b.created_at DESC LIMIT 5"
)->fetchAll();

require '../config/labels.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Owner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-custom navbar-owner">
        <div class="container">
            <a class="navbar-brand text-white" href="dashboard.php"><img src="../img1.jpeg" alt="Logo"> Laboratorium PT ITI Utama</a>
            <button class="navbar-toggler text-white border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navOwner">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navOwner">
                <div class="ms-auto d-flex align-items-center gap-2 nav-actions">
                    <a href="monitoring.php" class="btn btn-outline-light btn-sm">Monitoring</a>
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
            <a href="monitoring.php" class="btn btn-primary"><i class="bi bi-graph-up"></i> Monitoring</a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-4">
                <div class="card stat-card stat-primary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3 class="mb-0"><?= $totalBooking ?></h3><p class="mb-0 mt-1">Total Booking</p></div>
                            <i class="bi bi-folder" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-4">
                <div class="card stat-card stat-info">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3 class="mb-0"><?= $totalBerjalan ?></h3><p class="mb-0 mt-1">Sedang Berjalan</p></div>
                            <i class="bi bi-arrow-repeat" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-4">
                <div class="card stat-card stat-success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3 class="mb-0"><?= $totalSelesai ?></h3><p class="mb-0 mt-1">Selesai</p></div>
                            <i class="bi bi-check-circle" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6">
                <div class="card stat-card stat-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h3 class="mb-0" style="font-size: 1.6rem;">Rp&nbsp;<?= number_format($totalPendapatan, 0, ',', '.') ?></h3>
                                <p class="mb-0 mt-1">Total Pendapatan</p>
                            </div>
                            <i class="bi bi-cash-stack" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="card stat-card stat-secondary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h3 class="mb-0" style="font-size: 1.6rem;">Rp&nbsp;<?= number_format($pendapatanBulanIni, 0, ',', '.') ?></h3>
                                <p class="mb-0 mt-1">Pendapatan Bulan Ini</p>
                            </div>
                            <i class="bi bi-graph-up-arrow" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white"><strong><i class="bi bi-bar-chart"></i> Booking per Bulan</strong></div>
                    <div class="card-body">
                        <div style="height: 260px;"><canvas id="chartBulan"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white"><strong><i class="bi bi-pie-chart"></i> Booking per Kategori</strong></div>
                    <div class="card-body">
                        <div style="height: 260px;"><canvas id="chartKategori"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white"><strong><i class="bi bi-people"></i> Monitoring Teknisi</strong></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th class="text-nowrap">Nama Teknisi</th><th class="text-nowrap">Tugas Aktif</th><th class="text-nowrap">Selesai</th><th style="min-width: 150px;">Progres</th></tr></thead>
                                <tbody>
                                    <?php foreach ($dataTeknisi as $t):
                                        $total = $t['tugas_aktif'] + $t['tugas_selesai'];
                                        $prosentase = $total > 0 ? round($t['tugas_selesai']/$total*100) : 0;
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($t['nama_lengkap']) ?></strong></td>
                                        <td><span class="badge bg-warning"><?= $t['tugas_aktif'] ?></span></td>
                                        <td><span class="badge bg-success"><?= $t['tugas_selesai'] ?></span></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1" style="height: 6px;">
                                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?= $prosentase ?>%" aria-valuenow="<?= $prosentase ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <small class="text-muted text-end" style="min-width: 38px;"><?= $prosentase ?>%</small>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($dataTeknisi)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4">Tidak ada data teknisi.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span><strong><i class="bi bi-clock"></i> Booking Terbaru</strong></span>
                        <a href="monitoring.php" class="btn btn-sm btn-outline-primary">Semua</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th class="text-nowrap">Booking</th><th class="text-nowrap">Tipe</th><th class="text-nowrap">Pelanggan</th><th class="text-nowrap">Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($bookingTerbaru as $b): [$label, $warna] = labelStatus($b['status_booking']); ?>
                                    <tr>
                                        <td>
                                            <strong style="font-size: 0.8125rem;"><?= htmlspecialchars($b['kode_booking']) ?></strong>
                                            <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($b['nama_pengujian']) ?></div>
                                        </td>
                                        <td>
                                            <?php if (($b['tipe_booking'] ?? 'online') === 'online'): ?>
                                                <span class="badge bg-success" style="font-size: 0.6rem;"><i class="bi bi-globe"></i> Online</span>
                                            <?php else: ?>
                                                <span class="badge bg-info" style="font-size: 0.6rem;"><i class="bi bi-building"></i> Offline</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.8125rem;"><?= htmlspecialchars($b['nama_pelanggan']) ?></td>
                                        <td><span class="badge bg-<?= $warna ?>" style="font-size: 0.65rem;"><?= $label ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($bookingTerbaru)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4">Belum ada booking.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        new Chart(document.getElementById('chartBulan'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($labelBulanPretty) ?>,
                datasets: [{
                    label: 'Jumlah Booking',
                    data: <?= json_encode($jumlahBulan) ?>,
                    backgroundColor: '#ea580c',
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f1f5f9' } },
                    x: { grid: { display: false } }
                },
                plugins: { legend: { display: false } }
            }
        });

        new Chart(document.getElementById('chartKategori'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($labelKategori) ?>,
                datasets: [{
                    data: <?= json_encode($jumlahKategori) ?>,
                    backgroundColor: ['#ea580c', '#16a34a', '#f59e0b', '#dc2626', '#78716c'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: { legend: { position: 'bottom' } }
            }
        });
    </script>
</body>
</html>
