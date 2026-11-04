<?php
require '../config/database.php';
require '../config/auth.php';
require '../config/labels.php';
require_once '../config/field_silinder.php';
require_once '../config/field_kubus.php';
requireRole(['pelanggan']);

$user = currentUser($pdo);
$booking = null;
$historis = null;
$laporan = null;
$error = '';
$kodeInput = trim($_GET['kode'] ?? $_POST['kode'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($kodeInput)) {
    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    $stmtL2 = $pdo->prepare(
        "SELECT l.*, b.*, j.nama_pengujian, j.kategori, u.nama_lengkap as nama_pelanggan, u.nama_perusahaan
         FROM laporan l
         JOIN bookings b ON l.booking_id = b.id
         JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
         JOIN users u ON b.user_id = u.id
         WHERE l.nomor_laporan = ? AND b.user_id = ?
         ORDER BY l.id DESC LIMIT 1"
    );
    $stmtL2->execute([$kodeInput, $user['id']]);
    $booking = $stmtL2->fetch();
    if ($booking) {
        $laporan = $booking;
    }

    if (!$booking) {
        $stmtH = $pdo->prepare(
            "SELECT h.*, u.nama_lengkap as nama_pelanggan, u.nama_perusahaan
             FROM hasil_pengujian h
             JOIN users u ON h.user_id = u.id
             WHERE h.kode = ? AND h.user_id = ? AND h.sampel_id IS NULL
             ORDER BY h.id ASC"
        );
        $stmtH->execute([$kodeInput, $user['id']]);
        $historis = $stmtH->fetchAll();
    }

    if (!$booking && empty($historis)) {
        $error = 'Nomor "' . htmlspecialchars($kodeInput) . '" tidak ditemukan atau bukan milik Anda.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validasi Dokumen - Pelanggan</title>
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

    <div class="page-container" style="max-width: 1200px;">
        <a href="dashboard.php" class="text-decoration-none text-muted">&larr; Kembali ke Dashboard</a>

        <div class="page-header mt-2">
            <h4>Validasi Dokumen</h4>
            <p>Masukkan nomor laporan untuk memvalidasi dokumen pengujian Anda</p>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="POST" class="d-flex gap-2">
                    <?= csrf_field() ?>
                    <input type="text" name="kode" class="form-control" required
                           placeholder="Nomor Laporan"
                           value="<?= htmlspecialchars($kodeInput) ?>">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-circle"></i> Validasi
                    </button>
                </form>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2">
                <i class="bi bi-x-circle" style="font-size:1.25rem;"></i>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <?php if ($booking): ?>
            <?php [$label, $warna] = labelStatus($booking['status_booking']); ?>
            <?php $adaLaporan = !empty($laporan); ?>
            <?php $laporanSiap = $adaLaporan && (($booking['status_booking'] ?? '') === 'selesai'); ?>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body text-center py-4">
                    <div style="font-size: 3rem; line-height: 1;">
                        <?php if ($laporanSiap): ?>
                            <i class="bi bi-check-circle-fill text-success"></i>
                        <?php elseif ($adaLaporan): ?>
                            <i class="bi bi-hourglass-split text-warning"></i>
                        <?php elseif ($booking['status_booking'] === 'selesai'): ?>
                            <i class="bi bi-hourglass-split text-warning"></i>
                        <?php else: ?>
                            <i class="bi bi-exclamation-circle-fill text-warning"></i>
                        <?php endif; ?>
                    </div>
                    <h5 class="mt-2 mb-1">
                        <?php if ($laporanSiap): ?>
                            <span class="text-success">Dokumen Tervalidasi</span>
                        <?php elseif ($adaLaporan): ?>
                            <span class="text-warning">Laporan Diterbitkan - Proses Belum Selesai</span>
                        <?php elseif ($booking['status_booking'] === 'selesai'): ?>
                            <span class="text-warning">Menunggu Penerbitan Laporan</span>
                        <?php else: ?>
                            <span class="text-muted">Proses Pengujian</span>
                        <?php endif; ?>
                    </h5>
                    <p class="text-muted mb-0" style="font-size: 0.875rem;">
                        Nomor Laporan: <strong><?= htmlspecialchars($booking['nomor_laporan'] ?? '-') ?></strong>
                    </p>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <table class="table table-borderless mb-0" style="font-size: 0.875rem;">
                        <tr>
                            <td class="text-muted" style="width: 140px;">Pelanggan</td>
                            <td><strong><?= htmlspecialchars($booking['nama_pelanggan']) ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Perusahaan</td>
                            <td><?= htmlspecialchars($booking['nama_perusahaan'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Jenis Pengujian</td>
                            <td><?= htmlspecialchars($booking['nama_pengujian']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Nama Proyek</td>
                            <td><?= htmlspecialchars($booking['objek_pengujian'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Status</td>
                            <td><span class="badge bg-<?= $warna ?>"><?= $label ?></span></td>
                        </tr>
                        <?php if ($adaLaporan): ?>
                        <tr>
                            <td class="text-muted">No. Laporan</td>
                            <td><strong><?= htmlspecialchars($laporan['nomor_laporan']) ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Tanggal Terbit</td>
                            <td><?= date('d-m-Y', strtotime($laporan['tanggal_terbit'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Laporan</td>
                            <td>
                                <?php if ($laporanSiap): ?>
                                    <a href="download_laporan.php?booking_id=<?= $booking['id'] ?>" class="btn btn-sm btn-success">
                                        <i class="bi bi-file-earmark-text"></i> Cek Hasil Laporan
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Laporan belum dapat diunduh (menunggu validasi &amp; pembayaran).</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($historis)): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body text-center py-4">
                    <div style="font-size: 3rem; line-height: 1;">
                        <i class="bi bi-check-circle-fill text-success"></i>
                    </div>
                    <h5 class="mt-2 mb-1"><span class="text-success">Data Historis Ditemukan</span></h5>
                    <p class="text-muted mb-0" style="font-size: 0.875rem;">
                        No. Laporan: <strong><?= htmlspecialchars($historis[0]['kode']) ?></strong>
                    </p>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <table class="table table-borderless mb-0" style="font-size: 0.875rem;">
                        <tr>
                            <td class="text-muted" style="width: 140px;">Pelanggan</td>
                            <td><strong><?= htmlspecialchars($historis[0]['nama_pelanggan']) ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Perusahaan</td>
                            <td><?= htmlspecialchars($historis[0]['nama_perusahaan'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Jenis Pengujian</td>
                            <td><?= htmlspecialchars($historis[0]['jenis_pengujian'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Tanggal Dites</td>
                            <td><?= $historis[0]['tgl_ditest'] ? date('d-m-Y', strtotime($historis[0]['tgl_ditest'])) : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Status</td>
                            <td>
                                <?php if ($historis[0]['status_verifikasi'] === 'terverifikasi'): ?>
                                    <span class="badge bg-success" style="font-size:0.75rem;"><i class="bi bi-check-circle-fill"></i> Tervalidasi</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark" style="font-size:0.75rem;"><i class="bi bi-clock-fill"></i> Menunggu Validasi Admin</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Laporan</td>
                            <td>
                                <?php if ($historis[0]['status_verifikasi'] === 'terverifikasi'): ?>
                                        <a href="download_laporan_historis.php?kode=<?= urlencode($historis[0]['kode']) ?>" target="_blank" class="btn btn-sm btn-success">
                                            <i class="bi bi-file-earmark-text"></i> Cek Hasil Laporan
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-secondary" disabled>
                                        <i class="bi bi-hourglass-split"></i> Belum Diverifikasi
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Dokumentasi</td>
                            <td>
                                <?php if (!empty($historis[0]['dokumentasi_file'])): ?>
                                    <a href="../<?= htmlspecialchars($historis[0]['dokumentasi_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i> Lihat Dokumen
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Detail Hasil Pengujian</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0" style="font-size:0.75rem; min-width:1200px;">
                            <thead class="table-light">
                                <tr>
                                    <th>No. Laporan</th>
                                    <th>Kategori</th>
                                    <th>Jenis Pengujian</th>
                                    <th>Parameter</th>
                                    <th>Tgl Cor</th>
                                    <th>Tgl Dites</th>
                                    <th>Umur</th>
                                    <th>Mutu</th>
                                    <th>Berat (kg)</th>
                                    <th>Beban Maks</th>
                                    <th>Tekan (Kg/cm&sup2;)</th>
                                    <th>Tekan 28hr</th>
                                    <th>Diameter</th>
                                    <th>D0u</th>
                                    <th>Luas (mm&sup2;)</th>
                                    <th>Au (mm&sup2;)</th>
                                    <th>Beban Leleh (kN)</th>
                                    <th>Panjang L0 (mm)</th>
                                    <th>Panjang Lu (mm)</th>
                                    <th>Tarik (MPa)</th>
                                    <th>Leleh (MPa)</th>
                                    <th>Regangan Maks (%)</th>
                                    <th>Kontraksi (%)</th>
                                    <th>Nilai</th>
                                    <th>Satuan</th>
                                    <th>Kesimpulan</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($historis as $h): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($h['kode'] ?? '-') ?></strong></td>
                                    <td>
                                        <?php
                                        $katJenis = kategoriDariNamaJenis($pdo, $h['jenis_pengujian']);
                                        $isBesi = $katJenis !== null
                                            ? ($katJenis === 'besi')
                                            : (empty($h['tgl_cor']) && !isUjiSilinder($h['jenis_pengujian']) && !isUjiKubus($h['jenis_pengujian']) && !isUjiPaving($h['jenis_pengujian']) && !isUjiLentur($h['jenis_pengujian']) && !isUjiCoreDrill($h['jenis_pengujian']));
                                        echo $isBesi ? '<span class="badge bg-warning" style="font-size:0.65rem;">Besi</span>' : '<span class="badge bg-info" style="font-size:0.65rem;">Beton</span>';
                                        ?>
                                    </td>
                                    <td><?= htmlspecialchars($h['jenis_pengujian'] ?? '-') ?></td>
                                    <td><strong><?= htmlspecialchars($h['parameter_uji'] ?? '-') ?></strong></td>
                                    <td><?= $h['tgl_cor'] ? date('d-m-Y', strtotime($h['tgl_cor'])) : '-' ?></td>
                                    <td><?= $h['tgl_ditest'] ? date('d-m-Y', strtotime($h['tgl_ditest'])) : '-' ?></td>
                                    <td><?= $h['umur_hari'] ? $h['umur_hari'] . ' hari' : '-' ?></td>
                                    <td><?= htmlspecialchars($h['mutu_beton'] ?? '-') ?></td>
                                    <td><?= $h['berat_kg'] ?? '-' ?></td>
                                    <td><?= $h['beban_maksimum'] ?? '-' ?></td>
                                    <td><?= $h['tegangan_tekan'] ?? '-' ?></td>
                                    <td><?= $h['tegangan_tekan_28_hari'] ?? '-' ?></td>
                                    <td><?= $h['diameter'] ?? '-' ?></td>
                                    <td><?= $h['diameter_akhir'] ?? '-' ?></td>
                                    <td><?= $h['luas_penampang'] ?? '-' ?></td>
                                    <td><?= $h['luas_penampang_akhir'] ?? '-' ?></td>
                                    <td><?= $h['beban_tarik_leleh'] ?? '-' ?></td>
                                    <td><?= $h['panjang_awal'] ?? '-' ?></td>
                                    <td><?= $h['panjang_akhir'] ?? '-' ?></td>
                                    <td><?= $h['tegangan_tarik'] ?? '-' ?></td>
                                    <td><?= $h['tegangan_leleh'] ?? '-' ?></td>
                                    <td><?= $h['perpanjangan'] ? $h['perpanjangan'] . '%' : '-' ?></td>
                                    <td><?= $h['reduksi_penampang'] ? $h['reduksi_penampang'] . '%' : '-' ?></td>
                                    <td><strong><?= htmlspecialchars($h['nilai_hasil'] ?? '-') ?></strong></td>
                                    <td><?= htmlspecialchars($h['satuan'] ?? '') ?></td>
                                    <td>
                                        <?php if ($h['kesimpulan'] === 'memenuhi_syarat'): ?>
                                            <span class="badge bg-success" style="font-size:0.65rem;">Memenuhi Syarat</span>
                                        <?php elseif ($h['kesimpulan'] === 'tidak_memenuhi_syarat'): ?>
                                            <span class="badge bg-danger" style="font-size:0.65rem;">Tidak Memenuhi Syarat</span>
                                        <?php else: ?>-<?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($h['status_verifikasi'] === 'terverifikasi'): ?>
                                            <span class="badge bg-success" style="font-size:0.65rem;">Tervalidasi</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary" style="font-size:0.65rem;">Belum Divalidasi</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
