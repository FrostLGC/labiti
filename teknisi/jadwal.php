<?php
require '../config/database.php';
require '../config/auth.php';
requireRole(['teknisi']);

$user = currentUser($pdo);
$success = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'sampel_selesai') $success = 'Semua sampel selesai diuji.';
if (isset($_GET['msg']) && $_GET['msg'] === 'sampel_selesai_sebagian') $success = 'Sampel yang dipilih selesai diuji. Masih ada sampel lain yang belum selesai, booking tetap menunggu.';
if (isset($_GET['msg']) && $_GET['msg'] === 'mulai_uji') $success = 'Pengujian berhasil dimulai.';

// Pastikan booking memang ditugaskan ke teknisi ini
function bookingMilikTeknisi($pdo, $bookingId, $teknisiId) {
    $stmt = $pdo->prepare(
        "SELECT b.id FROM bookings b
         JOIN penjadwalan p ON p.booking_id = b.id
         WHERE b.id = ? AND p.teknisi_id = ? LIMIT 1"
    );
    $stmt->execute([$bookingId, $teknisiId]);
    return (bool) $stmt->fetch();
}

// ============ PROSES SELESAI UJI SEMUA SAMPEL ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selesai_uji_semua'])) {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    if (!$bookingId || !bookingMilikTeknisi($pdo, $bookingId, $user['id'])) {
        die('Booking tidak ditemukan atau tidak ditugaskan kepada Anda.');
    }

    $pdo->prepare("UPDATE sampel SET status_sampel = 'selesai_diuji' WHERE booking_id = ? AND status_sampel = 'sedang_diuji'")
         ->execute([$bookingId]);

    $stmtSisa = $pdo->prepare("SELECT COUNT(*) FROM sampel WHERE booking_id = ? AND status_sampel != 'selesai_diuji'");
    $stmtSisa->execute([$bookingId]);
    $sisaBelum = (int) $stmtSisa->fetchColumn();

    if ($sisaBelum > 0) {
        header('Location: jadwal.php?msg=sampel_selesai_sebagian');
        exit;
    }

    $pdo->prepare("UPDATE bookings SET status_booking = 'menunggu_input_admin' WHERE id = ?")->execute([$bookingId]);
    $pdo->prepare("INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan) VALUES (?, 'menunggu_input_admin', ?, ?)")
         ->execute([$bookingId, $user['id'], "Seluruh sampel selesai diuji oleh teknisi {$user['nama_lengkap']}, menunggu input nilai oleh admin"]);

    header('Location: jadwal.php?msg=sampel_selesai');
    exit;
}

// ============ PROSES MULAI PENGUJIAN ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mulai_uji'])) {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    if (!$bookingId || !bookingMilikTeknisi($pdo, $bookingId, $user['id'])) {
        die('Booking tidak ditemukan atau tidak ditugaskan kepada Anda.');
    }

    $pdo->prepare("UPDATE sampel SET status_sampel = 'sedang_diuji' WHERE booking_id = ? AND status_sampel = 'diterima'")
         ->execute([$bookingId]);

    $stmtCekBooking = $pdo->prepare("SELECT status_booking FROM bookings WHERE id = ?");
    $stmtCekBooking->execute([$bookingId]);
    $statusSekarang = $stmtCekBooking->fetchColumn();

    if ($statusSekarang === 'menunggu_diuji') {
        $pdo->prepare("UPDATE bookings SET status_booking = 'sedang_diuji' WHERE id = ?")->execute([$bookingId]);
        $pdo->prepare("INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan) VALUES (?, 'sedang_diuji', ?, ?)")
             ->execute([$bookingId, $user['id'], "Pengujian dimulai oleh teknisi {$user['nama_lengkap']}"]);

    }
    header('Location: jadwal.php?msg=mulai_uji');
    exit;
}

// ============ AMBIL DATA GROUPED BY BOOKING ============
$stmtBooking = $pdo->prepare(
    "SELECT DISTINCT
            p.tanggal_jadwal, p.jam_mulai, p.jam_selesai,
            b.id as booking_id, b.kode_booking, b.status_booking, b.jumlah_sampel, b.dokumen_persyaratan,
            j.nama_pengujian, j.satuan_sampel,
            u.nama_lengkap as nama_pelanggan, u.nama_perusahaan
     FROM penjadwalan p
     JOIN bookings b ON p.booking_id = b.id
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     JOIN users u ON b.user_id = u.id
     LEFT JOIN sampel s ON s.booking_id = b.id
     WHERE p.teknisi_id = ? AND b.status_booking != 'ditolak'
     ORDER BY p.tanggal_jadwal ASC, p.jam_mulai ASC"
);
$stmtBooking->execute([$user['id']]);
$daftarBooking = $stmtBooking->fetchAll();

