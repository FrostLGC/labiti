<?php
require '../config/database.php';
require '../config/auth.php';
requireRole(['admin']);

$user = currentUser($pdo);
$error = '';
$success = '';

// ============ TAMBAH USER BARU ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_user'])) {
    $role = $_POST['role'] ?? '';
    $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
    $namaPerusahaan = trim($_POST['nama_perusahaan'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $noTelepon = trim($_POST['no_telepon'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    if (!in_array($role, ['pelanggan', 'teknisi', 'admin', 'owner'], true)) {
        $error = 'Role user tidak valid.';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Format email tidak valid.';
    } else {
        $cek = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $cek->execute([$email]);

        if ($cek->fetch()) {
            $error = 'Email sudah digunakan oleh user lain.';
        } elseif (strlen($password) < 6) {
            $error = 'Password minimal 6 karakter.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (role, nama_lengkap, nama_perusahaan, email, no_telepon, password, status_aktif)
                 VALUES (?, ?, ?, ?, ?, ?, 1)"
            );
            $stmt->execute([$role, $namaLengkap, $role === 'pelanggan' ? $namaPerusahaan : null, $email, $noTelepon, $hash]);
            $success = "User \"$namaLengkap\" ($role) berhasil ditambahkan.";
        }
    }
}

// ============ EDIT USER ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $editId = (int) ($_POST['user_id'] ?? 0);
    $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
    $namaPerusahaan = trim($_POST['nama_perusahaan'] ?? '');
    $noTelepon = trim($_POST['no_telepon'] ?? '');
    $passwordBaru = $_POST['password_baru'] ?? '';

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    if (!empty($passwordBaru)) {
        if (strlen($passwordBaru) < 6) {
            $error = 'Password baru minimal 6 karakter.';
        } else {
            $hash = password_hash($passwordBaru, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                "UPDATE users SET nama_lengkap = ?, nama_perusahaan = ?, no_telepon = ?, password = ? WHERE id = ?"
            );
            $stmt->execute([$namaLengkap, $namaPerusahaan, $noTelepon, $hash, $editId]);
            $success = 'User berhasil diperbarui (termasuk password baru).';
        }
    } else {
        $stmt = $pdo->prepare(
            "UPDATE users SET nama_lengkap = ?, nama_perusahaan = ?, no_telepon = ? WHERE id = ?"
        );
        $stmt->execute([$namaLengkap, $namaPerusahaan, $noTelepon, $editId]);
        $success = 'User berhasil diperbarui.';
    }
}

// ============ AKTIFKAN / NONAKTIFKAN USER ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $toggleId = (int) ($_POST['user_id'] ?? 0);

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    if ($toggleId == $user['id']) {
        $error = 'Anda tidak bisa menonaktifkan akun Anda sendiri.';
    } else {
        $stmt = $pdo->prepare("UPDATE users SET status_aktif = NOT status_aktif WHERE id = ?");
        $stmt->execute([$toggleId]);
        $success = 'Status user berhasil diubah.';
    }
}

// ============ HAPUS USER ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_user'])) {
    $hapusId = (int) ($_POST['user_id'] ?? 0);

    if (!csrf_check()) { die('Token CSRF tidak valid. Muat ulang halaman.'); }

    if ($hapusId == $user['id']) {
        $error = 'Anda tidak bisa menghapus akun Anda sendiri.';
    } else {
        $cekBooking = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ?");
        $cekBooking->execute([$hapusId]);
        if ((int) $cekBooking->fetchColumn() > 0) {
            $error = 'User ini masih memiliki booking. Semua booking, sampel, jadwal, dan riwayatnya akan ikut terhapus karena relasi di database. Nonaktifkan saja user ini sebagai gantinya.';
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$hapusId]);
                $success = 'User berhasil dihapus.';
            } catch (PDOException $e) {
                $error = 'User tidak bisa dihapus karena masih memiliki data terkait (booking/jadwal). Nonaktifkan saja user ini sebagai gantinya.';
            }
        }
    }
}

// ============ AMBIL SEMUA USER ============
$filterRole = $_GET['role'] ?? '';
$sql = "SELECT * FROM users";
$params = [];
if ($filterRole) {
    $sql .= " WHERE role = ?";
    $params[] = $filterRole;
}
$sql .= " ORDER BY role, nama_lengkap";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$semuaUser = $stmt->fetchAll();

