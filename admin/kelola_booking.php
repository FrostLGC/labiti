<?php
require '../config/database.php';
require '../config/auth.php';
requireRole(['admin']);

$user = currentUser($pdo);
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $aksi = $_POST['aksi'] ?? '';

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    $cekStmt = $pdo->prepare("SELECT status_booking FROM bookings WHERE id = ?");
    $cekStmt->execute([$bookingId]);
    $statusSekarang = $cekStmt->fetchColumn();

    if ($aksi === 'terima' && $statusSekarang === 'menunggu_verifikasi') {
        $stmt = $pdo->prepare("UPDATE bookings SET status_booking = 'booking_diterima', diverifikasi_oleh = ?, diverifikasi_pada = NOW() WHERE id = ?");
        $stmt->execute([$user['id'], $bookingId]);

        $pdo->prepare("INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan) VALUES (?, 'booking_diterima', ?, 'Booking diverifikasi dan diterima oleh Admin')")
             ->execute([$bookingId, $user['id']]);

        $success = 'Booking berhasil diverifikasi dan diterima.';

    } elseif ($aksi === 'tolak' && $statusSekarang === 'menunggu_verifikasi') {
        $alasan = trim($_POST['alasan_ditolak'] ?? '');

        $stmt = $pdo->prepare("UPDATE bookings SET status_booking = 'ditolak', alasan_ditolak = ?, diverifikasi_oleh = ?, diverifikasi_pada = NOW() WHERE id = ?");
        $stmt->execute([$alasan, $user['id'], $bookingId]);

        $pdo->prepare("INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan) VALUES (?, 'ditolak', ?, ?)")
             ->execute([$bookingId, $user['id'], "Ditolak oleh Admin: $alasan"]);

        $success = 'Booking berhasil ditolak.';

    } elseif ($aksi === 'validasi_hasil' && $statusSekarang === 'validasi_hasil') {
        $stmt = $pdo->prepare("UPDATE bookings SET status_booking = 'menunggu_pembayaran' WHERE id = ?");
        $stmt->execute([$bookingId]);

        $pdo->prepare("INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan) VALUES (?, 'menunggu_pembayaran', ?, 'Hasil uji telah divalidasi oleh Admin. Menunggu pembayaran.')")
             ->execute([$bookingId, $user['id']]);

        $success = 'Hasil uji berhasil divalidasi. Booking masuk ke tahap pembayaran.';
    }

    if ($aksi === 'cancel_pengujian' && $statusSekarang === 'jadwal_ditentukan') {
        $cekTipe = $pdo->prepare("SELECT tipe_booking FROM bookings WHERE id = ?");
        $cekTipe->execute([$bookingId]);
        $tipe = $cekTipe->fetchColumn();

        if ($tipe !== 'online') { $error = 'Cancel hanya berlaku untuk booking online.'; } else {

        $alasan = trim($_POST['alasan_cancel'] ?? '');

        $pdo->prepare("UPDATE bookings SET status_booking = 'ditolak', alasan_ditolak = ? WHERE id = ?")
             ->execute([$alasan ?: 'Dibatalkan oleh Admin sebelum Sampel Diterima', $bookingId]);

        $pdo->prepare("DELETE FROM penjadwalan WHERE booking_id = ?")->execute([$bookingId]);

        $pdo->prepare("INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan) VALUES (?, 'ditolak', ?, ?)")
             ->execute([$bookingId, $user['id'], 'Pengujian dibatalkan oleh Admin sebelum Sampel Diterima. ' . ($alasan ?: '')]);

        $success = 'Pengujian berhasil dibatalkan dan jadwal pengujian dihapus.';
        }
    }

    if ($success === '' && $error === '') {
        $error = 'Aksi tidak dapat diproses: status booking tidak sesuai atau aksi tidak dikenal. Muat ulang halaman lalu coba lagi.';
    }
}

$msg = $_GET['msg'] ?? '';
if ($msg === 'pembayaran_berhasil') $success = 'Pembayaran berhasil diverifikasi. Booking selesai.';
elseif ($msg === 'pembayaran_ditolak') $success = 'Bukti transfer ditolak. Pelanggan akan mengunggah ulang.';
elseif ($msg === 'input_nilai_berhasil') $success = 'Laporan berhasil diterbitkan. Menunggu validasi hasil uji.';
elseif ($msg === 'validasi_berhasil') $success = 'Hasil uji berhasil divalidasi. Booking masuk ke tahap pembayaran.';
elseif ($msg === 'cancel_berhasil') $success = 'Pengujian berhasil dibatalkan.';
elseif ($msg === 'jadwal_berhasil') $success = 'Jadwal pengujian berhasil dibuat.';
elseif ($msg === 'sampel_diterima') $success = 'Sampel berhasil diterima.';

