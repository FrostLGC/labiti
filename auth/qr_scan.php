<?php
require '../config/database.php';
require '../config/labels.php';
require '../config/field_besi.php';
require_once '../config/field_silinder.php';
require_once '../config/field_kubus.php';

$hasil = null;
$error = '';
$kodeInput = '';
$tampilkanCocok = isset($_GET['cocok']) && $_GET['cocok'] === '1';

$bookingFull = null;
$sampelList = [];
$hasilPerSampel = [];
$semuaHasil = [];
$nomorLaporan = '-';
$mutuBeton = '-';
$firstHasil = [];
$tglLaporan = '-';

if (isset($_GET['kode'])) {
    $kodeInput = trim($_GET['kode']);

    if (empty($kodeInput)) {
        $error = 'Masukkan nomor laporan terlebih dahulu.';
    } else {
        $stmtL = $pdo->prepare(
            "SELECT b.*, l.nomor_laporan, u.nama_perusahaan, j.nama_pengujian, j.satuan_sampel, j.kategori,
                    u.nama_lengkap as nama_pelanggan
             FROM laporan l
             JOIN bookings b ON l.booking_id = b.id
             JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
             JOIN users u ON b.user_id = u.id
             WHERE l.nomor_laporan = ?
             ORDER BY l.id DESC LIMIT 1"
        );
        $stmtL->execute([$kodeInput]);
        $hasil = $stmtL->fetch();
        if ($hasil) {
            $hasil['tipe'] = 'booking';
            $bookingFull = $hasil;
            $nomorLaporan = $hasil['nomor_laporan'] ?? '-';
        }

        if (!$hasil) {
            $stmtH = $pdo->prepare(
                "SELECT h.*, u.nama_perusahaan, u.nama_lengkap as nama_pelanggan
                 FROM hasil_pengujian h
                 JOIN users u ON h.user_id = u.id
                 WHERE h.kode = ? AND h.sampel_id IS NULL
                 ORDER BY h.id ASC"
            );
            $stmtH->execute([$kodeInput]);
            $hasilRows = $stmtH->fetchAll();
            if ($hasilRows) {
                $hasil = $hasilRows[0];
                $hasil['tipe'] = 'historis';
                $semuaHasil = $hasilRows;
                $firstHasil = $hasilRows[0];
                $nomorLaporan = $kodeInput;
                foreach ($hasilRows as $h) {
                    if (!empty($h['tgl_ditest'])) { $tglLaporan = date('d-m-Y', strtotime($h['tgl_ditest'])); break; }
                }
                foreach ($hasilRows as $h) {
                    if (!empty($h['mutu_beton'])) { $mutuBeton = $h['mutu_beton']; break; }
                }
            }
        }

        if (!$hasil) {
            $error = 'Nomor "' . htmlspecialchars($kodeInput) . '" tidak valid.';
        }
    }
}

if ($bookingFull) {
    $bookingId = (int) $bookingFull['id'];

    $stmtSampel = $pdo->prepare("SELECT * FROM sampel WHERE booking_id = ? ORDER BY kode_sampel ASC");
    $stmtSampel->execute([$bookingId]);
    $sampelList = $stmtSampel->fetchAll();

    $sampelIds = array_column($sampelList, 'id');
    if ($sampelIds) {
        $placeholders = implode(',', array_fill(0, count($sampelIds), '?'));
        $stmtHasil = $pdo->prepare(
            "SELECT hp.*, s.kode_sampel FROM hasil_pengujian hp
             JOIN sampel s ON hp.sampel_id = s.id
             WHERE hp.sampel_id IN ($placeholders)
             ORDER BY s.kode_sampel ASC, hp.id ASC"
        );
        $stmtHasil->execute($sampelIds);
        $semuaHasil = $stmtHasil->fetchAll();
        foreach ($semuaHasil as $h) {
            $hasilPerSampel[$h['sampel_id']][] = $h;
        }
    }

    foreach ($semuaHasil as $h) {
        if (!empty($h['mutu_beton'])) { $mutuBeton = $h['mutu_beton']; break; }
    }
    $firstHasil = !empty($semuaHasil) ? $semuaHasil[0] : [];
    foreach ($semuaHasil as $h) {
        if (!empty($h['tgl_ditest'])) { $tglLaporan = date('d-m-Y', strtotime($h['tgl_ditest'])); break; }
    }
}

