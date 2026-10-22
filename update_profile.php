<?php
/**
 * Update Profile Settings Handler
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

// --- Request Method Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// --- Input Processing & Validation ---
$name = trim((string) ($_POST['user_name'] ?? ''));

if ($name === '' || mb_strlen($name) > 100) {
    redirect_back('Nama profil wajib diisi (maks. 100 karakter).', 'dashboard', true);
}

// --- Upsert Profile Name ---
try {
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
