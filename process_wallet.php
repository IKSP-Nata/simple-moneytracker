<?php
/**
 * Create Wallet Handler
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

// --- Request Method Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php#wallets');
    exit;
}

// --- Input Processing & Validation ---
$name    = trim((string) ($_POST['wallet_name'] ?? ''));
$type    = trim((string) ($_POST['wallet_type'] ?? ''));
$balance = parse_amount($_POST['balance'] ?? '0');

if ($name === '' || mb_strlen($name) > 50) {
    redirect_back('Nama dompet wajib diisi (maks. 50 karakter).', 'wallets', true);
}
if ($type === '' || mb_strlen($type) > 30) {
    redirect_back('Tipe dompet wajib diisi (maks. 30 karakter).', 'wallets', true);
}

// --- Database Insertion ---
try {
    $pdo->prepare('INSERT INTO wallets (wallet_name, wallet_type, balance) VALUES (?, ?, ?)')
        ->execute([$name, $type, $balance]);
    redirect_back('Dompet "' . $name . '" berhasil ditambahkan!', 'wallets');
} catch (PDOException $ex) {
    redirect_back('Gagal menambahkan dompet.', 'wallets', true);
}
