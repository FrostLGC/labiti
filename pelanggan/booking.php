<?php
require '../config/database.php';
require '../config/auth.php';
require '../config/labels.php';
requireRole(['pelanggan']);

$user = currentUser($pdo);
$error = '';
$success = '';

$stmtJenis = $pdo->query("SELECT * FROM jenis_pengujian WHERE status_aktif = 1 ORDER BY kategori, nama_pengujian");
$daftarJenis = $stmtJenis->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jenisPengujianId = $_POST['jenis_pengujian_id'] ?? '';
    $penanggungJawab = trim($_POST['penanggung_jawab'] ?? '');
    $jumlahSampel = (int) ($_POST['jumlah_sampel'] ?? 0);
    $tanggalPengujian = $_POST['tanggal_pengujian'] ?? '';
    $catatan = trim($_POST['catatan_pelanggan'] ?? '');
    $objekPengujian = trim($_POST['objek_pengujian'] ?? '');
    $lokasi = trim($_POST['lokasi'] ?? '');
    $supplier = trim($_POST['supplier'] ?? '');
    $mutuBeton = trim($_POST['mutu_beton'] ?? '');
    $daftarMutu = ['K-175','K-200','K-225','K-250','K-275','K-300','K-350','K-400','K-450','K-500'];
    $tipeBooking = (($_POST['tipe_booking'] ?? '') === 'offline') ? 'offline' : 'online';
    $tambahanCetak = !empty($_POST['tambahan_cetak']) ? 1 : 0;
    $tambahanSetting = !empty($_POST['tambahan_setting']) ? 1 : 0;

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    // Walk-in: paksa tanggal = hari ini
    if ($tipeBooking === 'offline') {
        $tanggalPengujian = date('Y-m-d');
    }

        $stmtHarga = $pdo->prepare("SELECT harga, kategori, nama_pengujian FROM jenis_pengujian WHERE id = ?");
        $stmtHarga->execute([$jenisPengujianId]);
        $jenis = $stmtHarga->fetch();
        $minSampel = $jenis ? minSampelPengujian($jenis['nama_pengujian'], $jenis['kategori']) : 1;

        if (!$jenis) {
        $error = 'Jenis pengujian tidak valid.';
    } elseif ($penanggungJawab === '') {
        $error = 'Nama penanggung jawab wajib diisi.';
    } elseif ($objekPengujian === '') {
        $error = 'Obyek pengujian wajib diisi.';
    } elseif ($lokasi === '') {
        $error = 'Lokasi wajib diisi.';
    } elseif (empty($supplier)) {
        $error = 'Supplier wajib diisi.';
    } elseif ($jenis['kategori'] === 'beton' && !in_array($mutuBeton, $daftarMutu, true)) {
        $error = 'Mutu beton wajib dipilih.';
    } elseif ($jumlahSampel < 1) {
        $error = 'Jumlah sampel minimal 1.';
    } elseif ($jumlahSampel < $minSampel) {
        $error = 'Untuk pengujian "' . $jenis['nama_pengujian'] . '", jumlah sampel minimal ' . $minSampel . ' pcs.';
    } elseif (!tanggalValid($tanggalPengujian)) {
        $error = 'Tanggal pengujian tidak valid.';
    } elseif (strtotime($tanggalPengujian) < strtotime(date('Y-m-d'))) {
        $error = 'Tanggal pengujian tidak boleh di masa lalu.';
    } else {
        $estimasiBiaya = $jenis['harga'] * $jumlahSampel;

        // Cas tambahan: Sabtu/Minggu +400rb, hari ini booking setelah jam 16:00 +250rb
        $casWeekend = 0;
        $casJamAkhir = 0;
        $hariBooking = date('w', strtotime($tanggalPengujian));
        $hariIni = date('Y-m-d');
        if ($hariBooking == 0 || $hariBooking == 6) {
            $casWeekend = 400000;
        } elseif ($tanggalPengujian === $hariIni && date('H') >= 16) {
            $casJamAkhir = 250000;
        }
        $estimasiBiaya += $casWeekend + $casJamAkhir;

        $biayaLimbahBeton = ($jenis['kategori'] === 'beton') ? 50000 : 0;
        $estimasiBiaya += $biayaLimbahBeton;

        $pakaiCetak = ($tambahanCetak && isCoreDrillUji($jenis['nama_pengujian'])) ? 1 : 0;
        $estimasiBiaya += $pakaiCetak ? HARGA_TAMBAHAN_CETAK * $jumlahSampel : 0;

        $pakaiSetting = ($tambahanSetting && $jenis['kategori'] === 'besi') ? 1 : 0;
        $estimasiBiaya += $pakaiSetting ? HARGA_TAMBAHAN_SETTING * $jumlahSampel : 0;

        $dokumenPath = null;
        if (isset($_FILES['dokumen_persyaratan']) && ($_FILES['dokumen_persyaratan']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $fileDok = $_FILES['dokumen_persyaratan'];
            $extensionsDiizinkan = ['pdf', 'jpg', 'jpeg', 'png'];
            $namaFile = $fileDok['name'];
            $ext = strtolower(pathinfo($namaFile, PATHINFO_EXTENSION));

            if ($fileDok['error'] !== UPLOAD_ERR_OK) {
                $error = 'Upload dokumen persyaratan gagal (kemungkinan file terlalu besar). Maksimal 5MB.';
            } elseif ($fileDok['size'] > 5 * 1024 * 1024) {
                $error = 'Ukuran dokumen persyaratan maksimal 5MB.';
            } elseif (!in_array($ext, $extensionsDiizinkan)) {
                $error = 'Format dokumen harus PDF, JPG, atau PNG.';
            } else {
                $isiCocok = true;
                if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                    $isiCocok = @getimagesize($fileDok['tmp_name']) !== false;
                } elseif ($ext === 'pdf') {
                    $fh = @fopen($fileDok['tmp_name'], 'rb');
                    $head = $fh ? fread($fh, 5) : '';
                    if ($fh) fclose($fh);
                    $isiCocok = (strncmp($head, '%PDF-', 5) === 0);
                }
                if (!$isiCocok) {
                    $error = 'Isi file tidak sesuai dengan formatnya. Pastikan file adalah PDF, JPG, atau PNG asli.';
                } else {
                    $namaBaru = 'dok_' . time() . '_' . uniqid() . '.' . $ext;
                    $tujuanUpload = '../uploads/dokumen/' . $namaBaru;

                    if (!is_dir('../uploads/dokumen')) {
                        mkdir('../uploads/dokumen', 0777, true);
                    }

                    if (move_uploaded_file($fileDok['tmp_name'], $tujuanUpload)) {
                        $dokumenPath = 'uploads/dokumen/' . $namaBaru;
                    } else {
                        $error = 'Gagal mengunggah dokumen.';
                    }
                }
            }
        }

        if (!$error) {
            $kodeBooking = generateKodeBooking($pdo, $jenisPengujianId, $jenis['nama_pengujian'], $jenis['kategori']);

            $statusAwal = 'menunggu_verifikasi';
            $keteranganRiwayat = ($tipeBooking === 'offline')
                ? 'Booking offline dibuat oleh pelanggan.'
                : 'Booking dibuat oleh pelanggan';

            $stmt = $pdo->prepare(
                "INSERT INTO bookings
                 (kode_booking, user_id, jenis_pengujian_id, penanggung_jawab, jumlah_sampel,
                  tanggal_pengujian_diminta, dokumen_persyaratan, estimasi_biaya, catatan_pelanggan, objek_pengujian, lokasi, supplier, mutu_beton, tambahan_cetak, tambahan_setting, tipe_booking, status_booking)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $kodeBooking, $user['id'], $jenisPengujianId, $penanggungJawab,
                $jumlahSampel, $tanggalPengujian, $dokumenPath, $estimasiBiaya, $catatan, $objekPengujian, $lokasi, $supplier,
                ($jenis['kategori'] === 'beton' ? $mutuBeton : null), $pakaiCetak, $pakaiSetting, $tipeBooking, $statusAwal
            ]);

            $bookingId = $pdo->lastInsertId();

            $stmtRiwayat = $pdo->prepare(
                "INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan)
                 VALUES (?, ?, ?, ?)"
            );
            $stmtRiwayat->execute([$bookingId, $statusAwal, $user['id'], $keteranganRiwayat]);

            if ($tipeBooking === 'offline') {
                $success = "Booking offline berhasil dibuat dengan kode: $kodeBooking. Silakan tunggu verifikasi dari admin.";
            } else {
                $success = "Booking berhasil dibuat dengan kode: $kodeBooking. Silakan tunggu verifikasi dari admin.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Pengujian - Pelanggan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .is-invalid-custom { border-color: #dc3545 !important; box-shadow: 0 0 0 0.2rem rgba(220,53,69,.15) !important; }
        .is-invalid-custom:focus { border-color: #dc3545 !important; box-shadow: 0 0 0 0.2rem rgba(220,53,69,.25) !important; }
        @keyframes shakeErr { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-4px)} 40%,80%{transform:translateX(4px)} }
        .shake-err { animation: shakeErr .3s ease; }
        .isi-info { position:fixed; bottom:20px; right:20px; z-index:1050; }
    </style>
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
        <a href="dashboard.php" class="text-decoration-none text-muted">&larr; Kembali ke Dashboard</a>

        <div class="page-header mt-2">
            <h4>Booking Pengujian</h4>
            <p>Isi formulir berikut untuk mengajukan pengujian bahan konstruksi</p>
        </div>

        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="formBooking">
            <?= csrf_field() ?>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong><i class="bi bi-person"></i> Data Anda</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['nama_lengkap']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Penanggung Jawab <span class="text-danger">*</span></label>
                        <input type="text" name="penanggung_jawab" class="form-control" required
                               value="<?= htmlspecialchars($_POST['penanggung_jawab'] ?? $user['nama_lengkap']) ?>" placeholder="Nama penanggung jawab">
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong><i class="bi bi-flask"></i> Data Pengujian</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Tipe Booking</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="tipe_booking" id="tipeOnline" value="online" checked>
                                <label class="form-check-label" for="tipeOnline">
                                    <i class="bi bi-globe text-success"></i> <strong>Online</strong><br>
                                    <small class="text-muted">Verifikasi oleh admin</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="tipe_booking" id="tipeOffline" value="offline">
                                <label class="form-check-label" for="tipeOffline">
                                    <i class="bi bi-building text-info"></i> <strong>Offline (Walk-in)</strong><br>
                                    <small class="text-muted">Bayar langsung di loket</small>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Jenis Pengujian</label>
                        <select name="jenis_pengujian_id" id="jenisPengujian" class="form-select" required>
                            <option value="">-- Pilih Jenis Pengujian --</option>
                            <?php
                            $valJenis = $_POST['jenis_pengujian_id'] ?? '';
                            $kategoriSaatIni = '';
                            foreach ($daftarJenis as $jenis):
                                if ($kategoriSaatIni !== $jenis['kategori']):
                                    if ($kategoriSaatIni !== '') echo '</optgroup>';
                                    echo '<optgroup label="' . strtoupper($jenis['kategori']) . '">';
                                    $kategoriSaatIni = $jenis['kategori'];
                                endif;
                            ?>
                                <option value="<?= $jenis['id'] ?>" data-harga="<?= $jenis['harga'] ?>" data-kategori="<?= $jenis['kategori'] ?>" data-min="<?= minSampelPengujian($jenis['nama_pengujian'], $jenis['kategori']) ?>"<?= isCoreDrillUji($jenis['nama_pengujian']) ? ' data-tambahan="cetak"' : '' ?><?= $valJenis == $jenis['id'] ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($jenis['nama_pengujian']) ?>
                                    (Rp <?= number_format($jenis['harga'], 0, ',', '.') ?> / <?= $jenis['satuan_sampel'] ?>)
                                </option>
                            <?php endforeach; ?>
                            <?php if ($kategoriSaatIni !== '') echo '</optgroup>'; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Jumlah Sampel <span class="text-danger">*</span></label>
                            <input type="number" name="jumlah_sampel" id="jumlahSampel" class="form-control" min="1" value="<?= htmlspecialchars($_POST['jumlah_sampel'] ?? '1') ?>" required>
                            <small class="text-muted" id="hintMinSampel"></small>
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Tanggal Pengujian Diinginkan <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_pengujian" id="tanggalPengujian" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($_POST['tanggal_pengujian'] ?? '') ?>" required>
                            <small class="text-muted d-none" id="hintTanggalWalkin">Tanggal otomatis = hari ini (walk-in)</small>
                            <small id="infoBookingTanggal" class="d-block mt-1"></small>
                        </div>
                    </div>
                    <div class="mb-3 d-none" id="rowTambahanCetak">
                        <label class="form-label">Layanan Tambahan (opsional)</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="tambahan_cetak" id="tambahanCetak" value="1">
                            <label class="form-check-label" for="tambahanCetak">
                                Pemotongan, Setting &amp; Cetak Benda Uji
                                <span class="text-danger">+Rp <?= number_format(HARGA_TAMBAHAN_CETAK, 0, ',', '.') ?></span> <span class="text-muted">/ sampel</span>
                            </label>
                        </div>
                        <small class="text-muted">Centang jika benda uji perlu dipotong, di-setting, dan dicetak.</small>
                    </div>
                    <div class="mb-3 d-none" id="rowTambahanSetting">
                        <label class="form-label">Layanan Tambahan (opsional)</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="tambahan_setting" id="tambahanSetting" value="1">
                            <label class="form-check-label" for="tambahanSetting">
                                Setting Ukuran Baja Tulangan
                                <span class="text-danger">+Rp <?= number_format(HARGA_TAMBAHAN_SETTING, 0, ',', '.') ?></span> <span class="text-muted">/ sampel</span>
                            </label>
                        </div>
                        <small class="text-muted">Centang jika benda uji perlu di-setting ukurannya sebelum diuji.</small>
                    </div>
                    <div class="mb-3" id="rowDokumen">
                        <label class="form-label">Upload Dokumen Persyaratan (opsional)</label>
                        <input type="file" name="dokumen_persyaratan" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                        <small class="text-muted">Format: PDF, JPG, atau PNG</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Proyek <span class="text-danger">*</span></label>
                        <input type="text" name="objek_pengujian" class="form-control" required
                               value="<?= htmlspecialchars($_POST['objek_pengujian'] ?? '') ?>" placeholder="Contoh: Gedung Serba Guna, Jembatan Tol">
                        <small class="text-muted">Masukkan nama proyek.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Lokasi</label>
                        <input type="text" name="lokasi" class="form-control"
                               value="<?= htmlspecialchars($_POST['lokasi'] ?? '') ?>" placeholder="Lokasi proyek (opsional)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Supplier <span class="text-danger">*</span></label>
                        <input type="text" name="supplier" class="form-control" required
                               value="<?= htmlspecialchars($_POST['supplier'] ?? '') ?>" placeholder="Nama supplier material">
                        <small class="text-muted">Masukkan nama supplier material.</small>
                    </div>
                    <div class="mb-3" id="mutuBetonWrap" style="display:none;">
                        <label class="form-label">Mutu Beton <span class="text-danger">*</span></label>
                        <?php $valMutu = $_POST['mutu_beton'] ?? ''; ?>
                        <select name="mutu_beton" id="mutuBeton" class="form-select">
                            <option value="">-- Pilih Mutu --</option>
                            <option value="K-175"<?= $valMutu==='K-175'?' selected':'' ?>>K-175</option>
                            <option value="K-200"<?= $valMutu==='K-200'?' selected':'' ?>>K-200</option>
                            <option value="K-225"<?= $valMutu==='K-225'?' selected':'' ?>>K-225</option>
                            <option value="K-250"<?= $valMutu==='K-250'?' selected':'' ?>>K-250</option>
                            <option value="K-275"<?= $valMutu==='K-275'?' selected':'' ?>>K-275</option>
                            <option value="K-300"<?= $valMutu==='K-300'?' selected':'' ?>>K-300</option>
                            <option value="K-350"<?= $valMutu==='K-350'?' selected':'' ?>>K-350</option>
                            <option value="K-400"<?= $valMutu==='K-400'?' selected':'' ?>>K-400</option>
                            <option value="K-450"<?= $valMutu==='K-450'?' selected':'' ?>>K-450</option>
                            <option value="K-500"<?= $valMutu==='K-500'?' selected':'' ?>>K-500</option>
                        </select>
                        <small class="text-muted">Sesuaikan dengan rencana mutu beton proyek.</small>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Estimasi Biaya</span>
                        <h4 class="text-danger mb-0" id="estimasiBiaya">Rp 0</h4>
                    </div>
                    <div id="detailCas" class="small" style="display:none;">
                        <div class="d-flex justify-content-between text-muted">
                            <span>Biaya pengujian</span><span id="labelBiayaDasar">Rp 0</span>
                        </div>
                        <div class="justify-content-between text-warning" id="rowCasWeekend" style="display:none;">
                            <span><i class="bi bi-exclamation-triangle"></i> Cas weekend (Sabtu/Minggu)</span><span>+Rp 400.000</span>
                        </div>
                        <div class="justify-content-between text-warning" id="rowCasJam" style="display:none;">
                            <span><i class="bi bi-clock"></i> Cas booking setelah jam 16:00</span><span>+Rp 250.000</span>
                        </div>
                        <div class="justify-content-between text-warning" id="rowLimbahBeton" style="display:none;">
                            <span><i class="bi bi-trash"></i> Limbah beton</span><span>+Rp 50.000</span>
                        </div>
                        <div class="justify-content-between text-warning" id="rowCetak" style="display:none;">
                            <span><i class="bi bi-scissors"></i> Pemotongan, Setting &amp; Cetak Benda Uji</span><span id="labelCetak">+Rp <?= number_format(HARGA_TAMBAHAN_CETAK, 0, ',', '.') ?></span>
                        </div>
                        <div class="justify-content-between text-warning" id="rowSetting" style="display:none;">
                            <span><i class="bi bi-rulers"></i> Setting Ukuran Baja Tulangan</span><span id="labelSetting">+Rp <?= number_format(HARGA_TAMBAHAN_SETTING, 0, ',', '.') ?></span>
                        </div>
                        <hr class="my-1">
                        <div class="d-flex justify-content-between fw-bold">
                            <span>Total</span><span id="labelTotal">Rp 0</span>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bi bi-send"></i> Ajukan Booking
            </button>
            <small class="text-muted d-block mt-2 text-center">Booking akan diverifikasi oleh admin terlebih dahulu.</small>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const casWeekend = 400000;
        const casJamAkhir = 250000;
        const biayaLimbahBeton = 50000;
        const hargaTambahanCetak = <?= HARGA_TAMBAHAN_CETAK ?>;
        const hargaTambahanSetting = <?= HARGA_TAMBAHAN_SETTING ?>;

        function hitungEstimasi() {
            const select = document.getElementById('jenisPengujian');
            const jumlah = parseInt(document.getElementById('jumlahSampel').value, 10) || 0;
            const tanggal = document.querySelector('[name="tanggal_pengujian"]').value;
            const opsiTerpilih = select.options[select.selectedIndex];
            const harga = opsiTerpilih ? (opsiTerpilih.dataset.harga || 0) : 0;
            const kategori = opsiTerpilih ? (opsiTerpilih.dataset.kategori || '') : '';

            let total = harga * jumlah;
            let casW = 0;
            let casJ = 0;
            const limbah = kategori === 'beton' ? biayaLimbahBeton : 0;
            const cbCetak = document.getElementById('tambahanCetak');
            const bisaCetak = !!(opsiTerpilih && opsiTerpilih.dataset.tambahan === 'cetak');
            const cetak = (bisaCetak && cbCetak && cbCetak.checked) ? hargaTambahanCetak * jumlah : 0;
            const cbSetting = document.getElementById('tambahanSetting');
            const bisaSetting = kategori === 'besi';
            const setting = (bisaSetting && cbSetting && cbSetting.checked) ? hargaTambahanSetting * jumlah : 0;

            const labelCetak = document.getElementById('labelCetak');
            const labelSetting = document.getElementById('labelSetting');
            if (labelCetak) labelCetak.textContent = '+Rp ' + (hargaTambahanCetak * jumlah).toLocaleString('id-ID');
            if (labelSetting) labelSetting.textContent = '+Rp ' + (hargaTambahanSetting * jumlah).toLocaleString('id-ID');

            const now = new Date();
            const jamSekarang = now.getHours();
            const y = now.getFullYear();
            const m = String(now.getMonth() + 1).padStart(2, '0');
            const d = String(now.getDate()).padStart(2, '0');
            const hariIni = y + '-' + m + '-' + d;

            if (tanggal) {
                const hari = new Date(tanggal + 'T00:00:00').getDay();
                if (hari === 0 || hari === 6) {
                    casW = casWeekend;
                } else if (tanggal === hariIni && jamSekarang >= 16) {
                    casJ = casJamAkhir;
                }
            }

            const totalAkhir = total + casW + casJ + limbah + cetak + setting;

            document.getElementById('estimasiBiaya').textContent = 'Rp ' + totalAkhir.toLocaleString('id-ID');
            document.getElementById('labelBiayaDasar').textContent = 'Rp ' + total.toLocaleString('id-ID');
            document.getElementById('labelTotal').textContent = 'Rp ' + totalAkhir.toLocaleString('id-ID');

            const detail = document.getElementById('detailCas');
            const rowW = document.getElementById('rowCasWeekend');
            const rowJ = document.getElementById('rowCasJam');
            const rowL = document.getElementById('rowLimbahBeton');
            const rowC = document.getElementById('rowCetak');
            const rowS = document.getElementById('rowSetting');

            if (casW > 0 || casJ > 0 || limbah > 0 || cetak > 0 || setting > 0) {
                detail.style.display = 'block';
                rowW.style.display = casW > 0 ? 'flex' : 'none';
                rowJ.style.display = casJ > 0 ? 'flex' : 'none';
                rowL.style.display = limbah > 0 ? 'flex' : 'none';
                rowC.style.display = cetak > 0 ? 'flex' : 'none';
                rowS.style.display = setting > 0 ? 'flex' : 'none';
            } else {
                detail.style.display = 'none';
            }
        }

        function cekMinSampel() {
            const select = document.getElementById('jenisPengujian');
            const jumlahInput = document.getElementById('jumlahSampel');
            const hint = document.getElementById('hintMinSampel');
            const opsiTerpilih = select.options[select.selectedIndex];
            const kategori = opsiTerpilih ? (opsiTerpilih.dataset.kategori || '') : '';

            const mutuWrap = document.getElementById('mutuBetonWrap');
            const mutuSelect = document.getElementById('mutuBeton');
            if (kategori === 'beton') {
                mutuWrap.style.display = 'block';
                mutuSelect.required = true;
            } else {
                mutuWrap.style.display = 'none';
                mutuSelect.required = false;
                mutuSelect.value = '';
            }

            const min = parseInt((opsiTerpilih && opsiTerpilih.dataset.min) || '1', 10) || 1;
            jumlahInput.min = min;
            if (min > 1) {
                hint.textContent = '* Minimal ' + min + ' pcs untuk pengujian ini';
                hint.className = 'text-danger';
                if (parseInt(jumlahInput.value) < min) jumlahInput.value = min;
            } else {
                hint.textContent = '';
                hint.className = 'text-muted';
            }

            const rowCetak = document.getElementById('rowTambahanCetak');
            const bisaCetak = !!(opsiTerpilih && opsiTerpilih.dataset.tambahan === 'cetak');
            if (rowCetak) {
                rowCetak.classList.toggle('d-none', !bisaCetak);
                if (!bisaCetak) {
                    const cbCetak = document.getElementById('tambahanCetak');
                    if (cbCetak) cbCetak.checked = false;
                }
            }

            const rowSetting = document.getElementById('rowTambahanSetting');
            const bisaSetting = kategori === 'besi';
            if (rowSetting) {
                rowSetting.classList.toggle('d-none', !bisaSetting);
                if (!bisaSetting) {
                    const cbSetting = document.getElementById('tambahanSetting');
                    if (cbSetting) cbSetting.checked = false;
                }
            }
        }

        function cekJumlahBooking(tanggal) {
            const info = document.getElementById('infoBookingTanggal');
            if (!tanggal) {
                info.textContent = '';
                info.className = 'd-block mt-1';
                return;
            }
            info.textContent = 'Memeriksa jadwal...';
            info.className = 'd-block mt-1 text-muted';

            fetch('../api/jumlah_booking.php?tanggal=' + tanggal)
                .then(r => r.json())
                .then(data => {
                    if (data.total === 0) {
                        info.innerHTML = '<i class="bi bi-check-circle text-success"></i> Tanggal ini masih kosong, belum ada booking.';
                        info.className = 'd-block mt-1 text-success';
                    } else if (data.total <= 3) {
                        info.innerHTML = '<i class="bi bi-info-circle text-info"></i> Sudah ada <strong>' + data.total + ' booking</strong> di tanggal ini. Masih ada slot.';
                        info.className = 'd-block mt-1 text-info';
                    } else {
                        info.innerHTML = '<i class="bi bi-exclamation-triangle text-warning"></i> Sudah ada <strong>' + data.total + ' booking</strong> di tanggal ini. Jadwal mungkin padat.';
                        info.className = 'd-block mt-1 text-warning';
                    }
                })
                .catch(() => {
                    info.textContent = '';
                    info.className = 'd-block mt-1';
                });
        }

        function toggleTipeBooking() {
            const isOffline = document.getElementById('tipeOffline').checked;
            const rowDokumen = document.getElementById('rowDokumen');
            const tanggalInput = document.getElementById('tanggalPengujian');
            const hintTanggal = document.getElementById('hintTanggalWalkin');

            if (isOffline) {
                rowDokumen.classList.add('d-none');
                tanggalInput.value = '<?= date('Y-m-d') ?>';
                tanggalInput.readOnly = true;
                tanggalInput.classList.add('bg-light');
                hintTanggal.classList.remove('d-none');
                cekJumlahBooking('<?= date('Y-m-d') ?>');
            } else {
                rowDokumen.classList.remove('d-none');
                tanggalInput.readOnly = false;
                tanggalInput.classList.remove('bg-light');
                tanggalInput.min = '<?= date('Y-m-d') ?>';
                hintTanggal.classList.add('d-none');
                if (tanggalInput.value) cekJumlahBooking(tanggalInput.value);
            }
        }

        document.querySelectorAll('[name="tipe_booking"]').forEach(function(radio) {
            radio.addEventListener('change', toggleTipeBooking);
        });
        toggleTipeBooking();

        document.getElementById('jenisPengujian').addEventListener('change', function() {
            cekMinSampel();
            hitungEstimasi();
        });
        document.getElementById('jumlahSampel').addEventListener('input', hitungEstimasi);
        document.getElementById('tambahanCetak').addEventListener('change', hitungEstimasi);
        document.getElementById('tambahanSetting').addEventListener('change', hitungEstimasi);
        document.querySelector('[name="tanggal_pengujian"]').addEventListener('change', function() {
            hitungEstimasi();
            cekJumlahBooking(this.value);
        });

        // === Validasi visual: tandai field kosong, jangan reset isian ===
        function getRequiredVisible() {
            const fields = [];
            document.querySelectorAll('#formBooking [required]').forEach(function(el) {
                if (el.offsetParent === null) return; // skip hidden
                if (el.closest('.d-none')) return;
                fields.push(el);
            });
            return fields;
        }

        function updateIsiInfo() {
            const fields = getRequiredVisible();
            let terisi = 0;
            fields.forEach(function(el) {
                const v = (el.value || '').trim();
                if (v !== '' && v !== '0') terisi++;
            });
            let box = document.getElementById('isiInfoBox');
            if (!box) {
                box = document.createElement('div');
                box.id = 'isiInfoBox';
                box.className = 'isi-info';
                document.body.appendChild(box);
            }
            const total = fields.length;
            const kurang = total - terisi;
            if (kurang === 0 && total > 0) {
                box.innerHTML = '<div class="alert alert-success py-2 px-3 shadow-sm mb-0" style="font-size:0.85rem;"><i class="bi bi-check-circle-fill"></i> Semua data wajib sudah terisi. Klik <strong>Ajukan Booking</strong>.</div>';
            } else if (total > 0) {
                box.innerHTML = '<div class="alert alert-warning py-2 px-3 shadow-sm mb-0" style="font-size:0.85rem;"><i class="bi bi-exclamation-triangle-fill"></i> Masih ada <strong>' + kurang + '</strong> isian wajib yang kosong.</div>';
            } else {
                box.innerHTML = '';
            }
        }

        function bersihkanTanda(el) {
            el.classList.remove('is-invalid-custom');
        }

        // Pasang listener input/change di semua required field
        function pasangListenerIsi() {
            document.querySelectorAll('#formBooking [required]').forEach(function(el) {
                if (el.dataset.listenerIsi) return;
                el.dataset.listenerIsi = '1';
                el.addEventListener('input', function() { bersihkanTanda(this); updateIsiInfo(); });
                el.addEventListener('change', function() { bersihkanTanda(this); updateIsiInfo(); });
            });
        }
        pasangListenerIsi();
        // Listener ulang saat jenis berubah (mutu muncul/hilang)
        document.getElementById('jenisPengujian').addEventListener('change', function() {
            pasangListenerIsi();
            updateIsiInfo();
        });
        updateIsiInfo();

        // Submit: validasi visual dulu, jangan sampai browser reset form
        document.getElementById('formBooking').addEventListener('submit', function(ev) {
            const fields = getRequiredVisible();
            let kosong = [];
            fields.forEach(function(el) {
                const v = (el.value || '').trim();
                if (v === '' || v === '0') {
                    el.classList.add('is-invalid-custom');
                    kosong.push(el);
                } else {
                    el.classList.remove('is-invalid-custom');
                }
            });
            if (kosong.length > 0) {
                ev.preventDefault();
                kosong[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                kosong[0].classList.add('shake-err');
                setTimeout(function() { kosong[0].classList.remove('shake-err'); }, 400);
                kosong[0].focus({ preventScroll: true });
                updateIsiInfo();
                return false;
            }
        });
    </script>
</body>
</html>
