<?php
    const HARGA_TAMBAHAN_CETAK = 250000;
    const HARGA_TAMBAHAN_SETTING = 100000;

function labelStatus($status) {
    $map = [
        'menunggu_verifikasi'   => ['Menunggu Verifikasi', 'secondary'],
        'booking_diterima'      => ['Booking Diterima', 'info'],
        'jadwal_ditentukan'     => ['Jadwal Ditentukan', 'info'],
        'sampel_diterima'       => ['Sampel Diterima', 'primary'],
        'menunggu_diuji'        => ['Menunggu Diuji', 'warning'],
        'sedang_diuji'          => ['Sedang Diuji', 'primary'],
        'menunggu_input_admin'  => ['Menunggu Input Admin', 'warning'],
        'validasi_hasil'        => ['Validasi Hasil', 'warning'],
        'menunggu_pembayaran'   => ['Menunggu Pembayaran', 'warning'],
        'selesai'               => ['Selesai', 'success'],
        'ditolak'               => ['Ditolak', 'danger'],
    ];
    return $map[$status] ?? [$status, 'secondary'];
}

function bulanRomawi($bulan) {
    $romawi = ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
    return $romawi[(int)$bulan - 1] ?? $bulan;
}

function kodeJenisUji($namaPengujian) {
    $nama = strtolower($namaPengujian);
    if (strpos($nama, 'tarik') !== false) return 'UTa';
    if (strpos($nama, 'tekuk') !== false) return 'UTk';
    if (strpos($nama, 'lentur') !== false || strpos($nama, 'flexural') !== false) return 'UL';
    if (strpos($nama, 'setting') !== false || strpos($nama, 'potong') !== false || strpos($nama, 'cetak') !== false) return 'US';
    return 'UT';
}

function kodeKategori($kategori) {
    $map = [
        'beton' => 'BTN',
        'besi'  => 'BS',
        'tanah' => 'TNH',
    ];
    return $map[strtolower($kategori)] ?? strtoupper(substr($kategori, 0, 3));
}

function isCoreDrillUji($namaPengujian) {
    return stripos((string) $namaPengujian, 'Uji Tekan Core Drill') === 0;
}

function kategoriDariNamaJenis($pdo, $namaPengujian) {
    static $cache = [];
    if (!array_key_exists($namaPengujian, $cache)) {
        $stmt = $pdo->prepare("SELECT kategori FROM jenis_pengujian WHERE nama_pengujian = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$namaPengujian]);
        $val = $stmt->fetchColumn();
        $cache[$namaPengujian] = ($val === false || $val === null || $val === '') ? null : $val;
    }
    return $cache[$namaPengujian];
}

function generateNomorLaporan($pdo, $jenisPengujianId, $namaPengujian, $kategori) {
    $kodeKat = kodeKategori($kategori);
    $kode = kodeJenisUji($namaPengujian);
    $tahun2 = date('y');
    $bulan = bulanRomawi(date('m'));
    $prefix = 'ITI-U/' . $bulan . '/' . $tahun2 . '/' . $kodeKat . '/' . $kode . '/';

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM laporan WHERE DATE(tanggal_terbit) = CURDATE()"
    );
    $stmt->execute([]);
    $nomorUrut = (int) $stmt->fetchColumn() + 1;

    $cek = $pdo->prepare("SELECT COUNT(*) FROM laporan WHERE nomor_laporan = ?");
    while ($cek->execute([$prefix . str_pad($nomorUrut, 2, '0', STR_PAD_LEFT)]) && (int) $cek->fetchColumn() > 0) {
        $nomorUrut++;
    }

    return $prefix . str_pad($nomorUrut, 2, '0', STR_PAD_LEFT);
}

function generateKodeBooking($pdo, $jenisPengujianId, $namaPengujian, $kategori) {
    $kodeKat = kodeKategori($kategori);
    $kode = kodeJenisUji($namaPengujian);
    $tahun2 = date('y');
    $bulan = bulanRomawi(date('m'));
    $prefix = 'ITI-U/' . $bulan . '/' . $tahun2 . '/' . $kodeKat . '/' . $kode . '/';

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM bookings WHERE DATE(created_at) = CURDATE()"
    );
    $stmt->execute([]);
    $nomorUrut = (int) $stmt->fetchColumn() + 1;

    $cek = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE kode_booking = ?");
    while ($cek->execute([$prefix . str_pad($nomorUrut, 2, '0', STR_PAD_LEFT)]) && (int) $cek->fetchColumn() > 0) {
        $nomorUrut++;
    }

    return $prefix . str_pad($nomorUrut, 2, '0', STR_PAD_LEFT);
}

function generateKodeHistoris($pdo) {
    $tahun2 = date('y');
    $bulan = bulanRomawi(date('m'));
    $prefix = 'ITI-U/' . $bulan . '/' . $tahun2 . '/';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM hasil_pengujian WHERE DATE(created_at) = CURDATE()");
    $stmt->execute([]);
    $nomorUrut = (int) $stmt->fetchColumn() + 1;

    $cek = $pdo->prepare("SELECT COUNT(*) FROM hasil_pengujian WHERE kode = ?");
    while ($cek->execute([$prefix . str_pad($nomorUrut, 2, '0', STR_PAD_LEFT)]) && (int) $cek->fetchColumn() > 0) {
        $nomorUrut++;
    }

    return $prefix . str_pad($nomorUrut, 2, '0', STR_PAD_LEFT);
}

function tanggalManualKeYmd($nilai) {
    $t = trim((string) $nilai);
    if ($t === '') return null;

    if (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$#', $t, $m)) {
        [$h, $b, $t2] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if (!checkdate($b, $h, $t2)) return null;
        return sprintf('%04d-%02d-%02d', $t2, $b, $h);
    }

    if (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$#', $t, $m)) {
        [$t2, $b, $h] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if (!checkdate($b, $h, $t2)) return null;
        return sprintf('%04d-%02d-%02d', $t2, $b, $h);
    }

    $ts = strtotime($t);
    return $ts ? date('Y-m-d', $ts) : null;
}

function tanggalValid($nilai) {
    $t = trim((string) $nilai);
    if (!preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $t, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function minSampelPengujian($namaPengujian, $kategori = '') {
    $nama = strtolower((string) $namaPengujian);
    $kat  = strtolower((string) $kategori);

    if ($kat === 'besi') {
        if (strpos($nama, 'setting') !== false) return 1;
        if (strpos($nama, 'tekuk') !== false) return 1;
        if (strpos($nama, 'tarik') !== false) return 3;
        return 3;
    }

    if (strpos($nama, 'core drill') !== false) return 3;
    if (strpos($nama, 'lentur') !== false || strpos($nama, 'flexural') !== false || strpos($nama, 'balok') !== false) return 3;

    if (strpos($nama, 'bata merah') !== false) return 6;
    if (strpos($nama, 'hebel') !== false) return 6;
    if (strpos($nama, 'mortar') !== false) return 6;
    if (strpos($nama, 'paving') !== false || strpos($nama, 'uskup') !== false || strpos($nama, 'kanstin') !== false) return 6;
    if (strpos($nama, 'silinder') !== false) return 6;
    if (strpos($nama, 'kubus') !== false) return 6;

    return ($kat === 'beton') ? 3 : 1;
}