<?php
require '../config/database.php';
require '../config/auth.php';
require '../config/labels.php';
require_once __DIR__ . '/../config/field_kubus.php';
requireRole(['admin']);

$user = currentUser($pdo);
$error = '';
$success = '';
$daftarMutu = ['K-175','K-200','K-225','K-250','K-275','K-300','K-350','K-400','K-450','K-500'];

$stmtJenis = $pdo->query("SELECT * FROM jenis_pengujian WHERE status_aktif = 1 ORDER BY kategori, nama_pengujian");
$daftarJenis = $stmtJenis->fetchAll();

$stmtPelanggan = $pdo->query("SELECT id, nama_lengkap, nama_perusahaan, email, no_telepon FROM users WHERE role = 'pelanggan' ORDER BY nama_lengkap");
$daftarPelanggan = $stmtPelanggan->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pilihPelanggan = trim($_POST['pilih_pelanggan'] ?? '');
    $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
    $namaPerusahaan = trim($_POST['nama_perusahaan'] ?? '');
    $noTelepon = trim($_POST['no_telepon'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $passwordBaru = trim($_POST['password_baru'] ?? '');

    $jenisPengujianId = $_POST['jenis_pengujian_id'] ?? '';
    $penanggungJawab = trim($_POST['penanggung_jawab'] ?? '');
    $jumlahSampel = (int) ($_POST['jumlah_sampel'] ?? 0);
    $tanggalPengujian = $_POST['tanggal_pengujian'] ?? '';
    $catatan = trim($_POST['catatan_pelanggan'] ?? '');
    $objekPengujian = trim($_POST['objek_pengujian'] ?? '');
    $lokasi = trim($_POST['lokasi'] ?? '');
    $supplier = trim($_POST['supplier'] ?? '');
    $mutuBeton = trim($_POST['mutu_beton'] ?? '');
    $tipeBooking = (($_POST['tipe_booking'] ?? '') === 'online') ? 'online' : 'offline';
    $tambahanCetak = !empty($_POST['tambahan_cetak']) ? 1 : 0;
    $tambahanSetting = !empty($_POST['tambahan_setting']) ? 1 : 0;
    $sampleBerat    = ($_POST['sample_berat'] ?? '') !== '' ? (float) $_POST['sample_berat'] : null;
    $sampleDiameter = ($_POST['sample_diameter'] ?? '') !== '' ? (float) $_POST['sample_diameter'] : null;
    $sampleTinggi   = ($_POST['sample_tinggi'] ?? '') !== '' ? (float) $_POST['sample_tinggi'] : null;
    $sampleBentangL = ($_POST['sample_bentang_l'] ?? '') !== '' ? (float) $_POST['sample_bentang_l'] : null;
    $sampleDimB     = ($_POST['sample_dimensi_b'] ?? '') !== '' ? (float) $_POST['sample_dimensi_b'] : null;
    $sampleDimH     = ($_POST['sample_dimensi_h'] ?? '') !== '' ? (float) $_POST['sample_dimensi_h'] : null;
    $sampleJenisBaja = trim($_POST['sample_jenis_baja'] ?? '') ?: null;
    $samplePanjang  = ($_POST['sample_panjang'] ?? '') !== '' ? (float) $_POST['sample_panjang'] : null;

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    $passwordSementara = '';
    $pelangganId = 0;

    if ($pilihPelanggan === '') {
        $error = 'Pilih pelanggan terlebih dahulu.';
    } elseif ($pilihPelanggan === 'baru') {
        if ($namaLengkap === '' || $noTelepon === '' || $email === '') {
            $error = 'Nama lengkap, no. telepon, dan email pelanggan baru wajib diisi.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Format email pelanggan baru tidak valid.';
        } elseif ($passwordBaru === '' || strlen($passwordBaru) < 6) {
            $error = 'Password pelanggan baru wajib diisi, minimal 6 karakter.';
        } else {
            $cekEmail = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $cekEmail->execute([$email]);

            if ($cekEmail->fetch()) {
                $error = 'Email tersebut sudah terdaftar. Pilih pelanggannya dari daftar.';
            } else {
                $passwordSementara = $passwordBaru;
                $hash = password_hash($passwordSementara, PASSWORD_BCRYPT);

                $stmtBuatUser = $pdo->prepare(
                    "INSERT INTO users (role, nama_lengkap, nama_perusahaan, email, no_telepon, password, status_aktif)
                     VALUES ('pelanggan', ?, ?, ?, ?, ?, 1)"
                );
                $stmtBuatUser->execute([$namaLengkap, $namaPerusahaan, $email, $noTelepon, $hash]);
                $pelangganId = (int) $pdo->lastInsertId();
            }
        }
    } else {
        $stmtCekPelanggan = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'pelanggan'");
        $stmtCekPelanggan->execute([(int) $pilihPelanggan]);
        $rowPelanggan = $stmtCekPelanggan->fetch();
        if ($rowPelanggan) {
            $pelangganId = (int) $rowPelanggan['id'];
        } else {
            $error = 'Pelanggan tidak ditemukan. Muat ulang halaman dan pilih kembali.';
        }
    }

    if (!$error) {
    $stmtHarga = $pdo->prepare("SELECT harga, kategori, nama_pengujian FROM jenis_pengujian WHERE id = ?");
    $stmtHarga->execute([$jenisPengujianId]);
    $jenis = $stmtHarga->fetch();
    $minSampel = $jenis ? minSampelPengujian($jenis['nama_pengujian'], $jenis['kategori']) : 1;

    if (!$jenis) {
        $error = 'Jenis pengujian tidak valid.';
    } elseif ($penanggungJawab === '') {
        $error = 'Nama penanggung jawab wajib diisi.';
    } elseif (!tanggalValid($tanggalPengujian)) {
        $error = 'Tanggal pengujian tidak valid.';
    } elseif ($jenis['kategori'] === 'beton' && !in_array($mutuBeton, $daftarMutu, true)) {
        $error = 'Mutu beton wajib dipilih.';
    } elseif ($jumlahSampel < 1) {
        $error = 'Jumlah sampel minimal 1.';
    } elseif ($jumlahSampel < $minSampel) {
        $error = 'Untuk pengujian "' . $jenis['nama_pengujian'] . '", jumlah sampel minimal ' . $minSampel . ' pcs.';
    }
    }

    if (!$error) {
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

        $kodeBooking = generateKodeBooking($pdo, $jenisPengujianId, $jenis['nama_pengujian'], $jenis['kategori']);

        $statusAwal = 'menunggu_verifikasi';

        $stmtBooking = $pdo->prepare(
            "INSERT INTO bookings
             (kode_booking, user_id, jenis_pengujian_id, penanggung_jawab, jumlah_sampel,
              tanggal_pengujian_diminta, estimasi_biaya, catatan_pelanggan, objek_pengujian, lokasi, supplier, mutu_beton, tambahan_cetak, tambahan_setting, tipe_booking, status_booking,
              sample_berat, sample_diameter, sample_tinggi, sample_bentang_l, sample_dimensi_b, sample_dimensi_h, sample_jenis_baja, sample_panjang,
              diverifikasi_oleh, diverifikasi_pada)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmtBooking->execute([
            $kodeBooking, $pelangganId, $jenisPengujianId, $penanggungJawab,
            $jumlahSampel, $tanggalPengujian, $estimasiBiaya, $catatan, $objekPengujian, $lokasi, $supplier,
            ($jenis['kategori'] === 'beton' ? $mutuBeton : null),
            $pakaiCetak, $pakaiSetting, $tipeBooking, $statusAwal,
            $sampleBerat, $sampleDiameter, $sampleTinggi, $sampleBentangL, $sampleDimB, $sampleDimH, $sampleJenisBaja, $samplePanjang,
            $user['id']
        ]);
        $bookingId = $pdo->lastInsertId();

        $keteranganRiwayat = ($tipeBooking === 'online')
            ? 'Booking online dibuat oleh Admin. Menunggu verifikasi.'
            : 'Booking offline dibuat oleh Admin. Menunggu verifikasi.';

        $stmtRiwayat = $pdo->prepare(
            "INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan) VALUES (?, ?, ?, ?)"
        );
        $stmtRiwayat->execute([$bookingId, $statusAwal, $user['id'], $keteranganRiwayat]);

        $success = "Booking berhasil dibuat dengan kode: $kodeBooking.";
        if ($passwordSementara) {
            $success .= " Akun pelanggan baru dibuat untuk $email. Password sudah Anda tentukan saat input.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Booking Manual (Walk-in) - Admin</title>
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

    <div class="page-container" style="max-width: 720px;">
        <a href="kelola_booking.php" class="text-decoration-none text-muted">&larr; Kembali ke Kelola Booking</a>

        <div class="page-header mt-2">
            <h4>Booking Manual (Walk-in)</h4>
            <p>Buat booking untuk pelanggan yang datang langsung ke lokasi</p>
            <span class="badge bg-info mt-1" style="font-size: 0.75rem;"><i class="bi bi-building"></i> Offline â€” Tatap Muka</span>
        </div>

        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <form method="POST" id="formWalkin">
            <?= csrf_field() ?>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong><i class="bi bi-person"></i> Data Pelanggan</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Tipe Booking</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="tipe_booking" id="tipeOnline" value="online">
                                <label class="form-check-label" for="tipeOnline">
                                    <i class="bi bi-globe text-success"></i> <strong>Online</strong>
                                    <br><small class="text-muted">Perlu verifikasi admin</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="tipe_booking" id="tipeOffline" value="offline" checked>
                                <label class="form-check-label" for="tipeOffline">
                                    <i class="bi bi-building text-info"></i> <strong>Offline (Walk-in)</strong>
                                    <br><small class="text-muted">Bayar di loket</small>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Pilih Pelanggan <span class="text-danger">*</span></label>
                        <select name="pilih_pelanggan" id="pilihPelanggan" class="form-select" required>
                            <option value="">-- Pilih pelanggan --</option>
                            <?php foreach ($daftarPelanggan as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"
                                data-nama="<?= htmlspecialchars($p['nama_lengkap']) ?>"
                                data-perusahaan="<?= htmlspecialchars($p['nama_perusahaan'] ?? '') ?>"
                                data-telepon="<?= htmlspecialchars($p['no_telepon'] ?? '') ?>"
                                data-email="<?= htmlspecialchars($p['email']) ?>">
                                <?= htmlspecialchars($p['nama_lengkap']) ?><?= !empty($p['nama_perusahaan']) ? ' &mdash; ' . htmlspecialchars($p['nama_perusahaan']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                            <option value="baru">+ Buat pelanggan baru (diisi admin)</option>
                        </select>
                        <small class="text-muted">Kalau pelanggan belum terdaftar, pilih &ldquo;Buat pelanggan baru&rdquo;.</small>
                    </div>

                    <div class="mb-3 d-none" id="infoPelanggan">
                        <div class="border rounded p-2 bg-light small">
                            <div class="fw-bold" id="infoNama"></div>
                            <div class="text-muted" id="infoPerusahaan"></div>
                            <div class="text-muted" id="infoTelepon"></div>
                            <div class="text-muted" id="infoEmail"></div>
                        </div>
                    </div>

                    <div class="d-none" id="rowPelangganBaru">
                        <div class="mb-3">
                            <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                            <input type="text" name="nama_lengkap" class="form-control wajib" placeholder="Masukkan nama lengkap">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Perusahaan</label>
                            <input type="text" name="nama_perusahaan" class="form-control" placeholder="Masukkan nama perusahaan (opsional)">
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">No. Telepon <span class="text-danger">*</span></label>
                                <input type="text" name="no_telepon" class="form-control wajib" placeholder="08xxxxxxxxxx">
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control wajib"
                                       placeholder="Masukkan email pelanggan" autocomplete="off">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="text" name="password_baru" class="form-control wajib"
                                   placeholder="Password yang dipakai pelanggan login" minlength="6" autocomplete="off">
                            <small class="text-muted">Min. 6 karakter</small>
                        </div>
                        <small class="text-muted">Data pelanggan baru akan disimpan ke daftar pelanggan.</small>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong><i class="bi bi-flask"></i> Data Pengujian</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Jenis Pengujian</label>
                        <select name="jenis_pengujian_id" id="jenisPengujian" class="form-select" required>
                            <option value="">-- Pilih Jenis Pengujian --</option>
                            <?php $kat = ''; foreach ($daftarJenis as $j):
                                if ($kat !== $j['kategori']) { if ($kat !== '') echo '</optgroup>'; echo '<optgroup label="' . strtoupper($j['kategori']) . '">'; $kat = $j['kategori']; }
                            ?>
                                <option value="<?= $j['id'] ?>" data-harga="<?= $j['harga'] ?>" data-kategori="<?= $j['kategori'] ?>" data-min="<?= minSampelPengujian($j['nama_pengujian'], $j['kategori']) ?>"<?= isCoreDrillUji($j['nama_pengujian']) ? ' data-tambahan="cetak"' : '' ?><?= isUjiSilinder($j['nama_pengujian']) ? ' data-tipe="silinder"' : '' ?><?= isUjiKubus($j['nama_pengujian']) ? ' data-tipe="kubus"' : '' ?><?= isUjiPaving($j['nama_pengujian']) ? ' data-tipe="paving"' : '' ?><?= isUjiLentur($j['nama_pengujian']) ? ' data-tipe="lentur"' : '' ?><?= isUjiCoreDrill($j['nama_pengujian']) ? ' data-tipe="core"' : '' ?>>
                                    <?= htmlspecialchars($j['nama_pengujian']) ?> (Rp <?= number_format($j['harga'],0,',','.') ?>/<?= $j['satuan_sampel'] ?>)
                                </option>
                            <?php endforeach; ?>
                            <?php if ($kat !== '') echo '</optgroup>'; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Penanggung Jawab</label>
                            <input type="text" name="penanggung_jawab" class="form-control" required placeholder="Nama penanggung jawab">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Jumlah Sampel</label>
                            <input type="number" name="jumlah_sampel" id="jumlahSampel" class="form-control" min="1" value="1" required>
                            <small class="text-muted" id="hintMinSampel"></small>
                        </div>
                    </div>
                    <div class="mb-3 d-none" id="mutuBetonWrap">
                        <label class="form-label">Mutu Beton <span class="text-danger">*</span></label>
                        <select name="mutu_beton" id="mutuBeton" class="form-select">
                            <option value="">-- Pilih Mutu --</option>
                            <?php foreach ($daftarMutu as $m): ?>
                            <option value="<?= $m ?>"><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Wajib dipilih untuk pengujian beton.</small>
                    </div>

                    <!-- Spesifikasi Sampel: menyesuaikan jenis pengujian -->
                    <div id="spesifikasiSampel" class="d-none">
                        <hr class="my-2">
                        <div class="fw-semibold mb-2" style="font-size:0.85rem;"><i class="bi bi-rulers"></i> Spesifikasi Sampel</div>
                        <div class="row">
                            <div class="col-sm-4 mb-3 d-none" id="spBerat">
                                <label class="form-label" style="font-size:0.8rem;" id="spBeratLabel">Berat (Kg)</label>
                                <input type="number" step="0.01" name="sample_berat" class="form-control form-control-sm" placeholder="Berat sampel">
                            </div>
                            <div class="col-sm-4 mb-3 d-none" id="spDiameter">
                                <label class="form-label" style="font-size:0.8rem;" id="spDiameterLabel">Diameter (mm)</label>
                                <input type="number" step="0.01" name="sample_diameter" class="form-control form-control-sm" placeholder="Diameter">
                            </div>
                            <div class="col-sm-4 mb-3 d-none" id="spTinggi">
                                <label class="form-label" style="font-size:0.8rem;" id="spTinggiLabel">Tinggi (mm)</label>
                                <input type="number" step="0.01" name="sample_tinggi" class="form-control form-control-sm" placeholder="Tinggi">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 mb-3 d-none" id="spBentang">
                                <label class="form-label" style="font-size:0.8rem;">Bentang L (Cm)</label>
                                <input type="number" step="0.01" name="sample_bentang_l" class="form-control form-control-sm" placeholder="Bentang L">
                            </div>
                            <div class="col-sm-4 mb-3 d-none" id="spDimB">
                                <label class="form-label" style="font-size:0.8rem;">Dimensi b (Cm)</label>
                                <input type="number" step="0.01" name="sample_dimensi_b" class="form-control form-control-sm" placeholder="Dimensi b">
                            </div>
                            <div class="col-sm-4 mb-3 d-none" id="spDimH">
                                <label class="form-label" style="font-size:0.8rem;">Dimensi h (Cm)</label>
                                <input type="number" step="0.01" name="sample_dimensi_h" class="form-control form-control-sm" placeholder="Dimensi h">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 mb-3 d-none" id="spJenisBaja">
                                <label class="form-label" style="font-size:0.8rem;">Jenis Baja</label>
                                <select name="sample_jenis_baja" class="form-select form-select-sm">
                                    <option value="">-- Pilih --</option>
                                    <option value="Polos">Polos</option>
                                    <option value="Ulir">Ulir</option>
                                </select>
                            </div>
                            <div class="col-sm-4 mb-3 d-none" id="spPanjang">
                                <label class="form-label" style="font-size:0.8rem;">Panjang (cm)</label>
                                <input type="number" step="0.01" name="sample_panjang" class="form-control form-control-sm" placeholder="Panjang">
                            </div>
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
                    <div class="mb-3">
                        <label class="form-label">Tanggal Pengujian</label>
                        <input type="date" name="tanggal_pengujian" id="tanggalPengujian" class="form-control bg-light" value="<?= date('Y-m-d') ?>" readonly required>
                        <small class="text-muted">Tanggal otomatis = hari ini (walk-in)</small>
                        <small id="infoBookingTanggal" class="d-block mt-1"></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Proyek <span class="text-danger">*</span></label>
                        <input type="text" name="objek_pengujian" class="form-control" required
                               placeholder="Contoh: Gedung Serba Guna, Jembatan Tol">
                        <small class="text-muted">Masukkan nama proyek.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Lokasi</label>
                        <input type="text" name="lokasi" class="form-control"
                               placeholder="Lokasi proyek (opsional)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Supplier</label>
                        <input type="text" name="supplier" class="form-control"
                               placeholder="Nama supplier material (opsional)">
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
                <i class="bi bi-check-circle"></i> Buat Booking
            </button>
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
            const opsi = select.options[select.selectedIndex];
            const harga = opsi ? (opsi.dataset.harga || 0) : 0;
            const kategori = opsi ? (opsi.dataset.kategori || '') : '';

            let total = harga * jumlah;
            let casW = 0;
            let casJ = 0;
            const limbah = kategori === 'beton' ? biayaLimbahBeton : 0;
            const cbCetak = document.getElementById('tambahanCetak');
            const bisaCetak = !!(opsi && opsi.dataset.tambahan === 'cetak');
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

            detail.style.display = 'block';
            rowW.style.display = casW > 0 ? 'flex' : 'none';
            rowJ.style.display = casJ > 0 ? 'flex' : 'none';
            rowL.style.display = limbah > 0 ? 'flex' : 'none';
            rowC.style.display = cetak > 0 ? 'flex' : 'none';
            rowS.style.display = setting > 0 ? 'flex' : 'none';
        }

        function cekMinSampel() {
            const select = document.getElementById('jenisPengujian');
            const jumlahInput = document.getElementById('jumlahSampel');
            const hint = document.getElementById('hintMinSampel');
            const opsi = select.options[select.selectedIndex];
            const kategori = opsi ? (opsi.dataset.kategori || '') : '';
            const tipe = opsi ? (opsi.dataset.tipe || '') : '';

            const min = parseInt((opsi && opsi.dataset.min) || '1', 10) || 1;
            jumlahInput.min = min;

            const mutuWrap = document.getElementById('mutuBetonWrap');
            const mutuSelect = document.getElementById('mutuBeton');
            if (mutuWrap && mutuSelect) {
                const butuhMutu = kategori === 'beton';
                mutuWrap.classList.toggle('d-none', !butuhMutu);
                mutuSelect.required = butuhMutu;
                if (!butuhMutu) mutuSelect.value = '';
            }
            if (min > 1) {
                hint.textContent = '* Minimal ' + min + ' pcs untuk pengujian ini';
                hint.className = 'text-danger';
                if (parseInt(jumlahInput.value) < min) jumlahInput.value = min;
            } else {
                hint.textContent = '';
                hint.className = 'text-muted';
            }

            // Spesifikasi Sampel — tampilkan field sesuai tipe
            const spWrap = document.getElementById('spesifikasiSampel');
            if (spWrap) {
                const adaSpesifikasi = (tipe !== '');
                spWrap.classList.toggle('d-none', !adaSpesifikasi);
            }
            // Reset semua kolom dulu
            ['spBerat','spDiameter','spTinggi','spBentang','spDimB','spDimH','spJenisBaja','spPanjang'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.classList.add('d-none');
            });
            // Aktifkan kolom sesuai tipe
            var lblBerat = document.getElementById('spBeratLabel');
            var lblDiameter = document.getElementById('spDiameterLabel');
            var lblTinggi = document.getElementById('spTinggiLabel');
            if (tipe === 'silinder') {
                document.getElementById('spBerat').classList.remove('d-none');
                document.getElementById('spDiameter').classList.remove('d-none');
                document.getElementById('spTinggi').classList.remove('d-none');
                if (lblBerat) lblBerat.textContent = 'Berat (Kg)';
                if (lblDiameter) lblDiameter.textContent = 'Diameter (mm)';
                if (lblTinggi) lblTinggi.textContent = 'Tinggi (mm)';
            } else if (tipe === 'kubus') {
                document.getElementById('spBerat').classList.remove('d-none');
                if (lblBerat) lblBerat.textContent = 'Berat (Kg)';
            } else if (tipe === 'paving') {
                document.getElementById('spBerat').classList.remove('d-none');
                if (lblBerat) lblBerat.textContent = 'Berat (Kg)';
            } else if (tipe === 'lentur') {
                document.getElementById('spBentang').classList.remove('d-none');
                document.getElementById('spDimB').classList.remove('d-none');
                document.getElementById('spDimH').classList.remove('d-none');
            } else if (tipe === 'core') {
                document.getElementById('spDiameter').classList.remove('d-none');
                document.getElementById('spTinggi').classList.remove('d-none');
                if (lblDiameter) lblDiameter.textContent = 'Diameter (Cm)';
                if (lblTinggi) lblTinggi.textContent = 'Tinggi (Cm)';
            } else if (kategori === 'besi') {
                document.getElementById('spDiameter').classList.remove('d-none');
                document.getElementById('spJenisBaja').classList.remove('d-none');
                document.getElementById('spPanjang').classList.remove('d-none');
                if (lblDiameter) lblDiameter.textContent = 'Diameter (mm)';
            }

            const rowCetak = document.getElementById('rowTambahanCetak');
            const bisaCetak = !!(opsi && opsi.dataset.tambahan === 'cetak');
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
                });
        }

        document.getElementById('jenisPengujian').addEventListener('change', function() {
            cekMinSampel();
            hitungEstimasi();
        });
        document.getElementById('jumlahSampel').addEventListener('input', hitungEstimasi);
        document.getElementById('tambahanCetak').addEventListener('change', hitungEstimasi);
        document.getElementById('tambahanSetting').addEventListener('change', hitungEstimasi);

        const selectPelanggan = document.getElementById('pilihPelanggan');
        function togglePelanggan() {
            const nilai = selectPelanggan.value;
            const baru = nilai === 'baru';
            const rowBaru = document.getElementById('rowPelangganBaru');
            const info = document.getElementById('infoPelanggan');
            const opsi = selectPelanggan.options[selectPelanggan.selectedIndex];

            rowBaru.classList.toggle('d-none', !baru);
            rowBaru.querySelectorAll('input.wajib').forEach(function(input) {
                if (baru) { input.setAttribute('required', 'required'); } else { input.removeAttribute('required'); }
            });

            if (nilai && !baru) {
                info.classList.remove('d-none');
                document.getElementById('infoNama').textContent = opsi.dataset.nama || '';
                document.getElementById('infoPerusahaan').textContent = opsi.dataset.perusahaan || '';
                document.getElementById('infoTelepon').textContent = opsi.dataset.telepon ? 'Telepon: ' + opsi.dataset.telepon : '';
                document.getElementById('infoEmail').textContent = opsi.dataset.email ? 'Email: ' + opsi.dataset.email : '';
            } else {
                info.classList.add('d-none');
            }
        }
        selectPelanggan.addEventListener('change', togglePelanggan);
        togglePelanggan();

        hitungEstimasi();
        cekJumlahBooking(document.getElementById('tanggalPengujian').value);
    </script>
</body>
</html>
