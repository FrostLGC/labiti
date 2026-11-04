<?php
require '../config/database.php';

$hasil = null;
$error = '';
$kodeInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['kode'])) {
    $kodeInput = trim($_GET['kode'] ?? $_POST['kode'] ?? '');

    if (empty($kodeInput)) {
        $error = 'Masukkan nomor laporan terlebih dahulu.';
    } else {
        $stmtL = $pdo->prepare(
            "SELECT b.kode_booking, b.tanggal_pengujian_diminta as tgl_uji,
                    l.nomor_laporan, u.nama_perusahaan, b.status_booking
             FROM laporan l
             JOIN bookings b ON l.booking_id = b.id
             JOIN users u ON b.user_id = u.id
             WHERE l.nomor_laporan = ?
             ORDER BY l.id DESC LIMIT 1"
        );
        $stmtL->execute([$kodeInput]);
        $hasil = $stmtL->fetch();

        if (!$hasil) {
            $stmtB = $pdo->prepare(
                "SELECT b.kode_booking, b.tanggal_pengujian_diminta as tgl_uji,
                        l.nomor_laporan, u.nama_perusahaan, b.status_booking
                 FROM bookings b
                 LEFT JOIN laporan l ON l.booking_id = b.id
                 JOIN users u ON b.user_id = u.id
                 WHERE b.kode_booking = ?
                 ORDER BY l.id DESC LIMIT 1"
            );
            $stmtB->execute([$kodeInput]);
            $hasil = $stmtB->fetch();
        }

        if ($hasil) {
            $hasil['tipe'] = 'booking';
        }

        if (!$hasil) {
            $stmtH = $pdo->prepare(
                "SELECT h.kode, h.tgl_ditest as tgl_uji, h.created_at, h.status_verifikasi,
                        u.nama_perusahaan
                 FROM hasil_pengujian h
                 JOIN users u ON h.user_id = u.id
                 WHERE h.kode = ? AND h.sampel_id IS NULL
                 ORDER BY h.id ASC LIMIT 1"
            );
            $stmtH->execute([$kodeInput]);
            $hasil = $stmtH->fetch();
            if ($hasil) {
                $hasil['tipe'] = 'historis';
            }
        }

        if (!$hasil) {
            $error = 'Nomor "' . htmlspecialchars($kodeInput) . '" tidak valid.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cek Hasil Pengujian - PT ITI Utama</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="auth-wrapper">
    <div class="auth-card" style="max-width: 500px;">
        <div class="auth-logo"><img src="../img1.jpeg" alt="Logo"></div>
        <h4>Cek Hasil Pengujian</h4>
        <p class="auth-subtitle">PT ITI Utama - Masukkan nomor laporan</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?= $error ?></div>
        <?php endif; ?>

        <form method="POST" class="mb-4">
            <div class="input-group">
                <input type="text" name="kode" class="form-control" required
                       placeholder="Kode booking atau Nomor Laporan"
                       value="<?= htmlspecialchars($kodeInput) ?>">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-search"></i> Cari
                </button>
            </div>
        </form>

        <?php if ($hasil): ?>
            <?php
            $isHistoris = ($hasil['tipe'] ?? '') === 'historis';
            $isValid = $isHistoris
                ? ($hasil['status_verifikasi'] === 'terverifikasi')
                : (($hasil['status_booking'] ?? '') === 'selesai');
            ?>
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
                            <td class="text-muted" style="width: 140px;">Kode</td>
                            <td><strong><?= htmlspecialchars($hasil['kode_booking'] ?? $hasil['kode']) ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Kapan Diuji</td>
                            <td><strong><?= $hasil['tgl_uji'] ? date('d-m-Y', strtotime($hasil['tgl_uji'])) : '-' ?></strong></td>
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
        <?php endif; ?>

        <div class="text-center mt-3">
            <a href="login.php" class="text-decoration-none" style="font-size: 0.875rem; color: #ea580c;">
                <i class="bi bi-arrow-left"></i> Kembali ke Login
            </a>
        </div>
    </div>
</body>
</html>
