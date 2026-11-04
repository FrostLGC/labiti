<?php
require '../config/database.php';
require '../config/auth.php';
require '../config/labels.php';
require '../config/field_besi.php';
require_once '../config/field_silinder.php';
require_once '../config/field_kubus.php';
requireRole(['pelanggan']);

$user = currentUser($pdo);
$kodeHistoris = $_GET['kode'] ?? null;
if (!$kodeHistoris) die('Data tidak ditemukan.');

$stmt = $pdo->prepare(
    "SELECT h.*, u.nama_lengkap, u.nama_perusahaan
     FROM hasil_pengujian h
     JOIN users u ON h.user_id = u.id
     WHERE h.kode = ? AND h.user_id = ? AND h.sampel_id IS NULL
     ORDER BY h.id ASC"
);
$stmt->execute([$kodeHistoris, $user['id']]);
$dataList = $stmt->fetchAll();

if (empty($dataList)) die('Data historis tidak ditemukan.');

$first = $dataList[0];

$tglLaporan = '-';
$tsLaporan = 0;
foreach ($dataList as $d) {
    if (!empty($d['tgl_ditest']) && strtotime($d['tgl_ditest']) > $tsLaporan) {
        $tsLaporan = strtotime($d['tgl_ditest']);
        $tglLaporan = date('d-m-Y', $tsLaporan);
    }
}

