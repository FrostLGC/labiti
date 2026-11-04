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

$semuaPelanggan = $pdo->query("SELECT id, nama_lengkap, nama_perusahaan, email FROM users WHERE role = 'pelanggan' ORDER BY nama_lengkap")->fetchAll();

$daftarJenis = $pdo->query("SELECT id, nama_pengujian, kategori FROM jenis_pengujian WHERE status_aktif = 1 ORDER BY kategori, nama_pengujian")->fetchAll();

$userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);

$isSilinderAwal = false;
$isKubusAwal = false;
$isPavingAwal = false;
$isLenturAwal = false;
$isCoreAwal = false;
$isTabelBetonAwal = false;
$isBesiAwal = false;
$modeLabelAwal = 'Silinder';
$judulModeAwal = 'Data Uji Tekan Silinder';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['jenis_pengujian_id'])) {
    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    $stmtAwal = $pdo->prepare("SELECT nama_pengujian, kategori FROM jenis_pengujian WHERE id = ? AND status_aktif = 1");
    $stmtAwal->execute([(int) $_POST['jenis_pengujian_id']]);
    $rowAwal = $stmtAwal->fetch();
    $isBesiAwal = $rowAwal ? ($rowAwal['kategori'] === 'besi') : false;
    $isSilinderAwal = $rowAwal ? isUjiSilinder($rowAwal['nama_pengujian']) : false;
    $isKubusAwal = $rowAwal ? isUjiKubus($rowAwal['nama_pengujian']) : false;
    $isPavingAwal = $rowAwal ? isUjiPaving($rowAwal['nama_pengujian']) : false;
    $isLenturAwal = $rowAwal ? isUjiLentur($rowAwal['nama_pengujian']) : false;
    $isCoreAwal = $rowAwal ? isUjiCoreDrill($rowAwal['nama_pengujian']) : false;
    $isTabelBetonAwal = $isSilinderAwal || $isKubusAwal || $isPavingAwal || $isLenturAwal || $isCoreAwal;
    $modeLabelAwal = ($isCoreAwal || $isLenturAwal)
        ? ($isCoreAwal ? fieldCoreShortLabel($rowAwal['nama_pengujian']) : fieldLenturShortLabel($rowAwal['nama_pengujian']))
        : ($isPavingAwal
            ? fieldPavingShortLabel($rowAwal['nama_pengujian'])
            : ($isKubusAwal ? 'Kubus' : 'Silinder'));
    $judulModeAwal = $isCoreAwal
        ? 'Data Uji Tekan Core Drill'
        : ($isLenturAwal
            ? 'Data Uji Lentur / Flexural Balok Beton'
            : 'Data Uji Tekan ' . $modeLabelAwal);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan'])) {
    foreach (['user_id','catatan_admin','supplier','proyek','lokasi','nomor_laporan','jenis_pengujian_id'] as $kPost) {
        if (isset($_POST[$kPost]) && is_array($_POST[$kPost])) { $_POST[$kPost] = reset($_POST[$kPost]) ?: ''; }
    }
    $uid = (int) ($_POST['user_id'] ?? 0);

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    // Keterangan = catatan_teknisi pada tabel laporan Uji Besi
    $catatanAdmin = trim($_POST['catatan_admin'] ?? '');
    $supplier = trim($_POST['supplier'] ?? '');
    $proyek = trim($_POST['proyek'] ?? '');
    $lokasi = trim($_POST['lokasi'] ?? '');
    $objek = trim($_POST['objek'] ?? '');

    $jenisPengujian = '';
    $kategori = 'beton';
    $isBesi = false;
    $jenisId = (int) ($_POST['jenis_pengujian_id'] ?? 0);
    $stmtJenis = $pdo->prepare("SELECT nama_pengujian, kategori FROM jenis_pengujian WHERE id = ? AND status_aktif = 1");
    $stmtJenis->execute([$jenisId]);
    $jenisTerpilih = $stmtJenis->fetch();
    if ($jenisTerpilih) {
        $kategori = $jenisTerpilih['kategori'];
        $isBesi = ($kategori === 'besi');
        $jenisPengujian = $jenisTerpilih['nama_pengujian'];
    }
    $isSilinder = isUjiSilinder($jenisPengujian);
    $isKubus = isUjiKubus($jenisPengujian);
    $isPaving = isUjiPaving($jenisPengujian);
    $isLentur = isUjiLentur($jenisPengujian);
    $isCore = isUjiCoreDrill($jenisPengujian);
    $isTabelBeton = $isSilinder || $isKubus || $isPaving || $isLentur || $isCore;

    $dokumentasiPath = null;
    if (isset($_FILES['dokumentasi']) && ($_FILES['dokumentasi']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $ext = strtolower(pathinfo($_FILES['dokumentasi']['name'], PATHINFO_EXTENSION));
        if ($_FILES['dokumentasi']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Upload dokumentasi gagal (kemungkinan file terlalu besar). Maksimal 5MB.';
        } elseif ($_FILES['dokumentasi']['size'] > 5 * 1024 * 1024) {
            $error = 'Ukuran file dokumentasi maksimal 5MB.';
        } elseif (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $error = 'Format dokumentasi harus JPG atau PNG.';
        } elseif (@getimagesize($_FILES['dokumentasi']['tmp_name']) === false) {
            $error = 'Isi file dokumentasi bukan gambar yang valid.';
        } else {
            $namaBaru = 'dokumentasi_' . time() . '_' . uniqid() . '.' . $ext;
            $folderTujuan = '../uploads/dokumentasi';
            if (!is_dir($folderTujuan)) mkdir($folderTujuan, 0777, true);
            if (move_uploaded_file($_FILES['dokumentasi']['tmp_name'], $folderTujuan . '/' . $namaBaru)) {
                $dokumentasiPath = 'uploads/dokumentasi/' . $namaBaru;
            } else {
                $error = 'Gagal mengunggah dokumen dokumentasi.';
            }
        }
    }

    $kode = trim($_POST['nomor_laporan'] ?? '');
    if (empty($kode)) {
        $error = 'Nomor laporan wajib diisi.';
    } else {
        $cekKode = $pdo->prepare("SELECT id FROM hasil_pengujian WHERE kode = ?");
        $cekKode->execute([$kode]);
        if ($cekKode->fetch()) {
            $error = 'Nomor laporan "' . htmlspecialchars($kode) . '" sudah digunakan.';
        }
    }

    if (empty($error) && !$jenisTerpilih) {
        $error = 'Jenis pengujian wajib dipilih.';
    }

    if (empty($error) && empty($supplier)) {
        $error = 'Supplier wajib diisi.';
    }

    if (empty($error) && $proyek === '') {
        $error = 'Proyek wajib diisi.';
    }

    if (empty($error) && $lokasi === '') {
        $error = 'Lokasi wajib diisi.';
    }

    if (empty($error)) {
        if ($uid <= 0) {
            $error = 'Pilih pelanggan terlebih dahulu.';
        } else {
            $cekPelanggan = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'pelanggan'");
            $cekPelanggan->execute([$uid]);
            if (!$cekPelanggan->fetch()) {
                $error = 'Pelanggan tidak ditemukan.';
            }
        }
    }
    if ($isBesi && $objek === '') {
        $error = 'Obyek wajib diisi untuk pengujian besi.';
    }

    $fieldList = ['parameter_uji','nilai_hasil','satuan','kesimpulan','berat_kg','beban_maksimum',
        'tgl_ditest','tgl_cor','kode_beton','mutu_beton','tegangan_tekan','tegangan_tekan_28_hari',
        'mpa','diameter','kode_benda_uji','diameter_akhir','luas_penampang','luas_penampang_akhir',
        'beban_tarik_leleh','tegangan_tarik','tegangan_leleh','panjang_awal','panjang_akhir',
        'perpanjangan','reduksi_penampang','keterangan','rata_rata','tinggi_l','rasio_ld',
        'faktor_koreksi','tegangan_benda_uji','konversi_silider6','konversi_kubus15','konversi_umur28',
        'konversi_umur28_mpa',
        'bentang_l','dimensi_b','dimensi_h','modulus_seksi','momen_lentur','tegangan_lentur',
        'catatan_teknisi','dokumentasi_file'];
    foreach ($fieldList as $kPost) {
        if (isset($_POST[$kPost]) && !is_array($_POST[$kPost])) { $_POST[$kPost] = [$_POST[$kPost]]; }
    }
    $parameterList = $_POST['parameter_uji'] ?? [];
    $nilaiList = $_POST['nilai_hasil'] ?? [];
    $satuanList = $_POST['satuan'] ?? [];
    $kesimpulanList = $_POST['kesimpulan'] ?? [];
    $beratList = $_POST['berat_kg'] ?? [];
    $bebanList = $_POST['beban_maksimum'] ?? [];
    $tglDitesList = $_POST['tgl_ditest'] ?? [];

    if ($kategori === 'beton') {
        $tglCorList = $_POST['tgl_cor'] ?? [];
        $kodeBetonList = $_POST['kode_beton'] ?? [];
        $mutuBetonList = $_POST['mutu_beton'] ?? [];
        $teganganList = $_POST['tegangan_tekan'] ?? [];
        $tegangan28List = $_POST['tegangan_tekan_28_hari'] ?? [];
        $mpaList = $_POST['mpa'] ?? [];
        $diameterList = $_POST['diameter'] ?? [];
    } else {
        $kodeBendaUjiList = $_POST['kode_benda_uji'] ?? [];
        $diameterList = $_POST['diameter'] ?? [];
        $diameterAkhirList = $_POST['diameter_akhir'] ?? [];
        $luasPenampangList = $_POST['luas_penampang'] ?? [];
        $luasPenampangAkhirList = $_POST['luas_penampang_akhir'] ?? [];
        $bebanTarikLelehList = $_POST['beban_tarik_leleh'] ?? [];
        $teganganTarikList = $_POST['tegangan_tarik'] ?? [];
        $teganganLelehList = $_POST['tegangan_leleh'] ?? [];
        $panjangAwalList = $_POST['panjang_awal'] ?? [];
        $panjangAkhirList = $_POST['panjang_akhir'] ?? [];
        $perpanjanganList = $_POST['perpanjangan'] ?? [];
        $reduksiList = $_POST['reduksi_penampang'] ?? [];
        $keteranganList = $_POST['keterangan'] ?? [];
    }

    foreach (['tgl_cor', 'tgl_ditest'] as $kTgl) {
        foreach ((array) ($_POST[$kTgl] ?? []) as $vTgl) {
            if ($vTgl !== '' && $vTgl !== null && tanggalManualKeYmd($vTgl) === null && empty($error)) {
                $error = 'Format tanggal tidak valid. Gunakan format dd-mm-yyyy.';
                break 2;
            }
        }
    }

    if (empty($error)) {
        $jumlahSimpan = 0;

        if ($isTabelBeton) {
            $rowTglCor   = $_POST['tgl_cor'] ?? [];
            $rowTglDites = $_POST['tgl_ditest'] ?? [];
            $rowBerat    = $_POST['berat_kg'] ?? [];
            $rowKode     = $_POST['kode_beton'] ?? [];
            $rowBeban    = $_POST['beban_maksimum'] ?? [];
            $rowTegTekan = $_POST['tegangan_tekan'] ?? [];
            $rowTeg28    = $_POST['tegangan_tekan_28_hari'] ?? [];
            $rowRata     = $_POST['rata_rata'] ?? [];
            $rowMpa      = $_POST['mpa'] ?? [];
            $rowMpa28    = $_POST['konversi_umur28_mpa'] ?? [];
            // Kubus: kolom terakhir tabel adalah Keterangan (catatan_teknisi)
            $rowKeterangan = $_POST['keterangan'] ?? [];
            // Uji Lentur / Flexural Balok Beton: semua nilai diisi manual admin
            $rowMutu       = $_POST['mutu_beton'] ?? [];
            $rowBentang    = $_POST['bentang_l'] ?? [];
            $rowDimB       = $_POST['dimensi_b'] ?? [];
            $rowDimH       = $_POST['dimensi_h'] ?? [];
            $rowModulus    = $_POST['modulus_seksi'] ?? [];
            $rowMomen      = $_POST['momen_lentur'] ?? [];
            $rowTegLentur  = $_POST['tegangan_lentur'] ?? [];
            // Uji Tekan Core Drill: seluruh kolom diisi manual admin sesuai format laporan
            $rowDiameter   = $_POST['diameter'] ?? [];
            $rowLuasCore   = $_POST['luas_penampang'] ?? [];
            $rowTinggi     = $_POST['tinggi_l'] ?? [];
            $rowRasioLd    = $_POST['rasio_ld'] ?? [];
            $rowFaktor     = $_POST['faktor_koreksi'] ?? [];
            $rowTegUji     = $_POST['tegangan_benda_uji'] ?? [];
            $rowKonvSil    = $_POST['konversi_silider6'] ?? [];
            $rowKonvKubus  = $_POST['konversi_kubus15'] ?? [];
            $rowKonvUmur   = $_POST['konversi_umur28'] ?? [];

            $jmlBaris = max(
                count($rowTglCor), count($rowTglDites), count($rowBerat), count($rowKode),
                count($rowBeban), count($rowTegTekan), count($rowTeg28), count($rowRata),
                count($rowMpa), count($rowMpa28), count($rowKeterangan), count($rowMutu), count($rowBentang),
                count($rowDimB), count($rowDimH), count($rowModulus), count($rowMomen),
                count($rowTegLentur), count($rowDiameter), count($rowLuasCore), count($rowTinggi),
                count($rowRasioLd), count($rowFaktor), count($rowTegUji), count($rowKonvSil),
                count($rowKonvKubus), count($rowKonvUmur)
            );

            if ($isCore) {
                $stmtTabel = $pdo->prepare(
                    "INSERT INTO hasil_pengujian (sampel_id, teknisi_id, user_id, supplier, proyek, lokasi, kode, jenis_pengujian, parameter_uji, tgl_cor, tgl_ditest, umur_hari, berat_kg, kode_beton, beban_maksimum, diameter, luas_penampang, tinggi_l, rasio_ld, faktor_koreksi, tegangan_benda_uji, konversi_silider6, konversi_kubus15, konversi_umur28, mpa, konversi_umur28_mpa, nilai_hasil, satuan, catatan_teknisi, dokumentasi_file, status_verifikasi)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 'Uji Tekan Core Drill', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'belum_diverifikasi')"
                );
            } elseif ($isLentur) {
                $stmtTabel = $pdo->prepare(
                    "INSERT INTO hasil_pengujian (sampel_id, teknisi_id, user_id, supplier, proyek, lokasi, kode, jenis_pengujian, parameter_uji, tgl_cor, tgl_ditest, umur_hari, berat_kg, kode_beton, mutu_beton, beban_maksimum, bentang_l, dimensi_b, dimensi_h, modulus_seksi, momen_lentur, tegangan_lentur, nilai_hasil, satuan, catatan_teknisi, dokumentasi_file, status_verifikasi)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 'Uji Lentur', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'belum_diverifikasi')"
                );
            } else {
                $stmtTabel = $pdo->prepare(
                    "INSERT INTO hasil_pengujian (sampel_id, teknisi_id, user_id, supplier, objek, proyek, lokasi, kode, jenis_pengujian, parameter_uji, tgl_cor, tgl_ditest, umur_hari, berat_kg, kode_beton, mutu_beton, beban_maksimum, tegangan_tekan, tegangan_tekan_28_hari, rata_rata, mpa, nilai_hasil, satuan, catatan_teknisi, dokumentasi_file, status_verifikasi)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'Kuat Tekan', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'belum_diverifikasi')"
                );
            }

            for ($i = 0; $i < $jmlBaris; $i++) {
                $tglCor   = tanggalManualKeYmd($rowTglCor[$i] ?? '');
                $tglDites = tanggalManualKeYmd($rowTglDites[$i] ?? '');
                $beratKg  = ($rowBerat[$i] ?? '') ?: null;
                $kodeBeton = trim($rowKode[$i] ?? '');
                $bebanMaks = ($rowBeban[$i] ?? '') ?: null;
                $tegTekan  = ($rowTegTekan[$i] ?? '') ?: null;
                $teg28     = ($rowTeg28[$i] ?? '') ?: null;
                $rataNilai = $isPaving ? (($rowRata[$i] ?? '') ?: null) : null;
                $mpaNilai  = ($isKubus || $isPaving || $isLentur || $isCore) ? null : (($rowMpa[$i] ?? '') ?: null);
                // Kubus/Keterangan: kolom terakhir tabel adalah Keterangan (catatan_teknisi)
                $keteranganBaris = (($isKubus || $isLentur || $isCore) ? (trim($rowKeterangan[$i] ?? '') ?: null) : null);
                $keteranganTabel = ($isKubus || $isLentur || $isCore) ? $keteranganBaris : ($catatanAdmin ?: null);
                $nilaiTabel = $isCore
                    ? ((($rowTegUji[$i] ?? '') ?: null))
                    : ($isLentur
                    ? ((($rowTegLentur[$i] ?? '') ?: null))
                    : ($isKubus
                        ? (($teg28 ?: $tegTekan) ?: null)
                        : ($isPaving ? (($rataNilai ?: ($teg28 ?: $tegTekan)) ?: null) : $mpaNilai)));
                $satuanTabel = $nilaiTabel !== null ? (($isKubus || $isPaving || $isLentur || $isCore) ? 'Kg/cm²' : 'MPa') : null;

                if ($isCore) {
                    $diameterCore  = ($rowDiameter[$i] ?? '') ?: null;
                    $luasCore      = ($rowLuasCore[$i] ?? '') ?: null;
                    $tinggiL       = ($rowTinggi[$i] ?? '') ?: null;
                    $rasioLd       = ($rowRasioLd[$i] ?? '') ?: null;
                    $faktorKoreksi = ($rowFaktor[$i] ?? '') ?: null;
                    $tegBendaUji   = ($rowTegUji[$i] ?? '') ?: null;
                    $konvSil     = ($rowKonvSil[$i] ?? '') ?: null;
                    $konvKubus   = ($rowKonvKubus[$i] ?? '') ?: null;
                    $konvUmur    = ($rowKonvUmur[$i] ?? '') ?: null;
                    $mpaCore     = ($rowMpa[$i] ?? '') ?: null;
                    $konvUmurMpa = ($rowMpa28[$i] ?? '') ?: null;
                    $mutuNilai = null; $bentangL = $dimensiB = $dimensiH = null;
                    $modulusSeksi = $momenLentur = $tegLentur = null;

                    if (!$tglCor && !$tglDites && !$beratKg && $kodeBeton === '' && !$bebanMaks
                        && !$diameterCore && !$luasCore && !$tinggiL && !$rasioLd && !$faktorKoreksi
                        && !$tegBendaUji && !$konvSil && !$konvKubus && !$konvUmur && !$mpaCore && !$konvUmurMpa && !$keteranganBaris) {
                        continue;
                    }
                } elseif ($isLentur) {
                    $mutuNilai   = trim($rowMutu[$i] ?? '');
                    $bentangL    = ($rowBentang[$i] ?? '') ?: null;
                    $dimensiB    = ($rowDimB[$i] ?? '') ?: null;
                    $dimensiH    = ($rowDimH[$i] ?? '') ?: null;
                    $modulusSeksi = ($rowModulus[$i] ?? '') ?: null;
                    $momenLentur = ($rowMomen[$i] ?? '') ?: null;
                    $tegLentur   = ($rowTegLentur[$i] ?? '') ?: null;

                    if (!$tglCor && !$tglDites && $kodeBeton === '' && !$bebanMaks && $mutuNilai === ''
                        && !$bentangL && !$dimensiB && !$dimensiH && !$modulusSeksi
                        && !$momenLentur && !$tegLentur && !$keteranganBaris) {
                        continue;
                    }
                } else {
                    $mutuNilai = trim($rowMutu[$i] ?? '');
                    $bentangL = $dimensiB = $dimensiH = null;
                    $modulusSeksi = $momenLentur = $tegLentur = null;

                    if (!$tglCor && !$tglDites && !$beratKg && $kodeBeton === '' && $mutuNilai === ''
                        && !$bebanMaks && !$tegTekan && !$teg28 && !$rataNilai && !$mpaNilai && !$keteranganBaris) {
                        continue;
                    }
                }

                $umurHari = null;
                if ($tglCor && $tglDites) {
                    $umurHari = (new DateTime($tglCor))->diff(new DateTime($tglDites))->days;
                }

                if ($isCore) {
                    $stmtTabel->execute([
                        $user['id'], $uid, $supplier, $proyek, $lokasi, $kode, $jenisPengujian,
                        $tglCor, $tglDites, $umurHari, $beratKg, $kodeBeton ?: null, $bebanMaks,
                        $diameterCore, $luasCore, $tinggiL, $rasioLd, $faktorKoreksi,
                        $tegBendaUji, $konvSil, $konvKubus, $konvUmur, $mpaCore, $konvUmurMpa,
                        $nilaiTabel, $satuanTabel, $keteranganTabel, $dokumentasiPath
                    ]);
                } elseif ($isLentur) {
                    $stmtTabel->execute([
                        $user['id'], $uid, $supplier, $proyek, $lokasi, $kode, $jenisPengujian,
                        $tglCor, $tglDites, $umurHari, $beratKg, $kodeBeton ?: null,
                        $mutuNilai ?: null, $bebanMaks,
                        $bentangL, $dimensiB, $dimensiH, $modulusSeksi, $momenLentur, $tegLentur,
                        $nilaiTabel, $satuanTabel, $keteranganTabel, $dokumentasiPath
                    ]);
                } else {
                    $stmtTabel->execute([
                        $user['id'], $uid, $supplier, $objek ?: null, $proyek, $lokasi, $kode, $jenisPengujian,
                        $tglCor, $tglDites, $umurHari, $beratKg, $kodeBeton ?: null,
                        $mutuNilai ?: null, $bebanMaks, $tegTekan, $teg28, $rataNilai, $mpaNilai,
                        $nilaiTabel, $satuanTabel, $keteranganTabel, $dokumentasiPath
                    ]);
                }
                $jumlahSimpan++;
            }

            if ($jumlahSimpan === 0) {
                $error = 'Minimal isi 1 baris data pengujian.';
            } else {
                $userId = $uid;
                header("Location: input_historis.php?user_id=$userId&msg=ok");
                exit;
            }
        } else {

        $totalBaris = $isBesi ? count($kodeBendaUjiList) : count($parameterList);

        for ($i = 0; $i < $totalBaris; $i++) {
        $param = trim($parameterList[$i] ?? '');
        $nilai = trim($nilaiList[$i] ?? '');
        $kodeBendaUji = trim($kodeBendaUjiList[$i] ?? '');
        if ($isBesi) {
            if ($kodeBendaUji === '') continue;
            $param = null;
            $nilai = null;
        } elseif ($param === '' && $nilai === '') {
            continue;
        }

        $tglCor = null; $tglDites = null; $umurHari = null;
        $berat = null; $diameter = null; $luasPenampang = null;
        $kodeBeton = null; $mutuBeton = null;
        $beban = null;         $tegTekan = null; $teg28 = null; $mpaBeton = null;
        $tegTarik = null; $tegLeleh = null; $perpanjangan = null; $reduksi = null;
        $diameterAkhir = null; $luasPenampangAkhir = null; $bebanTarikLeleh = null;
        $panjangAwal = null; $panjangAkhir = null; $keterangan = null;

        $berat = $beratList[$i] ?? null ?: null;
        $beban = $bebanList[$i] ?? null ?: null;

        if ($kategori === 'beton') {
            $tglCor = tanggalManualKeYmd($tglCorList[$i] ?? '');
            $tglDites = tanggalManualKeYmd($tglDitesList[$i] ?? '');
            if ($tglCor && $tglDites) {
                $umurHari = (new DateTime($tglCor))->diff(new DateTime($tglDites))->days;
            }
            $kodeBeton = trim($kodeBetonList[$i] ?? '') ?: null;
            $mutuBeton = $mutuBetonList[$i] ?? null ?: null;
            $diameter = $diameterList[$i] ?? null ?: null;
            $luasPenampang = $diameter ? round(M_PI * pow($diameter / 2, 2), 4) : null;
            $tegTekan = $teganganList[$i] ?? null ?: null;
            $teg28 = $tegangan28List[$i] ?? null ?: null;
            $mpaBeton = $mpaList[$i] ?? null ?: null;
        } else {
            $tglDites = tanggalManualKeYmd($tglDitesList[$i] ?? '');
            $diameter = $diameterList[$i] ?? null ?: null;
            $diameterAkhir = $diameterAkhirList[$i] ?? null ?: null;
            $luasPenampang = $luasPenampangList[$i] ?? null ?: null;
            $luasPenampangAkhir = $luasPenampangAkhirList[$i] ?? null ?: null;
            $bebanTarikLeleh = $bebanTarikLelehList[$i] ?? null ?: null;
            $tegTarik = $teganganTarikList[$i] ?? null ?: null;
            $tegLeleh = $teganganLelehList[$i] ?? null ?: null;
            $panjangAwal = $panjangAwalList[$i] ?? null ?: null;
            $panjangAkhir = $panjangAkhirList[$i] ?? null ?: null;
            $perpanjangan = $perpanjanganList[$i] ?? null ?: null;
            $reduksi = $reduksiList[$i] ?? null ?: null;
            $keterangan = trim($keteranganList[$i] ?? '') ?: null;
        }

        $pdo->prepare(
            "INSERT INTO hasil_pengujian (sampel_id, teknisi_id, user_id, supplier, objek, proyek, lokasi, kode, jenis_pengujian, kode_benda_uji, parameter_uji, tgl_cor, tgl_ditest, umur_hari, berat_kg, diameter, diameter_akhir, luas_penampang, luas_penampang_akhir, kode_beton, mutu_beton, beban_tarik_leleh, beban_maksimum, tegangan_tekan, tegangan_tekan_28_hari, mpa, tegangan_tarik, tegangan_leleh, panjang_awal, panjang_akhir, perpanjangan, reduksi_penampang, nilai_hasil, satuan, kesimpulan, dokumentasi_file, catatan_teknisi, status_verifikasi)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'belum_diverifikasi')"
        )->execute([
            $user['id'], $uid, $supplier, $isBesi ? $objek : null, $proyek, $lokasi, $kode, $jenisPengujian,
            $isBesi ? $kodeBendaUji : null,
            $param, $tglCor, $tglDites, $umurHari,
            $berat, $diameter, $diameterAkhir, $luasPenampang, $luasPenampangAkhir,
            $kodeBeton, $mutuBeton, $bebanTarikLeleh, $beban,
            $tegTekan, $teg28, $mpaBeton,
            $tegTarik, $tegLeleh, $panjangAwal, $panjangAkhir, $perpanjangan, $reduksi,
            $nilai, $satuanList[$i] ?? null, $kesimpulanList[$i] ?? null,
            $dokumentasiPath, $keterangan ?: $catatanAdmin,
        ]);
        $jumlahSimpan++;
    }

            if ($jumlahSimpan === 0) {
                $error = 'Minimal isi 1 baris data pengujian.';
            } else {
                $success = "$jumlahSimpan data historis berhasil disimpan.";
                $userId = $uid;
                header("Location: input_historis.php?user_id=$userId&msg=ok");
                exit;
            }
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'ok') $success = 'Data historis berhasil disimpan.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_historis'])) {
    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    $hapusId = (int) ($_POST['hapus_id'] ?? 0);
    $pdo->prepare("DELETE FROM hasil_pengujian WHERE id = ? AND sampel_id IS NULL AND user_id = ?")->execute([$hapusId, $userId]);
    header("Location: input_historis.php?user_id=$userId&msg=hapus");
    exit;
}
if (isset($_GET['msg']) && $_GET['msg'] === 'hapus') $success = 'Data historis berhasil dihapus.';

