<?php
/**
 * config/field_kubus.php
 * Definisi tunggal kolom laporan Uji Tekan Kubus (15x15 Cm) untuk form input
 * dan seluruh laporan - selalu identik dengan format resmi (foto).
 *
 * Struktur laporan (1 level header, 10 kolom, kolom terakhir = Keterangan):
 *   No. | Tgl. Dicor | Tgl. Ditest | Umur (Hari) | Berat (Kg) | Kode
 *      | Beban Maksimum (Kg) | Tegangan Tekan (Kg/cm2)
 *      | Tegangan Tekan 28 hari (Kg/cm2) | Keterangan
 *
 * Kolom pertama s/d kedua-terakhir identik dengan Uji Tekan Silinder.
 */

require_once __DIR__ . '/field_silinder.php';
require_once __DIR__ . '/field_paving.php';
require_once __DIR__ . '/field_lentur.php';
require_once __DIR__ . '/field_core.php';

/** Deteksi jenis pengujian Uji Tekan Kubus 15x15 dari nama pada tabel jenis_pengujian */
function isUjiKubus($nama) {
    if (!is_string($nama)) {
        return false;
    }
    $n = trim($nama);
    if (stripos($n, 'Uji Tekan Kubus') !== 0) {
        return false;
    }
    // Uji Tekan Kubus Mortar (5x5 Cm) adalah pengujian berbeda
    if (stripos($n, 'Mortar') !== false) {
        return false;
    }
    return true;
}

/** Nama kolom "No." diletakkan pertama pada setiap tabel */
function fieldKubusNoLabel() {
    return fieldSilinderNoLabel();
}

function fieldKubus() {
    $fields = fieldSilinder();
    // Kolom terakhir: Mpa (silinder) -> Keterangan (kubus)
    $fields[count($fields) - 1] = [
        'db'    => 'catatan_teknisi',
        'label' => 'Keterangan',
        'type'  => 'text',
        'align' => 'left',
    ];
    return $fields;
}

/** Jumlah total kolom termasuk kolom No. */
function fieldKubusJumlahKolom() {
    return count(fieldKubus()) + 1;
}

/** Header tabel 1 level sesuai format laporan resmi */
function fieldKubusThead() {
    $fields = fieldKubus();
    ob_start(); ?>
    <thead>
        <tr>
            <th style="width:40px;"><?= htmlspecialchars(fieldKubusNoLabel()) ?></th>
            <?php foreach ($fields as $f): ?>
                <th><?= $f['label'] ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <?php
    return ob_get_clean();
}

/** Satu baris data, mengikuti urutan persis header di atas */
function fieldKubusRow($baris, $no, $fallbackKode = null) {
    $fields = fieldKubus();
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
function fieldKubusRowKosong($no) {
    ob_start(); ?>
    <tr>
        <td><?= $no ?></td>
        <td colspan="<?= fieldKubusJumlahKolom() - 1 ?>" class="text-center">-</td>
    </tr>
    <?php
    return ob_get_clean();
}

/* ------------------------------------------------------------------ */
/* Dispatcher: pilih silinder atau kubus berdasarkan flag              */
/* ------------------------------------------------------------------ */

/** Header tabel mode "kartu/table" beton (silinder/kubus/paving/lentur/core drill) */
function tabelBetonThead($isKubus, $isPaving = false, $isLentur = false, $isCore = false) {
    if ($isCore) {
        return fieldCoreThead();
    }
    if ($isLentur) {
        return fieldLenturThead();
    }
    if ($isPaving) {
        return fieldPavingThead();
    }
    return $isKubus ? fieldKubusThead() : fieldSilinderThead();
}

/** Satu baris data mode kartu/table beton (silinder/kubus/paving/lentur/core drill) */
function tabelBetonRow($isKubus, $baris, $no, $fallbackKode = null, $isPaving = false, $isLentur = false, $isCore = false) {
    if ($isCore) {
        return fieldCoreRow($baris, $no, $fallbackKode);
    }
    if ($isLentur) {
        return fieldLenturRow($baris, $no, $fallbackKode);
    }
    if ($isPaving) {
        return fieldPavingRow($baris, $no, $fallbackKode);
    }
    return $isKubus ? fieldKubusRow($baris, $no, $fallbackKode) : fieldSilinderRow($baris, $no, $fallbackKode);
}

/** Jumlah kolom mode kartu/table beton (silinder/kubus/paving/lentur/core drill) */
function tabelBetonJumlahKolom($isKubus, $isPaving = false, $isLentur = false, $isCore = false) {
    if ($isCore) {
        return fieldCoreJumlahKolom();
    }
    if ($isLentur) {
        return fieldLenturJumlahKolom();
    }
    if ($isPaving) {
        return fieldPavingJumlahKolom();
    }
    return $isKubus ? fieldKubusJumlahKolom() : fieldSilinderJumlahKolom();
}

/** Baris kosong mode kartu/table beton (silinder/kubus/paving/lentur/core drill) */
function tabelBetonRowKosong($isKubus, $no, $isPaving = false, $isLentur = false, $isCore = false) {
    if ($isCore) {
        return fieldCoreRowKosong($no);
    }
    if ($isLentur) {
        return fieldLenturRowKosong($no);
    }
    if ($isPaving) {
        return fieldPavingRowKosong($no);
    }
    return $isKubus ? fieldKubusRowKosong($no) : fieldSilinderRowKosong($no);
}
