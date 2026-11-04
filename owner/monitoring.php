<?php
require '../config/database.php';
require '../config/auth.php';
requireRole(['owner']);

$user = currentUser($pdo);

$filterStatus = $_GET['status'] ?? '';
$filterBulan = $_GET['bulan'] ?? '';
$filterTahun = $_GET['tahun'] ?? '';

$sql = "SELECT b.*, j.nama_pengujian, j.kategori, u.nama_lengkap, u.nama_perusahaan
        FROM bookings b
        JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
        JOIN users u ON b.user_id = u.id";
$params = [];
$conditions = [];

if ($filterStatus) {
    $conditions[] = "b.status_booking = ?";
    $params[] = $filterStatus;
}
if ($filterBulan) {
    $conditions[] = "MONTH(b.tanggal_pengujian_diminta) = ?";
    $params[] = $filterBulan;
}
if ($filterTahun) {
    $conditions[] = "YEAR(b.tanggal_pengujian_diminta) = ?";
    $params[] = $filterTahun;
}
if ($conditions) {
    $sql .= " WHERE " . implode(' AND ', $conditions);
}
$sql .= " ORDER BY b.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$semuaBooking = $stmt->fetchAll();

require '../config/labels.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring Booking - Owner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4>Monitoring Seluruh Booking</h4>
                    <p>Pantau status seluruh booking pengujian</p>
                </div>
                <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Kembali ke Dashboard</a>
            </div>
        </div>

        <form method="GET" class="mb-3">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <select name="status" class="form-select" style="width: auto;" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="menunggu_verifikasi" <?= $filterStatus === 'menunggu_verifikasi' ? 'selected' : '' ?>>Menunggu Verifikasi</option>
                    <option value="menunggu_pembayaran" <?= $filterStatus === 'menunggu_pembayaran' ? 'selected' : '' ?>>Menunggu Pembayaran</option>
                    <option value="booking_diterima" <?= $filterStatus === 'booking_diterima' ? 'selected' : '' ?>>Booking Diterima</option>
                    <option value="jadwal_ditentukan" <?= $filterStatus === 'jadwal_ditentukan' ? 'selected' : '' ?>>Jadwal Ditentukan</option>
                    <option value="sampel_diterima" <?= $filterStatus === 'sampel_diterima' ? 'selected' : '' ?>>Sampel Diterima</option>
                    <option value="menunggu_diuji" <?= $filterStatus === 'menunggu_diuji' ? 'selected' : '' ?>>Menunggu Diuji</option>
                    <option value="sedang_diuji" <?= $filterStatus === 'sedang_diuji' ? 'selected' : '' ?>>Sedang Diuji</option>
                    <option value="menunggu_input_admin" <?= $filterStatus === 'menunggu_input_admin' ? 'selected' : '' ?>>Menunggu Input Admin</option>
                    <option value="validasi_hasil" <?= $filterStatus === 'validasi_hasil' ? 'selected' : '' ?>>Validasi Hasil</option>
                    <option value="selesai" <?= $filterStatus === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                    <option value="ditolak" <?= $filterStatus === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                </select>

                <select name="bulan" class="form-select" style="width: auto;" onchange="this.form.submit()">
                    <option value="">Semua Bulan</option>
                    <option value="1" <?= $filterBulan === '1' ? 'selected' : '' ?>>Januari</option>
                    <option value="2" <?= $filterBulan === '2' ? 'selected' : '' ?>>Februari</option>
                    <option value="3" <?= $filterBulan === '3' ? 'selected' : '' ?>>Maret</option>
                    <option value="4" <?= $filterBulan === '4' ? 'selected' : '' ?>>April</option>
                    <option value="5" <?= $filterBulan === '5' ? 'selected' : '' ?>>Mei</option>
                    <option value="6" <?= $filterBulan === '6' ? 'selected' : '' ?>>Juni</option>
                    <option value="7" <?= $filterBulan === '7' ? 'selected' : '' ?>>Juli</option>
                    <option value="8" <?= $filterBulan === '8' ? 'selected' : '' ?>>Agustus</option>
                    <option value="9" <?= $filterBulan === '9' ? 'selected' : '' ?>>September</option>
                    <option value="10" <?= $filterBulan === '10' ? 'selected' : '' ?>>Oktober</option>
                    <option value="11" <?= $filterBulan === '11' ? 'selected' : '' ?>>November</option>
                    <option value="12" <?= $filterBulan === '12' ? 'selected' : '' ?>>Desember</option>
                </select>

                <select name="tahun" class="form-select" style="width: auto;" onchange="this.form.submit()">
                    <option value="">Semua Tahun</option>
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                        <option value="<?= $y ?>" <?= $filterTahun == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>

                <?php if ($filterStatus || $filterBulan || $filterTahun): ?>
                    <a href="monitoring.php" class="btn btn-outline-danger btn-sm">Reset Filter</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Tipe</th>
                                <th>Pelanggan</th>
                                <th>Jenis Pengujian</th>
                                <th class="d-none d-sm-table-cell">Sampel</th>
                                <th class="d-none d-md-table-cell">Tanggal</th>
                                <th class="d-none d-lg-table-cell">Biaya</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($semuaBooking as $b): [$label, $warna] = labelStatus($b['status_booking']); ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($b['kode_booking']) ?></strong></td>
                                <td>
                                    <?php if (($b['tipe_booking'] ?? 'online') === 'online'): ?>
                                        <span class="badge bg-success" style="font-size: 0.65rem;"><i class="bi bi-globe"></i> Online</span>
                                    <?php else: ?>
                                        <span class="badge bg-info" style="font-size: 0.65rem;"><i class="bi bi-building"></i> Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($b['nama_lengkap']) ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($b['nama_perusahaan'] ?? '-') ?></small>
                                </td>
                                <td><?= htmlspecialchars($b['nama_pengujian']) ?></td>
                                <td class="d-none d-sm-table-cell"><?= $b['jumlah_sampel'] ?></td>
                                <td class="d-none d-md-table-cell"><?= date('d-m-Y', strtotime($b['tanggal_pengujian_diminta'])) ?></td>
                                <td class="d-none d-lg-table-cell">Rp <?= number_format($b['estimasi_biaya'], 0, ',', '.') ?></td>
                                <td><span class="badge bg-<?= $warna ?>"><?= $label ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($semuaBooking)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data.</td></tr>
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
