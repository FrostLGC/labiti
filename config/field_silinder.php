<?php
/**
 * config/field_silinder.php
 * Definisi tunggal kolom laporan Uji Tekan Silinder untuk form input
 * dan seluruh laporan - agar selalu identik dengan format resmi.
 *
 * Struktur laporan (1 level header, 10 kolom, tanpa Keterangan):
 *   No. | Tgl. Dicor | Tgl. Ditest | Umur (Hari) | Berat (Kg) | Kode
 *      | Beban Maksimum (Kg) | Tegangan Tekan (Kg/cm2)
 *      | Tegangan Tekan 28 hari (Kg/cm2) | Mpa
 */

/** Deteksi jenis pengujian Uji Tekan Silinder dari nama pada tabel jenis_pengujian */
function isUjiSilinder($nama) {
    return is_string($nama) && stripos(trim($nama), 'Uji Tekan Silinder') === 0;
}

/** Nama kolom "No." diletakkan pertama pada setiap tabel */
function fieldSilinderNoLabel() {
    return 'No.';
}

function fieldSilinder() {
    return [
        ['db' => 'tgl_cor',               'label' => 'Tgl. Dicor',                     'type' => 'date',   'align' => 'center'],
        ['db' => 'tgl_ditest',            'label' => 'Tgl. Ditest',                    'type' => 'date',   'align' => 'center'],
        ['db' => 'umur_hari',             'label' => 'Umur (Hari)',                    'type' => 'number', 'align' => 'center'],
        ['db' => 'berat_kg',              'label' => 'Berat (Kg)',                     'type' => 'number', 'align' => 'center'],
        ['db' => 'kode_beton',            'label' => 'Kode',                           'type' => 'text',   'align' => 'left'],
        ['db' => 'beban_maksimum',        'label' => 'Beban Maksimum (Kg)',            'type' => 'number', 'align' => 'center'],
        ['db' => 'tegangan_tekan',        'label' => 'Tegangan Tekan (Kg/cm&sup2;)',   'type' => 'number', 'align' => 'center'],
        ['db' => 'tegangan_tekan_28_hari','label' => 'Tegangan Tekan 28 hari (Kg/cm&sup2;)','type' => 'number','align' => 'center'],
        ['db' => 'mpa',                   'label' => 'Mpa',                            'type' => 'number', 'align' => 'center'],
    ];
}

/** Jumlah total kolom termasuk kolom No. */
function fieldSilinderJumlahKolom() {
    return count(fieldSilinder()) + 1;
}

/** Escape + format satu nilai; Kode jatuh ke kode_sampel bila kosong */
function fieldSilinderNilai($baris, $db, $fallbackKode = null) {
    $v = $baris[$db] ?? null;

    if ($db === 'kode_beton') {
        if ($v === null || $v === '') {
            $v = $fallbackKode ?? ($baris['kode_sampel'] ?? null);
        }
    }

    if ($v === null || $v === '') {
        return '-';
    }

    if ($db === 'tgl_cor' || $db === 'tgl_ditest') {
        $ts = strtotime($v);
        return $ts ? date('d-m-Y', $ts) : '-';
    }

    return htmlspecialchars((string) $v);
}

/** Header tabel 1 level sesuai format laporan resmi */
function fieldSilinderThead() {
    $fields = fieldSilinder();
    ob_start(); ?>
    <thead>
        <tr>
            <th style="width:40px;"><?= htmlspecialchars(fieldSilinderNoLabel()) ?></th>
            <?php foreach ($fields as $f): ?>
                <th><?= $f['label'] ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <?php
    return ob_get_clean();
}

/** Satu baris data, mengikuti urutan persis header di atas */
function fieldSilinderRow($baris, $no, $fallbackKode = null) {
    $fields = fieldSilinder();
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
function fieldSilinderRowKosong($no) {
    ob_start(); ?>
    <tr>
        <td><?= $no ?></td>
        <td colspan="<?= fieldSilinderJumlahKolom() - 1 ?>" class="text-center">-</td>
    </tr>
    <?php
    return ob_get_clean();
}