$dataHistoris = [];
if (!empty($userId)) {
    $stmtData = $pdo->prepare("SELECT * FROM hasil_pengujian WHERE user_id = ? AND sampel_id IS NULL ORDER BY id DESC");
    $stmtData->execute([$userId]);
    $dataHistoris = $stmtData->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Data Historis - Admin</title>
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

    <div class="page-container" style="max-width: 950px;">
        <a href="dashboard.php" class="text-decoration-none text-muted">&larr; Kembali ke Dashboard</a>

        <div class="page-header mt-2">
            <h4>Input Data Historis</h4>
            <p>Input hasil pengujian yang sudah dilakukan sebelumnya</p>
        </div>

        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong><i class="bi bi-person"></i> Pilih Pelanggan</strong></div>
            <div class="card-body">
                <form method="GET">
                    <select name="user_id" class="form-select" required onchange="this.form.submit()">
                        <option value="">-- Pilih Pelanggan --</option>
                        <?php foreach ($semuaPelanggan as $pg): ?>
                            <option value="<?= $pg['id'] ?>" <?= $userId == $pg['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pg['nama_lengkap']) ?>
                                <?php if ($pg['nama_perusahaan']): ?> — <?= htmlspecialchars($pg['nama_perusahaan']) ?><?php endif; ?>
                                (<?= htmlspecialchars($pg['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>

        <?php if (!empty($userId)): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong><i class="bi bi-pencil-square"></i> Input Hasil Pengujian</strong></div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="formHistoris">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">

                    <div class="row">
                        <div class="col-sm-2 mb-3">
                            <label class="form-label">Nomor Laporan <span class="text-danger">*</span></label>
                            <input type="text" name="nomor_laporan" class="form-control" required
                                   placeholder="Contoh: 001/LAB/UT" value="<?= htmlspecialchars($_POST['nomor_laporan'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Jenis Pengujian <span class="text-danger">*</span></label>
                            <select name="jenis_pengujian_id" id="jenisPengujianInput" class="form-select" required onchange="switchKategori()">
                                <option value="">-- Pilih Jenis Pengujian --</option>
                                <?php
                                $jenisTerpilihId = (int) ($_POST['jenis_pengujian_id'] ?? 0);
                                $kategoriSaatIni = '';
                                foreach ($daftarJenis as $dj):
                                    if ($kategoriSaatIni !== $dj['kategori']):
                                        if ($kategoriSaatIni !== '') echo '</optgroup>';
                                        echo '<optgroup label="' . strtoupper($dj['kategori']) . '">';
                                        $kategoriSaatIni = $dj['kategori'];
                                    endif;
                                ?>
                                    <option value="<?= $dj['id'] ?>" data-kategori="<?= $dj['kategori'] ?>" data-silinder="<?= (isUjiSilinder($dj['nama_pengujian']) || isUjiKubus($dj['nama_pengujian']) || isUjiPaving($dj['nama_pengujian']) || isUjiLentur($dj['nama_pengujian']) || isUjiCoreDrill($dj['nama_pengujian'])) ? '1' : '0' ?>" data-kubus="<?= isUjiKubus($dj['nama_pengujian']) ? '1' : '0' ?>" data-paving="<?= isUjiPaving($dj['nama_pengujian']) ? '1' : '0' ?>" data-lentur="<?= isUjiLentur($dj['nama_pengujian']) ? '1' : '0' ?>" data-core="<?= isUjiCoreDrill($dj['nama_pengujian']) ? '1' : '0' ?>" data-label="<?= htmlspecialchars(isUjiCoreDrill($dj['nama_pengujian']) ? fieldCoreShortLabel($dj['nama_pengujian']) : (isUjiLentur($dj['nama_pengujian']) ? fieldLenturShortLabel($dj['nama_pengujian']) : (isUjiPaving($dj['nama_pengujian']) ? fieldPavingShortLabel($dj['nama_pengujian']) : (isUjiKubus($dj['nama_pengujian']) ? 'Kubus' : 'Silinder')))) ?>"<?= $dj['id'] === $jenisTerpilihId ? ' selected' : '' ?>><?= htmlspecialchars($dj['nama_pengujian']) ?></option>
                                <?php endforeach; ?>
                                <?php if ($kategoriSaatIni !== '') echo '</optgroup>'; ?>
                            </select>
                        </div>
                        <div class="col-sm-2 mb-3">
                            <label class="form-label">Supplier <span class="text-danger">*</span></label>
                            <input type="text" name="supplier" class="form-control" required
                                   placeholder="Nama supplier" value="<?= htmlspecialchars($_POST['supplier'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Proyek <span class="text-danger">*</span></label>
                            <input type="text" name="proyek" class="form-control" required
                                   placeholder="Nama proyek" value="<?= htmlspecialchars($_POST['proyek'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Lokasi <span class="text-danger">*</span></label>
                            <input type="text" name="lokasi" class="form-control" required
                                   placeholder="Lokasi proyek" value="<?= htmlspecialchars($_POST['lokasi'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row" id="rowObjekBesi" style="<?= ($isBesiAwal || $isPavingAwal) ? '' : 'display:none;' ?>">
                        <div class="col-sm-12 mb-3">
                            <label class="form-label">Obyek <span class="text-danger" id="tandaWajibObjek"<?= $isBesiAwal ? '' : ' style="display:none;"' ?>>*</span></label>
                            <input type="text" name="objek" id="inputObjekBesi" class="form-control"
                                   placeholder="Obyek pengujian (misal: Paving ( 10 x 20cm ) / Pondasi Tower Leg A)" value="<?= htmlspecialchars($_POST['objek'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mb-3">
                        <div class="text-end" style="max-width:420px; width:100%;">
                            <label class="form-label mb-1" style="font-size:0.78rem;">Upload Foto Bukti Pengujian</label>
                            <input type="file" name="dokumentasi" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                            <small class="text-muted">Format JPG/PNG, maks 5MB &mdash; foto bukti ini akan tampil di laporan.</small>
                        </div>
                    </div>

                        <div id="modeSilinder" style="<?= $isTabelBetonAwal ? '' : 'display:none;' ?>">
                        <hr>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong><i class="bi bi-list-ol"></i> <span id="judulModeSilinder"><?= htmlspecialchars($judulModeAwal) ?></span></strong>
                            <button type="button" class="btn btn-sm btn-success" onclick="tambahBarisSilinder()">
                                <i class="bi bi-plus-lg"></i> Tambah Baris
                            </button>
                        </div>
                        <div id="containerSilinder">
                            <div class="baris-silinder card mb-3" data-index="0">
                                <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                                    <strong style="font-size:0.8125rem;">Data <?= htmlspecialchars($modeLabelAwal) ?> #1</strong>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="hapusBarisSilinder(this)" title="Hapus data"><i class="bi bi-trash"></i></button>
                                </div>
                                <div class="card-body py-2">
                                    <div class="row">
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;" data-silinder-text="Tanggal Cor" data-paving-text="Tgl. Di Buat" data-lentur-text="Tgl. Di Cor" data-core-text="Tanggal Pembuatan">Tanggal Cor</label>
                                            <input type="date" name="tgl_cor[]" class="form-control form-control-sm tgl-cor" onchange="hitungUmurSilinder(this)">
                                        </div>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;" data-silinder-text="Tanggal Pengujian" data-paving-text="Tgl. Ditest" data-lentur-text="Tgl. Di Test" data-core-text="Tanggal Test">Tanggal Pengujian</label>
                                            <input type="date" name="tgl_ditest[]" class="form-control form-control-sm tgl-dites" onchange="hitungUmurSilinder(this)">
                                        </div>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;" data-silinder-text="Umur (Hari)" data-paving-text="Umur/Hari" data-lentur-text="Umur (Hari)" data-core-text="Umur Beton (Hari)">Umur (Hari)</label>
                                            <input type="number" class="form-control form-control-sm umur-hari" readonly style="background:#f1f5f9;">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;" data-silinder-text="Kode Beton" data-paving-text="Kode" data-lentur-text="Kode" data-core-text="CODE">Kode Beton</label>
                                            <input type="text" name="kode_beton[]" class="form-control form-control-sm" placeholder="KB-001">
                                        </div>
                                        <div class="col-sm-4 mb-3 col-berat">
                                            <label class="form-label" style="font-size:0.75rem;" data-silinder-text="Berat (Kg)" data-paving-text="Berat (Kg)" data-lentur-text="Berat (Kg)" data-core-text="Berat (G) (Kg)">Berat (Kg)</label>
                                            <input type="number" step="0.001" name="berat_kg[]" class="form-control form-control-sm" placeholder="Berat">
                                        </div>
                                        <div class="col-sm-4 mb-3 col-mutu" style="display:none;">
                                            <label class="form-label" style="font-size:0.75rem;">Mutu</label>
                                            <input type="text" name="mutu_beton[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;" data-silinder-text="Beban Maksimum (Kg)" data-paving-text="Beban Maksimum (Kg)" data-lentur-text="Beban (P) (Kg)" data-core-text="Beban (P) (Kg)">Beban Maksimum (Kg)</label>
                                            <input type="number" step="0.01" name="beban_maksimum[]" class="form-control form-control-sm" placeholder="Input manual">
                                        </div>
                                        <div class="col-sm-4 mb-3 col-tegtek">
                                            <label class="form-label" style="font-size:0.75rem;">Tegangan Tekan (Kg/cm&sup2;)</label>
                                            <input type="number" step="0.01" name="tegangan_tekan[]" class="form-control form-control-sm" placeholder="Input manual">
                                        </div>
                                        <div class="col-sm-4 mb-3 col-teg28">
                                            <label class="form-label" style="font-size:0.75rem;">Teg. Tekan 28 Hari (Kg/cm&sup2;)</label>
                                            <input type="number" step="0.01" name="tegangan_tekan_28_hari[]" class="form-control form-control-sm" placeholder="Input manual">
                                        </div>
                                    </div>
                                    <div class="row row-lentur" style="display:none;">
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Bentang L (Cm)</label>
                                            <input type="number" step="0.01" name="bentang_l[]" class="form-control form-control-sm" placeholder="Input manual">
                                        </div>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Dimensi b (Cm)</label>
                                            <input type="number" step="0.01" name="dimensi_b[]" class="form-control form-control-sm" placeholder="Input manual">
                                        </div>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Dimensi h (Cm)</label>
                                            <input type="number" step="0.01" name="dimensi_h[]" class="form-control form-control-sm" placeholder="Input manual">
                                        </div>
                                    </div>
                                    <div class="row row-lentur" style="display:none;">
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">W 1/6.b.h&sup2; (Cm3)</label>
                                            <input type="number" step="0.0001" name="modulus_seksi[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Momen Lentur M=1/6.P.L (KgCm)</label>
                                            <input type="number" step="0.01" name="momen_lentur[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Tegangan Lentur (M/W) (Kg/cm&sup2;)</label>
                                            <input type="number" step="0.01" name="tegangan_lentur[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                    </div>
                                    <div class="row row-core"<?= $isCoreAwal ? '' : ' style="display:none;"' ?>>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Diameter (D) (Cm)</label>
                                            <input type="number" step="0.01" name="diameter[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Tinggi (L) (Cm)</label>
                                            <input type="number" step="0.01" name="tinggi_l[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Luas Penampang (A) (Cm)</label>
                                            <input type="number" step="0.01" name="luas_penampang[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                    </div>
                                    <div class="row row-core"<?= $isCoreAwal ? '' : ' style="display:none;"' ?>>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">L / D</label>
                                            <input type="number" step="0.0001" name="rasio_ld[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Faktor Koreksi (C)</label>
                                            <input type="number" step="0.0001" name="faktor_koreksi[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Tegangan Benda Uji &phi; 4&quot; (Kg/Cm&sup2;)</label>
                                            <input type="number" step="0.001" name="tegangan_benda_uji[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                    </div>
                                    <div class="row row-core"<?= $isCoreAwal ? '' : ' style="display:none;"' ?>>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Konversi Tegangan Utk Silider 6&quot; (Kg/Cm&sup2;)</label>
                                            <input type="number" step="0.001" name="konversi_silider6[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Konversi Tegangan Untuk Kubus 15x15x15 (Kg/Cm&sup2;)</label>
                                            <input type="number" step="0.001" name="konversi_kubus15[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Konversi Umur Beton Ke 28 Hari (Kg/Cm&sup2;)</label>
                                            <input type="number" step="0.001" name="konversi_umur28[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                    </div>
                                    <div class="row row-core"<?= $isCoreAwal ? '' : ' style="display:none;"' ?>>
                                        <div class="col-sm-4 mb-3">
                                            <label class="form-label" style="font-size:0.75rem;">Konversi Umur ke 28 Hari (Mpa)</label>
                                            <input type="number" step="0.01" name="konversi_umur28_mpa[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-sm-4 mb-3 row-mpa"<?= ($isKubusAwal || $isPavingAwal || $isLenturAwal) ? ' style="display:none;"' : '' ?>>
                                            <label class="form-label" style="font-size:0.75rem;">MPa</label>
                                            <input type="number" step="0.01" name="mpa[]" class="form-control form-control-sm" placeholder="Input manual">
                                        </div>
                                        <div class="col-sm-4 mb-3 row-rata"<?= $isPavingAwal ? '' : ' style="display:none;"' ?>>
                                            <label class="form-label" style="font-size:0.75rem;">Rata-rata (Kg/cm&sup2;)</label>
                                            <input type="number" step="0.01" name="rata_rata[]" class="form-control form-control-sm" placeholder="Diisi manual">
                                        </div>
                                        <div class="col-sm-4 mb-3 row-keterangan"<?= ($isKubusAwal || $isLenturAwal) ? '' : ' style="display:none;"' ?>>
                                            <label class="form-label" style="font-size:0.75rem;">Keterangan</label>
                                            <input type="text" name="keterangan[]" class="form-control form-control-sm" placeholder="Keterangan uji">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="modeBeton" style="<?= $isTabelBetonAwal ? 'display:none;' : '' ?>">
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong><i class="bi bi-list-ol"></i> Parameter Uji</strong>
                        <button type="button" class="btn btn-sm btn-success" onclick="tambahParameter()">
                            <i class="bi bi-plus-lg"></i> Tambah Label
                        </button>
                    </div>

                    <div id="parameterContainer">
                        <div class="parameter-row card mb-3" data-index="0">
                            <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                                <strong style="font-size:0.8125rem;">Parameter #1</strong>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="hapusParameter(this)" title="Hapus parameter"><i class="bi bi-trash"></i></button>
                            </div>
                            <div class="card-body py-2 parameter-body" id="parameterBody"></div>
                        </div>
                    </div>
                    </div>

                    <button type="submit" name="simpan" class="btn btn-primary w-100 py-2" onclick="return confirm('Simpan data historis?')">
                        <i class="bi bi-save"></i> Simpan Semua
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($dataHistoris)): ?>
        <?php
        $grupHistoris = [];
        foreach ($dataHistoris as $dh) {
            $grupHistoris[$dh['kode'] ?? '(Tanpa Kode)'][] = $dh;
        }
        $noPengujian = 0;
        ?>
        <style>
            .grp-header { cursor:pointer; transition:background .15s; }
            .grp-header:hover { background:#f8fafc; }
            .grp-header .chev { transition:transform .2s; }
            .grp-header[aria-expanded="true"] .chev { transform:rotate(90deg); }
        </style>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong><i class="bi bi-table"></i> Data Historis</strong></div>
            <div class="card-body p-0">
                <?php foreach ($grupHistoris as $kodeGrup => $barisGrup): $noPengujian++; ?>
                <?php
                    $barisPertama = $barisGrup[0];
                    $katGrup = kategoriDariNamaJenis($pdo, $barisPertama['jenis_pengujian']);
                    $isBesiGrup = $katGrup !== null
                        ? ($katGrup === 'besi')
                        : (empty($barisPertama['tgl_cor']) && !isUjiSilinder($barisPertama['jenis_pengujian']) && !isUjiKubus($barisPertama['jenis_pengujian']) && !isUjiPaving($barisPertama['jenis_pengujian']) && !isUjiLentur($barisPertama['jenis_pengujian']) && !isUjiCoreDrill($barisPertama['jenis_pengujian']));
                    $jmlValid = 0;
                    foreach ($barisGrup as $bg) { if ($bg['status_verifikasi'] === 'terverifikasi') $jmlValid++; }
                    $jmlBaris = count($barisGrup);
                ?>
                <div class="border-bottom">
                    <div class="d-flex align-items-center grp-header py-3 px-3"
                         data-bs-toggle="collapse" data-bs-target="#grupHistoris<?= $noPengujian ?>"
                         aria-expanded="false" style="cursor:pointer;">
                        <i class="bi bi-chevron-right chev text-primary"></i>
                        <strong class="text-primary ms-2">Pengujian <?= $noPengujian ?></strong>
                        <span class="badge <?= $isBesiGrup ? 'bg-warning' : 'bg-info' ?> ms-2" style="font-size:0.65rem;"><?= $isBesiGrup ? 'Besi' : 'Beton' ?></span>
                        <span class="text-muted ms-2" style="font-size:0.8rem;"><i class="bi bi-file-earmark-text"></i> <?= htmlspecialchars($kodeGrup) ?></span>
                        <span class="text-muted ms-2" style="font-size:0.8rem;"><i class="bi bi-journal-text"></i> <?= htmlspecialchars($barisPertama['jenis_pengujian'] ?? '-') ?></span>
                        <span class="badge bg-light text-dark border ms-2" style="font-size:0.7rem;"><?= $jmlBaris ?> data</span>
                        <?php if ($jmlValid === $jmlBaris): ?>
                            <span class="badge bg-success ms-2" id="badge-grup-status-<?= $noPengujian ?>" style="font-size:0.65rem;">✓ Semua Valid</span>
                        <?php elseif ($jmlValid > 0): ?>
                            <span class="badge bg-warning text-dark ms-2" id="badge-grup-status-<?= $noPengujian ?>" style="font-size:0.65rem;">Sebagian (<?= $jmlValid ?>/<?= $jmlBaris ?>)</span>
                        <?php else: ?>
                            <span class="badge bg-secondary ms-2" id="badge-grup-status-<?= $noPengujian ?>" style="font-size:0.65rem;">○ Belum Divalidasi</span>
                        <?php endif; ?>
                        <div class="ms-auto d-flex gap-1" onclick="event.stopPropagation();">
                            <?php $statusGrupSaatIni = ($jmlValid === $jmlBaris) ? 'terverifikasi' : 'belum_diverifikasi'; ?>
                            <button type="button"
                                    class="btn btn-sm <?= $statusGrupSaatIni === 'terverifikasi' ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                    id="btn-validasi-grup-<?= $noPengujian ?>"
                                    onclick="toggleValidasiGrup(<?= $noPengujian ?>, '<?= htmlspecialchars($kodeGrup, ENT_QUOTES) ?>', <?= (int) $userId ?>, '<?= $statusGrupSaatIni ?>')"
                                    title="<?= $statusGrupSaatIni === 'terverifikasi' ? 'Batalkan validasi semua data' : 'Validasi semua data pengujian ini' ?>">
                                <i class="bi <?= $statusGrupSaatIni === 'terverifikasi' ? 'bi-x-circle' : 'bi-check-circle' ?>"></i>
                                <span class="d-none d-sm-inline ms-1"><?= $statusGrupSaatIni === 'terverifikasi' ? 'Batalkan' : 'Validasi' ?></span>
                            </button>
                        </div>
                    </div>
                    <div class="collapse" id="grupHistoris<?= $noPengujian ?>">
                        <?php
                        // Kolom data opsional: hanya tampil jika minimal 1 baris di grup punya isi
                        $kolomOpsional = [
                            'tgl_cor'               => ['Tgl Cor', 'tanggal'],
                            'tgl_ditest'            => ['Tgl Dites', 'tanggal'],
                            'umur_hari'             => ['Umur', 'umur'],
                            'mutu_beton'            => ['Mutu', 'teks'],
                            'berat_kg'              => ['Berat (kg)', 'nilai'],
                            'beban_maksimum'        => ['Beban Maks', 'nilai'],
                            'tegangan_tekan'        => ['Tekan (Kg/cm&sup2;)', 'nilai'],
                            'tegangan_tekan_28_hari'=> ['Tekan 28hr', 'nilai'],
                            'diameter'              => ['Diameter', 'nilai'],
                            'diameter_akhir'        => ['D0u', 'nilai'],
                            'luas_penampang'        => ['Luas (mm&sup2;)', 'nilai'],
                            'luas_penampang_akhir'  => ['Au (mm&sup2;)', 'nilai'],
                            'beban_tarik_leleh'     => ['Beban Leleh (kN)', 'nilai'],
                            'panjang_awal'          => ['Panjang L0 (mm)', 'nilai'],
                            'panjang_akhir'         => ['Panjang Lu (mm)', 'nilai'],
                            'tegangan_tarik'        => ['Tarik (MPa)', 'nilai'],
                            'tegangan_leleh'        => ['Leleh (MPa)', 'nilai'],
                            'perpanjangan'          => ['Regangan Maks (%)', 'persen'],
                            'reduksi_penampang'     => ['Kontraksi (%)', 'persen'],
                            'parameter_uji'         => ['Parameter', 'parameter'],
                            'satuan'                => ['Satuan', 'satuan'],
                            'kesimpulan'            => ['Kesimpulan', 'kesimpulan'],
                        ];
                        $kolomAktif = [];
                        foreach ($kolomOpsional as $kKey => $kMeta) {
                            foreach ($barisGrup as $bgKol) {
                                $vKol = $bgKol[$kKey] ?? null;
                                if ($vKol !== null && $vKol !== '') { $kolomAktif[$kKey] = true; break; }
                            }
                        }
                        $jmlKolom = 4 + count($kolomAktif) + 4; // Tetap: laporan, kategori, jenis + opsional + nilai, status, aksi(+parameter/satuan/kesimpulan sudah di opsional)
                        ?>
                        <div class="table-responsive px-2 pb-3" style="overflow-x:auto;">
                            <table class="table table-hover table-sm mb-0" style="font-size:0.75rem; min-width:<?= max(700, 170 * $jmlKolom) ?>px;">
                                <thead class="table-light">
                                    <tr>
                                        <th>No. Laporan</th>
                                        <th>Kategori</th>
                                        <th>Jenis Pengujian</th>
                                        <?php foreach ($kolomOpsional as $kKey => $kMeta): if (empty($kolomAktif[$kKey])) continue; ?>
                                        <th><?= $kMeta[0] ?></th>
                                        <?php endforeach; ?>
                                        <th>Nilai</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($barisGrup as $dh): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($dh['kode'] ?? '-') ?></strong></td>
                                        <td>
                                            <span class="badge <?= $isBesiGrup ? 'bg-warning' : 'bg-info' ?>" style="font-size:0.65rem;"><?= $isBesiGrup ? 'Besi' : 'Beton' ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($dh['jenis_pengujian'] ?? '-') ?></td>
                                        <?php foreach ($kolomOpsional as $kKey => $kMeta): if (empty($kolomAktif[$kKey])) continue; ?>
                                        <td><?php
                                            $v = $dh[$kKey] ?? null;
                                            switch ($kMeta[1]) {
                                                case 'tanggal':
                                                    echo $v ? date('d-m-Y', strtotime($v)) : '-';
                                                    break;
                                                case 'umur':
                                                    echo $v ? $v . ' hr' : '-';
                                                    break;
                                                case 'persen':
                                                    echo $v !== null && $v !== '' ? $v . '%' : '-';
                                                    break;
                                                case 'parameter':
                                                    echo '<strong>' . htmlspecialchars($v ?? '-') . '</strong>';
                                                    break;
                                                case 'satuan':
                                                    echo htmlspecialchars($v ?? '');
                                                    break;
                                                case 'kesimpulan':
                                                    if ($v === 'memenuhi_syarat') echo '<span class="badge bg-success" style="font-size:0.65rem;">MS</span>';
                                                    elseif ($v === 'tidak_memenuhi_syarat') echo '<span class="badge bg-danger" style="font-size:0.65rem;">TMS</span>';
                                                    else echo '-';
                                                    break;
                                                case 'teks':
                                                    echo htmlspecialchars($v ?? '-');
                                                    break;
                                                default: // nilai
                                                    echo $v ?? '-';
                                            }
                                        ?></td>
                                        <?php endforeach; ?>
                                        <td><strong><?= htmlspecialchars($dh['nilai_hasil'] ?? '-') ?></strong></td>
                                        <td>
                                            <span class="badge <?= $dh['status_verifikasi'] === 'terverifikasi' ? 'bg-success' : 'bg-secondary' ?>"
                                                  id="badge-status-<?= $dh['id'] ?>"
                                                  style="font-size:0.65rem;">
                                                <?= $dh['status_verifikasi'] === 'terverifikasi' ? '✓ Valid' : '○ Belum' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <a href="download_laporan_historis.php?kode=<?= urlencode($dh['kode']) ?>&user_id=<?= $userId ?>" target="_blank" class="btn btn-sm btn-outline-info" title="Laporan">
                                                    <i class="bi bi-file-earmark-text"></i>
                                                </a>
                                                <button type="button" class="btn btn-sm btn-outline-primary" title="Edit"
                                                        onclick="openEditModal(<?= (int) $dh['id'] ?>)">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <form method="POST" style="display:inline" onsubmit="return confirm('Hapus data ini?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
                                                    <input type="hidden" name="hapus_id" value="<?= (int) $dh['id'] ?>">
                                                    <button type="submit" name="hapus_historis" class="btn btn-sm btn-outline-danger" title="Hapus">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal Edit Historis -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">Edit Data Historis</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="edit-id">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Proyek <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit-proyek" placeholder="Nama proyek" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Lokasi <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit-lokasi" placeholder="Lokasi proyek" required>
                        </div>
                    </div>
                    <div class="row g-3" id="editRowObjek" style="display:none;">
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Obyek</label>
                            <input type="text" class="form-control" id="edit-objek" placeholder="Obyek pengujian (misal: Paving ( 10 x 20cm ))">
                        </div>
                    </div>
                    <div id="editModeUmum">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Parameter Uji <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit-parameter_uji" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Nilai Hasil <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit-nilai_hasil" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Satuan</label>
                            <input type="text" class="form-control" id="edit-satuan">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Kesimpulan</label>
                            <select class="form-select" id="edit-kesimpulan">
                                <option value="">-- Pilih --</option>
                                <option value="memenuhi_syarat">Memenuhi Syarat (MS)</option>
                                <option value="tidak_memenuhi_syarat">Tidak Memenuhi Syarat (TMS)</option>
                            </select>
                        </div>
                    </div>
                    <hr>
                    </div>
                    <div id="sectionBeton">
                    <h6 class="text-muted mb-3">Data Beton</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tanggal Cor</label>
                            <input type="date" class="form-control" id="edit-tgl_cor" onchange="hitungUmurEditModal()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tanggal Dites</label>
                            <input type="date" class="form-control" id="edit-tgl_ditest" onchange="hitungUmurEditModal()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Umur (hari)</label>
                            <input type="number" class="form-control" id="edit-umur_hari" readonly style="background:#f1f5f9;">
                        </div>
                        <div class="col-md-4" id="editColBerat">
                            <label class="form-label fw-semibold">Berat (kg)</label>
                            <input type="text" class="form-control" id="edit-berat_kg">
                        </div>
                        <div class="col-md-4" id="editColMutu" style="display:none;">
                            <label class="form-label fw-semibold">Mutu</label>
                            <input type="text" class="form-control" id="edit-mutu_beton" placeholder="Diisi manual">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Kode</label>
                            <input type="text" class="form-control" id="edit-kode_beton" placeholder="KB-001">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" id="editLabelBeban">Beban Maksimum (kg)</label>
                            <input type="text" class="form-control" id="edit-beban_maksimum">
                        </div>
                        <div class="col-md-4" id="editColTegTek">
                            <label class="form-label fw-semibold">Tegangan Tekan (Kg/cm²)</label>
                            <input type="text" class="form-control" id="edit-tegangan_tekan">
                        </div>
                        <div class="col-md-4" id="editColTeg28">
                            <label class="form-label fw-semibold">Tegangan Tekan 28 Hari (Kg/cm²)</label>
                            <input type="text" class="form-control" id="edit-tegangan_tekan_28_hari">
                        </div>
                        <div class="col-md-4" id="editColMpa">
                            <label class="form-label fw-semibold">MPa</label>
                            <input type="text" class="form-control" id="edit-mpa">
                        </div>
                        <div class="col-md-4" id="editColRata" style="display:none;">
                            <label class="form-label fw-semibold">Rata-rata (Kg/cm²)</label>
                            <input type="text" class="form-control" id="edit-rata_rata">
                        </div>
                    </div>
                    <div class="row g-3 mt-0" id="editRowLentur" style="display:none;">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Bentang L (Cm)</label>
                            <input type="text" class="form-control" id="edit-bentang_l">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Dimensi b (Cm)</label>
                            <input type="text" class="form-control" id="edit-dimensi_b">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Dimensi h (Cm)</label>
                            <input type="text" class="form-control" id="edit-dimensi_h">
                        </div>
                    </div>
                    <div class="row g-3 mt-0" id="editRowLentur2" style="display:none;">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">W 1/6.b.h² (Cm3)</label>
                            <input type="text" class="form-control" id="edit-modulus_seksi">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Momen Lentur M=1/6.P.L (KgCm)</label>
                            <input type="text" class="form-control" id="edit-momen_lentur">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tegangan Lentur (M/W) (Kg/cm²)</label>
                            <input type="text" class="form-control" id="edit-tegangan_lentur">
                        </div>
                    </div>
                    <div class="row g-3 mt-0" id="editRowCore" style="display:none;">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Diameter (D) (Cm)</label>
                            <input type="text" class="form-control" id="edit-core_diameter">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tinggi (L) (Cm)</label>
                            <input type="text" class="form-control" id="edit-tinggi_l">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Luas Penampang (A) (Cm)</label>
                            <input type="text" class="form-control" id="edit-core_luas_penampang">
                        </div>
                    </div>
                    <div class="row g-3 mt-0" id="editRowCore2" style="display:none;">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">L / D</label>
                            <input type="text" class="form-control" id="edit-rasio_ld">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Faktor Koreksi (C)</label>
                            <input type="text" class="form-control" id="edit-faktor_koreksi">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tegangan Benda Uji &phi; 4&quot; (Kg/Cm&sup2;)</label>
                            <input type="text" class="form-control" id="edit-tegangan_benda_uji">
                        </div>
                    </div>
                    <div class="row g-3 mt-0" id="editRowCore3" style="display:none;">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Konversi Tegangan Utk Silider 6&quot; (Kg/Cm&sup2;)</label>
                            <input type="text" class="form-control" id="edit-konversi_silider6">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Konversi Tegangan Untuk Kubus 15x15x15 (Kg/Cm&sup2;)</label>
                            <input type="text" class="form-control" id="edit-konversi_kubus15">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Konversi Umur Beton Ke 28 Hari (Kg/Cm&sup2;)</label>
                            <input type="text" class="form-control" id="edit-konversi_umur28">
                        </div>
                    </div>
                    <div class="row g-3 mt-0" id="editRowCore4" style="display:none;">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Konversi Umur ke 28 Hari (Mpa)</label>
                            <input type="text" class="form-control" id="edit-konversi_umur28_mpa">
                        </div>
                    </div>
                    </div>
                    <div class="row g-3 mt-0" id="editRowKeterangan">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Keterangan</label>
                            <input type="text" class="form-control" id="edit-catatan_teknisi">
                        </div>
                    </div>
                    <hr>
                    <div id="sectionBesi">
                    <h6 class="text-muted mb-3">Data Besi</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Kode Benda Uji</label>
                            <input type="text" class="form-control" id="edit-kode_benda_uji">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Diameter D0 (mm)</label>
                            <input type="text" class="form-control" id="edit-diameter">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Diameter D0u (mm)</label>
                            <input type="text" class="form-control" id="edit-diameter_akhir">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Luas A0 (mm&sup2;)</label>
                            <input type="text" class="form-control" id="edit-luas_penampang">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Luas Au (mm&sup2;)</label>
                            <input type="text" class="form-control" id="edit-luas_penampang_akhir">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Beban Tarik Leleh (kN)</label>
                            <input type="text" class="form-control" id="edit-beban_tarik_leleh">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Beban Tarik Maks (kN)</label>
                            <input type="text" class="form-control" id="edit-beban_tarik_maks">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Beban Tarik Leleh (MPa)</label>
                            <input type="text" class="form-control" id="edit-tegangan_leleh">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Beban Tarik Maks (MPa)</label>
                            <input type="text" class="form-control" id="edit-tegangan_tarik">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Panjang Ulur L0 (mm)</label>
                            <input type="text" class="form-control" id="edit-panjang_awal">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Panjang Ulur Lu (mm)</label>
                            <input type="text" class="form-control" id="edit-panjang_akhir">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Regangan Maks (%)</label>
                            <input type="text" class="form-control" id="edit-perpanjangan">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Kontraksi (%)</label>
                            <input type="text" class="form-control" id="edit-reduksi_penampang">
                        </div>
                    </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" onclick="saveEdit()">Simpan Perubahan</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var jumlahBaris = 1;

        var mutuBetonOptions = '<option value="">-- Pilih --</option>';
        <?php foreach (['K-175','K-200','K-225','K-250','K-275','K-300','K-350','K-400','K-450','K-500'] as $m): ?>
            mutuBetonOptions += '<option value="<?= $m ?>"><?= $m ?></option>';
        <?php endforeach; ?>

        function getBetonFields(idx) {
            return '' +
            '<div class="row">' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Parameter Uji <span class="text-danger">*</span></label>' +
                    '<input type="text" name="parameter_uji[]" class="form-control form-control-sm" required placeholder="Kuat Tekan, Slump, dll">' +
                '</div>' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Kesimpulan <span class="text-danger">*</span></label>' +
                    '<select name="kesimpulan[]" class="form-select form-select-sm" required>' +
                        '<option value="">-- Pilih --</option>' +
                        '<option value="memenuhi_syarat">Memenuhi Syarat</option>' +
                        '<option value="tidak_memenuhi_syarat">Tidak Memenuhi Syarat</option>' +
                    '</select>' +
                '</div>' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Mutu Beton</label>' +
                    '<select name="mutu_beton[]" class="form-select form-select-sm">' + mutuBetonOptions + '</select>' +
                '</div>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Kode Beton</label>' +
                    '<input type="text" name="kode_beton[]" class="form-control form-control-sm" placeholder="KB-001">' +
                '</div>' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Tanggal Cor</label>' +
                    '<input type="date" name="tgl_cor[]" class="form-control form-control-sm tgl-cor" onchange="hitungUmurBaris(this)">' +
                '</div>' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Tanggal Pengujian <span class="text-danger">*</span></label>' +
                    '<input type="date" name="tgl_ditest[]" class="form-control form-control-sm tgl-dites" onchange="hitungUmurBaris(this)" required>' +
                '</div>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Umur (Hari)</label>' +
                    '<input type="number" class="form-control form-control-sm umur-hari" readonly style="background:#f1f5f9;">' +
                '</div>' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Berat (Kg)</label>' +
                    '<input type="number" step="0.01" name="berat_kg[]" class="form-control form-control-sm" placeholder="Berat">' +
                '</div>' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Beban Maks (Kg)</label>' +
                    '<input type="number" step="0.01" name="beban_maksimum[]" class="form-control form-control-sm" placeholder="Input manual">' +
                '</div>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Tegangan Tekan (Kg/cm&sup2;)</label>' +
                    '<input type="number" step="0.01" name="tegangan_tekan[]" class="form-control form-control-sm" placeholder="Input manual">' +
                '</div>' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Teg. Tekan 28 Hari (Kg/cm&sup2;)</label>' +
                    '<input type="number" step="0.01" name="tegangan_tekan_28_hari[]" class="form-control form-control-sm" placeholder="Input manual">' +
                '</div>' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">MPa</label>' +
                    '<input type="number" step="0.01" name="mpa[]" class="form-control form-control-sm" placeholder="Input manual">' +
                '</div>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Nilai Hasil <span class="text-danger">*</span></label>' +
                    '<input type="text" name="nilai_hasil[]" class="form-control form-control-sm" required placeholder="Contoh: 285">' +
                '</div>' +
                '<div class="col-sm-4 mb-3">' +
                    '<label class="form-label" style="font-size:0.75rem;">Satuan</label>' +
                    '<input type="text" name="satuan[]" class="form-control form-control-sm" placeholder="kg/cm&sup2; / MPa">' +
                '</div>' +
            '</div>';
        }

        function getBesiFields(idx) {
            var lbl = 'style="font-size:0.75rem;"';
            return '' +
            '<div class="row mb-2">' +
                '<div class="col-sm-4">' +
                    '<label class="form-label" ' + lbl + '>Tanggal Pengujian <span class="text-danger">*</span></label>' +
                    '<input type="date" name="tgl_ditest[]" class="form-control form-control-sm tgl-dites" required>' +
                '</div>' +
                '<div class="col-sm-8">' +
                    '<label class="form-label" ' + lbl + '>Kode Benda Uji <span class="text-danger">*</span></label>' +
                    '<input type="text" name="kode_benda_uji[]" class="form-control form-control-sm" required>' +
                '</div>' +
            '</div>' +
            '<div class="row mb-2">' +
                '<div class="col-sm-6">' +
                    '<label class="form-label" ' + lbl + '>Diameter D0 (mm)</label>' +
                    '<input type="number" step="0.01" name="diameter[]" class="form-control form-control-sm">' +
                '</div>' +
                '<div class="col-sm-6">' +
                    '<label class="form-label" ' + lbl + '>Diameter D0u (mm)</label>' +
                    '<input type="number" step="0.01" name="diameter_akhir[]" class="form-control form-control-sm">' +
                '</div>' +
            '</div>' +
            '<div class="row mb-2">' +
                '<div class="col-sm-6">' +
                    '<label class="form-label" ' + lbl + '>Luas Penampang A0 (mm&sup2;)</label>' +
                    '<input type="number" step="0.01" name="luas_penampang[]" class="form-control form-control-sm">' +
                '</div>' +
                '<div class="col-sm-6">' +
                    '<label class="form-label" ' + lbl + '>Luas Penampang Au (mm&sup2;)</label>' +
                    '<input type="number" step="0.01" name="luas_penampang_akhir[]" class="form-control form-control-sm">' +
                '</div>' +
            '</div>' +
            '<div class="row mb-2">' +
                '<div class="col-sm-6">' +
                    '<label class="form-label" ' + lbl + '>Beban Tarik Leleh (kN)</label>' +
                    '<input type="number" step="0.01" name="beban_tarik_leleh[]" class="form-control form-control-sm">' +
                '</div>' +
                '<div class="col-sm-6">' +
                    '<label class="form-label" ' + lbl + '>Beban Tarik Maks (kN)</label>' +
                    '<input type="number" step="0.01" name="beban_maksimum[]" class="form-control form-control-sm">' +
                '</div>' +
            '</div>' +
            '<div class="row mb-2">' +
                '<div class="col-sm-6">' +
                    '<label class="form-label" ' + lbl + '>Tegangan Leleh (MPa)</label>' +
                    '<input type="number" step="0.01" name="tegangan_leleh[]" class="form-control form-control-sm">' +
                '</div>' +
                '<div class="col-sm-6">' +
                    '<label class="form-label" ' + lbl + '>Tegangan Tarik (MPa)</label>' +
                    '<input type="number" step="0.01" name="tegangan_tarik[]" class="form-control form-control-sm">' +
                '</div>' +
            '</div>' +
            '<div class="row mb-2">' +
                '<div class="col-sm-6">' +
                    '<label class="form-label" ' + lbl + '>Panjang Ulur Awal L0 (mm)</label>' +
                    '<input type="number" step="0.01" name="panjang_awal[]" class="form-control form-control-sm">' +
                '</div>' +
                '<div class="col-sm-6">' +
                    '<label class="form-label" ' + lbl + '>Panjang Ulur Akhir Lu (mm)</label>' +
                    '<input type="number" step="0.01" name="panjang_akhir[]" class="form-control form-control-sm">' +
                '</div>' +
            '</div>' +
            '<div class="row mb-2">' +
                '<div class="col-sm-6">' +
                    '<label class="form-label" ' + lbl + '>Regangan Maks (%)</label>' +
                    '<input type="number" step="0.01" name="perpanjangan[]" class="form-control form-control-sm">' +
                '</div>' +
                '<div class="col-sm-6">' +
                    '<label class="form-label" ' + lbl + '>Kontraksi (%)</label>' +
                    '<input type="number" step="0.01" name="reduksi_penampang[]" class="form-control form-control-sm">' +
                '</div>' +
            '</div>' +
            '<div class="row mb-2">' +
                '<div class="col-sm-12">' +
                    '<label class="form-label" ' + lbl + '>Keterangan</label>' +
                    '<input type="text" name="keterangan[]" class="form-control form-control-sm" placeholder="Keterangan uji, misal: Uji Tek-uk">' +
                '</div>' +
            '</div>';
        }

        var jenisTerakhir = { id: '', kategori: '', silinder: false, kubus: false, paving: false, lentur: false, core: false, label: 'Silinder' };

        function kategoriJenis() {
            var sel = document.getElementById('jenisPengujianInput');
            if (!sel || sel.selectedIndex < 0) return '';
            var opsi = sel.options[sel.selectedIndex];
            return (opsi && opsi.dataset) ? (opsi.dataset.kategori || '') : '';
        }

        function renderParameterBody(row, idx) {
            row.querySelector('.parameter-body').innerHTML = (kategoriJenis() === 'besi') ? getBesiFields(idx) : getBetonFields(idx);
        }

        function switchKategori() {
            var sel = document.getElementById('jenisPengujianInput');
            var kategoriBaru = kategoriJenis();
            var silinderBaru = modeSilinder();
            var kubusBaru = modeKubus();
            var pavingBaru = modePaving();
            var lenturBaru = modeLentur();
            var coreBaru = modeCore();
            var labelBaru = modeLabel();
            var modeBerubah = ((jenisTerakhir.silinder === true) !== silinderBaru) ||
                              ((jenisTerakhir.kubus === true) !== kubusBaru) ||
                              ((jenisTerakhir.paving === true) !== pavingBaru) ||
                              ((jenisTerakhir.lentur === true) !== lenturBaru) ||
                              ((jenisTerakhir.core === true) !== coreBaru);

            if (kategoriBaru === jenisTerakhir.kategori && !modeBerubah) {
                jenisTerakhir.id = sel.value;
                jenisTerakhir.silinder = silinderBaru;
                jenisTerakhir.kubus = kubusBaru;
                jenisTerakhir.paving = pavingBaru;
                jenisTerakhir.lentur = lenturBaru;
                jenisTerakhir.core = coreBaru;
                if (labelBaru !== jenisTerakhir.label) {
                    jenisTerakhir.label = labelBaru;
                    var judulBox = document.getElementById('judulModeSilinder');
                    if (judulBox) judulBox.textContent = judulMode();
                    renumberSilinder();
                }
                return;
            }

            if ((jenisTerakhir.kategori !== '' || jenisTerakhir.silinder === true || jenisTerakhir.kubus === true || jenisTerakhir.paving === true || jenisTerakhir.lentur === true) &&
                !confirm('Mengganti jenis pengujian akan menghapus semua data parameter. Lanjutkan?')) {
                sel.value = jenisTerakhir.id;
                return;
            }

            document.querySelectorAll('.parameter-row').forEach(function(row, idx) {
                renderParameterBody(row, idx);
            });
            resetBarisSilinder();
            toggleModeInput();

            jenisTerakhir.id = sel.value;
            jenisTerakhir.kategori = kategoriBaru;
            jenisTerakhir.silinder = silinderBaru;
            jenisTerakhir.kubus = kubusBaru;
            jenisTerakhir.paving = pavingBaru;
            jenisTerakhir.lentur = lenturBaru;
            jenisTerakhir.core = coreBaru;
            jenisTerakhir.label = labelBaru;
        }

        function modeSilinder() {
            var sel = document.getElementById('jenisPengujianInput');
            if (!sel || sel.selectedIndex < 0) return false;
            var opsi = sel.options[sel.selectedIndex];
            return !!(opsi && opsi.dataset && opsi.dataset.silinder === '1');
        }

        function modeKubus() {
            var sel = document.getElementById('jenisPengujianInput');
            if (!sel || sel.selectedIndex < 0) return false;
            var opsi = sel.options[sel.selectedIndex];
            return !!(opsi && opsi.dataset && opsi.dataset.kubus === '1');
        }

        function modePaving() {
            var sel = document.getElementById('jenisPengujianInput');
            if (!sel || sel.selectedIndex < 0) return false;
            var opsi = sel.options[sel.selectedIndex];
            return !!(opsi && opsi.dataset && opsi.dataset.paving === '1');
        }

        function modeLentur() {
            var sel = document.getElementById('jenisPengujianInput');
            if (!sel || sel.selectedIndex < 0) return false;
            var opsi = sel.options[sel.selectedIndex];
            return !!(opsi && opsi.dataset && opsi.dataset.lentur === '1');
        }

        function modeCore() {
            var sel = document.getElementById('jenisPengujianInput');
            if (!sel || sel.selectedIndex < 0) return false;
            var opsi = sel.options[sel.selectedIndex];
            return !!(opsi && opsi.dataset && opsi.dataset.core === '1');
        }

        function judulMode() {
            if (modeCore()) return 'Data Uji Tekan Core Drill';
            if (modeLentur()) return 'Data Uji Lentur / Flexural Balok Beton';
            return 'Data Uji Tekan ' + modeLabel();
        }

        function modeLabel() {
            var sel = document.getElementById('jenisPengujianInput');
            if (!sel || sel.selectedIndex < 0) return 'Silinder';
            var opsi = sel.options[sel.selectedIndex];
            if (opsi && opsi.dataset && opsi.dataset.label) return opsi.dataset.label;
            return modeKubus() ? 'Kubus' : 'Silinder';
        }

        function toggleModeInput() {
            var silinder = modeSilinder();
            var kubus = modeKubus();
            var paving = modePaving();
            var lentur = modeLentur();
            var core = modeCore();
            var modeBeton = document.getElementById('modeBeton');
            var modeSilinderBox = document.getElementById('modeSilinder');
            var rowObjek = document.getElementById('rowObjekBesi');
            var isBesiKategori = (kategoriJenis() === 'besi');
            var showObjek = isBesiKategori || paving;
            if (rowObjek) {
                rowObjek.style.display = showObjek ? '' : 'none';
                rowObjek.querySelectorAll('input').forEach(function(el) { el.required = isBesiKategori; el.disabled = !showObjek; });
            }
            var tandaWajibObjek = document.getElementById('tandaWajibObjek');
            if (tandaWajibObjek) tandaWajibObjek.style.display = isBesiKategori ? '' : 'none';
            if (modeBeton) {
                modeBeton.style.display = silinder ? 'none' : '';
                modeBeton.querySelectorAll('input, select, textarea').forEach(function(el) { el.disabled = silinder; });
            }
            if (modeSilinderBox) {
                modeSilinderBox.style.display = silinder ? '' : 'none';
                modeSilinderBox.querySelectorAll('input, select, textarea').forEach(function(el) { el.disabled = !silinder; });
                modeSilinderBox.querySelectorAll('.col-berat').forEach(function(el) { el.style.display = lentur ? 'none' : ''; });
                modeSilinderBox.querySelectorAll('.col-mutu').forEach(function(el) { el.style.display = (silinder && !core && !paving) ? '' : 'none'; });
                modeSilinderBox.querySelectorAll('.col-tegtek, .col-teg28').forEach(function(el) { el.style.display = (lentur || core) ? 'none' : ''; });
                modeSilinderBox.querySelectorAll('.row-lentur').forEach(function(el) { el.style.display = lentur ? '' : 'none'; });
            modeSilinderBox.querySelectorAll('.row-core').forEach(function(el) { el.style.display = core ? '' : 'none'; });
            modeSilinderBox.querySelectorAll('.row-mpa').forEach(function(el) { el.style.display = (kubus || paving || lentur) ? 'none' : ''; });
            modeSilinderBox.querySelectorAll('.row-keterangan').forEach(function(el) { el.style.display = (kubus || lentur) ? '' : 'none'; });
                modeSilinderBox.querySelectorAll('.row-rata').forEach(function(el) { el.style.display = paving ? '' : 'none'; });
                modeSilinderBox.querySelectorAll('[data-paving-text]').forEach(function(el) {
                    if (core) {
                        el.textContent = el.dataset.coreText || el.dataset.silinderText;
                    } else if (lentur) {
                        el.textContent = el.dataset.lenturText || el.dataset.silinderText;
                    } else {
                        el.textContent = paving ? el.dataset.pavingText : el.dataset.silinderText;
                    }
                });
            }
            var judul = document.getElementById('judulModeSilinder');
            if (judul) judul.textContent = judulMode();
            renumberSilinder();
        }

        function clearBarisSilinder(card) {
            if (!card) return;
            card.querySelectorAll('input').forEach(function(inp) {
                if (inp.type !== 'hidden') inp.value = '';
            });
        }

        function resetBarisSilinder() {
            var container = document.getElementById('containerSilinder');
            if (!container) return;
            while (container.children.length > 1) container.removeChild(container.lastElementChild);
            clearBarisSilinder(container.firstElementChild);
            renumberSilinder();
        }

        function tambahBarisSilinder() {
            var container = document.getElementById('containerSilinder');
            if (!container) return;
            var baris = container.firstElementChild.cloneNode(true);
            clearBarisSilinder(baris);
            container.appendChild(baris);
            renumberSilinder();
        }

        function hapusBarisSilinder(btn) {
            var container = document.getElementById('containerSilinder');
            if (!container || container.children.length <= 1) return;
            var card = btn.closest('.baris-silinder');
            if (card) card.parentNode.removeChild(card);
            renumberSilinder();
        }

        function renumberSilinder() {
            document.querySelectorAll('#containerSilinder .baris-silinder').forEach(function(card, i) {
                var judul = card.querySelector('.card-header strong');
                if (judul) judul.textContent = 'Data ' + modeLabel() + ' #' + (i + 1);
                var hapus = card.querySelector('.card-header button');
                if (hapus) hapus.style.display = (i === 0) ? 'none' : '';
            });
        }

        function parseTanggalManual(v) {
            if (!v) return null;
            v = String(v).trim();
            var m = v.match(/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/);
            if (m) return new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]));
            m = v.match(/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/);
            if (m) return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
            return null;
        }

        function tanggalYmdKeManual(v) {
            if (!v) return '';
            var m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/);
            return m ? (m[3] + '-' + m[2] + '-' + m[1]) : String(v);
        }

        function hitungUmurSilinder(el) {
            var card = el.closest('.baris-silinder');
            if (!card) return;
            var cor = card.querySelector('.tgl-cor');
            var dites = card.querySelector('.tgl-dites');
            var umur = card.querySelector('.umur-hari');
            if (!cor || !dites || !umur) return;
            var d1 = parseTanggalManual(cor.value);
            var d2 = parseTanggalManual(dites.value);
            umur.value = (d1 && d2) ? Math.ceil(Math.abs(d2 - d1) / (1000 * 60 * 60 * 24)) : '';
        }

        function initParameterFields() {
            var body = document.querySelector('#parameterContainer .parameter-row .parameter-body');
            var sel = document.getElementById('jenisPengujianInput');
            if (!body || !sel) return;
            body.innerHTML = (kategoriJenis() === 'besi') ? getBesiFields(0) : getBetonFields(0);
            jenisTerakhir.id = sel.value;
            jenisTerakhir.kategori = kategoriJenis();
            jenisTerakhir.silinder = modeSilinder();
            jenisTerakhir.kubus = modeKubus();
            jenisTerakhir.paving = modePaving();
            jenisTerakhir.lentur = modeLentur();
            jenisTerakhir.core = modeCore();
            jenisTerakhir.label = modeLabel();
            toggleModeInput();
            renumberSilinder();
        }

        function hitungUmurBaris(el) {
            var row = el.closest('.parameter-row');
            var cor = row.querySelector('.tgl-cor');
            var dites = row.querySelector('.tgl-dites');
            var umur = row.querySelector('.umur-hari');
            if (cor && dites && cor.value && dites.value) {
                var d1 = parseTanggalManual(cor.value);
                var d2 = parseTanggalManual(dites.value);
                if (!d1 || !d2) { umur.value = ''; return; }
                var diff = d2 - d1;
                var hari = Math.floor(diff / (1000 * 60 * 60 * 24));
                umur.value = hari >= 0 ? hari : '';
            }
        }

        function tambahParameter() {
            jumlahBaris++;
            var container = document.getElementById('parameterContainer');
            var div = document.createElement('div');
            div.className = 'parameter-row card mb-3';
            div.innerHTML =
                '<div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">' +
                    '<strong style="font-size:0.8125rem;">Parameter #' + jumlahBaris + '</strong>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" onclick="hapusParameter(this)" title="Hapus parameter"><i class="bi bi-trash"></i></button>' +
                '</div>' +
                '<div class="parameter-body card-body py-2"></div>';
            container.appendChild(div);
            renderParameterBody(div, jumlahBaris - 1);
        }

        function hapusParameter(btn) {
            var rows = document.querySelectorAll('.parameter-row');
            if (rows.length <= 1) { alert('Minimal harus ada 1 parameter uji.'); return; }
            btn.closest('.parameter-row').remove();
            var allRows = document.querySelectorAll('.parameter-row');
            allRows.forEach(function(row, idx) {
                row.querySelector('.card-header strong').textContent = 'Parameter #' + (idx + 1);
            });
            jumlahBaris = allRows.length;
        }

        function getCsrfToken() {
            var el = document.querySelector('input[name="csrf_token"]');
            return el ? el.value : '';
        }

        function toggleValidasi(id, currentStatus) {
            var newStatus = currentStatus === 'terverifikasi' ? 'belum_diverifikasi' : 'terverifikasi';
            var payload = { id: id, status: newStatus, csrf_token: getCsrfToken() };

            fetch('validasi_historis_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.ok) { alert(res.msg || 'Gagal memperbarui status'); return; }

                var badge = document.getElementById('badge-status-' + id);
                var btn   = document.getElementById('btn-validasi-' + id);

                if (newStatus === 'terverifikasi') {
                    badge.className = 'badge bg-success';
                    badge.innerHTML = '✓ Tervalidasi';
                    if (btn) {
                        btn.className   = 'btn btn-sm btn-outline-warning';
                        btn.querySelector('i').className = 'bi bi-x-circle';
                        btn.setAttribute('title', 'Batalkan validasi');
                        btn.setAttribute('onclick', 'toggleValidasi(' + id + ', \'' + newStatus + '\')');
                    }
                } else {
                    badge.className = 'badge bg-secondary';
                    badge.innerHTML = '○ Belum';
                    if (btn) {
                        btn.className   = 'btn btn-sm btn-outline-success';
                        btn.querySelector('i').className = 'bi bi-check-circle';
                        btn.setAttribute('title', 'Validasi data ini');
                        btn.setAttribute('onclick', 'toggleValidasi(' + id + ', \'' + newStatus + '\')');
                    }
                }
            })
            .catch(function() { alert('Terjadi kesalahan koneksi'); });
        }

        function toggleValidasiGrup(noGrup, kode, userId, currentStatus) {
            var newStatus = currentStatus === 'terverifikasi' ? 'belum_diverifikasi' : 'terverifikasi';
            var payload = { kode: kode, user_id: userId, status: newStatus, csrf_token: getCsrfToken() };

            fetch('validasi_historis_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.ok) { alert(res.msg || 'Gagal memperbarui status'); return; }

                var btn = document.getElementById('btn-validasi-grup-' + noGrup);
                var badgeGrup = document.getElementById('badge-grup-status-' + noGrup);

                if (newStatus === 'terverifikasi') {
                    if (btn) {
                        btn.className = 'btn btn-sm btn-outline-warning';
                        btn.querySelector('i').className = 'bi bi-x-circle';
                        btn.setAttribute('title', 'Batalkan validasi semua data');
                        var lbl = btn.querySelector('span');
                        if (lbl) lbl.textContent = 'Batalkan';
                        btn.setAttribute('onclick', 'toggleValidasiGrup(' + noGrup + ', \'' + kode.replace(/'/g, "\\'") + '\', ' + userId + ', \'' + newStatus + '\')');
                    }
                    if (badgeGrup) { badgeGrup.className = 'badge bg-success ms-2'; badgeGrup.innerHTML = '✓ Semua Valid'; }
                } else {
                    if (btn) {
                        btn.className = 'btn btn-sm btn-outline-success';
                        btn.querySelector('i').className = 'bi bi-check-circle';
                        btn.setAttribute('title', 'Validasi semua data pengujian ini');
                        var lbl2 = btn.querySelector('span');
                        if (lbl2) lbl2.textContent = 'Validasi';
                        btn.setAttribute('onclick', 'toggleValidasiGrup(' + noGrup + ', \'' + kode.replace(/'/g, "\\'") + '\', ' + userId + ', \'' + newStatus + '\')');
                    }
                    if (badgeGrup) { badgeGrup.className = 'badge bg-secondary ms-2'; badgeGrup.innerHTML = '○ Belum Divalidasi'; }
                }

                var collapseEl = document.getElementById('grupHistoris' + noGrup);
                if (collapseEl) {
                    collapseEl.querySelectorAll('[id^="badge-status-"]').forEach(function(b) {
                        if (newStatus === 'terverifikasi') {
                            b.className = 'badge bg-success';
                            b.innerHTML = '✓ Valid';
                        } else {
                            b.className = 'badge bg-secondary';
                            b.innerHTML = '○ Belum';
                        }
                    });
                }
            })
            .catch(function() { alert('Terjadi kesalahan koneksi'); });
        }

        document.addEventListener('DOMContentLoaded', function() {
            initParameterFields();
        });

        var editIsBesi = false;
        var editIsCore = false;

        function openEditModal(id) {
            fetch('edit_historis_ajax.php?id=' + id)
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.ok) { alert(res.msg || 'Gagal memuat data'); return; }
                var d = res.data;
                var modeUmum = document.getElementById('editModeUmum');
                var jenisNama = d.jenis_pengujian || '';
                var isKubusRow = jenisNama.indexOf('Uji Tekan Kubus') === 0 && jenisNama.indexOf('Mortar') === -1;
                var isPavingRow = jenisNama.indexOf('Uji Tekan Paving') === 0 ||
                                  jenisNama.indexOf('Uji Tekan Bata Merah') === 0 ||
                                  jenisNama.indexOf('Uji Tekan Hebel') === 0;
                var isLenturRow = jenisNama.indexOf('Uji Lentur') === 0 || jenisNama.indexOf('Flexural') !== -1;
                var isCoreRow = jenisNama.indexOf('Uji Tekan Core Drill') === 0;
                editIsCore = isCoreRow;
                var isSilinder = isKubusRow || jenisNama.indexOf('Uji Tekan Silinder') === 0 || isPavingRow || isLenturRow || isCoreRow;
                editIsBesi = !isSilinder && !d.tgl_cor;
                if (modeUmum) modeUmum.style.display = isSilinder ? 'none' : '';
                var colMpa = document.getElementById('editColMpa');
                if (colMpa) colMpa.style.display = (isKubusRow || isPavingRow || isLenturRow || isCoreRow) ? 'none' : '';
                var colRata = document.getElementById('editColRata');
                if (colRata) colRata.style.display = isPavingRow ? '' : 'none';
                var rowKet = document.getElementById('editRowKeterangan');
                if (rowKet) rowKet.style.display = isPavingRow ? 'none' : '';
                var colBerat = document.getElementById('editColBerat');
                if (colBerat) colBerat.style.display = isLenturRow ? 'none' : '';
                var colMutu = document.getElementById('editColMutu');
                if (colMutu) colMutu.style.display = (isSilinder && !isPavingRow && !isCoreRow) ? '' : 'none';
                var colTegTek = document.getElementById('editColTegTek');
                if (colTegTek) colTegTek.style.display = (isLenturRow || isCoreRow) ? 'none' : '';
                var colTeg28 = document.getElementById('editColTeg28');
                if (colTeg28) colTeg28.style.display = (isLenturRow || isCoreRow) ? 'none' : '';
                var rowLentur = document.getElementById('editRowLentur');
                if (rowLentur) rowLentur.style.display = isLenturRow ? '' : 'none';
                var rowLentur2 = document.getElementById('editRowLentur2');
                if (rowLentur2) rowLentur2.style.display = isLenturRow ? '' : 'none';
                var rowCore = document.getElementById('editRowCore');
                if (rowCore) rowCore.style.display = isCoreRow ? '' : 'none';
                var rowCore2 = document.getElementById('editRowCore2');
                if (rowCore2) rowCore2.style.display = isCoreRow ? '' : 'none';
                var rowCore3 = document.getElementById('editRowCore3');
                if (rowCore3) rowCore3.style.display = isCoreRow ? '' : 'none';
                var rowCore4 = document.getElementById('editRowCore4');
                if (rowCore4) rowCore4.style.display = isCoreRow ? '' : 'none';
                var labelBeban = document.getElementById('editLabelBeban');
                if (labelBeban) labelBeban.textContent = (isLenturRow || isCoreRow) ? 'Beban (P) (Kg)' : 'Beban Maksimum (kg)';
                var secBeton = document.getElementById('sectionBeton');
                var secBesi = document.getElementById('sectionBesi');
                if (secBeton) secBeton.style.display = editIsBesi ? 'none' : '';
                if (secBesi) secBesi.style.display = editIsBesi ? '' : 'none';
                var rowObjekEdit = document.getElementById('editRowObjek');
                if (rowObjekEdit) rowObjekEdit.style.display = (editIsBesi || isPavingRow) ? '' : 'none';
                document.getElementById('edit-id').value = d.id;
                document.getElementById('edit-proyek').value = d.proyek || '';
                document.getElementById('edit-lokasi').value = d.lokasi || '';
                document.getElementById('edit-objek').value = d.objek || '';
                document.getElementById('edit-parameter_uji').value = d.parameter_uji || '';
                document.getElementById('edit-nilai_hasil').value = d.nilai_hasil || '';
                document.getElementById('edit-satuan').value = d.satuan || '';
                document.getElementById('edit-kesimpulan').value = d.kesimpulan || '';
                document.getElementById('edit-tgl_cor').value = d.tgl_cor || '';
                document.getElementById('edit-tgl_ditest').value = d.tgl_ditest || '';
                document.getElementById('edit-umur_hari').value = d.umur_hari || '';
                document.getElementById('edit-berat_kg').value = d.berat_kg || '';
                document.getElementById('edit-kode_beton').value = d.kode_beton || '';
                document.getElementById('edit-beban_maksimum').value = editIsBesi ? '' : (d.beban_maksimum || '');
                document.getElementById('edit-beban_tarik_maks').value = editIsBesi ? (d.beban_maksimum || '') : '';
                document.getElementById('edit-tegangan_tekan').value = d.tegangan_tekan || '';
                document.getElementById('edit-tegangan_tekan_28_hari').value = d.tegangan_tekan_28_hari || '';
                document.getElementById('edit-mpa').value = d.mpa || '';
                document.getElementById('edit-rata_rata').value = d.rata_rata || '';
                document.getElementById('edit-mutu_beton').value = d.mutu_beton || '';
                document.getElementById('edit-bentang_l').value = d.bentang_l || '';
                document.getElementById('edit-dimensi_b').value = d.dimensi_b || '';
                document.getElementById('edit-dimensi_h').value = d.dimensi_h || '';
                document.getElementById('edit-modulus_seksi').value = d.modulus_seksi || '';
                document.getElementById('edit-momen_lentur').value = d.momen_lentur || '';
                document.getElementById('edit-tegangan_lentur').value = d.tegangan_lentur || '';
                document.getElementById('edit-core_diameter').value = d.diameter || '';
                document.getElementById('edit-tinggi_l').value = d.tinggi_l || '';
                document.getElementById('edit-core_luas_penampang').value = d.luas_penampang || '';
                document.getElementById('edit-rasio_ld').value = d.rasio_ld || '';
                document.getElementById('edit-faktor_koreksi').value = d.faktor_koreksi || '';
                document.getElementById('edit-tegangan_benda_uji').value = d.tegangan_benda_uji || '';
                document.getElementById('edit-konversi_silider6').value = d.konversi_silider6 || '';
                document.getElementById('edit-konversi_kubus15').value = d.konversi_kubus15 || '';
                document.getElementById('edit-konversi_umur28').value = d.konversi_umur28 || '';
                document.getElementById('edit-konversi_umur28_mpa').value = d.konversi_umur28_mpa || '';
                document.getElementById('edit-kode_benda_uji').value = d.kode_benda_uji || '';
                document.getElementById('edit-diameter').value = d.diameter || '';
                document.getElementById('edit-diameter_akhir').value = d.diameter_akhir || '';
                document.getElementById('edit-luas_penampang').value = d.luas_penampang || '';
                document.getElementById('edit-luas_penampang_akhir').value = d.luas_penampang_akhir || '';
                document.getElementById('edit-beban_tarik_leleh').value = d.beban_tarik_leleh || '';
                document.getElementById('edit-tegangan_tarik').value = d.tegangan_tarik || '';
                document.getElementById('edit-tegangan_leleh').value = d.tegangan_leleh || '';
                document.getElementById('edit-panjang_awal').value = d.panjang_awal || '';
                document.getElementById('edit-panjang_akhir').value = d.panjang_akhir || '';
                document.getElementById('edit-perpanjangan').value = d.perpanjangan || '';
                document.getElementById('edit-reduksi_penampang').value = d.reduksi_penampang || '';
                document.getElementById('edit-catatan_teknisi').value = d.catatan_teknisi || '';

                new bootstrap.Modal(document.getElementById('editModal')).show();
            })
            .catch(function() { alert('Terjadi kesalahan koneksi'); });
        }

        function saveEdit() {
            var id = document.getElementById('edit-id').value;
                if (!document.getElementById('edit-proyek').value.trim()) { alert('Proyek wajib diisi.'); return; }
                if (!document.getElementById('edit-lokasi').value.trim()) { alert('Lokasi wajib diisi.'); return; }
                var payload = {
                id: id,
                csrf_token: getCsrfToken(),
                proyek: document.getElementById('edit-proyek').value,
                lokasi: document.getElementById('edit-lokasi').value,
                objek: (editIsBesi || isPavingRow) ? document.getElementById('edit-objek').value : '',
                parameter_uji: document.getElementById('edit-parameter_uji').value,
                nilai_hasil: document.getElementById('edit-nilai_hasil').value,
                satuan: document.getElementById('edit-satuan').value,
                kesimpulan: document.getElementById('edit-kesimpulan').value,
                tgl_cor: document.getElementById('edit-tgl_cor').value,
                tgl_ditest: document.getElementById('edit-tgl_ditest').value,
                umur_hari: document.getElementById('edit-umur_hari').value,
                berat_kg: document.getElementById('edit-berat_kg').value,
                kode_beton: document.getElementById('edit-kode_beton').value,
                beban_maksimum: editIsBesi ? document.getElementById('edit-beban_tarik_maks').value : document.getElementById('edit-beban_maksimum').value,
                tegangan_tekan: document.getElementById('edit-tegangan_tekan').value,
                tegangan_tekan_28_hari: document.getElementById('edit-tegangan_tekan_28_hari').value,
                mpa: document.getElementById('edit-mpa').value,
                rata_rata: document.getElementById('edit-rata_rata').value,
                mutu_beton: document.getElementById('edit-mutu_beton').value,
                bentang_l: document.getElementById('edit-bentang_l').value,
                dimensi_b: document.getElementById('edit-dimensi_b').value,
                dimensi_h: document.getElementById('edit-dimensi_h').value,
                modulus_seksi: document.getElementById('edit-modulus_seksi').value,
                momen_lentur: document.getElementById('edit-momen_lentur').value,
                tegangan_lentur: document.getElementById('edit-tegangan_lentur').value,
                tinggi_l: document.getElementById('edit-tinggi_l').value,
                rasio_ld: document.getElementById('edit-rasio_ld').value,
                faktor_koreksi: document.getElementById('edit-faktor_koreksi').value,
                tegangan_benda_uji: document.getElementById('edit-tegangan_benda_uji').value,
                konversi_silider6: document.getElementById('edit-konversi_silider6').value,
                konversi_kubus15: document.getElementById('edit-konversi_kubus15').value,
                konversi_umur28: document.getElementById('edit-konversi_umur28').value,
                konversi_umur28_mpa: document.getElementById('edit-konversi_umur28_mpa').value,
                diameter: editIsCore ? document.getElementById('edit-core_diameter').value : document.getElementById('edit-diameter').value,
                diameter_akhir: document.getElementById('edit-diameter_akhir').value,
                luas_penampang: editIsCore ? document.getElementById('edit-core_luas_penampang').value : document.getElementById('edit-luas_penampang').value,
                luas_penampang_akhir: document.getElementById('edit-luas_penampang_akhir').value,
                beban_tarik_leleh: document.getElementById('edit-beban_tarik_leleh').value,
                tegangan_tarik: document.getElementById('edit-tegangan_tarik').value,
                tegangan_leleh: document.getElementById('edit-tegangan_leleh').value,
                panjang_awal: document.getElementById('edit-panjang_awal').value,
                panjang_akhir: document.getElementById('edit-panjang_akhir').value,
                perpanjangan: document.getElementById('edit-perpanjangan').value,
                reduksi_penampang: document.getElementById('edit-reduksi_penampang').value,
                kode_benda_uji: document.getElementById('edit-kode_benda_uji').value,
                catatan_teknisi: document.getElementById('edit-catatan_teknisi').value
            };
            fetch('edit_historis_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.ok) { alert(res.msg || 'Gagal menyimpan'); return; }
                bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
                location.reload();
            })
            .catch(function() { alert('Terjadi kesalahan koneksi'); });
        }

        function hitungUmurEditModal() {
            var cor = document.getElementById('edit-tgl_cor').value;
            var dites = document.getElementById('edit-tgl_ditest').value;
            var umur = document.getElementById('edit-umur_hari');
            var d1 = parseTanggalManual(cor);
            var d2 = parseTanggalManual(dites);
            umur.value = (d1 && d2) ? Math.max(0, Math.floor((d2 - d1) / (1000 * 60 * 60 * 24))) : '';
        }
    </script>
</body>
</html>