$isHistoris = $hasil ? (($hasil['tipe'] ?? '') === 'historis') : false;
$isValid = $hasil
    ? ($isHistoris
        ? (($hasil['status_verifikasi'] ?? '') === 'terverifikasi')
        : (($hasil['status_booking'] ?? '') === 'selesai'))
    : false;
$bolehCocok = $tampilkanCocok && $isValid;

$namaUji = $bookingFull['nama_pengujian'] ?? ($firstHasil['jenis_pengujian'] ?? '-');

$isSilinder = isUjiSilinder($namaUji);
$isKubus = isUjiKubus($namaUji);
$isPaving = isUjiPaving($namaUji);
$isLentur = isUjiLentur($namaUji);
$isCore = isUjiCoreDrill($namaUji);
$isTabelBeton = $isSilinder || $isKubus || $isPaving || $isLentur || $isCore;

$kategoriUji = $bookingFull['kategori'] ?? null;
if ($kategoriUji === null) {
    $katDariJenis = !empty($firstHasil['jenis_pengujian']) ? kategoriDariNamaJenis($pdo, $firstHasil['jenis_pengujian']) : null;
    if ($katDariJenis !== null) {
        $kategoriUji = $katDariJenis;
    } else {
        $kategoriUji = (!$isTabelBeton && empty($firstHasil['tgl_cor'] ?? null)) ? 'besi' : 'beton';
    }
}
$kategoriBesi = ($kategoriUji === 'besi');
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validasi Laporan - PT ITI Utama</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        table.laporan-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        table.laporan-table th,
        table.laporan-table td { border: 1px solid #94a3b8; padding: 6px 8px; text-align: center; vertical-align: middle; }
        table.laporan-table th { background: #f1f5f9; font-weight: 600; font-size: 0.75rem; }
        .laporan-top { display: flex; align-items: center; justify-content: space-between; border-bottom: 3px double #1e293b; padding-bottom: 15px; margin-bottom: 20px; }
        .laporan-top h5, .laporan-top div { text-align: center; }
        @media print {
            body { background: #fff; }
            .no-print, a[href="login.php"] { display: none !important; }
        }
    </style>
</head>
<body class="auth-wrapper">
    <div class="auth-card" style="max-width: <?= $bolehCocok && !empty($semuaHasil) ? '1200px' : '500px' ?>;">
        <div class="auth-logo"><img src="../img1.jpeg" alt="Logo"></div>
        <h4>Validasi Laporan</h4>
        <p class="auth-subtitle">PT ITI Utama</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?= $error ?></div>
        <?php elseif (empty($kodeInput)): ?>
            <div class="text-center text-muted py-4">
                <i class="bi bi-qr-code-scan" style="font-size: 3rem;"></i>
                <p class="mt-3">Silakan scan QR Code pada laporan Anda</p>
            </div>
        <?php endif; ?>

        <?php if ($hasil): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-4">
                    <?php if ($isValid): ?>
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 2.5rem;"></i>
                        <h5 class="mt-2 mb-3 text-success">✓ Valid</h5>
                    <?php else: ?>
                        <i class="bi bi-clock-fill text-warning" style="font-size: 2.5rem;"></i>
                        <h5 class="mt-2 mb-3 text-warning">Belum Divalidasi</h5>
                        <p class="text-muted" style="font-size: 0.8125rem;">Data ini sudah diinput namun belum divalidasi oleh admin. Silakan hubungi admin untuk proses validasi.</p>
                    <?php endif; ?>
                    <table class="table table-borderless mb-0" style="font-size: 0.95rem; text-align: left;">
                        <tr>
                            <td class="text-muted" style="width: 140px;">No. Laporan</td>
                            <td><strong><?= htmlspecialchars($hasil['nomor_laporan'] ?? $hasil['kode'] ?? '-') ?></strong></td>
                        </tr>
                        <?php if (!$isHistoris): ?>
                        <tr>
                            <td class="text-muted">Kode Booking</td>
                            <td><strong><?= htmlspecialchars($hasil['kode_booking'] ?? '-') ?></strong></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="text-muted">Kapan Diuji</td>
                            <td><strong>
                                <?php
                                $tglDitesRef = $isHistoris
                                    ? ($hasil['tgl_ditest'] ?? null)
                                    : ($hasil['tanggal_pengujian_diminta'] ?? null);
                                echo $tglDitesRef ? date('d-m-Y', strtotime($tglDitesRef)) : '-';
                                ?>
                            </strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Dilakukan oleh</td>
                            <td><strong><?= htmlspecialchars($hasil['nama_perusahaan'] ?? '-') ?></strong></td>
                        </tr>
                        <?php if ($isHistoris): ?>
                        <tr>
                            <td class="text-muted">Status</td>
                            <td>
                                <?php if ($isValid): ?>
                                    <span class="badge bg-success">✓ Tervalidasi</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Menunggu Validasi Admin</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <?php if ($isValid): ?>
            <div class="text-center mt-3 mb-3">
                <a href="qr_scan.php?kode=<?= urlencode($kodeInput) ?>&cocok=1"
                   class="btn btn-primary px-4 py-2" style="border-radius: 8px;">
                    <i class="bi bi-arrow-left-right"></i> Cocokan Data
                </a>
                <?php if ($bolehCocok): ?>
                    <a href="qr_scan.php?kode=<?= urlencode($kodeInput) ?>"
                       class="btn btn-outline-secondary px-4 py-2 ms-2" style="border-radius: 8px;">
                        <i class="bi bi-x-circle"></i> Tutup
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($bolehCocok && $bookingFull && !empty($semuaHasil)): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <strong>Laporan Hasil Pengujian Lengkap</strong>
                </div>
                <div class="card-body" style="background:#fff;">
                    <div class="laporan-top">
                        <div></div>
                        <div class="laporan-header text-center">
                            <h5 class="fw-bold mb-0">LAPORAN HASIL PENGUJIAN <?= strtoupper($kategoriBesi ? 'BESI' : 'BETON') ?></h5>
                            <div class="fw-semibold"><?= htmlspecialchars($bookingFull['nama_perusahaan'] ?? $bookingFull['nama_pelanggan'] ?? '-') ?></div>
                        </div>
                        <div style="width:60px; flex-shrink:0;"><img src="../img1.jpeg" alt="Logo" style="width:100%;"></div>
                    </div>

                    <div class="row mb-3" style="font-size:0.875rem;">
                        <div class="col-md-6">
                            <table style="width:100%;">
                                <tr><td style="width:130px; font-weight:600;">NO LAPORAN</td><td>: <?= htmlspecialchars($nomorLaporan) ?></td></tr>
                                <tr><td style="font-weight:600;">PELAKSANA</td><td>: <?= htmlspecialchars($bookingFull['nama_perusahaan'] ?? $bookingFull['nama_pelanggan'] ?? '-') ?></td></tr>
                                <tr><td style="font-weight:600;">Supplier</td><td>: <?= htmlspecialchars($bookingFull['supplier'] ?? '-') ?></td></tr>
                                <tr><td style="font-weight:600;">PROYEK</td><td>: <?= htmlspecialchars($bookingFull['objek_pengujian'] ?? '-') ?></td></tr>
                                <tr><td style="font-weight:600;">Lokasi</td><td>: <?= htmlspecialchars($bookingFull['lokasi'] ?? '-') ?></td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table style="width:100%;">
                                <?php if ($kategoriBesi || $isPaving): ?>
                                <tr><td style="width:130px; font-weight:600;">OBYEK</td><td>: <?= htmlspecialchars(($firstHasil['objek'] ?? '') ?: ($bookingFull['objek_pengujian'] ?? '-')) ?></td></tr>
                                <?php endif; ?>
                                <tr><td style="font-weight:600;">JENIS PENGUJIAN</td><td>: <?= htmlspecialchars($namaUji) ?></td></tr>
                                <?php if (!$kategoriBesi): ?>
                                <tr><td style="font-weight:600;">MUTU</td><td>: <?= htmlspecialchars($mutuBeton) ?> kg/cm&sup2;</td></tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>

                    <?php $fotoBukti = null; foreach ($semuaHasil as $r) { if (!empty($r['dokumentasi_file'])) { $fotoBukti = $r['dokumentasi_file']; break; } } ?>
                    <?php if ($fotoBukti !== null): ?>
                    <div class="text-end mb-2">
                        <a href="../<?= htmlspecialchars($fotoBukti) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Lihat Foto Bukti Pengujian</a>
                    </div>
                    <?php endif; ?>

                    <div class="table-responsive mt-3">
                        <table class="laporan-table" style="width:100%; border-collapse:collapse; font-size:0.8125rem;">
                            <?php if ($isTabelBeton): ?>
                                <?= tabelBetonThead($isKubus, $isPaving, $isLentur, $isCore) ?>
                            <?php elseif ($kategoriBesi): ?>
                                <?= fieldBesiThead() ?>
                            <?php else: ?>
                            <thead>
                                <tr>
                                    <th style="width:40px;">No.</th>
                                    <th>Tgl. Dicor</th>
                                    <th>Tgl. Dites</th>
                                    <th>Umur (Hari)</th>
                                    <th>Berat (Kg)</th>
                                    <th>Kode</th>
                                    <th>Beban Maksimum (Kg)</th>
                                    <th>Tegangan Tekan (Kg/cm&sup2;)</th>
                                    <th>Tegangan Tekan 28 Hari (Kg/cm&sup2;)</th>
                                    <th>Mpa</th>
                                    <th>Keterangan</th>
                                </tr>
                            </thead>
                            <?php endif; ?>
                            <tbody>
                                <?php $no = 0; foreach ($sampelList as $s): ?>
                                    <?php
                                    $hasilList = $hasilPerSampel[$s['id']] ?? [];
                                    if (empty($hasilList)) {
                                        $no++;
                                        $colspan = $kategoriBesi ? count(fieldBesi()) : ($isTabelBeton ? tabelBetonJumlahKolom($isKubus, $isPaving, $isLentur, $isCore) - 1 : 10);
                                    ?>
                                    <tr>
                                        <td><?= $no ?></td>
                                        <td colspan="<?= $colspan ?>" class="text-center">-</td>
                                    </tr>
                                    <?php } else { ?>
                                        <?php foreach ($hasilList as $h): $no++; ?>
                                        <?php if ($isTabelBeton): ?>
                                            <?= tabelBetonRow($isKubus, $h, $no, $s['kode_sampel'], $isPaving, $isLentur, $isCore) ?>
                                        <?php elseif ($kategoriBesi): ?>
                                            <?= fieldBesiRow($h, $no) ?>
                                        <?php else: ?>
                                        <tr>
                                            <td><?= $no ?></td>
                                            <td><?= $h['tgl_cor'] ? date('d-m-Y', strtotime($h['tgl_cor'])) : '-' ?></td>
                                            <td><?= $h['tgl_ditest'] ? date('d-m-Y', strtotime($h['tgl_ditest'])) : '-' ?></td>
                                            <td><?= $h['umur_hari'] ?? '-' ?></td>
                                            <td><?= $h['berat_kg'] ?? '-' ?></td>
                                            <td><?= htmlspecialchars($h['kode_beton'] ?? $s['kode_sampel']) ?></td>
                                            <td><?= $h['beban_maksimum'] ?? '-' ?></td>
                                            <td><?= $h['tegangan_tekan'] ?? '-' ?></td>
                                            <td><?= $h['tegangan_tekan_28_hari'] ?? '-' ?></td>
                                            <td><?= $h['mpa'] ?? '-' ?></td>
                                            <td>
                                                <?php if ($h['kesimpulan'] === 'memenuhi_syarat'): ?>
                                                    <span style="background:#16a34a; color:#fff; padding:2px 8px; border-radius:4px; font-size:0.75rem;">Memenuhi Syarat</span>
                                                <?php elseif ($h['kesimpulan'] === 'tidak_memenuhi_syarat'): ?>
                                                    <span style="background:#dc2626; color:#fff; padding:2px 8px; border-radius:4px; font-size:0.75rem;">Tidak Memenuhi Syarat</span>
                                                <?php else: ?>-<?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php } ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between mt-4" style="font-size:0.8125rem;">
                        <div class="text-center" style="width:200px;">
                            <div style="border-top:1px solid #1e293b; margin-top:115px; padding-top:5px;">Mengetahui,<br><strong>Penguji</strong></div>
                        </div>
                        <div class="text-center" style="width:200px;">
                            <div style="height:115px; padding-top:15px;"><?= $tglLaporan ?><br>PT. Inti Teknologi Indonesia Utama,</div>
                            <div style="border-top:1px solid #1e293b; padding-top:5px;"><strong>Direktur</strong></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php elseif ($bolehCocok && $isHistoris && !empty($semuaHasil)): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <strong>Laporan Hasil Pengujian (Historis) Lengkap</strong>
                </div>
                <div class="card-body" style="background:#fff;">
                    <div class="laporan-top">
                        <div></div>
                        <div class="laporan-header text-center">
                            <h5 class="fw-bold mb-0">LAPORAN HASIL PENGUJIAN <?= strtoupper($kategoriBesi ? 'BESI' : 'BETON') ?></h5>
                            <div class="fw-semibold"><?= htmlspecialchars($firstHasil['nama_perusahaan'] ?? '-') ?></div>
                        </div>
                        <div style="width:60px; flex-shrink:0;"><img src="../img1.jpeg" alt="Logo" style="width:100%;"></div>
                    </div>

                    <div class="row mb-3" style="font-size:0.875rem;">
                        <div class="col-md-6">
                            <table style="width:100%;">
                                <tr><td style="width:130px; font-weight:600;">NO LAPORAN</td><td>: <?= htmlspecialchars($nomorLaporan) ?></td></tr>
                                <tr><td style="font-weight:600;">PELAKSANA</td><td>: <?= htmlspecialchars($firstHasil['nama_perusahaan'] ?? '-') ?></td></tr>
                                <tr><td style="font-weight:600;">Supplier</td><td>: <?= htmlspecialchars($firstHasil['supplier'] ?? '-') ?></td></tr>
                                <tr><td style="font-weight:600;">PROYEK</td><td>: <?= htmlspecialchars($firstHasil['proyek'] ?? '-') ?></td></tr>
                                <tr><td style="font-weight:600;">Lokasi</td><td>: <?= htmlspecialchars($firstHasil['lokasi'] ?? '-') ?></td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table style="width:100%;">
                                <tr><td style="width:130px; font-weight:600;">JENIS PENGUJIAN</td><td>: <?= htmlspecialchars($namaUji) ?></td></tr>
                                <?php if (!$kategoriBesi): ?>
                                <tr><td style="font-weight:600;">MUTU</td><td>: <?= htmlspecialchars($mutuBeton) ?> kg/cm&sup2;</td></tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>

                    <?php $fotoBuktiHist = null; foreach ($semuaHasil as $r) { if (!empty($r['dokumentasi_file'])) { $fotoBuktiHist = $r['dokumentasi_file']; break; } } ?>
                    <?php if ($fotoBuktiHist !== null): ?>
                    <div class="text-end mb-2">
                        <a href="../<?= htmlspecialchars($fotoBuktiHist) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Lihat Foto Bukti Pengujian</a>
                    </div>
                    <?php endif; ?>

                    <div class="table-responsive mt-3">
                        <table class="laporan-table" style="width:100%; border-collapse:collapse; font-size:0.8125rem;">
                            <?php if ($isTabelBeton): ?>
                                <?= tabelBetonThead($isKubus, $isPaving, $isLentur, $isCore) ?>
                            <?php elseif ($kategoriBesi): ?>
                                <?= fieldBesiThead() ?>
                            <?php else: ?>
                            <thead>
                                <tr>
                                    <th style="width:40px;">No.</th>
                                    <th>Parameter Uji</th>
                                    <th>Tgl. Dicor</th>
                                    <th>Tgl. Dites</th>
                                    <th>Umur (Hari)</th>
                                    <th>Berat (Kg)</th>
                                    <th>Kode Beton</th>
                                    <th>Beban Maks (Kg)</th>
                                    <th>Teg. Tekan (Kg/cm&sup2;)</th>
                                    <th>Teg. 28 Hari (Kg/cm&sup2;)</th>
                                    <th>Mpa</th>
                                    <th>Nilai Hasil</th>
                                    <th>Kesimpulan</th>
                                </tr>
                            </thead>
                            <?php endif; ?>
                            <tbody>
                                <?php $no = 0; foreach ($semuaHasil as $h): $no++; ?>
                                <?php if ($isTabelBeton): ?>
                                    <?= tabelBetonRow($isKubus, $h, $no, null, $isPaving, $isLentur, $isCore) ?>
                                <?php elseif ($kategoriBesi): ?>
                                    <?= fieldBesiRow($h, $no) ?>
                                <?php else: ?>
                                <tr>
                                    <td><?= $no ?></td>
                                    <td><strong><?= htmlspecialchars($h['parameter_uji'] ?? '-') ?></strong></td>
                                    <td><?= $h['tgl_cor'] ? date('d-m-Y', strtotime($h['tgl_cor'])) : '-' ?></td>
                                    <td><?= $h['tgl_ditest'] ? date('d-m-Y', strtotime($h['tgl_ditest'])) : '-' ?></td>
                                    <td><?= $h['umur_hari'] ?? '-' ?></td>
                                    <td><?= $h['berat_kg'] ?? '-' ?></td>
                                    <td><?= htmlspecialchars($h['kode_beton'] ?? '-') ?></td>
                                    <td><?= $h['beban_maksimum'] ?? '-' ?></td>
                                    <td><?= $h['tegangan_tekan'] ?? '-' ?></td>
                                    <td><?= $h['tegangan_tekan_28_hari'] ?? '-' ?></td>
                                    <td><?= $h['mpa'] ?? '-' ?></td>
                                    <td><strong><?= htmlspecialchars($h['nilai_hasil'] ?? '-') ?> <?= htmlspecialchars($h['satuan'] ?? '') ?></strong></td>
                                    <td>
                                        <?php if ($h['kesimpulan'] === 'memenuhi_syarat'): ?>
                                            <span style="background:#16a34a; color:#fff; padding:2px 8px; border-radius:4px; font-size:0.75rem;">Memenuhi Syarat</span>
                                        <?php elseif ($h['kesimpulan'] === 'tidak_memenuhi_syarat'): ?>
                                            <span style="background:#dc2626; color:#fff; padding:2px 8px; border-radius:4px; font-size:0.75rem;">Tidak Memenuhi Syarat</span>
                                        <?php else: ?>-<?php endif; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!$kategoriBesi && !empty($firstHasil['catatan_teknisi'] ?? null)): ?>
                    <div class="mt-3" style="font-size:0.875rem;">
                        <strong>Catatan:</strong> <?= nl2br(htmlspecialchars($firstHasil['catatan_teknisi'])) ?>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between mt-4" style="font-size:0.8125rem;">
                        <div class="text-center" style="width:200px;">
                            <div style="border-top:1px solid #1e293b; margin-top:115px; padding-top:5px;">Mengetahui,<br><strong>Penguji</strong></div>
                        </div>
                        <div class="text-center" style="width:200px;">
                            <div style="height:115px; padding-top:15px;"><?= $tglLaporan ?><br>PT. Inti Teknologi Indonesia Utama,</div>
                            <div style="border-top:1px solid #1e293b; padding-top:5px;"><strong>Direktur</strong></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php elseif ($bolehCocok): ?>
            <div class="alert alert-info"><i class="bi bi-info-circle"></i> Data hasil pengujian belum tersedia untuk nomor laporan ini.</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="text-center mt-3">
            <a href="login.php" class="text-decoration-none" style="font-size: 0.875rem; color: #ea580c;">
                <i class="bi bi-arrow-left"></i> Kembali ke Login
            </a>
        </div>
    </div>
</body>
</html>