<?php
/**
 * config.php — Koneksi database db_money (PDO) + helper umum.
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Makassar');

const DB_HOST = 'localhost';
const DB_NAME = 'db_money';
const DB_USER = 'root';
const DB_PASS = '';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Koneksi database gagal: ' . htmlspecialchars($e->getMessage()));
}

/** Format angka ke Rupiah, contoh: Rp 2.450.000 */
function rupiah(float|int|string|null $value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

/** Escape output HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Ubah input nominal ("50.000", "Rp 50,000") menjadi angka bulat. */
function parse_amount(mixed $raw): float
{
    $digits = preg_replace('/[^0-9]/', '', (string) $raw);
    return $digits === '' ? 0.0 : (float) $digits;
}

/** Redirect ke index.php dengan pesan toast & tab tujuan. */
function redirect_back(string $msg, string $tab = 'dashboard', bool $error = false): never
{
    $query = http_build_query(['msg' => $msg, 'status' => $error ? 'error' : 'success']);
    header('Location: index.php?' . $query . '#' . $tab);
    exit;
}
