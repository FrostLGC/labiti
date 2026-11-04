<?php
require '../config/database.php';
require '../config/auth.php';
require_once '../config/labels.php';
requireRole(['pelanggan']);

$user = currentUser($pdo);
$bookingId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT b.*, j.nama_pengujian, j.kategori, j.satuan_sampel
     FROM bookings b
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     WHERE b.id = ? AND b.user_id = ?"
);
$stmt->execute([$bookingId, $user['id']]);
$booking = $stmt->fetch();

if (!$booking) {
    die('Booking tidak ditemukan atau bukan milik Anda.');
}

$stmtRiwayat = $pdo->prepare(
    "SELECT * FROM riwayat_status WHERE booking_id = ? ORDER BY created_at ASC"
);
$stmtRiwayat->execute([$bookingId]);
$riwayat = $stmtRiwayat->fetchAll();

$tipeBooking = $booking['tipe_booking'] ?? 'online';

if ($tipeBooking === 'offline') {
    $urutanTahap = [
        'menunggu_verifikasi' => 'Menunggu Verifikasi',
        'booking_diterima'    => 'Booking Diterima',
        'jadwal_ditentukan'   => 'Jadwal Ditentukan',
        'sampel_diterima'     => 'Sampel Diterima',
        'menunggu_diuji'      => 'Menunggu Diuji',
        'sedang_diuji'        => 'Sedang Diuji',
        'menunggu_input_admin' => 'Menunggu Input Admin',
        'validasi_hasil'      => 'Menunggu Validasi Hasil',
        'menunggu_pembayaran' => 'Menunggu Pembayaran (di Loket)',
        'selesai'             => 'Selesai',
    ];
} else {
    $urutanTahap = [
        'menunggu_verifikasi' => 'Menunggu Verifikasi',
        'booking_diterima'    => 'Booking Diterima',
        'jadwal_ditentukan'   => 'Jadwal Ditentukan',
        'sampel_diterima'     => 'Sampel Diterima',
        'menunggu_diuji'      => 'Menunggu Diuji',
        'sedang_diuji'        => 'Sedang Diuji',
        'menunggu_input_admin' => 'Menunggu Input Admin',
        'validasi_hasil'      => 'Menunggu Validasi Hasil',
        'menunggu_pembayaran' => 'Menunggu Pembayaran',
        'selesai'             => 'Selesai',
    ];
}

$statusTercapai = [];
foreach ($riwayat as $r) {
    $statusTercapai[$r['status']] = $r;
}

