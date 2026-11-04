<?php
/**
 * config/field_paving.php
 * Definisi tunggal kolom laporan Uji Tekan Paving, Uskup, Kanstin,
 * Uji Tekan Bata Merah dan Uji Tekan Hebel untuk form input dan seluruh
 * laporan - selalu identik dengan format resmi (foto).
 *
 * Struktur laporan (1 level header, 10 kolom, kolom terakhir = Rata-rata):
 *   No. | Tgl. Di Buat | Tgl. Ditest | Umur/Hari | Berat (Kg) | Kode
 *      | Beban Maksimum (Kg) | Tegangan Tekan (Kg/cm2)
 *      | Tegangan Tekan 28 hari (Kg/cm2) | Rata-rata (Kg/cm2)
 */

require_once __DIR__ . '/field_silinder.php';

/** Deteksi jenis pengujian Paving/Uskup/Kanstin, Bata Merah, Hebel dari nama jenis */
function isUjiPaving($nama) {
    if (!is_string($nama)) {
        return false;
    }
    $n = trim($nama);
    foreach (['Uji Tekan Paving', 'Uji Tekan Bata Merah', 'Uji Tekan Hebel'] as $prefix) {
        if (stripos($n, $prefix) === 0) {
            return true;
        }
    }
    return false;
}

/** Label singkat untuk judul baris input (mis. "Paving, Uskup, Kanstin") */
function fieldPavingShortLabel($nama) {
    if (is_string($nama)) {
        if (stripos($nama, 'Bata Merah') !== false) {
            return 'Bata Merah';
        }
        if (stripos($nama, 'Hebel') !== false) {
            return 'Hebel';
        }
    }
    return 'Paving, Uskup, Kanstin';
}

/** Nama kolom "No." diletakkan pertama pada setiap tabel */
function fieldPavingNoLabel() {
    return 'No.';
}

function fieldPaving() {
    return [
        ['db' => 'tgl_cor',               'label' => 'Tgl. Di Buat',                       'type' => 'date',   'align' => 'center'],
        ['db' => 'tgl_ditest',            'label' => 'Tgl. Ditest',                        'type' => 'date',   'align' => 'center'],
        ['db' => 'umur_hari',             'label' => 'Umur/Hari',                          'type' => 'number', 'align' => 'center'],
        ['db' => 'berat_kg',              'label' => 'Berat (Kg)',                         'type' => 'number', 'align' => 'center'],
        ['db' => 'kode_beton',            'label' => 'Kode',                               'type' => 'text',   'align' => 'left'],
        ['db' => 'beban_maksimum',        'label' => 'Beban Maksimum (Kg)',                'type' => 'number', 'align' => 'center'],
        ['db' => 'tegangan_tekan',        'label' => 'Tegangan Tekan (Kg/cm&sup2;)',       'type' => 'number', 'align' => 'center'],
        ['db' => 'tegangan_tekan_28_hari','label' => 'Tegangan Tekan 28 hari (Kg/cm&sup2;)','type' => 'number', 'align' => 'center'],
        ['db' => 'rata_rata',             'label' => 'Rata-rata (Kg/cm&sup2;)',            'type' => 'number', 'align' => 'center'],
    ];
}

/** Jumlah total kolom termasuk kolom No. */
function fieldPavingJumlahKolom() {
    return count(fieldPaving()) + 1;
}

/** Header tabel 1 level sesuai format laporan resmi */
function fieldPavingThead() {
    $fields = fieldPaving();
    ob_start(); ?>
    <thead>
        <tr>
            <th style="width:40px;"><?= htmlspecialchars(fieldPavingNoLabel()) ?></th>
            <?php foreach ($fields as $f): ?>
                <th><?= $f['label'] ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <?php
    return ob_get_clean();
}

/** Satu baris data, mengikuti urutan persis header di atas */
function fieldPavingRow($baris, $no, $fallbackKode = null) {
    $fields = fieldPaving();
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
function fieldPavingRowKosong($no) {
    ob_start(); ?>
    <tr>
        <td><?= $no ?></td>
        <td colspan="<?= fieldPavingJumlahKolom() - 1 ?>" class="text-center">-</td>
    </tr>
    <?php
    return ob_get_clean();
}
