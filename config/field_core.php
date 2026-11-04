<?php
/**
 * config/field_core.php
 * Definisi tunggal kolom laporan Uji Tekan Core Drill untuk form input
 * dan seluruh laporan - selalu identik dengan format resmi (foto).
 *
 * Struktur laporan (header 2 level, 18 kolom):
 *   No. | Tanggal Pembuatan | Tanggal Test | CODE
 *   | Diameter (D) Cm | Tinggi (L) Cm | Umur Beton Hari | Luas Penampang (A) Cm
 *   | Berat (G) kg | Beban (P) Kg | L/D | Faktor Koreksi C
 *   | Tegangan Benda Uji dia 4" Kg/Cm2 | Konversi Tegangan Utk Silider 6" Kg/Cm2
 *   | Koversi Tegangan Untuk Kubus 15x15x15 Kg/Cm2
 *   | Konversi Umur Beton Ke 28 Hari Kg/Cm2 | Tegangan Mpa | Konversi Umur ke 28 Hari Mpa
 */

require_once __DIR__ . '/field_silinder.php';

/** Deteksi jenis pengujian Uji Tekan Core Drill dari nama jenis */
function isUjiCoreDrill($nama) {
    if (!is_string($nama)) {
        return false;
    }
    return stripos(trim($nama), 'Uji Tekan Core Drill') === 0;
}

/** Label singkat untuk judul baris input */
function fieldCoreShortLabel($nama) {
    return 'Core Drill';
}

/**
 * Daftar kolom (tanpa kolom No.).
 *   group = teks header baris atas (null = header 1 baris / rowspan)
 *   head  = teks header baris bawah
 *   label = teks label pada form input
 *   db    = null -> kolom tanpa data (sesuai format resmi)
 */
function fieldCore() {
    return [
        ['db' => 'tgl_cor',            'group' => 'Tanggal Pembuatan', 'head' => '',                            'label' => 'Tanggal Pembuatan',                   'type' => 'date',   'align' => 'center'],
        ['db' => 'tgl_ditest',         'group' => 'Tanggal Test',      'head' => '',                            'label' => 'Tanggal Test',                        'type' => 'date',   'align' => 'center'],
        ['db' => 'kode_beton',         'group' => 'CODE',              'head' => '',                            'label' => 'CODE',                                'type' => 'text',   'align' => 'left'],
        ['db' => 'diameter',           'group' => 'Diameter (D)',      'head' => 'Cm',                          'label' => 'Diameter (D) (Cm)',                   'type' => 'number', 'align' => 'center'],
        ['db' => 'tinggi_l',           'group' => 'Tinggi (L)',        'head' => 'Cm',                          'label' => 'Tinggi (L) (Cm)',                     'type' => 'number', 'align' => 'center'],
        ['db' => 'umur_hari',          'group' => 'Umur Beton',        'head' => 'Hari',                        'label' => 'Umur Beton (Hari)',                   'type' => 'number', 'align' => 'center'],
        ['db' => 'luas_penampang',     'group' => 'Luas Penampang (A)','head' => 'Cm',                          'label' => 'Luas Penampang (A) (Cm)',             'type' => 'number', 'align' => 'center'],
        ['db' => 'berat_kg',           'group' => 'Berat (G)',         'head' => 'kg',                          'label' => 'Berat (G) (Kg)',                      'type' => 'number', 'align' => 'center'],
        ['db' => 'beban_maksimum',     'group' => 'Beban (P)',         'head' => 'Kg',                          'label' => 'Beban (P) (Kg)',                      'type' => 'number', 'align' => 'center'],
        ['db' => 'rasio_ld',           'group' => 'L',                 'head' => 'D',                           'label' => 'L / D',                               'type' => 'number', 'align' => 'center'],
        ['db' => 'faktor_koreksi',     'group' => 'Faktor Koreksi',    'head' => 'C',                           'label' => 'Faktor Koreksi (C)',                  'type' => 'number', 'align' => 'center'],
        ['db' => 'tegangan_benda_uji', 'group' => 'Tegangan Benda Uji &phi; 4&quot;', 'head' => 'Kg/Cm&sup2;',      'label' => 'Tegangan Benda Uji &phi; 4&quot; (Kg/Cm&sup2;)', 'type' => 'number', 'align' => 'center'],
        ['db' => 'konversi_silider6',  'group' => 'Konversi Tegangan Utk Silider 6&quot;', 'head' => 'Kg/Cm&sup2;', 'label' => 'Konversi Tegangan Utk Silider 6&quot; (Kg/Cm&sup2;)', 'type' => 'number', 'align' => 'center'],
        ['db' => 'konversi_kubus15',   'group' => 'Koversi Tegangan Untuk Kubus 15x15x15', 'head' => 'Kg/Cm&sup2;', 'label' => 'Konversi Tegangan Untuk Kubus 15x15x15 (Kg/Cm&sup2;)', 'type' => 'number', 'align' => 'center'],
        ['db' => 'konversi_umur28',    'group' => 'Konversi Umur Beton Ke 28 Hari', 'head' => 'Kg/Cm&sup2;',             'label' => 'Konversi Umur Beton Ke 28 Hari (Kg/Cm&sup2;)', 'type' => 'number', 'align' => 'center'],
        ['db' => 'mpa',                'group' => 'Tegangan',          'head' => 'Mpa',                         'label' => 'Tegangan (Mpa)',                      'type' => 'number', 'align' => 'center'],
        ['db' => 'konversi_umur28_mpa','group' => 'Konversi Umur ke 28 Hari', 'head' => 'Mpa',                    'label' => 'Konversi Umur ke 28 Hari (Mpa)',      'type' => 'number', 'align' => 'center'],
    ];
}

/** Nama kolom "No." diletakkan pertama pada setiap tabel */
function fieldCoreNoLabel() {
    return 'No.';
}

/** Jumlah total kolom termasuk kolom No. */
function fieldCoreJumlahKolom() {
    return count(fieldCore()) + 1;
}

/** Header tabel 2 level sesuai format laporan resmi */
function fieldCoreThead() {
    $fields = fieldCore();
    ob_start(); ?>
    <thead>
        <tr>
            <th rowspan="2" style="width:40px;"><?= htmlspecialchars(fieldCoreNoLabel()) ?></th>
            <?php foreach ($fields as $f):
                $isRowspan = ($f['head'] === '' || $f['head'] === null); ?>
                <th<?= $isRowspan ? ' rowspan="2"' : '' ?>><?= $f['group'] ?></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach ($fields as $f):
                if ($f['head'] === '' || $f['head'] === null) continue; ?>
                <th><?= $f['head'] ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <?php
    return ob_get_clean();
}

/** Satu baris data, mengikuti urutan persis header di atas */
function fieldCoreRow($baris, $no, $fallbackKode = null) {
    $fields = fieldCore();
    ob_start(); ?>
    <tr>
        <td><?= $no ?></td>
        <?php foreach ($fields as $f): ?>
            <td style="text-align:<?= $f['align']; ?>;"><?= $f['db'] === null ? '-' : fieldSilinderNilai($baris, $f['db'], $fallbackKode) ?></td>
        <?php endforeach; ?>
    </tr>
    <?php
    return ob_get_clean();
}

/** Satu baris kosong (data belum diinput) - hanya kolom No. yang terisi */
function fieldCoreRowKosong($no) {
    ob_start(); ?>
    <tr>
        <td><?= $no ?></td>
        <td colspan="<?= fieldCoreJumlahKolom() - 1 ?>" class="text-center">-</td>
    </tr>
    <?php
    return ob_get_clean();
}