if ($first['status_verifikasi'] !== 'terverifikasi') {
    die('<div style="text-align:center;padding:60px 20px;font-family:sans-serif;">
        <h2 style="color:#dc3545;">Laporan Belum Diverifikasi</h2>
        <p style="color:#6c757d;">Data historis ini belum divalidasi oleh admin.<br>Mohon tunggu hingga proses validasi selesai.</p>
    </div>');
}
$isSilinder = isUjiSilinder($first['jenis_pengujian']);
$isKubus = isUjiKubus($first['jenis_pengujian']);
$isPaving = isUjiPaving($first['jenis_pengujian']);
$isLentur = isUjiLentur($first['jenis_pengujian']);
$isCore = isUjiCoreDrill($first['jenis_pengujian']);
$isTabelBeton = $isSilinder || $isKubus || $isPaving || $isLentur || $isCore;
$stmtKat = $pdo->prepare("SELECT kategori FROM jenis_pengujian WHERE nama_pengujian = ? ORDER BY id DESC LIMIT 1");
$stmtKat->execute([$first['jenis_pengujian']]);
$kategoriJenis = $stmtKat->fetchColumn();
$isBesi = ($kategoriJenis !== false && $kategoriJenis !== null && $kategoriJenis !== '')
    ? ($kategoriJenis === 'besi')
    : (!$isTabelBeton && empty($first['tgl_cor']));
$kategoriLabel = $isBesi ? 'BESI' : 'BETON';

$qrKode = $kodeHistoris;
$qrUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['PHP_SELF'])) . '/auth/qr_scan.php?kode=' . urlencode($qrKode);
$qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode($qrUrl);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Historis <?= htmlspecialchars($kodeHistoris) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; color: #1e293b; background: #fff; margin: 0; padding: 20px; }
        .laporan-top { display: flex; align-items: center; justify-content: space-between; border-bottom: 3px double #1e293b; padding-bottom: 15px; margin-bottom: 20px; }
        .laporan-top .logo-wrap { width: 60px; height: 60px; flex-shrink: 0; }
        .laporan-top .logo-wrap img { width: 100%; height: 100%; object-fit: contain; }
        .laporan-header { text-align: center; flex: 1; }
        .laporan-header h2 { margin: 0; font-size: 1.25rem; font-weight: 700; }
        .laporan-header h3 { margin: 5px 0 0; font-size: 1rem; font-weight: 600; }
        table.laporan-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        table.laporan-table th,
        table.laporan-table td { border: 1px solid #94a3b8; padding: 6px 8px; text-align: center; vertical-align: middle; }
        table.laporan-table th { background: #f1f5f9; font-weight: 600; font-size: 0.75rem; }
        table.laporan-table td { font-size: 0.8125rem; }
        .badge-ms { background: #16a34a; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .badge-ts { background: #dc2626; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .ttd-section { margin-top: 24px; display: flex; justify-content: space-between; }
        .ttd-box { text-align: center; width: 200px; }
        .ttd-box .line { border-top: 1px solid #1e293b; margin-top: 115px; padding-top: 5px; }
@page { size: 297mm 210mm; margin: 0; }
        @media print {
            body { padding: 10mm; margin: 0; }
            .no-print { display: none !important; }
            .watermark { opacity: 0.10 !important; }
            img[alt="Banner"] { max-height: 55px !important; max-width: 100% !important; }
            .laporan-top { padding-bottom: 6px; margin-bottom: 8px; }
            table { font-size: 0.75rem !important; }
            table.laporan-table { font-size: 0.6875rem !important; }
            table.laporan-table th, table.laporan-table td { padding: 2px 4px !important; }
            .ttd-section { margin-top: 12px !important; }
            tr { page-break-inside: avoid; }
            thead { display: table-header-group; }
        }
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.15;
            z-index: 0;
            width: 70%;
            max-width: 650px;
            height: auto;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <div style="text-align:center; margin-bottom:15px;">
        <img src="../Picture1.png" alt="Banner" style="max-width:100%; height:auto; max-height:120px; object-fit:contain;">
    </div>
    <img src="../img1.jpeg" class="watermark" alt="">
    <div class="no-print mb-3 d-flex justify-content-end gap-2">
        <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> Cetak</button>
        <a href="cek_booking.php?kode=<?= urlencode($kodeHistoris) ?>" class="btn btn-outline-secondary btn-sm">← Kembali</a>
    </div>

    <div class="laporan-top">
        <div></div>
        <div class="laporan-header">
            <h2>LAPORAN HASIL PENGUJIAN <?= $kategoriLabel ?></h2>
            <h3><?= htmlspecialchars($first['nama_perusahaan'] ?? $first['nama_lengkap']) ?></h3>
        </div>
        <div class="logo-wrap">
            <img src="../img1.jpeg" alt="Logo">
        </div>
    </div>

    <table style="width:100%; font-size:0.875rem; margin-bottom:15px;">
        <tr>
            <td style="width:50%; vertical-align:top; padding:0;">
                <table style="width:100%; font-size:0.875rem;">
                    <tr>
                        <td style="width:140px; font-weight:600; padding:2px 0;">KODE HISTORIS</td>
                        <td style="padding:2px 0;">: <?= htmlspecialchars($first['kode']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; padding:2px 0;">PELAKSANA</td>
                        <td style="padding:2px 0;">: <?= htmlspecialchars($first['nama_perusahaan'] ?? $first['nama_lengkap']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; padding:2px 0;">SUPPLIER</td>
                        <td style="padding:2px 0;">: <?= htmlspecialchars($first['supplier'] ?? '-') ?></td>
                    </tr>
                </table>
            </td>
            <td style="width:50%; vertical-align:top; padding:0;">
                <table style="width:100%; font-size:0.875rem;">
                    <?php if ($isBesi || $isPaving): ?>
                    <tr>
                        <td style="width:140px; font-weight:600; padding:2px 0;">OBYEK</td>
                        <td style="padding:2px 0;">: <?= htmlspecialchars($first['objek'] ?? '-') ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td style="width:140px; font-weight:600; padding:2px 0;">JENIS PENGUJIAN</td>
                        <td style="padding:2px 0;">: <?= htmlspecialchars($first['jenis_pengujian'] ?? '-') ?></td>
                    </tr>
                    <?php if (!$isBesi): ?>
                    <tr>
                        <td style="font-weight:600; padding:2px 0;">MUTU</td>
                        <td style="padding:2px 0;">: <?= htmlspecialchars($first['mutu_beton'] ?? '-') ?> kg/cm&sup2;</td>
                    </tr>
                    <?php endif; ?>
                </table>
            </td>
        </tr>
    </table>

    <?php $fotoBukti = null; foreach ($dataList as $r) { if (!empty($r['dokumentasi_file'])) { $fotoBukti = $r['dokumentasi_file']; break; } } ?>
    <?php if ($fotoBukti !== null): ?>
    <div class="d-flex justify-content-end mb-2">
        <a href="../<?= htmlspecialchars($fotoBukti) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Lihat Foto Bukti Pengujian</a>
    </div>
    <?php endif; ?>

    <div class="table-responsive mt-3">
        <table class="laporan-table">
            <?php if ($isTabelBeton): ?>
                    <?= tabelBetonThead($isKubus, $isPaving, $isLentur, $isCore) ?>
                <?php elseif ($isBesi): ?>
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
                <?php $no = 0; foreach ($dataList as $h): $no++; ?>
                <?php if ($isTabelBeton): ?>
                    <?= tabelBetonRow($isKubus, $h, $no, null, $isPaving, $isLentur, $isCore) ?>
                <?php elseif ($isBesi): ?>
                            <?= fieldBesiRow($h, $no) ?>
                <?php else: ?>
                <tr>
                    <td><?= $no ?></td>
                    <td><?= htmlspecialchars($h['parameter_uji'] ?? '-') ?></td>
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
                            <span class="badge-ms">Memenuhi Syarat</span>
                        <?php elseif ($h['kesimpulan'] === 'tidak_memenuhi_syarat'): ?>
                            <span class="badge-ts">Tidak Memenuhi Syarat</span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!$isBesi && !$isKubus && !empty($first['catatan_teknisi'])): ?>
    <div class="mt-3" style="font-size:0.875rem;">
        <strong>Catatan:</strong> <?= nl2br(htmlspecialchars($first['catatan_teknisi'])) ?>
    </div>
    <?php endif; ?>

    <div class="ttd-section">
        <div class="ttd-box">
            <div class="line">Mengetahui,<br><strong>Penguji</strong></div>
        </div>
        <div class="ttd-box text-center">
            <img src="<?= $qrApiUrl ?>" alt="QR Code" style="width:90px;height:90px;">
            <div style="font-size:0.6rem;color:#64748b;margin-top:2px;">Scan untuk verifikasi</div>
        </div>
        <div class="ttd-box">
            <div style="height:115px;">
    <div><?= $tglLaporan ?></div>
    <div>PT. Inti Teknologi Indonesia Utama,</div>
</div>
<div class="line" style="margin-top:0;"><strong>Direktur</strong></div>
        </div>
    </div>
</body>
</html>
