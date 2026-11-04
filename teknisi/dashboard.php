<?php
require '../config/database.php';
require '../config/auth.php';
requireRole(['teknisi']);

$user = currentUser($pdo);
$hari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$bulanArr = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$tanggalHari = $hari[date('w')] . ', ' . date('d') . ' ' . $bulanArr[date('m')-1] . ' ' . date('Y');

$totalTugas = $pdo->prepare(
    "SELECT COUNT(DISTINCT b.id) FROM penjadwalan p
     JOIN bookings b ON p.booking_id = b.id
     WHERE p.teknisi_id = ? AND b.status_booking IN ('menunggu_diuji', 'sedang_diuji', 'jadwal_ditentukan', 'sampel_diterima')"
);
$totalTugas->execute([$user['id']]);
$jumlahTugas = $totalTugas->fetchColumn();

$totalSelesai = $pdo->prepare(
    "SELECT COUNT(DISTINCT b.id) FROM penjadwalan p
     JOIN bookings b ON p.booking_id = b.id
     WHERE p.teknisi_id = ? AND b.status_booking IN ('menunggu_input_admin', 'validasi_hasil', 'menunggu_pembayaran', 'selesai')"
);
$totalSelesai->execute([$user['id']]);
$jumlahSelesai = $totalSelesai->fetchColumn();

$jadwalHariIni = $pdo->prepare(
    "SELECT p.*, b.kode_booking, j.nama_pengujian, u.nama_lengkap as nama_pelanggan,
            (SELECT COUNT(*) FROM sampel s WHERE s.booking_id = b.id AND s.status_sampel IN ('diterima','sedang_diuji')) as sisa_sampel,
            (SELECT COUNT(*) FROM sampel s WHERE s.booking_id = b.id AND s.status_sampel = 'selesai_diuji') as selesai_sampel,
            (SELECT COUNT(*) FROM sampel s WHERE s.booking_id = b.id) as total_sampel
     FROM penjadwalan p
     JOIN bookings b ON p.booking_id = b.id
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     JOIN users u ON b.user_id = u.id
     WHERE p.teknisi_id = ? AND p.tanggal_jadwal = CURDATE() AND b.status_booking NOT IN ('ditolak', 'selesai')
     ORDER BY p.jam_mulai ASC"
);
$jadwalHariIni->execute([$user['id']]);
$dataHariIni = $jadwalHariIni->fetch();

$totalSampelAktif = 0;
$totalSampelSelesai = 0;
if ($dataHariIni) {
    $totalSampelAktif = $dataHariIni['sisa_sampel'];
    $totalSampelSelesai = $dataHariIni['selesai_sampel'];
}

