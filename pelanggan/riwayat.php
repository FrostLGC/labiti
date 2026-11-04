<?php
require '../config/database.php';
require '../config/auth.php';
requireRole(['pelanggan']);

$user = currentUser($pdo);

$stmt = $pdo->prepare(
    "SELECT b.*, j.nama_pengujian, j.kategori
     FROM bookings b
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     WHERE b.user_id = ?
     ORDER BY b.created_at DESC"
);
$stmt->execute([$user['id']]);
$daftarBooking = $stmt->fetchAll();

require '../config/labels.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Booking - Pelanggan</title>
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

    <div class="page-container">
        <div class="page-header">
            <h4>Riwayat Booking Saya</h4>
            <p>Semua pengajuan pengujian yang pernah Anda buat</p>
        </div>

        <?php if (empty($daftarBooking)): ?>
            <div class="alert alert-info">
                Anda belum memiliki riwayat booking.
                <a href="booking.php" class="fw-bold">Buat booking pertama Anda</a>.
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Tipe</th>
                                    <th>Jenis Pengujian</th>
                                    <th class="d-none d-sm-table-cell">Sampel</th>
                                    <th class="d-none d-md-table-cell">Tanggal</th>
                                    <th class="d-none d-lg-table-cell">Biaya</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($daftarBooking as $b):
                                    [$label, $warna] = labelStatus($b['status_booking']);
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($b['kode_booking']) ?></strong></td>
                                    <td>
                                        <?php if (($b['tipe_booking'] ?? 'online') === 'online'): ?>
                                            <span class="badge bg-success" style="font-size: 0.65rem;"><i class="bi bi-globe"></i> Online</span>
                                        <?php else: ?>
                                            <span class="badge bg-info" style="font-size: 0.65rem;"><i class="bi bi-building"></i> Offline</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($b['nama_pengujian']) ?></td>
                                    <td class="d-none d-sm-table-cell"><?= $b['jumlah_sampel'] ?></td>
                                    <td class="d-none d-md-table-cell"><?= date('d-m-Y', strtotime($b['tanggal_pengujian_diminta'])) ?></td>
                                    <td class="d-none d-lg-table-cell">Rp <?= number_format($b['estimasi_biaya'], 0, ',', '.') ?></td>
                                    <td><span class="badge bg-<?= $warna ?>"><?= $label ?></span></td>
                                    <td>
                                        <a href="detail_booking.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary">Detail</a>
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