$statusSaatIni = $booking['status_booking'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Booking <?= htmlspecialchars($booking['kode_booking']) ?></title>
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

    <div class="page-container" style="max-width: 720px;">
        <a href="riwayat.php" class="text-decoration-none text-muted">&larr; Kembali ke Riwayat</a>

        <div class="page-header mt-2">
            <h4><?= htmlspecialchars($booking['kode_booking']) ?></h4>
            <p>Detail booking dan status pengujian</p>
            <?php if ($tipeBooking === 'online'): ?>
                <span class="badge bg-success" style="font-size: 0.75rem;"><i class="bi bi-globe"></i> Online — Dibuat melalui website</span>
            <?php else: ?>
                <span class="badge bg-info" style="font-size: 0.75rem;"><i class="bi bi-building"></i> Offline — Walk-in / Tatap Muka</span>
            <?php endif; ?>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>Informasi Booking</strong></div>
            <div class="card-body">
                <div class="row g-3">
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
                    <?php if (!empty($booking['objek_pengujian'])): ?>
                    <div class="col-12">
                        <small class="text-muted d-block">Nama Proyek</small>
                        <strong><?= htmlspecialchars($booking['objek_pengujian']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Tanggal Pengujian</small>
                        <strong><?= date('d F Y', strtotime($booking['tanggal_pengujian_diminta'])) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Estimasi Biaya</small>
                        <strong>Rp <?= number_format($booking['estimasi_biaya'], 0, ',', '.') ?></strong>
                    </div>
                    <?php if ($booking['catatan_pelanggan']): ?>
                    <div class="col-12">
                        <small class="text-muted d-block">Catatan</small>
                        <strong><?= htmlspecialchars($booking['catatan_pelanggan']) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($statusSaatIni === 'ditolak'): ?>
            <div class="alert alert-danger">
                <strong>Booking Ditolak.</strong>
                <?php if ($booking['alasan_ditolak']): ?>
                    <p class="mb-0 mt-1">Alasan: <?= htmlspecialchars($booking['alasan_ditolak']) ?></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Status Booking</strong></div>
                <div class="card-body">
                    <?php
                    $tahapKeys = array_keys($urutanTahap);
                    $totalTahap = count($tahapKeys);
                    $semuaSelesai = ($statusSaatIni === 'selesai');
                    foreach ($tahapKeys as $i => $key):
                        $sudahTercapai = isset($statusTercapai[$key]) || $semuaSelesai;
                        $adalahSaatIni = ($key === $statusSaatIni) && !$semuaSelesai;
                        $kelas = $sudahTercapai
                            ? ($adalahSaatIni ? 'timeline-current' : 'timeline-done')
                            : 'timeline-upcoming';
                        $isLast = ($i === $totalTahap - 1);
                    ?>
                    <div class="timeline-item <?= $kelas ?>">
                        <div class="d-flex flex-column align-items-center">
                            <div class="timeline-dot">
                                <?php if ($sudahTercapai && !$adalahSaatIni): ?>
                                    &#10003;
                                <?php elseif ($adalahSaatIni): ?>
                                    &#9679;
                                <?php endif; ?>
                            </div>
                            <?php if (!$isLast): ?>
                                <div class="timeline-line"></div>
                            <?php endif; ?>
                        </div>
                        <div class="pb-4">
                            <p class="mb-0 <?= $adalahSaatIni ? 'fw-bold text-primary' : '' ?>">
                                <?= $urutanTahap[$key] ?>
                            </p>
                            <?php if (isset($statusTercapai[$key])): ?>
                                <small class="text-muted">
                                    <?= date('d M Y, H:i', strtotime($statusTercapai[$key]['created_at'])) ?>
                                    <?php if ($statusTercapai[$key]['keterangan']): ?>
                                        &mdash; <?= htmlspecialchars($statusTercapai[$key]['keterangan']) ?>
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($statusSaatIni === 'selesai' && $tipeBooking === 'online'): ?>
            <div class="text-center mt-3 mb-4">
                <a href="download_laporan.php?booking_id=<?= $booking['id'] ?>" class="btn btn-success">
                    Cek Hasil Laporan
                </a>
            </div>
        <?php elseif ($statusSaatIni === 'selesai' && $tipeBooking === 'offline'): ?>
            <div class="text-center mt-3 mb-4">
                <a href="download_laporan.php?booking_id=<?= $booking['id'] ?>" class="btn btn-success">
                    Cek Hasil Laporan
                </a>
                <div class="alert alert-info mt-2 mb-0">
                    <i class="bi bi-info-circle"></i> Laporan juga dapat diambil di loket laboratorium.
                </div>
            </div>
        <?php elseif ($statusSaatIni === 'menunggu_pembayaran' && $tipeBooking === 'offline'): ?>
            <div class="card mb-3">
                <div class="card-body text-center">
                    <i class="bi bi-building text-info" style="font-size: 2rem;"></i>
                    <p class="mt-2 mb-2" style="font-size: 0.9375rem;">Silakan lakukan pembayaran di loket kasir sebesar:</p>
                    <h4 class="text-danger mb-3">Rp <?= number_format($booking['estimasi_biaya'], 0, ',', '.') ?></h4>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle"></i> Setelah membayar di loket, admin akan memverifikasi dan booking akan selesai.
                    </div>
                </div>
            </div>
        <?php elseif ($statusSaatIni === 'menunggu_pembayaran' && $tipeBooking === 'online'): ?>
            <div class="card mb-3">
                <div class="card-body text-center">
                    <p class="mb-2" style="font-size: 0.9375rem;">Pengujian telah selesai. Silakan melakukan pembayaran sebesar:</p>
                    <h4 class="text-danger mb-3">Rp <?= number_format($booking['estimasi_biaya'], 0, ',', '.') ?></h4>
                    <a href="upload_pembayaran.php?id=<?= $booking['id'] ?>" class="btn btn-primary">
                        Upload Bukti Transfer
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