// Ambil semua sampel per booking
$allSampel = [];
if (!empty($daftarBooking)) {
    $bookingIds = array_column($daftarBooking, 'booking_id');
    $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
    $stmtSampel = $pdo->prepare(
        "SELECT s.*, b.id as booking_id
         FROM sampel s
         JOIN bookings b ON s.booking_id = b.id
         WHERE s.booking_id IN ($placeholders)
         ORDER BY s.kode_sampel ASC"
    );
    $stmtSampel->execute($bookingIds);
    $semuaSampel = $stmtSampel->fetchAll();

    foreach ($semuaSampel as $s) {
        $allSampel[$s['booking_id']][] = $s;
    }
}

// Kategorikan booking
$belumMulai = [];  // semua sampel diterima
$sedangUji = [];   // ada yang sedang_diuji, belum semua selesai
$selesaiUji = [];  // semua sampel selesai_diuji

foreach ($daftarBooking as $bk) {
    $bookingId = $bk['booking_id'];
    $sampelList = $allSampel[$bookingId] ?? [];
    if (empty($sampelList)) {
        $belumMulai[] = $bk;
        continue;
    }

    $adaDiterima = false;
    $adaSedangDiuji = false;
    $semuaSelesai = true;
    foreach ($sampelList as $sl) {
        if ($sl['status_sampel'] === 'diterima') $adaDiterima = true;
        if ($sl['status_sampel'] === 'sedang_diuji') $adaSedangDiuji = true;
        if ($sl['status_sampel'] !== 'selesai_diuji') $semuaSelesai = false;
    }

    if ($semuaSelesai) {
        $selesaiUji[] = $bk;
    } elseif ($adaSedangDiuji) {
        $sedangUji[] = $bk;
    } else {
        $belumMulai[] = $bk;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Pengujian - Teknisi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
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
        <div class="page-header">
            <h4>Jadwal Pengujian Saya</h4>
            <p>Daftar proyek dan sampel yang ditugaskan untuk diuji</p>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <?php if (empty($daftarBooking)): ?>
            <div class="alert alert-info">Belum ada jadwal pengujian yang ditugaskan kepada Anda.</div>
        <?php else: ?>

            <?php
            $sections = [
                ['title' => 'Belum Mulai Uji', 'icon' => 'bi-clock', 'color' => 'warning', 'list' => $belumMulai],
                ['title' => 'Sedang Uji', 'icon' => 'bi-play-circle', 'color' => 'primary', 'list' => $sedangUji],
                ['title' => 'Sudah Selesai', 'icon' => 'bi-check-circle', 'color' => 'success', 'list' => $selesaiUji],
            ];
            $firstOpen = true;
            foreach ($sections as $sec):
                if (empty($sec['list'])) continue;
            ?>
            <div class="mb-4">
                <h6 class="fw-bold mb-3" style="color: var(--bs-<?= $sec['color'] ?>);">
                    <i class="bi <?= $sec['icon'] ?>"></i> <?= $sec['title'] ?>
                    <span class="badge bg-<?= $sec['color'] ?> ms-1" style="font-size: 0.7rem;"><?= count($sec['list']) ?></span>
                </h6>
                <div class="accordion" id="accordion<?= $sec['color'] ?>">
                <?php foreach ($sec['list'] as $idx => $bk):
                    $bookingId = $bk['booking_id'];
                    $sampelList = $allSampel[$bookingId] ?? [];
                    $totalSampel = count($sampelList);
                    $sudahDiuji = 0;
                    foreach ($sampelList as $sl) { if ($sl['status_sampel'] === 'selesai_diuji') $sudahDiuji++; }
                    $isOpen = ($firstOpen && $idx === 0) ? 'show' : '';
                    $firstOpen = false;
                ?>
                    <div class="accordion-item border-0 mb-2 shadow-sm" style="border-radius: 12px !important; overflow: hidden;">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-bold" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#collapse<?= $bookingId ?>"
                                    style="background: linear-gradient(135deg, #7c2d12, #9a3412); color: #fff;">
                                <div class="d-flex flex-column flex-md-row align-items-md-center gap-2 w-100">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-white text-orange" style="color: #c2410c !important; font-size: 0.7rem;">
                                            <?= $totalSampel ?> Sampel
                                        </span>
                                        <strong><?= htmlspecialchars($bk['kode_booking']) ?></strong>
                                    </div>
                                    <div class="d-none d-md-flex align-items-center gap-3 text-white-50" style="font-size: 0.8125rem;">
                                        <span><?= htmlspecialchars($bk['nama_pengujian']) ?></span>
                                        <span>&bull;</span>
                                        <span><?= htmlspecialchars($bk['nama_pelanggan']) ?></span>
                                        <span>&bull;</span>
                                        <span><?= date('d M Y', strtotime($bk['tanggal_jadwal'])) ?></span>
                                        <span class="badge bg-light" style="color: #c2410c; font-size: 0.7rem;">
                                            <?= $sudahDiuji ?>/<?= $totalSampel ?> Selesai
                                        </span>
                                    </div>
                                    <div class="d-flex d-md-none gap-2 flex-wrap" style="font-size: 0.75rem; color: rgba(255,255,255,0.7);">
                                        <span><?= htmlspecialchars($bk['nama_pengujian']) ?></span>
                                        <span>|</span>
                                        <span><?= htmlspecialchars($bk['nama_pelanggan']) ?></span>
                                        <span>|</span>
                                        <span><?= date('d M Y', strtotime($bk['tanggal_jadwal'])) ?></span>
                                        <span class="badge bg-light" style="color: #c2410c; font-size: 0.65rem; margin-left: 4px;">
                                            <?= $sudahDiuji ?>/<?= $totalSampel ?>
                                        </span>
                                    </div>
                                </div>
                            </button>
                        </h2>
                        <div id="collapse<?= $bookingId ?>" class="accordion-collapse collapse <?= $isOpen ?>" data-bs-parent="#accordion<?= $sec['color'] ?>">
                            <div class="accordion-body p-0">
                                <?php if (!empty($bk['dokumen_persyaratan'])): ?>
                                    <div class="px-3 pt-3">
                                        <a href="../<?= htmlspecialchars($bk['dokumen_persyaratan']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-file-earmark"></i> Lihat Dokumen Persyaratan
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($sec['color'] !== 'success'): ?>
                                    <?php
                                    $adaDiterima = false;
                                    foreach ($sampelList as $sl) { if ($sl['status_sampel'] === 'diterima') $adaDiterima = true; }
                                    ?>
                                    <?php if ($adaDiterima): ?>
                                        <div class="px-3 pt-3">
                                            <form method="POST" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                                                <button type="submit" name="mulai_uji" class="btn btn-primary"
                                                        onclick="return confirm('Mulai pengujian untuk semua <?= $totalSampel ?> sampel sekaligus?')">
                                                    Mulai Uji Semua Sampel
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                    <?php
                                    $adaSedangDiuji = false;
                                    foreach ($sampelList as $sl) { if ($sl['status_sampel'] === 'sedang_diuji') $adaSedangDiuji = true; }
                                    ?>
                                    <?php if ($adaSedangDiuji): ?>
                                        <div class="px-3 pt-3">
                                            <form method="POST" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                                                <button type="submit" name="selesai_uji_semua" class="btn btn-success"
                                                        onclick="return confirm('Tandai semua sampel selesai diuji?')">
                                                    Selesai Uji Semua Sampel
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($sampelList)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th style="font-size: 0.75rem;">Kode Sampel</th>
                                                <th style="font-size: 0.75rem;">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sampelList as $s): ?>
                                            <tr>
                                                <td>
                                                    <strong style="font-size: 0.875rem;"><?= htmlspecialchars($s['kode_sampel']) ?></strong>
                                                </td>
                                                <td>
                                                    <?php if ($s['status_sampel'] === 'diterima'): ?>
                                                        <span class="badge bg-secondary">Siap Diuji</span>
                                                    <?php elseif ($s['status_sampel'] === 'sedang_diuji'): ?>
                                                        <span class="badge bg-warning text-dark">Sedang Diuji</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Selesai</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                    <div class="text-center py-3 text-muted" style="font-size: 0.875rem;">Tidak ada data sampel.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