$filterTipe = $_GET['tipe'] ?? '';
if (in_array($filterTipe, ['online', 'offline'])) {
    $stmt = $pdo->prepare(
        "SELECT b.*, j.nama_pengujian, u.nama_lengkap, u.nama_perusahaan
         FROM bookings b
         JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
         JOIN users u ON b.user_id = u.id
         WHERE b.tipe_booking = ?
         ORDER BY b.tanggal_pengujian_diminta ASC, b.created_at DESC"
    );
    $stmt->execute([$filterTipe]);
} else {
    $stmt = $pdo->query(
        "SELECT b.*, j.nama_pengujian, u.nama_lengkap, u.nama_perusahaan
         FROM bookings b
         JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
         JOIN users u ON b.user_id = u.id
         ORDER BY b.tanggal_pengujian_diminta ASC, b.created_at DESC"
    );
}
$semuaBooking = $stmt->fetchAll();

require '../config/labels.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Booking - Admin</title>
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
        <div class="page-header">
            <h4>Kelola Booking</h4>
            <p>Verifikasi dan pantau seluruh booking pengujian</p>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="mb-3">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="text-muted" style="font-size: 0.875rem;">Filter Tipe:</span>
                <a href="kelola_booking.php" class="btn btn-sm <?= !$filterTipe ? 'btn-primary' : 'btn-outline-primary' ?>">Semua</a>
                <a href="kelola_booking.php?tipe=online" class="btn btn-sm <?= $filterTipe === 'online' ? 'btn-success' : 'btn-outline-success' ?>">
                    <i class="bi bi-globe"></i> Online
                </a>
                <a href="kelola_booking.php?tipe=offline" class="btn btn-sm <?= $filterTipe === 'offline' ? 'btn-info' : 'btn-outline-info' ?>">
                    <i class="bi bi-building"></i> Offline (Walk-in)
                </a>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Tipe</th>
                                <th>Pelanggan</th>
                                <th>Jenis Pengujian</th>
                                <th>Proyek</th>
                                <th>Sampel</th>
                                <th>Tanggal</th>
                                <th>Dokumen</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($semuaBooking as $b): [$label, $warna] = labelStatus($b['status_booking']); ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($b['kode_booking']) ?></strong></td>
                                <td>
                                    <?php if ($b['tipe_booking'] === 'online'): ?>
                                        <span class="badge bg-success" style="font-size: 0.7rem;"><i class="bi bi-globe"></i> Online</span>
                                    <?php else: ?>
                                        <span class="badge bg-info" style="font-size: 0.7rem;"><i class="bi bi-building"></i> Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($b['nama_lengkap']) ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($b['nama_perusahaan'] ?? '-') ?></small>
                                </td>
                                <td><?= htmlspecialchars($b['nama_pengujian']) ?></td>
                                <td><small><?= htmlspecialchars($b['objek_pengujian'] ?? '-') ?></small></td>
                                <td>
                                    <?= $b['jumlah_sampel'] ?>
                                    <?php
                                    $sp = [];
                                    if (!empty($b['sample_berat']))    $sp[] = 'Berat ' . rtrim(rtrim(number_format($b['sample_berat'], 2, ',', '.'), '0'), ',') . ' Kg';
                                    if (!empty($b['sample_diameter'])) $sp[] = 'Ø ' . rtrim(rtrim(number_format($b['sample_diameter'], 2, ',', '.'), '0'), ',');
                                    if (!empty($b['sample_tinggi']))   $sp[] = 'T ' . rtrim(rtrim(number_format($b['sample_tinggi'], 2, ',', '.'), '0'), ',');
                                    if (!empty($b['sample_bentang_l'])) $sp[] = 'L ' . rtrim(rtrim(number_format($b['sample_bentang_l'], 2, ',', '.'), '0'), ',');
                                    if (!empty($b['sample_dimensi_b'])) $sp[] = 'b ' . rtrim(rtrim(number_format($b['sample_dimensi_b'], 2, ',', '.'), '0'), ',');
                                    if (!empty($b['sample_dimensi_h'])) $sp[] = 'h ' . rtrim(rtrim(number_format($b['sample_dimensi_h'], 2, ',', '.'), '0'), ',');
                                    if (!empty($b['sample_jenis_baja'])) $sp[] = $b['sample_jenis_baja'];
                                    if (!empty($b['sample_panjang']))   $sp[] = 'P ' . rtrim(rtrim(number_format($b['sample_panjang'], 2, ',', '.'), '0'), ',');
                                    if ($sp) echo '<br><small class="text-muted">' . htmlspecialchars(implode(' · ', $sp)) . '</small>';
                                    ?>
                                </td>
                                <td><?= date('d-m-Y', strtotime($b['tanggal_pengujian_diminta'])) ?></td>
                                <td>
                                    <?php if (!empty($b['dokumen_persyaratan'])): ?>
                                        <a href="../<?= htmlspecialchars($b['dokumen_persyaratan']) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Lihat Dokumen">
                                            <i class="bi bi-file-earmark"></i> Lihat
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-<?= $warna ?>"><?= $label ?></span></td>
                                <td>
                                    <?php if ($b['tipe_booking'] === 'offline' && $b['status_booking'] === 'menunggu_pembayaran'): ?>
                                        <small class="text-muted d-block mb-1" style="font-size: 0.7rem;">Walk-in — Langsung Bayar</small>
                                    <?php endif; ?>
                                    <?php if ($b['status_booking'] === 'menunggu_verifikasi'): ?>
                                        <form method="POST" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                            <input type="hidden" name="aksi" value="terima">
                                            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Terima booking ini?')">Terima</button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#modalTolak<?= $b['id'] ?>">Tolak</button>

                                        <div class="modal fade" id="modalTolak<?= $b['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <?= csrf_field() ?>
                                                        <div class="modal-header">
                                                            <h6 class="modal-title">Tolak Booking <?= htmlspecialchars($b['kode_booking']) ?></h6>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                                            <input type="hidden" name="aksi" value="tolak">
                                                            <label class="form-label">Alasan Penolakan</label>
                                                            <textarea name="alasan_ditolak" class="form-control" rows="3" required placeholder="Masukkan alasan penolakan..."></textarea>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                            <button type="submit" class="btn btn-danger btn-sm">Tolak Booking</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php elseif ($b['status_booking'] === 'booking_diterima'): ?>
                                        <a href="penjadwalan.php?booking_id=<?= $b['id'] ?>" class="btn btn-sm btn-primary">Buat Jadwal</a>
                                    <?php elseif ($b['status_booking'] === 'jadwal_ditentukan'): ?>
                                        <a href="terima_sampel.php?booking_id=<?= $b['id'] ?>" class="btn btn-sm btn-primary">Terima Sampel</a>
                                        <?php if (($b['tipe_booking'] ?? 'online') === 'online'): ?>
                                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#cancelModal<?= $b['id'] ?>"><i class="bi bi-x-circle"></i> Cancel</button>
                                        <div class="modal fade" id="cancelModal<?= $b['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <?= csrf_field() ?>
                                                        <div class="modal-header">
                                                            <h6 class="modal-title">Cancel Pengujian <?= htmlspecialchars($b['kode_booking']) ?></h6>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>Booking <strong><?= htmlspecialchars($b['kode_booking']) ?></strong> akan dibatalkan sebelum sampel diterima.</p>
                                                            <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                                            <input type="hidden" name="aksi" value="cancel_pengujian">
                                                            <div class="mb-3">
                                                                <label class="form-label">Alasan Cancel (opsional)</label>
                                                                <textarea name="alasan_cancel" class="form-control" rows="3" placeholder="Contoh: Pelanggan membatalkan pesanan"></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                            <button type="submit" class="btn btn-danger btn-sm">Batalkan Pengujian</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    <?php elseif ($b['status_booking'] === 'menunggu_diuji'): ?>
                                        <span class="badge bg-warning">Menunggu Teknisi</span>
                                    <?php elseif ($b['status_booking'] === 'menunggu_input_admin'): ?>
                                        <a href="input_nilai.php?booking_id=<?= $b['id'] ?>" class="btn btn-sm btn-primary">Input Nilai</a>
                                    <?php elseif ($b['status_booking'] === 'validasi_hasil'): ?>
                                        <a href="edit_nilai.php?booking_id=<?= $b['id'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil-square"></i> Edit Nilai</a>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Validasi hasil uji ini? Booking akan masuk ke tahap pembayaran.')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                            <input type="hidden" name="aksi" value="validasi_hasil">
                                            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-circle"></i> Validasi Hasil</button>
                                        </form>
                                    <?php elseif ($b['status_booking'] === 'menunggu_pembayaran'): ?>
                                        <a href="verifikasi_pembayaran.php?booking_id=<?= $b['id'] ?>" class="btn btn-sm btn-success"><i class="bi bi-cash"></i> Verifikasi Bayar</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($semuaBooking)): ?>
                            <tr><td colspan="10" class="text-center text-muted py-4">Tidak ada data booking.</td></tr>
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
