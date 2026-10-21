<?php
/**
 * process_goal.php — Tambah target tabungan baru.
 * POST: goal_title, target_amount, current_amount (opsional)
 */

require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php#goals');
    exit;
}

$title   = trim((string) ($_POST['goal_title'] ?? ''));
$target  = parse_amount($_POST['target_amount'] ?? '');
$current = parse_amount($_POST['current_amount'] ?? '0');

if ($title === '' || mb_strlen($title) > 100) {
    redirect_back('Nama target wajib diisi (maks. 100 karakter).', 'goals', true);
}
if ($target <= 0) {
    redirect_back('Nominal target harus lebih dari 0.', 'goals', true);
}

try {
    $pdo->prepare('INSERT INTO savings_goals (goal_title, target_amount, current_amount) VALUES (?, ?, ?)')
        ->execute([$title, $target, $current]);
    redirect_back('Target "' . $title . '" berhasil ditambahkan!', 'goals');
} catch (PDOException $ex) {
    redirect_back('Gagal menambahkan target tabungan.', 'goals', true);
}
