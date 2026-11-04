<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/field_silinder.php';
require_once __DIR__ . '/../config/field_kubus.php';
require_once __DIR__ . '/../config/labels.php';
requireRole(['admin']);

header('Content-Type: application/json');

$user = currentUser($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);

    $token = $payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        echo json_encode(['ok' => false, 'msg' => 'Token CSRF tidak valid']);
        exit;
    }

    $id = (int) ($payload['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'ID tidak valid']);
        exit;
    }

    // Kategori ditentukan dari data tersimpan: tgl_cor kosong = besi
    $stmtCek = $pdo->prepare("SELECT tgl_cor, jenis_pengujian FROM hasil_pengujian WHERE id = ? AND sampel_id IS NULL");
    $stmtCek->execute([$id]);
    $rowLama = $stmtCek->fetch();
    if (!$rowLama) {
        echo json_encode(['ok' => false, 'msg' => 'Data tidak ditemukan']);
        exit;
    }
    $isSilinder = isUjiSilinder($rowLama['jenis_pengujian']);
    $isKubus = isUjiKubus($rowLama['jenis_pengujian']);
    $isPaving = isUjiPaving($rowLama['jenis_pengujian']);
    $isLentur = isUjiLentur($rowLama['jenis_pengujian']);
    $isCore = isUjiCoreDrill($rowLama['jenis_pengujian']);
    $isTabelBeton = $isSilinder || $isKubus || $isPaving || $isLentur || $isCore;
    $stmtKat = $pdo->prepare("SELECT kategori FROM jenis_pengujian WHERE nama_pengujian = ? ORDER BY id DESC LIMIT 1");
    $stmtKat->execute([$rowLama['jenis_pengujian']]);
    $kategoriJenis = $stmtKat->fetchColumn();
    $isBesi = ($kategoriJenis !== false && $kategoriJenis !== null && $kategoriJenis !== '')
        ? ($kategoriJenis === 'besi')
        : (!$isTabelBeton && empty($rowLama['tgl_cor']));

    $tglCor = $isBesi ? null : tanggalManualKeYmd($payload['tgl_cor'] ?? '');
    $tglDites = tanggalManualKeYmd($payload['tgl_ditest'] ?? '');
    $proyek = trim($payload['proyek'] ?? '');
    $lokasi = trim($payload['lokasi'] ?? '');
    $objek = trim($payload['objek'] ?? '') ?: null;
    if ($proyek === '' || $lokasi === '') {
        echo json_encode(['ok' => false, 'msg' => 'Proyek dan lokasi wajib diisi']);
        exit;
    }
    if ($isBesi && $objek === null) {
        echo json_encode(['ok' => false, 'msg' => 'Obyek wajib diisi untuk pengujian besi']);
        exit;
    }
    $umurHari = $tglCor && $tglDites
        ? (new DateTime($tglCor))->diff(new DateTime($tglDites))->days
        : null;

    if ($isBesi) {
        $parameterUji = null;
        $nilaiHasil = null;
        $satuan = null;
        $kesimpulan = null;
        $beratKg = null;
        $kodeBeton = null;
        $mutuBeton = null;
        $teganganTekan = null;
        $teganganTekan28 = null;
        $rataRata = null;
        $mpa = null;
        $diameter = ($payload['diameter'] ?? '') ?: null;
        $luasPenampang = ($payload['luas_penampang'] ?? '') ?: null;
        $kodeBendaUji = trim($payload['kode_benda_uji'] ?? '') ?: null;
        $diameterAkhir = ($payload['diameter_akhir'] ?? '') ?: null;
        $luasPenampangAkhir = ($payload['luas_penampang_akhir'] ?? '') ?: null;
        $bebanTarikLeleh = ($payload['beban_tarik_leleh'] ?? '') ?: null;
        $teganganTarik = ($payload['tegangan_tarik'] ?? '') ?: null;
        $teganganLeleh = ($payload['tegangan_leleh'] ?? '') ?: null;
        $panjangAwal = ($payload['panjang_awal'] ?? '') ?: null;
        $panjangAkhir = ($payload['panjang_akhir'] ?? '') ?: null;
        $perpanjangan = ($payload['perpanjangan'] ?? '') ?: null;
        $reduksi = ($payload['reduksi_penampang'] ?? '') ?: null;
        $catatan = trim($payload['catatan_teknisi'] ?? '') ?: null;
    } else {
        $parameterUji = trim($payload['parameter_uji'] ?? '');
        $nilaiHasil = trim($payload['nilai_hasil'] ?? '');
        $satuan = trim($payload['satuan'] ?? '');
        $kesimpulan = $payload['kesimpulan'] ?? null;
        $beratKg = ($payload['berat_kg'] ?? '') ?: null;
        $kodeBeton = trim($payload['kode_beton'] ?? '') ?: null;
        $mutuBeton = ($payload['mutu_beton'] ?? '') ?: null;
        $teganganTekan = ($payload['tegangan_tekan'] ?? '') ?: null;
        $teganganTekan28 = ($payload['tegangan_tekan_28_hari'] ?? '') ?: null;
        $rataRata = $isPaving ? (($payload['rata_rata'] ?? '') ?: null) : null;
        $mpa = $isPaving ? null : (($payload['mpa'] ?? '') ?: null);
        // Uji Lentur / Flexural Balok Beton: semua nilai diisi manual admin
        $bentangL = $isLentur ? (($payload['bentang_l'] ?? '') ?: null) : null;
        $dimensiB = $isLentur ? (($payload['dimensi_b'] ?? '') ?: null) : null;
        $dimensiH = $isLentur ? (($payload['dimensi_h'] ?? '') ?: null) : null;
        $modulusSeksi = $isLentur ? (($payload['modulus_seksi'] ?? '') ?: null) : null;
        $momenLentur = $isLentur ? (($payload['momen_lentur'] ?? '') ?: null) : null;
        $teganganLentur = $isLentur ? (($payload['tegangan_lentur'] ?? '') ?: null) : null;
        // Uji Tekan Core Drill: seluruh kolom diisi manual admin sesuai format laporan
        $tinggiL = $isCore ? (($payload['tinggi_l'] ?? '') ?: null) : null;
        $rasioLd = $isCore ? (($payload['rasio_ld'] ?? '') ?: null) : null;
        $faktorKoreksi = $isCore ? (($payload['faktor_koreksi'] ?? '') ?: null) : null;
        $teganganBendaUji = $isCore ? (($payload['tegangan_benda_uji'] ?? '') ?: null) : null;
        $konvSilider6 = $isCore ? (($payload['konversi_silider6'] ?? '') ?: null) : null;
        $konvKubus15 = $isCore ? (($payload['konversi_kubus15'] ?? '') ?: null) : null;
        $konvUmur28 = $isCore ? (($payload['konversi_umur28'] ?? '') ?: null) : null;
        $konvUmur28Mpa = $isCore ? (($payload['konversi_umur28_mpa'] ?? '') ?: null) : null;
        $diameter = ($payload['diameter'] ?? '') ?: null;
        $luasPenampang = $isCore
            ? (($payload['luas_penampang'] ?? '') ?: null)
            : ($diameter ? round(M_PI * pow($diameter / 2, 2), 4) : null);
        $kodeBendaUji = null;
        $diameterAkhir = null;
        $luasPenampangAkhir = null;
        $bebanTarikLeleh = null;
        $teganganTarik = null;
        $teganganLeleh = null;
        $panjangAwal = null;
        $panjangAkhir = null;
        $perpanjangan = null;
        $reduksi = null;
        // Beton juga menyimpan catatan (ditampilkan di laporan beton)
        $catatan = trim($payload['catatan_teknisi'] ?? '') ?: null;
    }

    $bebanMaks = ($payload['beban_maksimum'] ?? '') ?: null;

    if ($isTabelBeton) {
        $parameterUji = $isCore ? 'Uji Tekan Core Drill' : ($isLentur ? 'Uji Lentur' : 'Kuat Tekan');
        $kesimpulan = null;
        if ($isCore) {
            // Uji Tekan Core Drill: nilai hasil = Tegangan Benda Uji, satuan Kg/cm²
            $nilaiHasil = $teganganBendaUji ?: null;
            $satuan = $nilaiHasil !== null ? 'Kg/cm²' : null;
            $rataRata = null;
            $teganganTekan = null;
            $teganganTekan28 = null;
        } elseif ($isLentur) {
            // Uji Lentur: nilai hasil = Tegangan Lentur (M/W), satuan Kg/cm²
            $nilaiHasil = $teganganLentur ?: null;
            $satuan = $nilaiHasil !== null ? 'Kg/cm²' : null;
            $mpa = null;
            $rataRata = null;
            $beratKg = null;
            $teganganTekan = null;
            $teganganTekan28 = null;
            $mutuBeton = trim($payload['mutu_beton'] ?? '') ?: null;
        } elseif ($isKubus) {
            // Kubus: kolom terakhir tabel adalah Keterangan (catatan_teknisi)
            $nilaiHasil = ($teganganTekan28 ?: $teganganTekan) ?: null;
            $satuan = $nilaiHasil !== null ? 'Kg/cm²' : null;
            $mpa = null;
            $rataRata = null;
        } elseif ($isPaving) {
            // Paving/Bata Merah/Hebel: kolom terakhir adalah Rata-rata (input manual)
            $nilaiHasil = ($rataRata ?: ($teganganTekan28 ?: $teganganTekan)) ?: null;
            $satuan = $nilaiHasil !== null ? 'Kg/cm²' : null;
            $mpa = null;
        } else {
            $nilaiHasil = $mpa ?: null;
            $satuan = $mpa ? 'MPa' : null;
            $rataRata = null;
        }
    } else {
        $rataRata = null;
    }

    $lenturCols = '';
    $lenturParams = [];
    if ($isLentur) {
        $lenturCols = ',
            bentang_l = ?, dimensi_b = ?, dimensi_h = ?,
            modulus_seksi = ?, momen_lentur = ?, tegangan_lentur = ?';
        $lenturParams = [$bentangL, $dimensiB, $dimensiH, $modulusSeksi, $momenLentur, $teganganLentur];
    }

    $coreCols = '';
    $coreParams = [];
    if ($isCore) {
        $coreCols = ',
            tinggi_l = ?, rasio_ld = ?, faktor_koreksi = ?,
            tegangan_benda_uji = ?, konversi_silider6 = ?, konversi_kubus15 = ?, konversi_umur28 = ?,
            konversi_umur28_mpa = ?';
        $coreParams = [$tinggiL, $rasioLd, $faktorKoreksi, $teganganBendaUji, $konvSilider6, $konvKubus15, $konvUmur28, $konvUmur28Mpa];
    }

    $stmt = $pdo->prepare(
        "UPDATE hasil_pengujian SET
            kode_benda_uji = ?, parameter_uji = ?, nilai_hasil = ?, satuan = ?, kesimpulan = ?,
            tgl_cor = ?, tgl_ditest = ?, umur_hari = ?, berat_kg = ?,
            beban_maksimum = ?, tegangan_tekan = ?, tegangan_tekan_28_hari = ?,
            rata_rata = ?,
            mpa = ?,
            diameter = ?, diameter_akhir = ?, luas_penampang = ?, luas_penampang_akhir = ?,
            kode_beton = ?, mutu_beton = ?,
            beban_tarik_leleh = ?, tegangan_tarik = ?, tegangan_leleh = ?,
            panjang_awal = ?, panjang_akhir = ?, perpanjangan = ?, reduksi_penampang = ?,
            catatan_teknisi = ?, proyek = ?, lokasi = ?, objek = ?
            $lenturCols
            $coreCols
         WHERE id = ? AND sampel_id IS NULL"
    );
    $params = [
        $kodeBendaUji, $parameterUji, $nilaiHasil, $satuan, $kesimpulan,
        $tglCor, $tglDites, $umurHari, $beratKg,
        $bebanMaks, $teganganTekan, $teganganTekan28,
        $rataRata,
        $mpa,
        $diameter, $diameterAkhir, $luasPenampang, $luasPenampangAkhir,
        $kodeBeton, $mutuBeton,
        $bebanTarikLeleh, $teganganTarik, $teganganLeleh,
        $panjangAwal, $panjangAkhir, $perpanjangan, $reduksi,
        $catatan, $proyek ?: null, $lokasi ?: null, $objek ?: null,
    ];
    foreach ($lenturParams as $lp) $params[] = $lp;
    foreach ($coreParams as $cp) $params[] = $cp;
    $params[] = $id;
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['ok' => true, 'msg' => 'Data berhasil diperbarui']);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Data tidak ditemukan atau tidak ada perubahan']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $pdo->prepare(
        "SELECT h.* FROM hasil_pengujian h
         WHERE h.id = ? AND h.sampel_id IS NULL"
    );
    $stmt->execute([$id]);
    $data = $stmt->fetch();
    if ($data) {
        echo json_encode(['ok' => true, 'data' => $data]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Data tidak ditemukan']);
    }
    exit;
}
