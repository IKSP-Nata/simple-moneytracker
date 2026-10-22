<?php
/**
 * Edit Savings Goal Handler
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

// --- Request Method Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php#goals');
    exit;
}

// --- Input Processing & Validation ---
$id     = (int) ($_POST['id'] ?? 0);
$title  = trim((string) ($_POST['goal_title'] ?? ''));
$target = parse_amount($_POST['target_amount'] ?? 0);

if ($id <= 0) {
    redirect_back('ID target tabungan tidak valid.', 'goals', true);
}
if ($title === '' || mb_strlen($title) > 100) {
    redirect_back('Nama target tabungan wajib diisi (maks. 100 karakter).', 'goals', true);
}
if ($target <= 0) {
    redirect_back('Nominal target tabungan harus lebih dari 0.', 'goals', true);
}

// --- Update Database ---
try {
    $stmt = $pdo->prepare('UPDATE savings_goals SET goal_title = ?, target_amount = ? WHERE id = ?');
    $stmt->execute([$title, $target, $id]);

    redirect_back('Target tabungan "' . $title . '" berhasil diperbarui!', 'goals');
} catch (PDOException $e) {
    redirect_back('Gagal memperbarui target tabungan.', 'goals', true);
}
