<?php
/**
 * config/field_besi.php
 * Definisi tunggal kolom Uji Besi (Tarik) untuk form input, form edit,
 * dan tabel laporan - agar semuanya selalu identik dengan format laporan resmi.
 *
 * Struktur laporan (2 level header):
 *   NO. | Kode Benda Uji | Diameter (mm) [D0, D0u] | Luas (mm2) [A0, Au]
 *      | Beban Tarik (kN) [Leleh, Maks] | Beban Tarik (MPa) [Leleh, Maks]
 *      | Panjang Ulur (mm) [L0, Lu] | Regangan Maks (%) | Kontraksi (%)
 *      | Keterangan
 */

function fieldBesi() {
    $fields = [
        ['db' => 'kode_benda_uji',       'grup' => null,                 'label' => 'Kode Benda Uji', 'type' => 'text',   'align' => 'left',  'lebar' => 9],
        ['db' => 'diameter',             'grup' => 'Diameter (mm)',      'label' => 'D0',             'type' => 'number', 'align' => 'right', 'lebar' => 5],
        ['db' => 'diameter_akhir',       'grup' => 'Diameter (mm)',      'label' => 'D0u',            'type' => 'number', 'align' => 'right', 'lebar' => 5],
        ['db' => 'luas_penampang',       'grup' => 'Luas (mm²)',         'label' => 'A0',             'type' => 'number', 'align' => 'right', 'lebar' => 5],
        ['db' => 'luas_penampang_akhir', 'grup' => 'Luas (mm²)',         'label' => 'Au',             'type' => 'number', 'align' => 'right', 'lebar' => 5],
        ['db' => 'beban_tarik_leleh',    'grup' => 'Beban Tarik (kN)',   'label' => 'Leleh',          'type' => 'number', 'align' => 'right', 'lebar' => 5],
        ['db' => 'beban_maksimum',       'grup' => 'Beban Tarik (kN)',   'label' => 'Maks',           'type' => 'number', 'align' => 'right', 'lebar' => 5],
        ['db' => 'tegangan_leleh',       'grup' => 'Beban Tarik (MPa)',  'label' => 'Leleh',          'type' => 'number', 'align' => 'right', 'lebar' => 5],
        ['db' => 'tegangan_tarik',       'grup' => 'Beban Tarik (MPa)',  'label' => 'Maks',           'type' => 'number', 'align' => 'right', 'lebar' => 5],
        ['db' => 'panjang_awal',         'grup' => 'Panjang Ulur (mm)',  'label' => 'L0',             'type' => 'number', 'align' => 'right', 'lebar' => 5],
        ['db' => 'panjang_akhir',        'grup' => 'Panjang Ulur (mm)',  'label' => 'Lu',             'type' => 'number', 'align' => 'right', 'lebar' => 5],
        ['db' => 'perpanjangan',         'grup' => null,                 'label' => 'Regangan Maks (%)',  'type' => 'number', 'align' => 'right', 'lebar' => 5],
        ['db' => 'reduksi_penampang',    'grup' => null,                 'label' => 'Kontraksi (%)',      'type' => 'number', 'align' => 'right', 'lebar' => 5],
        ['db' => 'catatan_teknisi',      'grup' => 'Keterangan',        'label' => 'Keterangan',       'type' => 'text',   'align' => 'left',  'lebar' => 8],
    ];
    return $fields;
}

/** Nama kolom DB untuk form (tanpa kolom yang tidak ada di tabel laporan) */
function fieldBesiKolomDb() {
    return array_column(fieldBesi(), 'db');
}

/** Kunci penanda bahwa data besi sudah diinput (menggantikan parameter_uji) */
function fieldBesiPenanda() {
    return 'kode_benda_uji';
}

/** Escape + format nilai; teks di Keterangan boleh multi-baris */
function fieldBesiNilai($baris, $kolom) {
    $v = $baris[$kolom] ?? null;
    if ($v === null || $v === '') {
        return '-';
    }
    return nl2br(htmlspecialchars((string) $v));
}

/**
 * Header tabel 2 level sesuai format laporan resmi.
 * Dipakai oleh seluruh halaman laporan agar selalu identik.
 */
function fieldBesiThead() {
    $fields = fieldBesi();

    $label = [];
    foreach ($fields as $f) {
        $label[$f['db']] = $f['label'];
    }

    // Kelompok kolom berdasarkan header grup, urut kemunculan pertama
    $grup = [];
    foreach ($fields as $f) {
        if ($f['grup'] === null) {
            continue;
        }
        $grup[$f['grup']][] = $f['db'];
    }
    // "Keterangan" selalu berada di kolom paling kanan
    $grupTerakhir = 'Keterangan';
    $grupKiri = $grup;
    $grupKanan = isset($grup[$grupTerakhir]) ? [$grupTerakhir => $grup[$grupTerakhir]] : [];
    unset($grupKiri[$grupTerakhir]);

    ob_start(); ?>
    <thead>
        <tr>
            <th rowspan="2" style="width:40px;">No.</th>
            <th rowspan="2" style="width:90px;">Kode Benda Uji</th>
            <?php foreach ($grupKiri as $namaGrup => $cols): ?>
                <th colspan="<?= count($cols) ?>" style="text-align:center;"><?= htmlspecialchars($namaGrup) ?></th>
            <?php endforeach; ?>
            <th rowspan="2" style="text-align:center;"><?= htmlspecialchars($label['perpanjangan']) ?></th>
            <th rowspan="2" style="text-align:center;"><?= htmlspecialchars($label['reduksi_penampang']) ?></th>
            <?php foreach ($grupKanan as $namaGrup => $cols): ?>
                <th rowspan="2" style="text-align:center;"><?= htmlspecialchars($namaGrup) ?></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach ($grupKiri as $cols): ?>
                <?php foreach ($cols as $db): ?>
                    <th style="text-align:center;"><?= htmlspecialchars($label[$db]) ?></th>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tr>
    </thead>
    <?php
    return ob_get_clean();
}

/** Satu baris data, mengikuti urutan persis header di atas */
function fieldBesiRow($baris, $no) {
    $fields = fieldBesi();
    ob_start(); ?>
    <tr>
        <td><?= $no ?></td>
        <?php foreach ($fields as $f): ?>
            <td style="text-align:<?= $f['align']; ?>;"><?= fieldBesiNilai($baris, $f['db']) ?></td>
        <?php endforeach; ?>
    </tr>
    <?php
    return ob_get_clean();
}
