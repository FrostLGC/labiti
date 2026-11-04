<?php
require '../config/database.php';
require '../config/auth.php';
requireRole(['pelanggan']);

$user = currentUser($pdo);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    if (isset($_POST['update_profil'])) {
        $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
        $namaPerusahaan = trim($_POST['nama_perusahaan'] ?? '');
        $noTelepon = trim($_POST['no_telepon'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');

        $fotoPath = $user['foto_profil'];
        if (isset($_FILES['foto_profil']) && ($_FILES['foto_profil']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $ext = strtolower(pathinfo($_FILES['foto_profil']['name'], PATHINFO_EXTENSION));
            $extensionsDiizinkan = ['jpg', 'jpeg', 'png'];

            if ($_FILES['foto_profil']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Upload foto profil gagal (kemungkinan file terlalu besar). Maksimal 2MB.';
            } elseif ($_FILES['foto_profil']['size'] > 2 * 1024 * 1024) {
                $error = 'Ukuran foto profil maksimal 2MB.';
            } elseif (!in_array($ext, $extensionsDiizinkan)) {
                $error = 'Format foto harus JPG atau PNG.';
            } elseif (@getimagesize($_FILES['foto_profil']['tmp_name']) === false) {
                $error = 'Isi file bukan gambar yang valid.';
            } else {
                $namaBaru = 'foto_' . $user['id'] . '_' . time() . '.' . $ext;
                $tujuanFolder = '../uploads/foto_profil';
                if (!is_dir($tujuanFolder)) {
                    mkdir($tujuanFolder, 0777, true);
                }
                if (move_uploaded_file($_FILES['foto_profil']['tmp_name'], $tujuanFolder . '/' . $namaBaru)) {
                    $fotoPath = 'uploads/foto_profil/' . $namaBaru;
                } else {
                    $error = 'Gagal mengunggah foto profil.';
                }
            }
        }

        if (!$error) {
            $stmt = $pdo->prepare(
                "UPDATE users SET nama_lengkap = ?, nama_perusahaan = ?, no_telepon = ?, alamat = ?, foto_profil = ?
                 WHERE id = ?"
            );
            $stmt->execute([$namaLengkap, $namaPerusahaan, $noTelepon, $alamat, $fotoPath, $user['id']]);

            $success = 'Profil berhasil diperbarui.';
            $user = currentUser($pdo);
            $_SESSION['user_name'] = $namaLengkap;
        }
    }

    if (isset($_POST['ganti_password'])) {
        $passwordLama = $_POST['password_lama'] ?? '';
        $passwordBaru = $_POST['password_baru'] ?? '';
        $konfirmasiPassword = $_POST['konfirmasi_password_baru'] ?? '';

        if (!password_verify($passwordLama, $user['password'])) {
            $error = 'Password lama salah.';
        } elseif ($passwordBaru !== $konfirmasiPassword) {
            $error = 'Konfirmasi password baru tidak cocok.';
        } elseif (strlen($passwordBaru) < 6) {
            $error = 'Password baru minimal 6 karakter.';
        } else {
            $hashBaru = password_hash($passwordBaru, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashBaru, $user['id']]);
            $success = 'Password berhasil diganti.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Profil - Pelanggan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-custom navbar-pelanggan">
        <div class="container">
            <a class="navbar-brand text-white" href="dashboard.php"><img src="../img1.jpeg" alt="Logo"> Laboratorium PT ITI Utama</a>
            <button class="navbar-toggler text-white border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navPelanggan">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navPelanggan">
                <div class="ms-auto d-flex align-items-center gap-2 nav-actions">
                    <a href="dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
                    <a href="booking.php" class="btn btn-outline-light btn-sm">Booking</a>
                    <a href="cek_booking.php" class="btn btn-outline-light btn-sm">Validasi Dokumen</a>
                    <a href="riwayat.php" class="btn btn-outline-light btn-sm">Riwayat</a>
                    <a href="profil.php" class="btn btn-outline-light btn-sm">Profil</a>
                    <a href="../auth/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="page-container" style="max-width: 650px;">
        <div class="page-header">
            <h4>Kelola Profil</h4>
            <p>Perbarui data diri dan password Anda</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong><i class="bi bi-person"></i> Data Diri</strong></div>
            <div class="card-body">
                <?php if ($user['foto_profil']): ?>
                    <div class="text-center mb-3">
                        <img src="../<?= htmlspecialchars($user['foto_profil']) ?>" class="rounded-circle" width="80" height="80" style="object-fit: cover;">
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Foto Profil</label>
                        <input type="file" name="foto_profil" class="form-control" accept=".jpg,.jpeg,.png">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" class="form-control" required
                               value="<?= htmlspecialchars($user['nama_lengkap']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Perusahaan</label>
                        <input type="text" name="nama_perusahaan" class="form-control"
                               value="<?= htmlspecialchars($user['nama_perusahaan'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                        <small class="text-muted">Email tidak dapat diubah.</small>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">No. Telepon</label>
                            <input type="text" name="no_telepon" class="form-control"
                                   value="<?= htmlspecialchars($user['no_telepon'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label">Alamat</label>
                            <textarea name="alamat" class="form-control" rows="2"><?= htmlspecialchars($user['alamat'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <button type="submit" name="update_profil" class="btn btn-primary w-100 py-2">Simpan Perubahan</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong><i class="bi bi-lock"></i> Ganti Password</strong></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Password Lama</label>
                        <input type="password" name="password_lama" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password Baru</label>
                        <input type="password" name="password_baru" class="form-control" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Konfirmasi Password Baru</label>
                        <input type="password" name="konfirmasi_password_baru" class="form-control" required minlength="6">
                    </div>
                    <button type="submit" name="ganti_password" class="btn btn-secondary w-100 py-2">Ganti Password</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
