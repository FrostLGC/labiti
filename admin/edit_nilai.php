<?php
require '../config/database.php';
require '../config/auth.php';
require '../config/labels.php';
require '../config/field_besi.php';
require_once '../config/field_silinder.php';
require_once '../config/field_kubus.php';
requireRole(['admin']);

$user = currentUser($pdo);
$success = '';
$error = '';
$bookingId = (int) ($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT b.*, j.nama_pengujian, j.satuan_sampel, j.kategori, u.nama_lengkap as nama_pelanggan
     FROM bookings b
     JOIN jenis_pengujian j ON b.jenis_pengujian_id = j.id
     JOIN users u ON b.user_id = u.id
     WHERE b.id = ? AND b.status_booking = 'validasi_hasil'"
);
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    die('Booking tidak ditemukan atau statusnya bukan "Validasi Hasil".');
}

$isSilinder = isUjiSilinder($booking['nama_pengujian']);
$isKubus = isUjiKubus($booking['nama_pengujian']);
$isPaving = isUjiPaving($booking['nama_pengujian']);
$isLentur = isUjiLentur($booking['nama_pengujian']);
$isCore = isUjiCoreDrill($booking['nama_pengujian']);
$isTabelBeton = $isSilinder || $isKubus || $isPaving || $isLentur || $isCore;

$stmtSampel = $pdo->prepare("SELECT * FROM sampel WHERE booking_id = ? ORDER BY kode_sampel ASC");
$stmtSampel->execute([$bookingId]);
$sampelList = $stmtSampel->fetchAll();

$hasilPerSampel = [];
$stmtAllHasil = $pdo->prepare(
    "SELECT hp.*, s.kode_sampel FROM hasil_pengujian hp
     JOIN sampel s ON hp.sampel_id = s.id WHERE s.booking_id = ? ORDER BY s.kode_sampel, hp.id"
);
$stmtAllHasil->execute([$bookingId]);
$semuaHasil = $stmtAllHasil->fetchAll();
foreach ($semuaHasil as $h) {
    $hasilPerSampel[$h['sampel_id']][] = $h;
}

