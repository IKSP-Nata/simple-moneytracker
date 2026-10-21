<?php
/**
 * update_profile.php — Mengubah nama user pada tabel settings (tanpa login).
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$name = trim((string) ($_POST['user_name'] ?? ''));

if ($name === '' || mb_strlen($name) > 100) {
    redirect_back('Nama profil wajib diisi (maks. 100 karakter).', 'dashboard', true);
}

try {
    // Pastikan baris id = 1 ada atau diupdate
    $stmt = $pdo->prepare('
        INSERT INTO settings (id, user_name)
        VALUES (1, ?)
        ON DUPLICATE KEY UPDATE user_name = VALUES(user_name)
    ');
    $stmt->execute([$name]);

    redirect_back('Nama profil berhasil diubah menjadi "' . $name . '"!', 'dashboard');
} catch (PDOException $e) {
    redirect_back('Gagal memperbarui nama profil.', 'dashboard', true);
}
