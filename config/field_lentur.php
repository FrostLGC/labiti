<?php
/**
 * config/field_lentur.php
 * Definisi tunggal kolom laporan Uji Lentur / Flexural Balok Beton (15x60 Cm)
 * untuk form input dan seluruh laporan - selalu identik dengan format resmi (foto).
 *
 * Struktur laporan (header 2 level, 14 kolom):
 *   No. | Tanggal [Di cor, Di Test] | Umur (Hari) | Mutu | Kode | Bentang L (Cm)
 *      | Dimensi [b (Cm), h (Cm)] | Beban (P) (Kg) | W 1/6.b.h2 (Cm3)
 *      | Momen Lentur M=1/6.P.L (KgCm) | Tegangan Lentur (M/W) (Kg/cm2)
 *      | Keterangan
 */

require_once __DIR__ . '/field_silinder.php';

/** Deteksi jenis pengujian Uji Lentur / Flexural Balok Beton dari nama jenis */
function isUjiLentur($nama) {
    if (!is_string($nama)) {
        return false;
    }
    $n = trim($nama);
    return stripos($n, 'Uji Lentur') === 0 || stripos($n, 'Flexural') !== false;
}

/** Label singkat untuk judul baris input */
function fieldLenturShortLabel($nama) {
    return 'Balok Beton';
}

/**
 * Daftar kolom (tanpa kolom No.).
 *   group = teks header baris atas (null = header 1 baris / rowspan)
 *   head  = teks header baris bawah
 *   label = teks label pada form input
 */
function fieldLentur() {
    return [
        ['db' => 'tgl_cor',          'group' => 'Tanggal', 'head' => 'Di cor',        'label' => 'Tgl. Di Cor',                      'type' => 'date',   'align' => 'center'],
        ['db' => 'tgl_ditest',       'group' => 'Tanggal', 'head' => 'Di Test',       'label' => 'Tgl. Di Test',                     'type' => 'date',   'align' => 'center'],
        ['db' => 'umur_hari',        'group' => 'Umur',    'head' => '( Hari )',      'label' => 'Umur (Hari)',                      'type' => 'number', 'align' => 'center'],
        ['db' => 'mutu_beton',       'group' => null,      'head' => 'Mutu',          'label' => 'Mutu',                             'type' => 'text',   'align' => 'center'],
        ['db' => 'kode_beton',       'group' => null,      'head' => 'Kode',          'label' => 'Kode',                             'type' => 'text',   'align' => 'left'],
        ['db' => 'bentang_l',        'group' => 'Bentang L', 'head' => '(Cm)',        'label' => 'Bentang L (Cm)',                   'type' => 'number', 'align' => 'center'],
        ['db' => 'dimensi_b',        'group' => 'Dimensi', 'head' => 'b (Cm)',        'label' => 'Dimensi b (Cm)',                   'type' => 'number', 'align' => 'center'],
        ['db' => 'dimensi_h',        'group' => 'Dimensi', 'head' => 'h (Cm)',        'label' => 'Dimensi h (Cm)',                   'type' => 'number', 'align' => 'center'],
        ['db' => 'beban_maksimum',   'group' => 'Beban (P)', 'head' => '(Kg)',        'label' => 'Beban (P) (Kg)',                   'type' => 'number', 'align' => 'center'],
        ['db' => 'modulus_seksi',    'group' => 'W 1/6.b.h&sup2;', 'head' => '(Cm3)', 'label' => 'W 1/6.b.h&sup2; (Cm3)',            'type' => 'number', 'align' => 'center'],
        ['db' => 'momen_lentur',     'group' => 'Momen Lentur M=1/6.P.L', 'head' => '(KgCm)', 'label' => 'Momen Lentur M=1/6.P.L (KgCm)', 'type' => 'number', 'align' => 'center'],
        ['db' => 'tegangan_lentur',  'group' => 'Tegangan Lentur (M/W)', 'head' => '(Kg/cm&sup2;)', 'label' => 'Tegangan Lentur (M/W) (Kg/cm&sup2;)', 'type' => 'number', 'align' => 'center'],
        ['db' => 'catatan_teknisi',  'group' => null,      'head' => 'Keterangan',    'label' => 'Keterangan',                        'type' => 'text',   'align' => 'left'],
    ];
}

/** Nama kolom "No." diletakkan pertama pada setiap tabel */
function fieldLenturNoLabel() {
    return 'No.';
}

/** Jumlah total kolom termasuk kolom No. */
function fieldLenturJumlahKolom() {
    return count(fieldLentur()) + 1;
}

/** Header tabel 2 level sesuai format laporan resmi */
function fieldLenturThead() {
    $fields = fieldLentur();
    ob_start(); ?>
    <thead>
        <tr>
            <th rowspan="2" style="width:40px;"><?= htmlspecialchars(fieldLenturNoLabel()) ?></th>
            <?php
            $i = 0; $n = count($fields);
            while ($i < $n) {
                $g = $fields[$i]['group'];
                if ($g === null) { ?>
                    <th rowspan="2"><?= $fields[$i]['head'] ?></th>
                <?php
                    $i++;
                    continue;
                }
                $span = 1;
                while ($i + $span < $n && $fields[$i + $span]['group'] === $g) $span++;
                if ($span > 1) { ?>
                    <th colspan="<?= $span ?>"><?= $g ?></th>
                    <?php
                } else { ?>
                    <th><?= $g ?></th>
                    <?php
                }
                $i += $span;
            }
            ?>
        </tr>
        <tr>
            <?php foreach ($fields as $f): if ($f['group'] === null) continue; ?>
                <th><?= $f['head'] ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <?php
    return ob_get_clean();
}

/** Satu baris data, mengikuti urutan persis header di atas */
function fieldLenturRow($baris, $no, $fallbackKode = null) {
    $fields = fieldLentur();
    ob_start(); ?>
    <tr>
        <td><?= $no ?></td>
        <?php foreach ($fields as $f): ?>
            <td style="text-align:<?= $f['align']; ?>;"><?= fieldSilinderNilai($baris, $f['db'], $fallbackKode) ?></td>
        <?php endforeach; ?>
    </tr>
    <?php
    return ob_get_clean();
}

/** Satu baris kosong (data belum diinput) - hanya kolom No. yang terisi */
function fieldLenturRowKosong($no) {
    ob_start(); ?>
    <tr>
        <td><?= $no ?></td>
        <td colspan="<?= fieldLenturJumlahKolom() - 1 ?>" class="text-center">-</td>
    </tr>
    <?php
    return ob_get_clean();
}