$nomorLaporan = '';
$stmtLaporan = $pdo->prepare("SELECT id, nomor_laporan FROM laporan WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
$stmtLaporan->execute([$bookingId]);
$lap = $stmtLaporan->fetch();
if ($lap) $nomorLaporan = $lap['nomor_laporan'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_nomor_laporan'])) {
    $nomorBaru = trim($_POST['nomor_laporan_baru'] ?? '');

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    if (empty($nomorBaru)) {
        $error = 'Nomor laporan wajib diisi.';
    } elseif (!$lap) {
        $error = 'Data laporan belum tersedia.';
    } else {
        $cekDuplikat = $pdo->prepare("SELECT id FROM laporan WHERE nomor_laporan = ? AND id != ?");
        $cekDuplikat->execute([$nomorBaru, $lap['id']]);
        if ($cekDuplikat->fetch()) {
            $error = 'Nomor laporan "' . htmlspecialchars($nomorBaru) . '" sudah digunakan.';
        } else {
            $pdo->prepare("UPDATE laporan SET nomor_laporan = ? WHERE id = ?")->execute([$nomorBaru, $lap['id']]);
            $nomorLaporan = $nomorBaru;
            $success = 'Nomor laporan berhasil diupdate.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_hasil'])) {
    $hasilId = $_POST['hasil_id'] ?? [];
    $sampelId = $_POST['sampel_id'] ?? null;
    if (is_array($sampelId)) { $sampelId = reset($sampelId) ?: null; }

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    if ($sampelId !== null && $sampelId !== '') {
        $cekOwn = $pdo->prepare("SELECT id FROM sampel WHERE id = ? AND booking_id = ?");
        $cekOwn->execute([$sampelId, $bookingId]);
        if (!$cekOwn->fetch()) {
            die('Sampel tidak ditemukan pada booking ini.');
        }
    }
    $kategori = $booking['kategori'] ?? 'beton';
    $isBesi = ($kategori === 'besi');
    $catatanAdmin = trim($_POST['catatan_admin'] ?? '');

    $dokumentasiPath = null;
    if (isset($_FILES['dokumentasi']) && ($_FILES['dokumentasi']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['dokumentasi']['error'] !== 0) {
            $error = 'Upload dokumentasi gagal (kemungkinan file terlalu besar). Maksimal 5MB.';
        } else {
            $ext = strtolower(pathinfo($_FILES['dokumentasi']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $error = 'Format file dokumentasi harus JPG, JPEG, atau PNG.';
            } elseif ($_FILES['dokumentasi']['size'] > 5 * 1024 * 1024) {
                $error = 'Ukuran file dokumentasi maksimal 5MB.';
            } elseif (@getimagesize($_FILES['dokumentasi']['tmp_name']) === false) {
                $error = 'Isi file dokumentasi bukan gambar yang valid.';
            } else {
                $namaBaru = 'dokumentasi_' . time() . '_' . uniqid() . '.' . $ext;
                $folderTujuan = '../uploads/dokumentasi';
                if (!is_dir($folderTujuan)) mkdir($folderTujuan, 0777, true);
                if (move_uploaded_file($_FILES['dokumentasi']['tmp_name'], $folderTujuan . '/' . $namaBaru)) {
                    $dokumentasiPath = 'uploads/dokumentasi/' . $namaBaru;
                } else {
                    $error = 'Upload dokumentasi gagal. Coba lagi.';
                }
            }
        }
    }

    if ($error === '' && $isTabelBeton) {
        $fieldTabel = ['tgl_cor','tgl_ditest','kode_beton','berat_kg','beban_maksimum','tegangan_tekan',
            'tegangan_tekan_28_hari','mpa','rata_rata','keterangan','catatan_teknisi','mutu_beton',
            'bentang_l','dimensi_b','dimensi_h','modulus_seksi','momen_lentur','tegangan_lentur',
            'diameter','luas_penampang','tinggi_l','rasio_ld','faktor_koreksi','tegangan_benda_uji',
            'konversi_silider6','konversi_kubus15','konversi_umur28'];
        foreach ($fieldTabel as $kPost) {
            if (isset($_POST[$kPost]) && !is_array($_POST[$kPost])) { $_POST[$kPost] = [$_POST[$kPost]]; }
        }
        $hasilIdList = (array) ($_POST['hasil_id'] ?? []);
        $jmlUpdate = 0;
        $fileLamaDiganti = [];
        foreach ($hasilIdList as $i => $hid) {
            $cekHid = $pdo->prepare(
                "SELECT hp.dokumentasi_file FROM hasil_pengujian hp
                 JOIN sampel s ON hp.sampel_id = s.id
                 WHERE hp.id = ? AND s.booking_id = ?"
            );
            $cekHid->execute([(int) $hid, $bookingId]);
            $rowHid = $cekHid->fetch();
            if (!$rowHid) { continue; }
            if ($dokumentasiPath && $rowHid['dokumentasi_file'] && $rowHid['dokumentasi_file'] !== $dokumentasiPath) {
                $fileLamaDiganti[] = $rowHid['dokumentasi_file'];
            }
            $tglCor    = tanggalManualKeYmd($_POST['tgl_cor'][$i] ?? '');
            $tglDites  = tanggalManualKeYmd($_POST['tgl_ditest'][$i] ?? '');
            $kodeBeton = trim($_POST['kode_beton'][$i] ?? '');
            $bebanMaks = ($_POST['beban_maksimum'][$i] ?? '') ?: null;

            if ($isCore) {
                // Uji Tekan Core Drill: seluruh kolom diisi manual admin sesuai format laporan
                $diameterCore  = ($_POST['diameter'][$i] ?? '') ?: null;
                $luasPenampangCore = ($_POST['luas_penampang'][$i] ?? '') ?: null;
                $beratKg       = ($_POST['berat_kg'][$i] ?? '') ?: null;
                $rasioLd       = ($_POST['rasio_ld'][$i] ?? '') ?: null;
                $faktorKoreksi = ($_POST['faktor_koreksi'][$i] ?? '') ?: null;
                $tegBendaUji   = ($_POST['tegangan_benda_uji'][$i] ?? '') ?: null;
                $konvSilider6  = ($_POST['konversi_silider6'][$i] ?? '') ?: null;
                $konvKubus15   = ($_POST['konversi_kubus15'][$i] ?? '') ?: null;
                $konvUmur28    = ($_POST['konversi_umur28'][$i] ?? '') ?: null;
                $mpaNilai      = (($_POST['mpa'][$i] ?? '') ?: null);
                $konvUmur28Mpa = (($_POST['konversi_umur28_mpa'][$i] ?? '') ?: null);
                $tinggiL       = ($_POST['tinggi_l'][$i] ?? '') ?: null;
                $keteranganBaris = trim($_POST['catatan_teknisi'][$i] ?? '') ?: ($catatanAdmin ?: null);
                $nilaiS = ($tegBendaUji ?: null);
                $tegTekan = null; $teg28 = null; $rataRata = null;
                $bentangL = $dimensiB = $dimensiH = $modulusSeksi = $momenLentur = $teganganLentur = null;
                $mutuManual = $booking['mutu_beton'] ?? null;
            } elseif ($isLentur) {
                // Uji Lentur / Flexural Balok Beton: semua nilai diisi manual admin
                $beratKg = null;
                $tegTekan = null;
                $teg28 = null;
                $mpaNilai = null;
                $rataRata = null;
                $bentangL     = ($_POST['bentang_l'][$i]     ?? '') ?: null;
                $dimensiB     = ($_POST['dimensi_b'][$i]     ?? '') ?: null;
                $dimensiH     = ($_POST['dimensi_h'][$i]     ?? '') ?: null;
                $modulusSeksi = ($_POST['modulus_seksi'][$i] ?? '') ?: null;
                $momenLentur  = ($_POST['momen_lentur'][$i]  ?? '') ?: null;
                $teganganLentur = ($_POST['tegangan_lentur'][$i] ?? '') ?: null;
                $mutuManual   = trim($_POST['mutu_beton'][$i] ?? '');
                $keteranganBaris = trim($_POST['catatan_teknisi'][$i] ?? '') ?: ($catatanAdmin ?: null);
                $nilaiS = ($teganganLentur ?: null);
            } else {
                $beratKg   = ($_POST['berat_kg'][$i] ?? '') ?: null;
                $tegTekan  = ($_POST['tegangan_tekan'][$i] ?? '') ?: null;
                $teg28     = ($_POST['tegangan_tekan_28_hari'][$i] ?? '') ?: null;
                $mpaNilai  = $isKubus ? null : (($_POST['mpa'][$i] ?? '') ?: null);
                $rataRata  = $isPaving ? (($_POST['rata_rata'][$i] ?? '') ?: null) : null;
                // Kubus: kolom terakhir tabel adalah Keterangan (catatan_teknisi)
                $keteranganBaris = $isKubus
                    ? (trim($_POST['keterangan'][$i] ?? '') ?: $catatanAdmin)
                    : $catatanAdmin;
                $nilaiS = $isKubus
                    ? (($teg28 ?: $tegTekan) ?: null)
                    : ($isPaving ? (($rataRata ?: ($teg28 ?: $tegTekan)) ?: null) : $mpaNilai);
                $bentangL = $dimensiB = $dimensiH = $modulusSeksi = $momenLentur = $teganganLentur = null;
                $mutuManual = null;
            }

            $umurHari = null;
            if ($tglCor && $tglDites) {
                $umurHari = (new DateTime($tglCor))->diff(new DateTime($tglDites))->days;
            }

            $sqlS = "UPDATE hasil_pengujian SET tgl_cor=?, tgl_ditest=?, umur_hari=?, berat_kg=?, kode_beton=?, beban_maksimum=?, tegangan_tekan=?, tegangan_tekan_28_hari=?, rata_rata=?, mpa=?, nilai_hasil=?, catatan_teknisi=?";
            $paramsS = [$tglCor, $tglDites, $umurHari, $beratKg, $kodeBeton ?: null, $bebanMaks, $tegTekan, $teg28, $rataRata, $mpaNilai, $nilaiS, $keteranganBaris ?: null];
            if ($isKubus || $isPaving || $isLentur || $isCore) {
                $sqlS .= ", satuan=?";
                $paramsS[] = $nilaiS !== null ? 'Kg/cm²' : null;
            }
            if ($isCore) {
                $sqlS .= ", parameter_uji=?, mutu_beton=?, diameter=?, luas_penampang=?, tinggi_l=?, rasio_ld=?, faktor_koreksi=?, tegangan_benda_uji=?, konversi_silider6=?, konversi_kubus15=?, konversi_umur28=?, konversi_umur28_mpa=?";
                $paramsS[] = 'Uji Tekan Core Drill';
                $paramsS[] = $mutuManual ?: null;
                $paramsS[] = $diameterCore; $paramsS[] = $luasPenampangCore; $paramsS[] = $tinggiL;
                $paramsS[] = $rasioLd; $paramsS[] = $faktorKoreksi; $paramsS[] = $tegBendaUji;
                $paramsS[] = $konvSilider6; $paramsS[] = $konvKubus15; $paramsS[] = $konvUmur28;
                $paramsS[] = $konvUmur28Mpa;
            } elseif ($isLentur) {
                $sqlS .= ", parameter_uji=?, mutu_beton=?, bentang_l=?, dimensi_b=?, dimensi_h=?, modulus_seksi=?, momen_lentur=?, tegangan_lentur=?";
                $paramsS[] = 'Uji Lentur';
                $paramsS[] = $mutuManual ?: null;
                $paramsS[] = $bentangL; $paramsS[] = $dimensiB; $paramsS[] = $dimensiH;
                $paramsS[] = $modulusSeksi; $paramsS[] = $momenLentur; $paramsS[] = $teganganLentur;
            }
            if ($dokumentasiPath) {
                $sqlS .= ", dokumentasi_file=?";
                $paramsS[] = $dokumentasiPath;
            }
            $sqlS .= " WHERE id=?";
            $paramsS[] = $hid;
            $pdo->prepare($sqlS)->execute($paramsS);
            $jmlUpdate++;
        }

        foreach (array_unique($fileLamaDiganti) as $fl) {
            if ($fl !== $dokumentasiPath && is_file('../' . $fl)) { @unlink('../' . $fl); }
        }

        if ($jmlUpdate === 0) {
            $error = 'Tidak ada data hasil yang dapat diupdate pada booking ini.';
        } else {
            $success = "$jmlUpdate data berhasil diupdate.";
            header("Location: edit_nilai.php?booking_id=$bookingId&msg=update");
            exit;
        }
    }

    if ($error === '' && !is_scalar($hasilId)) {
        $error = 'Data hasil pengujian tidak valid.';
    } elseif ($error === '' && (int) $hasilId > 0) {
        $cekHasil = $pdo->prepare(
            "SELECT hp.id FROM hasil_pengujian hp
             JOIN sampel s ON hp.sampel_id = s.id
             WHERE hp.id = ? AND s.booking_id = ?"
        );
        $cekHasil->execute([(int) $hasilId, $bookingId]);
        if (!$cekHasil->fetch()) {
            $error = 'Data hasil tidak ditemukan pada booking ini.';
        }
    } elseif ($error === '') {
        $error = 'Data hasil pengujian tidak valid.';
    }

    if ($error === '') {
    $parameterUji = $nilaiHasil = $satuan = $kesimpulan = null;
    if (!$isBesi) {
        $parameterUji = trim($_POST['parameter_uji'] ?? '');
        $nilaiHasil = trim($_POST['nilai_hasil'] ?? '');
        $satuan = trim($_POST['satuan'] ?? '');
        $kesimpulan = $_POST['kesimpulan'] ?? '';
    }

    $beratKg = ($_POST['berat_kg'] ?? '') ?: null;
    $bebanMaks = ($_POST['beban_maksimum'] ?? '') ?: null;

    foreach (['kode_benda_uji','diameter','diameter_akhir','luas_penampang','luas_penampang_akhir',
        'beban_tarik_leleh','tegangan_leleh','tegangan_tarik','panjang_awal','panjang_akhir',
        'perpanjangan','reduksi_penampang','keterangan','tgl_cor','tgl_ditest','kode_beton',
        'mutu_beton','tegangan_tekan','tegangan_tekan_28_hari','mpa','berat_kg','beban_maksimum',
        'nilai_hasil','satuan','kesimpulan','parameter_uji','objek'] as $kPost) {
        if (isset($_POST[$kPost]) && is_array($_POST[$kPost])) { $_POST[$kPost] = reset($_POST[$kPost]) ?: ''; }
    }

    if ($isBesi) {
        $objek = trim($_POST['objek'] ?? '') ?: null;
        if ($objek === null) {
            $error = 'Obyek wajib diisi untuk pengujian besi.';
        }
        $kodeBendaUji = trim($_POST['kode_benda_uji'] ?? '') ?: null;
        $diameter = ($_POST['diameter'] ?? '') ?: null;
        $diameterAkhir = ($_POST['diameter_akhir'] ?? '') ?: null;
        $luasPenampang = ($_POST['luas_penampang'] ?? '') ?: null;
        $luasPenampangAkhir = ($_POST['luas_penampang_akhir'] ?? '') ?: null;
        $bebanTarikLeleh = ($_POST['beban_tarik_leleh'] ?? '') ?: null;
        $teganganLeleh = ($_POST['tegangan_leleh'] ?? '') ?: null;
        $teganganTarik = ($_POST['tegangan_tarik'] ?? '') ?: null;
        $panjangAwal = ($_POST['panjang_awal'] ?? '') ?: null;
        $panjangAkhir = ($_POST['panjang_akhir'] ?? '') ?: null;
        $perpanjangan = ($_POST['perpanjangan'] ?? '') ?: null;
        $reduksi = ($_POST['reduksi_penampang'] ?? '') ?: null;
        $keterangan = trim($_POST['keterangan'] ?? '') ?: null;
        $tglDitest = tanggalManualKeYmd($_POST['tgl_ditest'] ?? '');
        $tglCor = null; $umurHari = null;
        $kodeBeton = null; $mutuBeton = null;
        $teganganTekan = null; $teganganTekan28 = null; $mpa = null;
        $beratKg = null;
    } else {
        $objek = $isPaving ? (trim($_POST['objek'] ?? '') ?: null) : null;
        $kodeBendaUji = null;
        $diameterAkhir = $luasPenampangAkhir = $bebanTarikLeleh = null;
        $panjangAwal = $panjangAkhir = null;
        $keterangan = null;
        $tglCor = tanggalManualKeYmd($_POST['tgl_cor'] ?? '');
        $tglDites = tanggalManualKeYmd($_POST['tgl_ditest'] ?? '');
        $umurHari = null;
        if ($tglCor && $tglDites) {
            $umurHari = (new DateTime($tglCor))->diff(new DateTime($tglDites))->days;
        }
        $kodeBeton = trim($_POST['kode_beton'] ?? '') ?: null;
        $mutuBeton = ($_POST['mutu_beton'] ?? '') ?: null;
        $teganganTekan = ($_POST['tegangan_tekan'] ?? '') ?: null;
        $teganganTekan28 = ($_POST['tegangan_tekan_28_hari'] ?? '') ?: null;
        $mpa = ($_POST['mpa'] ?? '') ?: null;
        $diameter = ($_POST['diameter'] ?? '') ?: null;
        $luasPenampang = $diameter ? round(M_PI * pow($diameter / 2, 2), 4) : null;
        $teganganTarik = null; $teganganLeleh = null; $perpanjangan = null; $reduksi = null;
    }

    if ($error === '') {
    $sql = "UPDATE hasil_pengujian SET objek=?, kode_benda_uji=?, parameter_uji=?, tgl_cor=?, tgl_ditest=?, umur_hari=?, berat_kg=?, diameter=?, diameter_akhir=?, luas_penampang=?, luas_penampang_akhir=?, kode_beton=?, mutu_beton=?, beban_tarik_leleh=?, beban_maksimum=?, tegangan_tekan=?, tegangan_tekan_28_hari=?, mpa=?, tegangan_tarik=?, tegangan_leleh=?, panjang_awal=?, panjang_akhir=?, perpanjangan=?, reduksi_penampang=?, nilai_hasil=?, satuan=?, kesimpulan=?, catatan_teknisi=?";
    $params = [$objek, $kodeBendaUji, $parameterUji, $tglCor, $tglDitest, $umurHari, $beratKg, $diameter, $diameterAkhir, $luasPenampang, $luasPenampangAkhir, $kodeBeton, $mutuBeton, $bebanTarikLeleh, $bebanMaks, $teganganTekan, $teganganTekan28, $mpa, $teganganTarik, $teganganLeleh, $panjangAwal, $panjangAkhir, $perpanjangan, $reduksi, $nilaiHasil, $satuan, $kesimpulan, $keterangan ?: $catatanAdmin];

    if ($dokumentasiPath) {
        $sql .= ", dokumentasi_file=?";
        $params[] = $dokumentasiPath;
    }

    $sql .= " WHERE id=?";
    $params[] = $hasilId;

    $fileLamaSatu = null;
    if ($dokumentasiPath) {
        $cekLama = $pdo->prepare(
            "SELECT hp.dokumentasi_file FROM hasil_pengujian hp
             JOIN sampel s ON hp.sampel_id = s.id
             WHERE hp.id = ? AND s.booking_id = ?"
        );
        $cekLama->execute([(int) $hasilId, $bookingId]);
        $lama = $cekLama->fetch();
        if ($lama && $lama['dokumentasi_file']) { $fileLamaSatu = $lama['dokumentasi_file']; }
    }

    $pdo->prepare($sql)->execute($params);

    if ($fileLamaSatu && $fileLamaSatu !== $dokumentasiPath && is_file('../' . $fileLamaSatu)) {
        @unlink('../' . $fileLamaSatu);
    }

    $success = 'Hasil pengujian berhasil diupdate.';
    header("Location: edit_nilai.php?booking_id=$bookingId&msg=update");
    exit;
    }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'update') $success = 'Hasil pengujian berhasil diupdate.';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Nilai Hasil Uji - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
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

    <div class="page-container" style="max-width: 850px;">
        <a href="kelola_booking.php" class="text-decoration-none text-muted">&larr; Kembali ke Kelola Booking</a>

        <div class="page-header mt-2">
            <h4>Edit Nilai Hasil Uji</h4>
            <p>Edit parameter dan nilai hasil pengujian yang sudah diinput</p>
        </div>

        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Nomor Laporan</strong></div>
                <div class="card-body">
                    <div class="d-flex gap-2 align-items-end">
                        <div class="flex-grow-1">
                            <label class="form-label fw-bold">Nomor Laporan <span class="text-danger">*</span></label>
                            <input type="text" name="nomor_laporan_baru" class="form-control" required
                                   value="<?= htmlspecialchars($nomorLaporan) ?>" placeholder="Contoh: ITI-U/IX/26/BS/UT/01">
                        </div>
                        <div>
                            <button type="submit" name="update_nomor_laporan" class="btn btn-primary py-2">Update</button>
                        </div>
                    </div>
                    <small class="text-muted">Pastikan nomor unik dan belum pernah dipakai.</small>
                </div>
            </div>
        </form>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>Detail Booking</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Kode Booking</small>
                        <strong><?= htmlspecialchars($booking['kode_booking']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Pelanggan</small>
                        <strong><?= htmlspecialchars($booking['nama_pelanggan']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Jenis Pengujian</small>
                        <strong><?= htmlspecialchars($booking['nama_pengujian']) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Jumlah Sampel</small>
                        <strong><?= $booking['jumlah_sampel'] ?> <?= $booking['satuan_sampel'] ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isTabelBeton): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <strong><i class="bi bi-table"></i> Edit Hasil - <?= htmlspecialchars($booking['nama_pengujian']) ?></strong>
                <span class="badge bg-info" style="font-size:0.65rem;">Beton</span>
            </div>
            <div class="card-body">
                <?php if (empty($semuaHasil)): ?>
                <div class="text-center text-muted py-3">
                    <i class="bi bi-info-circle"></i> Belum ada data hasil pengujian.
                </div>
                <?php else: ?>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                    <div class="d-flex justify-content-end mb-3">
                        <div class="text-end" style="max-width:420px; width:100%;">
                            <label class="form-label mb-1" style="font-size:0.78rem;">Upload Foto Bukti Pengujian</label>
                            <input type="file" name="dokumentasi" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                            <small class="text-muted">Format JPG/PNG, maks 5MB &mdash; foto bukti ini akan tampil di laporan.</small>
                        </div>
                    </div>
                    <?php if ($isPaving): ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Obyek</label>
                            <input type="text" name="objek" class="form-control" placeholder="misal: Paving ( 10 x 20cm )"
                                   value="<?= htmlspecialchars($semuaHasil[0]['objek'] ?? '') ?>">
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0" style="font-size:0.8rem;">
                            <?= tabelBetonThead($isKubus, $isPaving, $isLentur, $isCore) ?>
                            <tbody>
                            <?php foreach ($semuaHasil as $i => $h): ?>
                                <?php if ($isCore): ?>
                                <tr>
                                    <td class="text-center" style="width:40px;"><?= $i + 1 ?>
                                        <input type="hidden" name="hasil_id[]" value="<?= $h['id'] ?>">
                                        <input type="hidden" name="sampel_id[]" value="<?= $h['sampel_id'] ?>">
                                    </td>
                                    <?php foreach (fieldCore() as $f): ?>
                                    <?php if ($f['db'] === null): ?>
                                    <td style="min-width:35px;"></td>
                                    <?php else: ?>
                                    <td style="min-width:115px;">
                                        <?php if ($f['db'] === 'tgl_cor' || $f['db'] === 'tgl_ditest'): ?>
                                        <input type="date" name="<?= $f['db'] ?>[]" class="form-control form-control-sm"
                                               value="<?= !empty($h[$f['db']]) ? date('Y-m-d', strtotime($h[$f['db']])) : '' ?>"
                                               onchange="hitungUmurBarisEdit(this)">
                                        <?php elseif ($f['db'] === 'umur_hari'): ?>
                                        <input type="number" name="umur_hari[]" class="form-control form-control-sm text-center"
                                               readonly style="background:#f1f5f9;" value="<?= htmlspecialchars((string) ($h['umur_hari'] ?? '')) ?>">
                                        <?php elseif ($f['type'] === 'number'): ?>
                                        <input type="number" step="0.0001" name="<?= $f['db'] ?>[]" class="form-control form-control-sm text-center"
                                               value="<?= htmlspecialchars((string) ($h[$f['db']] ?? '')) ?>">
                                        <?php else: ?>
                                        <input type="text" name="<?= $f['db'] ?>[]" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars($h[$f['db']] ?? ($f['db'] === 'kode_beton' ? ($h['kode_sampel'] ?? '') : '')) ?>">
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                                <?php elseif ($isLentur): ?>
                                <tr>
                                    <td class="text-center" style="width:40px;"><?= $i + 1 ?>
                                        <input type="hidden" name="hasil_id[]" value="<?= $h['id'] ?>">
                                        <input type="hidden" name="sampel_id[]" value="<?= $h['sampel_id'] ?>">
                                    </td>
                                    <?php foreach (fieldLentur() as $f): ?>
                                    <td style="min-width:115px;">
                                        <?php if ($f['db'] === 'tgl_cor' || $f['db'] === 'tgl_ditest'): ?>
                                        <input type="date" name="<?= $f['db'] ?>[]" class="form-control form-control-sm"
                                               value="<?= !empty($h[$f['db']]) ? date('Y-m-d', strtotime($h[$f['db']])) : '' ?>"
                                               onchange="hitungUmurBarisEdit(this)">
                                        <?php elseif ($f['db'] === 'umur_hari'): ?>
                                        <input type="number" name="umur_hari[]" class="form-control form-control-sm text-center"
                                               readonly style="background:#f1f5f9;" value="<?= htmlspecialchars((string) ($h['umur_hari'] ?? '')) ?>">
                                        <?php elseif ($f['type'] === 'number'): ?>
                                        <input type="number" step="0.0001" name="<?= $f['db'] ?>[]" class="form-control form-control-sm text-center"
                                               value="<?= htmlspecialchars((string) ($h[$f['db']] ?? '')) ?>">
                                        <?php else: ?>
                                        <input type="text" name="<?= $f['db'] ?>[]" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars($h[$f['db']] ?? ($f['db'] === 'kode_beton' ? ($h['kode_sampel'] ?? '') : '')) ?>">
                                        <?php endif; ?>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <td class="text-center" style="width:40px;"><?= $i + 1 ?></td>
                                    <td style="min-width:130px;">
                                        <input type="hidden" name="hasil_id[]" value="<?= $h['id'] ?>">
                                        <input type="hidden" name="sampel_id[]" value="<?= $h['sampel_id'] ?>">
                                        <input type="date" name="tgl_cor[]" class="form-control form-control-sm"
                                               value="<?= $h['tgl_cor'] ? date('Y-m-d', strtotime($h['tgl_cor'])) : '' ?>"
                                               onchange="hitungUmurBarisEdit(this)">
                                    </td>
                                    <td style="min-width:130px;">
                                        <input type="date" name="tgl_ditest[]" class="form-control form-control-sm"
                                               value="<?= $h['tgl_ditest'] ? date('Y-m-d', strtotime($h['tgl_ditest'])) : '' ?>"
                                               onchange="hitungUmurBarisEdit(this)">
                                    </td>
                                    <td style="min-width:95px;">
                                        <input type="number" name="umur_hari[]" class="form-control form-control-sm text-center"
                                               readonly style="background:#f1f5f9;" value="<?= htmlspecialchars((string) ($h['umur_hari'] ?? '')) ?>">
                                    </td>
                                    <td style="min-width:100px;">
                                        <input type="number" step="0.01" name="berat_kg[]" class="form-control form-control-sm text-center"
                                               value="<?= htmlspecialchars((string) ($h['berat_kg'] ?? '')) ?>">
                                    </td>
                                    <td style="min-width:120px;">
                                        <input type="text" name="kode_beton[]" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars($h['kode_beton'] ?? ($h['kode_sampel'] ?? '')) ?>">
                                    </td>
                                    <td style="min-width:120px;">
                                        <input type="number" step="0.01" name="beban_maksimum[]" class="form-control form-control-sm text-center"
                                               value="<?= htmlspecialchars((string) ($h['beban_maksimum'] ?? '')) ?>">
                                    </td>
                                    <td style="min-width:120px;">
                                        <input type="number" step="0.01" name="tegangan_tekan[]" class="form-control form-control-sm text-center"
                                               value="<?= htmlspecialchars((string) ($h['tegangan_tekan'] ?? '')) ?>">
                                    </td>
                                    <td style="min-width:130px;">
                                        <input type="number" step="0.01" name="tegangan_tekan_28_hari[]" class="form-control form-control-sm text-center"
                                               value="<?= htmlspecialchars((string) ($h['tegangan_tekan_28_hari'] ?? '')) ?>">
                                    </td>
                                    <td style="min-width:95px;">
                                        <?php if ($isKubus): ?>
                                        <input type="text" name="keterangan[]" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars($h['catatan_teknisi'] ?? '') ?>">
                                        <?php elseif ($isPaving): ?>
                                        <input type="number" step="0.01" name="rata_rata[]" class="form-control form-control-sm text-center"
                                               value="<?= htmlspecialchars((string) ($h['rata_rata'] ?? '')) ?>">
                                        <?php else: ?>
                                        <input type="number" step="0.01" name="mpa[]" class="form-control form-control-sm text-center"
                                               value="<?= htmlspecialchars((string) ($h['mpa'] ?? '')) ?>">
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="row mt-3">
                    </div>
                    <button type="submit" name="update_hasil" class="btn btn-primary w-100 py-2">Update Semua Hasil Pengujian</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
<?php else: ?>
<?php foreach ($sampelList as $s): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center bg-white">
                <span>Sampel: <strong><?= htmlspecialchars($s['kode_sampel']) ?></strong>
                    <?php if (($booking['kategori'] ?? 'beton') === 'besi'): ?>
                        <span class="badge bg-warning" style="font-size:0.65rem;">Besi</span>
                    <?php else: ?>
                        <span class="badge bg-info" style="font-size:0.65rem;">Beton</span>
                    <?php endif; ?>
                </span>
                <?php if (!empty($hasilPerSampel[$s['id']])): ?>
                    <span class="badge bg-success">Sudah Diinput</span>
                <?php else: ?>
                    <span class="badge bg-warning">Belum Diinput</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($hasilPerSampel[$s['id']])): ?>
                    <?php foreach ($hasilPerSampel[$s['id']] as $h): ?>
                    <form method="POST" enctype="multipart/form-data" class="mb-3 pb-3 border-bottom">
                        <?= csrf_field() ?>
                        <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                        <input type="hidden" name="hasil_id" value="<?= $h['id'] ?>">
                        <input type="hidden" name="sampel_id" value="<?= $s['id'] ?>">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <strong>
                                <?php if (($booking['kategori'] ?? 'beton') === 'besi'): ?>
                                    Kode Benda Uji: <?= htmlspecialchars($h['kode_benda_uji'] ?? '-') ?>
                                <?php else: ?>
                                    Parameter: <?= htmlspecialchars($h['parameter_uji'] ?? '-') ?>
                                <?php endif; ?>
                            </strong>
                            <span class="badge bg-secondary">ID: <?= $h['id'] ?></span>
                        </div>

                        <div class="d-flex justify-content-end mb-3">
                            <div class="text-end" style="max-width:420px; width:100%;">
                                <label class="form-label mb-1" style="font-size:0.78rem;">Upload Foto Bukti Pengujian</label>
                                <input type="file" name="dokumentasi" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                                <small class="text-muted">Format JPG/PNG, maks 5MB &mdash; foto bukti ini akan tampil di laporan.</small>
                            </div>
                        </div>

                        <?php if (($booking['kategori'] ?? 'beton') === 'besi'): ?>
                        <div class="row">
                            <div class="col-sm-12 mb-3">
                                <label class="form-label">Obyek <span class="text-danger">*</span></label>
                                <input type="text" name="objek" class="form-control" required
                                       placeholder="Obyek pengujian besi" value="<?= htmlspecialchars($h['objek'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 mb-3">
                                <label class="form-label">Tanggal Dites</label>
                                <input type="date" name="tgl_ditest" class="form-control" value="<?= htmlspecialchars($h['tgl_ditest'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Kode Benda Uji <span class="text-danger">*</span></label>
                                <input type="text" name="kode_benda_uji" class="form-control" required value="<?= htmlspecialchars($h['kode_benda_uji'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Diameter D0 (mm)</label>
                                <input type="number" step="0.01" name="diameter" class="form-control" value="<?= htmlspecialchars($h['diameter'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Diameter D0u (mm)</label>
                                <input type="number" step="0.01" name="diameter_akhir" class="form-control" value="<?= htmlspecialchars($h['diameter_akhir'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Luas Penampang A0 (mm&sup2;)</label>
                                <input type="number" step="0.01" name="luas_penampang" class="form-control" value="<?= htmlspecialchars($h['luas_penampang'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Luas Penampang Au (mm&sup2;)</label>
                                <input type="number" step="0.01" name="luas_penampang_akhir" class="form-control" value="<?= htmlspecialchars($h['luas_penampang_akhir'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Beban Tarik Leleh (kN)</label>
                                <input type="number" step="0.01" name="beban_tarik_leleh" class="form-control" value="<?= htmlspecialchars($h['beban_tarik_leleh'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Beban Tarik Maks (kN)</label>
                                <input type="number" step="0.01" name="beban_maksimum" class="form-control" value="<?= htmlspecialchars($h['beban_maksimum'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Beban Tarik Leleh (MPa)</label>
                                <input type="number" step="0.01" name="tegangan_leleh" class="form-control" value="<?= htmlspecialchars($h['tegangan_leleh'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Beban Tarik Maks (MPa)</label>
                                <input type="number" step="0.01" name="tegangan_tarik" class="form-control" value="<?= htmlspecialchars($h['tegangan_tarik'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Panjang Ulur Awal L0 (mm)</label>
                                <input type="number" step="0.01" name="panjang_awal" class="form-control" value="<?= htmlspecialchars($h['panjang_awal'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Panjang Ulur Akhir Lu (mm)</label>
                                <input type="number" step="0.01" name="panjang_akhir" class="form-control" value="<?= htmlspecialchars($h['panjang_akhir'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Regangan Maks (%)</label>
                                <input type="number" step="0.01" name="perpanjangan" class="form-control" value="<?= htmlspecialchars($h['perpanjangan'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Kontraksi (%)</label>
                                <input type="number" step="0.01" name="reduksi_penampang" class="form-control" value="<?= htmlspecialchars($h['reduksi_penampang'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Keterangan</label>
                            <input type="text" name="keterangan" class="form-control" placeholder="Keterangan uji, misal: Uji Tek-uk" value="<?= htmlspecialchars($h['catatan_teknisi'] ?? '') ?>">
                        </div>
                        <?php else: ?>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Tanggal Cor</label>
                                <input type="date" name="tgl_cor" class="form-control" id="edit-tgl_cor_<?= $h['id'] ?>" value="<?= htmlspecialchars($h['tgl_cor'] ?? '') ?>" onchange="hitungUmurEdit(<?= $h['id'] ?>)">
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Tanggal Dites</label>
                                <input type="date" name="tgl_ditest" class="form-control" id="edit-tgl_ditest_<?= $h['id'] ?>" value="<?= htmlspecialchars($h['tgl_ditest'] ?? '') ?>" onchange="hitungUmurEdit(<?= $h['id'] ?>)">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Umur (Hari)</label>
                                <input type="number" name="umur_hari" id="edit-umur_<?= $h['id'] ?>" class="form-control" readonly style="background: #f1f5f9;" value="<?= htmlspecialchars($h['umur_hari'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Berat (kg)</label>
                                <input type="number" step="0.01" name="berat_kg" class="form-control" value="<?= htmlspecialchars($h['berat_kg'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Kode Beton</label>
                                <input type="text" name="kode_beton" class="form-control" value="<?= htmlspecialchars($h['kode_beton'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Mutu Beton</label>
                                <select name="mutu_beton" class="form-select" required>
                                    <option value="">-- Pilih Mutu --</option>
                                    <?php foreach (['K-175','K-200','K-225','K-250','K-275','K-300','K-350','K-400','K-450','K-500'] as $m): ?>
                                    <option value="<?= $m ?>" <?= ($h['mutu_beton'] ?? '') === $m ? 'selected' : '' ?>><?= $m ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label">Parameter Uji</label>
                                <input type="text" name="parameter_uji" class="form-control" required value="<?= htmlspecialchars($h['parameter_uji']) ?>">
                            </div>
                            <div class="col-sm-3 mb-3">
                                <label class="form-label">Nilai Hasil</label>
                                <input type="text" name="nilai_hasil" class="form-control" required value="<?= htmlspecialchars($h['nilai_hasil']) ?>">
                            </div>
                            <div class="col-sm-3 mb-3">
                                <label class="form-label">Satuan</label>
                                <input type="text" name="satuan" class="form-control" value="<?= htmlspecialchars($h['satuan'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 mb-3">
                                <label class="form-label">Beban Maksimum</label>
                                <input type="number" step="0.01" name="beban_maksimum" class="form-control" value="<?= htmlspecialchars($h['beban_maksimum'] ?? '') ?>">
                            </div>
                            <div class="col-sm-4 mb-3">
                                <label class="form-label">Tegangan Tekan</label>
                                <input type="number" step="0.01" name="tegangan_tekan" class="form-control" value="<?= htmlspecialchars($h['tegangan_tekan'] ?? '') ?>">
                            </div>
                            <div class="col-sm-4 mb-3">
                                <label class="form-label">Tegangan Tekan 28 Hari</label>
                                <input type="number" step="0.01" name="tegangan_tekan_28_hari" class="form-control" value="<?= htmlspecialchars($h['tegangan_tekan_28_hari'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 mb-3">
                                <label class="form-label">MPa</label>
                                <input type="number" step="0.01" name="mpa" class="form-control" value="<?= htmlspecialchars($h['mpa'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kesimpulan</label>
                            <select name="kesimpulan" class="form-select" required>
                                <option value="">-- Pilih --</option>
                                <option value="memenuhi_syarat" <?= $h['kesimpulan'] === 'memenuhi_syarat' ? 'selected' : '' ?>>Memenuhi Syarat</option>
                                <option value="tidak_memenuhi_syarat" <?= $h['kesimpulan'] === 'tidak_memenuhi_syarat' ? 'selected' : '' ?>>Tidak Memenuhi Syarat</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <button type="submit" name="update_hasil" class="btn btn-primary w-100 py-2">Update Hasil Pengujian</button>
                    </form>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-info-circle"></i> Belum ada data hasil pengujian untuk sampel ini.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
<?php endif; ?>

        <a href="kelola_booking.php" class="btn btn-secondary w-100 py-2">Kembali ke Kelola Booking</a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function hitungUmurEdit(id) {
        var tglCor = document.getElementById('edit-tgl_cor_' + id).value;
        var tglDites = document.getElementById('edit-tgl_ditest_' + id).value;
        var umurField = document.getElementById('edit-umur_' + id);
        if (tglCor && tglDites) {
            var diff = new Date(tglDites) - new Date(tglCor);
            var hari = Math.floor(diff / (1000 * 60 * 60 * 24));
            umurField.value = hari >= 0 ? hari : '';
        }
    }
    function hitungUmurBarisEdit(el) {
        var tr = el.closest('tr');
        var tglCor = tr.querySelector('input[name="tgl_cor[]"]');
        var tglDites = tr.querySelector('input[name="tgl_ditest[]"]');
        var umurField = tr.querySelector('input[name="umur_hari[]"]');
        if (tglCor.value && tglDites.value) {
            var hari = Math.floor(Math.abs(new Date(tglDites.value) - new Date(tglCor.value)) / (1000 * 60 * 60 * 24));
            umurField.value = hari >= 0 ? hari : '';
        } else {
            umurField.value = '';
        }
    }
    </script>
</body>
</html>
