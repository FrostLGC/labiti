<?php
/**
 * config/auth.php
 * Kumpulan fungsi bantu untuk mengecek session login dan role.
 * Panggil require 'config/auth.php' di paling atas setiap halaman
 * yang butuh proteksi login.
 */

session_start();

/**
 * Cek apakah user sudah login. Kalau belum, lempar ke halaman login.
 */
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /lab-iti-utama2/auth/login.php');
        exit;
    }
}

/**
 * Cek apakah user sudah login DAN rolenya sesuai yang diizinkan.
 * Contoh pakai: requireRole(['admin']); atau requireRole(['admin', 'manajer']);
 */
function requireRole(array $allowedRoles) {
    requireLogin();
    if (!in_array($_SESSION['user_role'], $allowedRoles)) {
        die('Anda tidak memiliki akses ke halaman ini.');
    }

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $stmt = $GLOBALS['pdo']->prepare("SELECT status_aktif, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();

        if (!$row) {
            session_unset();
            session_destroy();
            header('Location: /lab-iti-utama2/auth/login.php');
            exit;
        }
        if (!(int) $row['status_aktif']) {
            session_unset();
            session_destroy();
            die('Akun Anda telah dinonaktifkan. Hubungi admin.');
        }
        if ($row['role'] !== $_SESSION['user_role']) {
            $_SESSION['user_role'] = $row['role'];
            if (!in_array($row['role'], $allowedRoles)) {
                die('Anda tidak memiliki akses ke halaman ini.');
            }
        }
    }
}

/**
 * Ambil data user yang sedang login (dipanggil di halaman yang butuh nama, dll)
 */
function currentUser($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * CSRF: buat token (sekali per session) dan return string HTML hidden input.
 * Pakai di semua form POST: <form method="POST"><?= csrf_field() ?>
 */
function csrf_field(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

/**
 * CSRF: verifikasi token dari POST. Return true jika valid.
 * Panggil di awal setiap blok POST handler.
 */
function csrf_check(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
