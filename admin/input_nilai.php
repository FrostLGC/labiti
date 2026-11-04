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
     WHERE b.id = ? AND b.status_booking = 'menunggu_input_admin'"
);
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    die('Booking tidak ditemukan atau statusnya bukan "Menunggu Input Admin".');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_hasil'])) {
    $kategori = $booking['kategori'] ?? 'beton';
    $isBesi = ($kategori === 'besi');
    $catatanAdmin = trim($_POST['catatan_admin'] ?? '');

    foreach (['tgl_cor','tgl_ditest','kode_beton','mutu_beton','berat_kg','beban_maksimum',
        'tegangan_tekan','tegangan_tekan_28_hari','mpa','rata_rata','diameter','tinggi_l',
        'rasio_ld','faktor_koreksi','tegangan_benda_uji','konversi_silider6','konversi_kubus15',
        'konversi_umur28','kode_benda_uji','diameter_akhir','luas_penampang','luas_penampang_akhir',
        'beban_tarik_leleh','tegangan_tarik','tegangan_leleh','panjang_awal','panjang_akhir',
        'perpanjangan','reduksi_penampang','bentang_l','dimensi_b','dimensi_h','modulus_seksi',
        'momen_lentur','tegangan_lentur','catatan_teknisi','parameter_uji','nilai_hasil','satuan',
        'kesimpulan','keterangan','konversi_umur28_mpa','sampel_id','objek'] as $kPost) {
        if (isset($_POST[$kPost]) && is_array($_POST[$kPost])) { $_POST[$kPost] = reset($_POST[$kPost]) ?: ''; }
    }

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

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

    $fotoLama = null;
    if ($error === '' && !empty($_POST['sampel_id'])) {
        $stFoto = $pdo->prepare("SELECT dokumentasi_file FROM hasil_pengujian WHERE sampel_id = ? ORDER BY id DESC LIMIT 1");
        $stFoto->execute([(int) $_POST['sampel_id']]);
        $fotoLama = $stFoto->fetchColumn() ?: null;
        if ($dokumentasiPath === null) { $dokumentasiPath = $fotoLama; }
    }
    if ($dokumentasiPath === null) {
        $stFotoB = $pdo->prepare("SELECT hp.dokumentasi_file FROM hasil_pengujian hp JOIN sampel s ON hp.sampel_id = s.id WHERE s.booking_id = ? AND hp.dokumentasi_file IS NOT NULL ORDER BY hp.id DESC LIMIT 1");
        $stFotoB->execute([$bookingId]);
        $dokumentasiPath = $stFotoB->fetchColumn() ?: null;
    }
    $hapusFotoLama = function () use ($fotoLama, $dokumentasiPath) {
        if ($fotoLama !== null && $fotoLama !== $dokumentasiPath) { @unlink('../' . $fotoLama); }
    };

    if ($error === '' && $isTabelBeton) {
        $sampelId  = $_POST['sampel_id'] ?? null;
        if ($sampelId === null || $sampelId === '') { die('Sampel wajib dipilih.'); }
        $cekOwn = $pdo->prepare("SELECT id FROM sampel WHERE id = ? AND booking_id = ?");
        $cekOwn->execute([$sampelId, $bookingId]);
        if (!$cekOwn->fetch()) { die('Sampel tidak ditemukan pada booking ini.'); }
        $tglCor    = tanggalManualKeYmd($_POST['tgl_cor'] ?? '');
        $tglDites  = tanggalManualKeYmd($_POST['tgl_ditest'] ?? '');
        $kodeBeton = trim($_POST['kode_beton'] ?? '');
        $bebanMaks = ($_POST['beban_maksimum'] ?? '') ?: null;

        if ($isCore) {
            // Uji Tekan Core Drill: seluruh kolom diisi manual admin sesuai format laporan
            $diameterCore   = ($_POST['diameter'] ?? '') ?: null;
            $tinggiL        = ($_POST['tinggi_l'] ?? '') ?: null;
            $luasPenampang  = ($_POST['luas_penampang'] ?? '') ?: null;
            $beratKg        = ($_POST['berat_kg'] ?? '') ?: null;
            $rasioLd        = ($_POST['rasio_ld'] ?? '') ?: null;
            $faktorKoreksi  = ($_POST['faktor_koreksi'] ?? '') ?: null;
            $tegBendaUji    = ($_POST['tegangan_benda_uji'] ?? '') ?: null;
            $konvSilider6   = ($_POST['konversi_silider6'] ?? '') ?: null;
            $konvKubus15    = ($_POST['konversi_kubus15'] ?? '') ?: null;
            $konvUmur28     = ($_POST['konversi_umur28'] ?? '') ?: null;
            $mpaNilai       = (($_POST['mpa'] ?? '') ?: null);
            $konvUmur28Mpa  = (($_POST['konversi_umur28_mpa'] ?? '') ?: null);
            $keteranganBaris = trim($_POST['keterangan'] ?? '') ?: null;
            $parameterUjiTabel = 'Uji Tekan Core Drill';
            $mutuNilai = $booking['mutu_beton'] ?? null;
            $keterangan = $keteranganBaris ?: ($catatanAdmin ?: null);
            $nilaiHasil = $tegBendaUji;
            $satuanNilai = $nilaiHasil !== null ? 'Kg/cm²' : null;
            $tegTekan = $teg28 = $rataRata = null;
            $bentangL = $dimensiB = $dimensiH = $modulusSeksi = $momenLentur = $teganganLentur = null;

            $semuaKosong = !$tglCor && !$tglDites && $kodeBeton === '' && !$bebanMaks
                && !$diameterCore && !$tinggiL && !$luasPenampang && !$beratKg && !$rasioLd
                && !$faktorKoreksi && !$tegBendaUji && !$konvSilider6 && !$konvKubus15
                && !$konvUmur28 && !$mpaNilai && !$konvUmur28Mpa && !$keteranganBaris;
        } elseif ($isLentur) {
            // Uji Lentur / Flexural Balok Beton: seluruh kolom dihitung diisi manual admin
            $beratKg   = null;
            $tegTekan  = null;
            $teg28     = null;
            $mpaNilai  = null;
            $rataRata  = null;
            $mutuManual = trim($_POST['mutu_beton'] ?? '');
            $bentangL  = ($_POST['bentang_l'] ?? '') ?: null;
            $dimensiB  = ($_POST['dimensi_b'] ?? '') ?: null;
            $dimensiH  = ($_POST['dimensi_h'] ?? '') ?: null;
            $modulusSeksi = ($_POST['modulus_seksi'] ?? '') ?: null;
            $momenLentur  = ($_POST['momen_lentur'] ?? '') ?: null;
            $teganganLentur = ($_POST['tegangan_lentur'] ?? '') ?: null;
            $keteranganBaris = trim($_POST['keterangan'] ?? '') ?: null;
            $parameterUjiTabel = 'Uji Lentur';
            $mutuNilai = $mutuManual ?: null;
            $keterangan = $keteranganBaris ?: ($catatanAdmin ?: null);
            $nilaiHasil = ($teganganLentur ?: null);
            $satuanNilai = $nilaiHasil !== null ? 'Kg/cm²' : null;

            $semuaKosong = !$tglCor && !$tglDites && $kodeBeton === '' && !$bebanMaks
                && !$mutuManual && !$bentangL && !$dimensiB && !$dimensiH
                && !$modulusSeksi && !$momenLentur && !$teganganLentur && !$keteranganBaris;
        } else {
            $beratKg   = ($_POST['berat_kg'] ?? '') ?: null;
            $tegTekan  = ($_POST['tegangan_tekan'] ?? '') ?: null;
            $teg28     = ($_POST['tegangan_tekan_28_hari'] ?? '') ?: null;
            $mpaNilai  = $isKubus ? null : (($_POST['mpa'] ?? '') ?: null);
            $rataRata  = $isPaving ? (($_POST['rata_rata'] ?? '') ?: null) : null;
            // Kubus: kolom terakhir adalah Keterangan (catatan_teknisi); Paving: tanpa kolom Keterangan
            $keteranganBaris = $isKubus ? (trim($_POST['keterangan'] ?? '') ?: null) : null;
            $parameterUjiTabel = 'Kuat Tekan';
            $mutuNilai = $booking['mutu_beton'] ?? null;
            $keterangan = $isKubus ? $keteranganBaris : ($catatanAdmin ?: null);
            $nilaiHasil = $isKubus
                ? (($teg28 ?: $tegTekan) ?: null)
                : ($isPaving ? (($rataRata ?: ($teg28 ?: $tegTekan)) ?: null) : $mpaNilai);
            $satuanNilai = null;
            if ($nilaiHasil !== null) {
                $satuanNilai = ($isKubus || $isPaving) ? 'Kg/cm²' : 'MPa';
            }
            $bentangL = $dimensiB = $dimensiH = $modulusSeksi = $momenLentur = $teganganLentur = null;

            $semuaKosong = !$tglCor && !$tglDites && !$beratKg && $kodeBeton === ''
                && !$bebanMaks && !$tegTekan && !$teg28 && !$mpaNilai && !$rataRata && !$keteranganBaris;
        }

        if ($semuaKosong) {
            $error = 'Minimal isi data pengujian terlebih dahulu.';
        } else {
            $umurHari = null;
            if ($tglCor && $tglDites) {
                $umurHari = (new DateTime($tglCor))->diff(new DateTime($tglDites))->days;
            }

            // Ganti hasil lama untuk sampel ini supaya tidak menghasilkan baris ganda
            $pdo->prepare("DELETE FROM hasil_pengujian WHERE sampel_id = ?")->execute([$sampelId]);

            if ($isCore) {
                $pdo->prepare(
                    "INSERT INTO hasil_pengujian (sampel_id, teknisi_id, parameter_uji, tgl_cor, tgl_ditest, umur_hari, diameter, luas_penampang, berat_kg, kode_beton, mutu_beton, beban_maksimum, tinggi_l, rasio_ld, faktor_koreksi, tegangan_benda_uji, konversi_silider6, konversi_kubus15, konversi_umur28, mpa, konversi_umur28_mpa, nilai_hasil, satuan, dokumentasi_file, catatan_teknisi, status_verifikasi)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'terverifikasi')"
                )->execute([
                    $sampelId, $user['id'], $parameterUjiTabel, $tglCor, $tglDites, $umurHari,
                    $diameterCore, $luasPenampang, $beratKg, $kodeBeton ?: null, $mutuNilai,
                    $bebanMaks, $tinggiL, $rasioLd, $faktorKoreksi, $tegBendaUji,
                    $konvSilider6, $konvKubus15, $konvUmur28, $mpaNilai, $konvUmur28Mpa,
                    $nilaiHasil, $satuanNilai, $dokumentasiPath, $keterangan
                ]);
            } else {
            $pdo->prepare(
                "INSERT INTO hasil_pengujian (sampel_id, teknisi_id, objek, parameter_uji, tgl_cor, tgl_ditest, umur_hari, berat_kg, kode_beton, mutu_beton, beban_maksimum, tegangan_tekan, tegangan_tekan_28_hari, rata_rata, mpa, bentang_l, dimensi_b, dimensi_h, modulus_seksi, momen_lentur, tegangan_lentur, nilai_hasil, satuan, dokumentasi_file, catatan_teknisi, status_verifikasi)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'terverifikasi')"
            )->execute([
                $sampelId, $user['id'], $objek, $parameterUjiTabel, $tglCor, $tglDites, $umurHari, $beratKg,
                $kodeBeton ?: null, $mutuNilai,
                $bebanMaks, $tegTekan, $teg28, $rataRata, $mpaNilai,
                $bentangL, $dimensiB, $dimensiH, $modulusSeksi, $momenLentur, $teganganLentur,
                $nilaiHasil, $satuanNilai, $dokumentasiPath, $keterangan
            ]);
            }

            $hapusFotoLama();
            header("Location: input_nilai.php?booking_id=$bookingId&msg=simpan");
            exit;
        }
    } elseif ($error === '') {

    $sampelId = $_POST['sampel_id'] ?? null;
    if ($sampelId === null || $sampelId === '') { die('Sampel wajib dipilih.'); }
    $cekOwn = $pdo->prepare("SELECT id FROM sampel WHERE id = ? AND booking_id = ?");
    $cekOwn->execute([$sampelId, $bookingId]);
    if (!$cekOwn->fetch()) { die('Sampel tidak ditemukan pada booking ini.'); }

    // Kolom berikut hanya ada pada form beton
    $parameterUji = $nilaiHasil = $satuan = $kesimpulan = null;
    if (!$isBesi) {
        $parameterUji = trim($_POST['parameter_uji'] ?? '');
        $nilaiHasil = trim($_POST['nilai_hasil'] ?? '');
        $satuan = trim($_POST['satuan'] ?? '');
        $kesimpulan = $_POST['kesimpulan'] ?? '';
    }

    $bebanMaks = ($_POST['beban_maksimum'] ?? '') ?: null;

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
        $beratKg = ($_POST['berat_kg'] ?? '') ?: null;
        $tglCor = tanggalManualKeYmd($_POST['tgl_cor'] ?? '');
        $tglDitest = tanggalManualKeYmd($_POST['tgl_ditest'] ?? '');
        $umurHari = null;
        if ($tglCor && $tglDitest) {
            $umurHari = (new DateTime($tglCor))->diff(new DateTime($tglDitest))->days;
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
    $pdo->prepare("DELETE FROM hasil_pengujian WHERE sampel_id = ?")->execute([$sampelId]);
    $pdo->prepare(
        "INSERT INTO hasil_pengujian (sampel_id, teknisi_id, objek, kode_benda_uji, parameter_uji, tgl_cor, tgl_ditest, umur_hari, berat_kg, diameter, diameter_akhir, luas_penampang, luas_penampang_akhir, kode_beton, mutu_beton, beban_tarik_leleh, beban_maksimum, tegangan_tekan, tegangan_tekan_28_hari, mpa, tegangan_tarik, tegangan_leleh, panjang_awal, panjang_akhir, perpanjangan, reduksi_penampang, nilai_hasil, satuan, kesimpulan, dokumentasi_file, catatan_teknisi, status_verifikasi)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'terverifikasi')"
    )->execute([$sampelId, $user['id'], $objek, $kodeBendaUji, $parameterUji, $tglCor, $tglDitest, $umurHari, $beratKg, $diameter, $diameterAkhir, $luasPenampang, $luasPenampangAkhir, $kodeBeton, $mutuBeton, $bebanTarikLeleh, $bebanMaks, $teganganTekan, $teganganTekan28, $mpa, $teganganTarik, $teganganLeleh, $panjangAwal, $panjangAkhir, $perpanjangan, $reduksi, $nilaiHasil, $satuan, $kesimpulan, $dokumentasiPath, $keterangan ?: $catatanAdmin]);

    $success = 'Hasil pengujian berhasil disimpan.';
    $hapusFotoLama();
    header("Location: input_nilai.php?booking_id=$bookingId&msg=simpan");
    exit;
    }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_dokumentasi'])) {
    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    if (!isset($_FILES['dokumentasi']) || ($_FILES['dokumentasi']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Pilih file dokumentasi terlebih dahulu.';
    } elseif ($_FILES['dokumentasi']['error'] !== 0) {
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
                $pathBaru = 'uploads/dokumentasi/' . $namaBaru;
                $pdo->prepare(
                    "UPDATE hasil_pengujian hp JOIN sampel s ON hp.sampel_id = s.id SET hp.dokumentasi_file = ? WHERE s.booking_id = ?"
                )->execute([$pathBaru, $bookingId]);
                $stmtOld = $pdo->prepare(
                    "SELECT DISTINCT hp.dokumentasi_file FROM hasil_pengujian hp JOIN sampel s ON hp.sampel_id = s.id WHERE s.booking_id = ? AND hp.dokumentasi_file IS NOT NULL AND hp.dokumentasi_file != ?"
                );
                $stmtOld->execute([$bookingId, $pathBaru]);
                foreach ($stmtOld->fetchAll(PDO::FETCH_COLUMN) as $fl) {
                    if (is_file('../' . $fl)) @unlink('../' . $fl);
                }
                header("Location: input_nilai.php?booking_id=$bookingId&msg=dok_upload");
                exit;
            } else {
                $error = 'Upload dokumentasi gagal. Coba lagi.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['terbitkan_laporan'])) {
    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    $stmtSampel = $pdo->prepare("SELECT COUNT(*) FROM sampel WHERE booking_id = ?");
    $stmtSampel->execute([$bookingId]);
    $jmlSampel = (int) $stmtSampel->fetchColumn();

    $stmtAllHasil = $pdo->prepare(
        "SELECT DISTINCT hp.sampel_id FROM hasil_pengujian hp
         JOIN sampel s ON hp.sampel_id = s.id
         WHERE s.booking_id = ? AND (hp.parameter_uji IS NOT NULL OR hp.kode_benda_uji IS NOT NULL)"
    );
    $stmtAllHasil->execute([$bookingId]);
    $sampelTerisi = $stmtAllHasil->fetchAll(PDO::FETCH_COLUMN);

    if ($jmlSampel === 0) {
        $error = 'Booking ini belum memiliki sampel. Terima sampel terlebih dahulu.';
    } elseif (count($sampelTerisi) < $jmlSampel) {
        $error = 'Semua sampel harus sudah diinput nilainya. Baru ' . count($sampelTerisi)
               . ' dari ' . $jmlSampel . ' sampel yang terisi.';
    } else {
        $nomorLaporan = trim($_POST['nomor_laporan'] ?? '');
        if (empty($nomorLaporan)) {
            $error = 'Nomor laporan wajib diisi.';
        } else {
            $cekNomor = $pdo->prepare("SELECT id FROM laporan WHERE nomor_laporan = ?");
            $cekNomor->execute([$nomorLaporan]);
            if ($cekNomor->fetch()) {
                $error = 'Nomor laporan "' . htmlspecialchars($nomorLaporan) . '" sudah digunakan.';
            }
        }

        if (empty($error)) {
            $pdo->prepare("INSERT INTO laporan (booking_id, nomor_laporan, file_laporan, dicetak_oleh, tanggal_terbit) VALUES (?, ?, ?, ?, CURDATE())")
                 ->execute([$bookingId, $nomorLaporan, 'pending_generate', $user['id']]);

            $pdo->prepare("UPDATE bookings SET status_booking = 'validasi_hasil' WHERE id = ?")->execute([$bookingId]);

            $pdo->prepare("INSERT INTO riwayat_status (booking_id, status, diubah_oleh, keterangan) VALUES (?, 'validasi_hasil', ?, ?)")
                 ->execute([$bookingId, $user['id'], "Laporan diterbitkan (No. $nomorLaporan). Menunggu validasi hasil uji."]);

            header('Location: kelola_booking.php?msg=input_nilai_berhasil');
            exit;
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'simpan') $success = 'Hasil pengujian berhasil disimpan.';
elseif (isset($_GET['msg']) && $_GET['msg'] === 'dok_upload') $success = 'Foto dokumentasi berhasil diupload.';

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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Nilai Hasil Uji - Admin</title>
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
            <h4>Input Nilai Hasil Uji</h4>
            <p>Input parameter dan nilai hasil pengujian untuk setiap sampel</p>
        </div>

        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Nomor Laporan</strong></div>
                <div class="card-body">
                    <div class="mb-0">
                        <label class="form-label fw-bold">Nomor Laporan <span class="text-danger">*</span></label>
                        <input type="text" name="nomor_laporan" id="nomor_laporan_display" class="form-control" required
                               placeholder="Contoh: ITI-U/IX/26/BS/UT/01">
                        <small class="text-muted">Input manual oleh admin. Pastikan nomor unik dan belum pernah dipakai.</small>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Upload Foto Bukti Pengujian</strong></div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="formUploadDokumentasi">
                        <?= csrf_field() ?>
                        <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                        <div class="mb-2">
                            <input type="file" name="dokumentasi" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                            <small class="text-muted">Format JPG/PNG, maks 5MB &mdash; foto bukti ini akan tampil di laporan. Cukup upload 1 kali.</small>
                        </div>
                        <button type="submit" name="upload_dokumentasi" class="btn btn-primary btn-sm">Upload Dokumentasi</button>
                    </form>
                    <?php $fotoBooking = null;
                    $stFotoB = $pdo->prepare("SELECT hp.dokumentasi_file FROM hasil_pengujian hp JOIN sampel s ON hp.sampel_id = s.id WHERE s.booking_id = ? AND hp.dokumentasi_file IS NOT NULL ORDER BY hp.id DESC LIMIT 1");
                    $stFotoB->execute([$bookingId]);
                    $fotoBooking = $stFotoB->fetchColumn() ?: null;
                    if ($fotoBooking): ?>
                    <div class="mt-2">
                        <img src="../<?= htmlspecialchars($fotoBooking) ?>" alt="Dokumentasi" style="max-height:80px;" class="rounded border">
                        <br><small class="text-muted">Foto sudah terupload. Upload ulang hanya jika ingin mengganti.</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

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
<?php foreach ($sampelList as $s): ?>
    <?php
    $ada = $hasilPerSampel[$s['id']] ?? [];
    $hv = $ada ? end($ada) : null;
    $v = [
        'tgl_cor' => '', 'tgl_ditest' => '', 'umur_hari' => '', 'berat_kg' => '',
        'kode_beton' => ($s['kode_sampel'] ?? ''), 'beban_maksimum' => '',
        'tegangan_tekan' => '', 'tegangan_tekan_28_hari' => '', 'rata_rata' => '', 'mpa' => '',
        'catatan_teknisi' => '',
        'mutu_beton' => '', 'bentang_l' => '', 'dimensi_b' => '', 'dimensi_h' => '',
        'modulus_seksi' => '', 'momen_lentur' => '', 'tegangan_lentur' => '',
        'diameter' => '', 'luas_penampang' => '', 'tinggi_l' => '', 'rasio_ld' => '',
        'faktor_koreksi' => '', 'tegangan_benda_uji' => '', 'konversi_silider6' => '',
        'konversi_kubus15' => '', 'konversi_umur28' => '', 'konversi_umur28_mpa' => '', 'objek' => '',
    ];
    if ($hv) {
        foreach ($v as $k => $unused) {
            if (isset($hv[$k]) && $hv[$k] !== '') $v[$k] = $hv[$k];
        }
    }
    $v['tgl_cor']    = $v['tgl_cor']    ? date('Y-m-d', strtotime($v['tgl_cor'])) : '';
    $v['tgl_ditest'] = $v['tgl_ditest'] ? date('Y-m-d', strtotime($v['tgl_ditest'])) : '';
    ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center bg-white">
            <span>Sampel: <strong><?= htmlspecialchars($s['kode_sampel']) ?></strong>
                <span class="badge bg-info" style="font-size:0.65rem;">Beton</span>
            </span>
            <?php if (!empty($hasilPerSampel[$s['id']])): ?>
                <span class="badge bg-success">Sudah Diinput</span>
            <?php else: ?>
                <span class="badge bg-warning">Belum Diinput</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                <input type="hidden" name="sampel_id" value="<?= $s['id'] ?>">

                <?php if ($isCore): ?>
                <!-- Uji Tekan Core Drill -->
                <div class="row">
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Tanggal Pembuatan</label>
                        <input type="date" name="tgl_cor" id="tgl_cor_<?= $s['id'] ?>" class="form-control"
                               value="<?= $v['tgl_cor'] ?>" onchange="hitungUmur(<?= $s['id'] ?>)">
                    </div>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Tanggal Test</label>
                        <input type="date" name="tgl_ditest" id="tgl_ditest_<?= $s['id'] ?>" class="form-control"
                               value="<?= $v['tgl_ditest'] ?>" onchange="hitungUmur(<?= $s['id'] ?>)">
                    </div>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Umur Beton (Hari)</label>
                        <input type="number" name="umur_hari" id="umur_<?= $s['id'] ?>" class="form-control"
                               readonly style="background:#f1f5f9;" value="<?= htmlspecialchars((string) $v['umur_hari']) ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">CODE</label>
                        <input type="text" name="kode_beton" class="form-control" placeholder="Contoh: CD-001"
                               value="<?= htmlspecialchars($v['kode_beton']) ?>">
                    </div>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Diameter (D) (Cm)</label>
                        <input type="number" step="0.01" name="diameter" class="form-control"
                               value="<?= htmlspecialchars((string) $v['diameter']) ?>">
                    </div>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Tinggi (L) (Cm)</label>
                        <input type="number" step="0.01" name="tinggi_l" class="form-control"
                               value="<?= htmlspecialchars((string) $v['tinggi_l']) ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Luas Penampang (A) (Cm)</label>
                        <input type="number" step="0.01" name="luas_penampang" class="form-control"
                               value="<?= htmlspecialchars((string) $v['luas_penampang']) ?>">
                    </div>
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Berat (G) (Kg)</label>
                            <input type="number" step="0.001" name="berat_kg" class="form-control"
                                   value="<?= htmlspecialchars((string) $v['berat_kg']) ?>">
                        </div>
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Beban (P) (Kg)</label>
                            <input type="number" step="0.001" name="beban_maksimum" class="form-control"
                                   value="<?= htmlspecialchars((string) $v['beban_maksimum']) ?>">
                        </div>
                </div>
                <div class="row">
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">L / D</label>
                        <input type="number" step="0.0001" name="rasio_ld" class="form-control" placeholder="Diisi manual"
                               value="<?= htmlspecialchars((string) $v['rasio_ld']) ?>">
                    </div>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Faktor Koreksi (C)</label>
                        <input type="number" step="0.0001" name="faktor_koreksi" class="form-control" placeholder="Diisi manual"
                               value="<?= htmlspecialchars((string) $v['faktor_koreksi']) ?>">
                    </div>
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Tegangan Benda Uji &phi; 4&quot; (Kg/Cm&sup2;)</label>
                            <input type="number" step="0.001" name="tegangan_benda_uji" class="form-control" placeholder="Diisi manual"
                                   value="<?= htmlspecialchars((string) $v['tegangan_benda_uji']) ?>">
                        </div>
                </div>
                    <div class="row">
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Konversi Tegangan Utk Silider 6&quot; (Kg/Cm&sup2;)</label>
                            <input type="number" step="0.001" name="konversi_silider6" class="form-control" placeholder="Diisi manual"
                                   value="<?= htmlspecialchars((string) $v['konversi_silider6']) ?>">
                        </div>
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Konversi Tegangan Untuk Kubus 15x15x15 (Kg/Cm&sup2;)</label>
                            <input type="number" step="0.001" name="konversi_kubus15" class="form-control" placeholder="Diisi manual"
                                   value="<?= htmlspecialchars((string) $v['konversi_kubus15']) ?>">
                        </div>
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Konversi Umur Beton Ke 28 Hari (Kg/Cm&sup2;)</label>
                            <input type="number" step="0.001" name="konversi_umur28" class="form-control" placeholder="Diisi manual"
                                   value="<?= htmlspecialchars((string) $v['konversi_umur28']) ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Tegangan (Mpa)</label>
                            <input type="number" step="0.01" name="mpa" class="form-control" placeholder="Diisi manual"
                                   value="<?= htmlspecialchars((string) ($v['mpa'] ?? '')) ?>">
                        </div>
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Konversi Umur ke 28 Hari (Mpa)</label>
                            <input type="number" step="0.01" name="konversi_umur28_mpa" class="form-control" placeholder="Diisi manual"
                                   value="<?= htmlspecialchars((string) ($v['konversi_umur28_mpa'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label">Keterangan</label>
                        <input type="text" name="keterangan" class="form-control" placeholder="Keterangan uji"
                               value="<?= htmlspecialchars((string) $v['catatan_teknisi']) ?>">
                    </div>
                </div>
                <?php elseif ($isLentur): ?>
                <!-- Uji Lentur / Flexural Balok Beton -->
                <div class="row">
                    <div class="col-sm-6 mb-3">
                        <label class="form-label">Tgl. Di Cor</label>
                        <input type="date" name="tgl_cor" id="tgl_cor_<?= $s['id'] ?>" class="form-control"
                               value="<?= $v['tgl_cor'] ?>" onchange="hitungUmur(<?= $s['id'] ?>)">
                    </div>
                    <div class="col-sm-6 mb-3">
                        <label class="form-label">Tgl. Di Test</label>
                        <input type="date" name="tgl_ditest" id="tgl_ditest_<?= $s['id'] ?>" class="form-control"
                               value="<?= $v['tgl_ditest'] ?>" onchange="hitungUmur(<?= $s['id'] ?>)">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-6 mb-3">
                        <label class="form-label">Umur (Hari)</label>
                        <input type="number" name="umur_hari" id="umur_<?= $s['id'] ?>" class="form-control"
                               readonly style="background:#f1f5f9;" value="<?= htmlspecialchars((string) $v['umur_hari']) ?>">
                    </div>
                    <div class="col-sm-6 mb-3">
                        <label class="form-label">Mutu</label>
                        <input type="text" name="mutu_beton" class="form-control" placeholder="Diisi manual"
                               value="<?= htmlspecialchars((string) $v['mutu_beton']) ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-6 mb-3">
                        <label class="form-label">Kode</label>
                        <input type="text" name="kode_beton" class="form-control" placeholder="Contoh: KB-001"
                               value="<?= htmlspecialchars($v['kode_beton']) ?>">
                    </div>
                    <div class="col-sm-6 mb-3">
                        <label class="form-label">Bentang L (Cm)</label>
                        <input type="number" step="0.01" name="bentang_l" class="form-control"
                               value="<?= htmlspecialchars((string) $v['bentang_l']) ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Dimensi b (Cm)</label>
                        <input type="number" step="0.01" name="dimensi_b" class="form-control"
                               value="<?= htmlspecialchars((string) $v['dimensi_b']) ?>">
                    </div>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Dimensi h (Cm)</label>
                        <input type="number" step="0.01" name="dimensi_h" class="form-control"
                               value="<?= htmlspecialchars((string) $v['dimensi_h']) ?>">
                    </div>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Beban (P) (Kg)</label>
                        <input type="number" step="0.01" name="beban_maksimum" class="form-control"
                               value="<?= htmlspecialchars((string) $v['beban_maksimum']) ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">W 1/6.b.h&sup2; (Cm3)</label>
                        <input type="number" step="0.0001" name="modulus_seksi" class="form-control"
                               placeholder="Diisi manual"
                               value="<?= htmlspecialchars((string) $v['modulus_seksi']) ?>">
                    </div>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Momen Lentur M=1/6.P.L (KgCm)</label>
                        <input type="number" step="0.01" name="momen_lentur" class="form-control"
                               placeholder="Diisi manual"
                               value="<?= htmlspecialchars((string) $v['momen_lentur']) ?>">
                    </div>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Tegangan Lentur (M/W) (Kg/cm&sup2;)</label>
                        <input type="number" step="0.01" name="tegangan_lentur" class="form-control"
                               placeholder="Diisi manual"
                               value="<?= htmlspecialchars((string) $v['tegangan_lentur']) ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label">Keterangan</label>
                        <input type="text" name="keterangan" class="form-control" placeholder="Keterangan uji"
                               value="<?= htmlspecialchars((string) $v['catatan_teknisi']) ?>">
                    </div>
                </div>
                <?php else: ?>
                <?php if ($isPaving): ?>
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label">Obyek</label>
                        <input type="text" name="objek" class="form-control" placeholder="misal: Paving ( 10 x 20cm )"
                               value="<?= htmlspecialchars($v['objek']) ?>">
                    </div>
                </div>
                <?php endif; ?>
                <div class="row">
                    <div class="col-sm-6 mb-3">
                        <label class="form-label"><?= $isPaving ? 'Tgl. Di Buat' : 'Tanggal Cor' ?></label>
                        <input type="date" name="tgl_cor" id="tgl_cor_<?= $s['id'] ?>" class="form-control"
                               value="<?= $v['tgl_cor'] ?>" onchange="hitungUmur(<?= $s['id'] ?>)">
                    </div>
                    <div class="col-sm-6 mb-3">
                        <label class="form-label"><?= $isPaving ? 'Tgl. Ditest' : 'Tanggal Dites' ?></label>
                        <input type="date" name="tgl_ditest" id="tgl_ditest_<?= $s['id'] ?>" class="form-control"
                               value="<?= $v['tgl_ditest'] ?>" onchange="hitungUmur(<?= $s['id'] ?>)">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-6 mb-3">
                        <label class="form-label"><?= $isPaving ? 'Umur/Hari' : 'Umur (Hari)' ?></label>
                        <input type="number" name="umur_hari" id="umur_<?= $s['id'] ?>" class="form-control"
                               readonly style="background:#f1f5f9;" value="<?= htmlspecialchars((string) $v['umur_hari']) ?>">
                    </div>
                    <div class="col-sm-6 mb-3">
                        <label class="form-label">Berat (kg)</label>
                        <input type="number" step="0.01" name="berat_kg" class="form-control"
                               placeholder="Berat saat ditimbang"
                               value="<?= htmlspecialchars((string) $v['berat_kg']) ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-6 mb-3">
                        <label class="form-label"><?= $isPaving ? 'Kode' : 'Kode Beton' ?></label>
                        <input type="text" name="kode_beton" class="form-control" placeholder="Contoh: KB-001"
                               value="<?= htmlspecialchars($v['kode_beton']) ?>">
                    </div>
                    <div class="col-sm-6 mb-3">
                        <label class="form-label">Beban Maksimum (Kg)</label>
                        <input type="number" step="0.01" name="beban_maksimum" class="form-control"
                               value="<?= htmlspecialchars((string) $v['beban_maksimum']) ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Tegangan Tekan (Kg/cm&sup2;)</label>
                        <input type="number" step="0.01" name="tegangan_tekan" class="form-control"
                               value="<?= htmlspecialchars((string) $v['tegangan_tekan']) ?>">
                    </div>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Tegangan Tekan 28 Hari (Kg/cm&sup2;)</label>
                        <input type="number" step="0.01" name="tegangan_tekan_28_hari" class="form-control"
                               value="<?= htmlspecialchars((string) $v['tegangan_tekan_28_hari']) ?>">
                    </div>
                    <?php if ($isKubus): ?>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Keterangan</label>
                        <input type="text" name="keterangan" class="form-control" placeholder="Keterangan uji"
                               value="<?= htmlspecialchars((string) $v['catatan_teknisi']) ?>">
                    </div>
                    <?php elseif ($isPaving): ?>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">Rata-rata (Kg/cm&sup2;)</label>
                        <input type="number" step="0.01" name="rata_rata" class="form-control"
                               placeholder="Diisi manual"
                               value="<?= htmlspecialchars((string) $v['rata_rata']) ?>">
                    </div>
                    <?php else: ?>
                    <div class="col-sm-4 mb-3">
                        <label class="form-label">MPa</label>
                        <input type="number" step="0.01" name="mpa" class="form-control"
                               value="<?= htmlspecialchars((string) $v['mpa']) ?>">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <button type="submit" name="simpan_hasil" class="btn btn-primary w-100 py-2">Simpan Hasil Pengujian</button>
            </form>

            <?php if (!empty($hasilPerSampel[$s['id']])): ?>
            <div class="mt-3">
                <small class="text-muted d-block mb-2">Hasil yang sudah diinput:</small>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover mb-0" style="font-size:0.75rem;">
                        <?= tabelBetonThead($isKubus, $isPaving, $isLentur, $isCore) ?>
                        <tbody>
                            <?php $noS = 0; foreach ($hasilPerSampel[$s['id']] as $h): $noS++; ?>
                            <?= tabelBetonRow($isKubus, $h, $noS, null, $isPaving, $isLentur, $isCore) ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
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
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                    <input type="hidden" name="sampel_id" value="<?= $s['id'] ?>">

                    <?php if (($booking['kategori'] ?? 'beton') === 'besi'): ?>
                    <div class="row">
                        <div class="col-sm-12 mb-3">
                            <label class="form-label">Obyek <span class="text-danger">*</span></label>
                            <input type="text" name="objek" class="form-control" required
                                   placeholder="Obyek pengujian besi (misal: Pondasi Tower Leg A)">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Tanggal Dites</label>
                            <input type="date" name="tgl_ditest" class="form-control" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Kode Benda Uji <span class="text-danger">*</span></label>
                            <input type="text" name="kode_benda_uji" class="form-control" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Diameter D0 (mm)</label>
                            <input type="number" step="0.01" name="diameter" class="form-control">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Diameter D0u (mm)</label>
                            <input type="number" step="0.01" name="diameter_akhir" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Luas Penampang A0 (mm&sup2;)</label>
                            <input type="number" step="0.01" name="luas_penampang" class="form-control">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Luas Penampang Au (mm&sup2;)</label>
                            <input type="number" step="0.01" name="luas_penampang_akhir" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Beban Tarik Leleh (kN)</label>
                            <input type="number" step="0.01" name="beban_tarik_leleh" class="form-control">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Beban Tarik Maks (kN)</label>
                            <input type="number" step="0.01" name="beban_maksimum" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Beban Tarik Leleh (MPa)</label>
                            <input type="number" step="0.01" name="tegangan_leleh" class="form-control">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Beban Tarik Maks (MPa)</label>
                            <input type="number" step="0.01" name="tegangan_tarik" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Panjang Ulur Awal L0 (mm)</label>
                            <input type="number" step="0.01" name="panjang_awal" class="form-control">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Panjang Ulur Akhir Lu (mm)</label>
                            <input type="number" step="0.01" name="panjang_akhir" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Regangan Maks (%)</label>
                            <input type="number" step="0.01" name="perpanjangan" class="form-control">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Kontraksi (%)</label>
                            <input type="number" step="0.01" name="reduksi_penampang" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Keterangan</label>
                        <input type="text" name="keterangan" class="form-control" placeholder="Keterangan uji, misal: Uji Tek-uk">
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                        <label class="form-label">Tanggal Cor</label>
                        <input type="date" name="tgl_cor" id="tgl_cor_<?= $s['id'] ?>" class="form-control" onchange="hitungUmur(<?= $s['id'] ?>)">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Tanggal Dites</label>
                            <input type="date" name="tgl_ditest" id="tgl_ditest_<?= $s['id'] ?>" class="form-control" onchange="hitungUmur(<?= $s['id'] ?>)">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                        <label class="form-label">Umur (Hari)</label>
                        <input type="number" name="umur_hari" id="umur_<?= $s['id'] ?>" class="form-control" readonly style="background: #f1f5f9;">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Berat (kg)</label>
                            <input type="number" step="0.01" name="berat_kg" class="form-control" placeholder="Berat saat ditimbang">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Kode Beton</label>
                            <input type="text" name="kode_beton" class="form-control" placeholder="Contoh: KB-001">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Mutu Beton</label>
                            <?php if (!empty($booking['mutu_beton'])): ?>
                            <input type="hidden" name="mutu_beton" value="<?= htmlspecialchars($booking['mutu_beton']) ?>">
                            <div class="form-control fw-semibold" style="background:#f1f5f9;"><?= htmlspecialchars($booking['mutu_beton']) ?></div>
                            <small class="text-muted"><i class="bi bi-lock"></i> Dipilih pelanggan saat booking.</small>
                            <?php else: ?>
                            <select name="mutu_beton" class="form-select" required>
                                <option value="">-- Pilih Mutu --</option>
                                <option value="K-175">K-175</option>
                                <option value="K-200">K-200</option>
                                <option value="K-225">K-225</option>
                                <option value="K-250">K-250</option>
                                <option value="K-275">K-275</option>
                                <option value="K-300">K-300</option>
                                <option value="K-350">K-350</option>
                                <option value="K-400">K-400</option>
                                <option value="K-450">K-450</option>
                                <option value="K-500">K-500</option>
                            </select>
                            <small class="text-muted">Belum ditentukan saat booking, pilih manual.</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Parameter Uji</label>
                        <input type="text" name="parameter_uji" class="form-control" required placeholder="Contoh: Kuat Tekan, Slump">
                    </div>
                    <div class="row">
                        <div class="col-sm-8 mb-3">
                            <label class="form-label">Nilai Hasil</label>
                            <input type="text" name="nilai_hasil" class="form-control" required placeholder="Contoh: 285">
                        </div>
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Satuan</label>
                            <input type="text" name="satuan" class="form-control" placeholder="kg/cm²">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Beban Maksimum</label>
                            <input type="number" step="0.01" name="beban_maksimum" class="form-control" placeholder="Manual input">
                        </div>
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Tegangan Tekan</label>
                            <input type="number" step="0.01" name="tegangan_tekan" class="form-control" placeholder="Manual input">
                        </div>
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Tegangan Tekan 28 Hari</label>
                            <input type="number" step="0.01" name="tegangan_tekan_28_hari" class="form-control" placeholder="Manual input">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-4 mb-3">
                            <label class="form-label">Mpa</label>
                            <input type="number" step="0.01" name="mpa" class="form-control" placeholder="Manual input">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kesimpulan</label>
                        <select name="kesimpulan" class="form-select" required>
                            <option value="">-- Pilih --</option>
                            <option value="memenuhi_syarat">Memenuhi Syarat</option>
                            <option value="tidak_memenuhi_syarat">Tidak Memenuhi Syarat</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <button type="submit" name="simpan_hasil" class="btn btn-primary w-100 py-2">Simpan Hasil Pengujian</button>
                </form>

                <?php if (!empty($hasilPerSampel[$s['id']])): ?>
                <div class="mt-3">
                    <small class="text-muted d-block mb-2">Hasil yang sudah diinput:</small>
                    <div class="table-responsive">
                        <?php if (($booking['kategori'] ?? 'beton') === 'besi'): ?>
                        <table class="table table-sm table-bordered table-hover mb-0" style="font-size:0.75rem;">
                            <?= fieldBesiThead() ?>
                            <tbody>
                                <?php $noBesi = 0; foreach ($hasilPerSampel[$s['id']] as $h): $noBesi++; ?>
                                <?= fieldBesiRow($h, $noBesi) ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style="font-size:0.75rem;">Parameter</th>
                                    <th style="font-size:0.75rem;">Tgl Cor</th>
                                    <th style="font-size:0.75rem;">Tgl Dites</th>
                                    <th style="font-size:0.75rem;">Umur</th>
                                    <th style="font-size:0.75rem;">Mutu</th>
                                    <th style="font-size:0.75rem;">Teg. Tekan</th>
                                    <th style="font-size:0.75rem;">Teg. 28hr</th>
                                    <th style="font-size:0.75rem;">Nilai</th>
                                    <th style="font-size:0.75rem;">Kesimpulan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hasilPerSampel[$s['id']] as $h): ?>
                                <tr>
                                    <td><strong style="font-size:0.8rem;"><?= htmlspecialchars($h['parameter_uji']) ?></strong></td>
                                    <td style="font-size:0.8rem;"><?= $h['tgl_cor'] ? date('d-m-Y', strtotime($h['tgl_cor'])) : '-' ?></td>
                                    <td style="font-size:0.8rem;"><?= $h['tgl_ditest'] ? date('d-m-Y', strtotime($h['tgl_ditest'])) : '-' ?></td>
                                    <td style="font-size:0.8rem;"><?= $h['umur_hari'] ? $h['umur_hari'] . ' hr' : '-' ?></td>
                                    <td style="font-size:0.8rem;"><?= htmlspecialchars($h['mutu_beton'] ?? '-') ?></td>
                                    <td style="font-size:0.8rem;"><?= $h['tegangan_tekan'] ?? '-' ?></td>
                                    <td style="font-size:0.8rem;"><?= $h['tegangan_tekan_28_hari'] ?? '-' ?></td>
                                    <td style="font-size:0.8rem;"><?= htmlspecialchars($h['nilai_hasil']) ?> <?= htmlspecialchars($h['satuan']) ?></td>
                                    <td>
                                        <?php if ($h['kesimpulan'] === 'memenuhi_syarat'): ?>
                                            <span class="badge bg-success" style="font-size:0.7rem;">Memenuhi Syarat</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger" style="font-size:0.7rem;">Tidak Memenuhi Syarat</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
<?php endforeach; ?>
<?php endif; ?>

<form method="POST" id="formTerbitkan">
            <?= csrf_field() ?>
            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
            <input type="hidden" name="nomor_laporan" id="nomor_laporan_hidden">
            <button type="submit" name="terbitkan_laporan" class="btn btn-success w-100 py-2"
                    onclick="syncNomorLaporan(); return confirm('Terbitkan laporan untuk booking ini? Semua sampel harus sudah diinput nilainya.')">
                Terbitkan Laporan & Lanjut ke Pembayaran
            </button>
            <small class="text-muted d-block mt-2 text-center">Pelanggan akan diminta mengunggah bukti transfer pembayaran.</small>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function hitungUmur(id) {
        var tglCor = document.getElementById('tgl_cor_' + id);
        var tglDites = document.getElementById('tgl_ditest_' + id);
        var umurField = document.getElementById('umur_' + id);
        if (tglCor.value && !tglDites.value) {
            var today = new Date();
            var yyyy = today.getFullYear();
            var mm = String(today.getMonth() + 1).padStart(2, '0');
            var dd = String(today.getDate()).padStart(2, '0');
            tglDites.value = yyyy + '-' + mm + '-' + dd;
        }
        if (tglCor.value && tglDites.value) {
            var d1 = new Date(tglCor.value);
            var d2 = new Date(tglDites.value);
            var diff = Math.abs(d2 - d1);
            var hari = Math.ceil(diff / (1000 * 60 * 60 * 24));
            umurField.value = hari;
        } else {
            umurField.value = '';
        }
    }
    function syncNomorLaporan() {
        document.getElementById('nomor_laporan_hidden').value = document.getElementById('nomor_laporan_display').value;
    }
    </script>
</body>
</html>
