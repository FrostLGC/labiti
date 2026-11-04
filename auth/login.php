<?php
require '../config/database.php';
require '../config/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        if (!$user['status_aktif']) {
            $error = 'Akun Anda telah dinonaktifkan. Hubungi admin.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['nama_lengkap'];
            $_SESSION['user_role'] = $user['role'];

            switch ($user['role']) {
                case 'admin':
                    header('Location: /lab-iti-utama2/admin/dashboard.php');
                    break;
                case 'teknisi':
                    header('Location: /lab-iti-utama2/teknisi/dashboard.php');
                    break;
                case 'owner':
                    header('Location: /lab-iti-utama2/owner/dashboard.php');
                    break;
                default:
                    header('Location: /lab-iti-utama2/pelanggan/dashboard.php');
            }
            exit;
        }
    } else {
        $error = 'Email atau password salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Laboratorium PT ITI Utama</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-logo"><img src="../img1.jpeg" alt="Logo"></div>
        <h4>Laboratorium PT ITI Utama</h4>
        <p class="auth-subtitle">PT ITI Utama - Sistem Informasi Manajemen</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" placeholder="Masukkan email Anda" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Masukkan password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Masuk</button>
        </form>

        <p class="auth-footer">
            Belum punya akun? <a href="register.php">Daftar di sini</a>
        </p>
        <p class="auth-footer">
            <a href="guest.php" style="color: #ea580c;"><i class="bi bi-search"></i> Cek Hasil Pengujian (Guest)</a>
        </p>
    </div>
</body>
</html>
