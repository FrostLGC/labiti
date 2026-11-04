<?php
/**
 * config/database.php
 * File koneksi database menggunakan PDO (PHP Data Objects).
 * PDO dipakai (bukan mysqli) karena mendukung prepared statement
 * dengan lebih aman untuk mencegah SQL Injection.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function ($e) {
    error_log($e->getMessage());
    http_response_code(500);
    die('Terjadi kesalahan server. Silakan coba lagi nanti.');
});

$host = 'localhost';
$dbname = 'db_lab_iti_utama';
$username = 'root';
$password = ''; // kosongkan kalau XAMPP MySQL Anda tidak pakai password

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}