// History pengujian yang sudah selesai dikerjakan teknisi ini
$historyQuery = $pdo->prepare(
    "SELECT DISTINCT b.id, b.kode_booking, b.status_booking, b.tipe_booking, b.created_at as tgl_booking,
            j.nama_pengujian, u.nama_lengkap as nama_pelanggan,
            (SELECT COUNT(*) FROM sampel s WHERE s.booking_id = b.id) as total_sampel,
            (SELECT COUNT(*) FROM sampel s WHERE s.booking_id = b.id AND s.status_sampel = 'selesai_diuji') as selesai_sampel
     FROM penjadwalan p
     JOIN bookings b ON p.booking_id = b.id
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     JOIN users u ON b.user_id = u.id
     WHERE p.teknisi_id = ?
     ORDER BY b.created_at DESC
     LIMIT 10"
);
$historyQuery->execute([$user['id']]);
$historyList = $historyQuery->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Teknisi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-custom navbar-teknisi">
        <div class="container">
            <a class="navbar-brand text-white" href="dashboard.php"><img src="../img1.jpeg" alt="Logo"> Laboratorium PT ITI Utama</a>
            <button class="navbar-toggler text-white border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navTeknisi">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navTeknisi">
                <div class="ms-auto d-flex align-items-center gap-2 nav-actions">
                    <a href="dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
                    <a href="jadwal.php" class="btn btn-outline-light btn-sm">Jadwal</a>
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
            <a href="jadwal.php" class="btn btn-success"><i class="bi bi-calendar-check"></i> Lihat Jadwal</a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6">
                <div class="card stat-card stat-warning">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3><?= $jumlahTugas ?></h3><p>Tugas Aktif</p></div>
                            <i class="bi bi-clipboard-check" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="card stat-card stat-success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h3><?= $jumlahSelesai ?></h3><p>Tugas Selesai</p></div>
                            <i class="bi bi-check-circle" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($dataHariIni): ?>
        <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #7c2d12, #c2410c); color: #fff;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <small class="opacity-75">Jadwal Hari Ini</small>
                        <h5 class="mb-0"><?= htmlspecialchars($dataHariIni['kode_booking']) ?></h5>
                    </div>
                    <span class="badge bg-light" style="color: #c2410c;"><?= date('H:i', strtotime($dataHariIni['jam_mulai'])) ?> - <?= date('H:i', strtotime($dataHariIni['jam_selesai'])) ?></span>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <small class="opacity-75 d-block">Jenis Pengujian</small>
                        <strong><?= htmlspecialchars($dataHariIni['nama_pengujian']) ?></strong>
                    </div>
                    <div class="col-6">
                        <small class="opacity-75 d-block">Pelanggan</small>
                        <strong><?= htmlspecialchars($dataHariIni['nama_pelanggan']) ?></strong>
                    </div>
                </div>
                <div class="mt-3">
                    <div class="d-flex justify-content-between mb-1">
                        <small class="opacity-75">Progres Sampel</small>
                        <small><strong><?= $totalSampelSelesai ?>/<?= $dataHariIni['total_sampel'] ?></strong></small>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-light" style="width: <?= $dataHariIni['total_sampel'] > 0 ? ($totalSampelSelesai/$dataHariIni['total_sampel']*100) : 0 ?>%"></div>
                    </div>
                </div>
                <a href="jadwal.php" class="btn btn-light btn-sm mt-3 w-100">Buka Jadwal &rarr;</a>
            </div>
        </div>
        <?php else: ?>
        <div class="card border-0 shadow-sm mb-4 text-center py-4">
            <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-2 mb-0">Tidak ada jadwal pengujian hari ini.</p>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong><i class="bi bi-lightning"></i> Aksi Cepat</strong></div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6">
                        <a href="jadwal.php" class="btn btn-outline-primary w-100 py-3">
                            <i class="bi bi-calendar-week" style="font-size: 1.5rem;"></i><br>
                            <small>Lihat Jadwal</small>
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="../auth/logout.php" class="btn btn-outline-danger w-100 py-3">
                            <i class="bi bi-box-arrow-right" style="font-size: 1.5rem;"></i><br>
                            <small>Logout</small>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span><strong><i class="bi bi-clock-history"></i> History Pengujian</strong></span>
                <a href="jadwal.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Tipe</th>
                                <th>Pelanggan</th>
                                <th>Jenis Pengujian</th>
                                <th>Sampel</th>
                                <th>Tanggal</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($historyList)): ?>
                                <?php foreach ($historyList as $h):
                                    $map = [
                                        'menunggu_diuji' => ['Menunggu Diuji', 'warning'],
                                        'sedang_diuji' => ['Sedang Diuji', 'primary'],
                                        'menunggu_input_admin' => ['Menunggu Input', 'warning'],
                                        'menunggu_pembayaran' => ['Menunggu Pembayaran', 'warning'],
                                        'selesai' => ['Selesai', 'success'],
                                    ];
                                    [$label, $warna] = $map[$h['status_booking']] ?? [$h['status_booking'], 'secondary'];
                                ?>
                                <tr>
                                    <td><strong style="font-size: 0.8125rem;"><?= htmlspecialchars($h['kode_booking']) ?></strong></td>
                                    <td>
                                        <?php if (($h['tipe_booking'] ?? 'online') === 'online'): ?>
                                            <span class="badge bg-success" style="font-size: 0.6rem;"><i class="bi bi-globe"></i> Online</span>
                                        <?php else: ?>
                                            <span class="badge bg-info" style="font-size: 0.6rem;"><i class="bi bi-building"></i> Offline</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 0.8125rem;"><?= htmlspecialchars($h['nama_pelanggan']) ?></td>
                                    <td style="font-size: 0.8125rem;"><?= htmlspecialchars($h['nama_pengujian']) ?></td>
                                    <td style="font-size: 0.8125rem;"><?= $h['selesai_sampel'] ?>/<?= $h['total_sampel'] ?></td>
                                    <td style="font-size: 0.8125rem;"><?= date('d M Y', strtotime($h['tgl_booking'])) ?></td>
                                    <td><span class="badge bg-<?= $warna ?>" style="font-size: 0.65rem;"><?= $label ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Belum ada history pengujian.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