function labelRole($role) {
    $map = [
        'pelanggan' => ['Pelanggan', 'secondary'],
        'admin'     => ['Admin', 'dark'],
        'teknisi'   => ['Teknisi', 'success'],
        'owner'     => ['Owner', 'primary'],
    ];
    return $map[$role] ?? [$role, 'secondary'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola User - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-custom navbar-admin">
        <div class="container">
            <a class="navbar-brand text-white" href="dashboard.php"><img src="../img1.jpeg" alt="Logo"> Laboratorium PT ITI Utama</a>
            <button class="navbar-toggler text-white border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navAdmin">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navAdmin">
                <div class="ms-auto d-flex align-items-center gap-2 nav-actions">
                    <a href="dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
                    <a href="kelola_booking.php" class="btn btn-outline-light btn-sm">Booking</a>
                    <a href="booking_manual.php" class="btn btn-outline-light btn-sm">Booking Manual</a>
                    <a href="input_historis.php" class="btn btn-outline-light btn-sm">Input Historis</a>
                    <a href="kelola_user.php" class="btn btn-outline-light btn-sm">User</a>
                    <a href="../auth/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="page-container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="page-header mb-0">
                <h4>Kelola User</h4>
                <p>Kelola akun seluruh pengguna sistem</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTambah">+ Tambah User</button>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <!-- FILTER ROLE -->
        <form method="GET" class="mb-3">
            <div class="d-flex gap-2 align-items-center">
                <select name="role" class="form-select" style="width: auto;" onchange="this.form.submit()">
                    <option value="">Semua Role</option>
                    <option value="pelanggan" <?= $filterRole === 'pelanggan' ? 'selected' : '' ?>>Pelanggan</option>
                    <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="teknisi" <?= $filterRole === 'teknisi' ? 'selected' : '' ?>>Teknisi</option>
                    <option value="owner" <?= $filterRole === 'owner' ? 'selected' : '' ?>>Owner</option>
                </select>
            </div>
        </form>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Role</th>
                                <th>Email</th>
                                <th class="d-none d-md-table-cell">Telepon</th>
                                <th class="d-none d-lg-table-cell">Perusahaan</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($semuaUser as $u): [$labelR, $warnaR] = labelRole($u['role']); ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($u['nama_lengkap']) ?></strong></td>
                                <td><span class="badge bg-<?= $warnaR ?>"><?= $labelR ?></span></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td class="d-none d-md-table-cell"><?= htmlspecialchars($u['no_telepon'] ?? '-') ?></td>
                                <td class="d-none d-lg-table-cell"><?= htmlspecialchars($u['nama_perusahaan'] ?? '-') ?></td>
                                <td>
                                    <?php if ($u['status_aktif']): ?>
                                        <span class="badge bg-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $u['id'] ?>">Edit</button>

                                        <form method="POST" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" name="toggle_status" class="btn btn-sm btn-outline-warning"
                                                    onclick="return confirm('Ubah status aktif user ini?')">
                                                <?= $u['status_aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                                            </button>
                                        </form>

                                        <form method="POST" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" name="hapus_user" class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('Hapus user ini secara permanen?')">Hapus</button>
                                        </form>
                                    </div>

                                    <!-- MODAL EDIT -->
                                    <div class="modal fade" id="modalEdit<?= $u['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <?= csrf_field() ?>
                                                    <div class="modal-header">
                                                        <h6 class="modal-title">Edit: <?= htmlspecialchars($u['nama_lengkap']) ?></h6>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <div class="mb-3">
                                                            <label class="form-label">Nama Lengkap</label>
                                                            <input type="text" name="nama_lengkap" class="form-control" value="<?= htmlspecialchars($u['nama_lengkap']) ?>" required>
                                                        </div>
                                                        <?php if ($u['role'] === 'pelanggan'): ?>
                                                        <div class="mb-3">
                                                            <label class="form-label">Nama Perusahaan</label>
                                                            <input type="text" name="nama_perusahaan" class="form-control" value="<?= htmlspecialchars($u['nama_perusahaan'] ?? '') ?>">
                                                        </div>
                                                        <?php else: ?>
                                                        <input type="hidden" name="nama_perusahaan" value="">
                                                        <?php endif; ?>
                                                        <div class="mb-3">
                                                            <label class="form-label">No. Telepon</label>
                                                            <input type="text" name="no_telepon" class="form-control" value="<?= htmlspecialchars($u['no_telepon'] ?? '') ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Email</label>
                                                            <input type="email" class="form-control" value="<?= htmlspecialchars($u['email']) ?>" disabled>
                                                            <small class="text-muted">Email tidak bisa diubah lewat sini.</small>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Reset Password (opsional)</label>
                                                            <input type="password" name="password_baru" class="form-control" placeholder="Kosongkan jika tidak ingin ganti" minlength="6">
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" name="edit_user" class="btn btn-primary btn-sm">Simpan</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($semuaUser)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data user.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL TAMBAH USER -->
    <div class="modal fade" id="modalTambah" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="modal-header">
                        <h6 class="modal-title">Tambah User Baru</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select" required>
                                <option value="pelanggan">Pelanggan</option>
                                <option value="admin">Admin</option>
                                <option value="teknisi">Teknisi</option>
                                <option value="owner">Owner</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Lengkap</label>
                            <input type="text" name="nama_lengkap" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Perusahaan (khusus Pelanggan)</label>
                            <input type="text" name="nama_perusahaan" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">No. Telepon</label>
                            <input type="text" name="no_telepon" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required minlength="6">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="tambah_user" class="btn btn-primary btn-sm">Tambah User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
