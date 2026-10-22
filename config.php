<?php
/**
 * Database connection (PDO) & global helper functions.
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Makassar');

// --- Database Configuration ---
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

    // Initialize settings table if not present
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_name VARCHAR(100) NOT NULL DEFAULT 'User'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        INSERT INTO settings (id, user_name)
        SELECT 1, 'User'
        WHERE NOT EXISTS (SELECT 1 FROM settings WHERE id = 1);
    ");
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

// --- Helper Functions ---

function get_user_name(PDO $pdo): string
{
    try {
        $name = (string) $pdo->query('SELECT user_name FROM settings WHERE id = 1 LIMIT 1')->fetchColumn();
        return $name !== '' ? $name : 'User';
    } catch (Throwable) {
        return 'User';
    }
}

function rupiah(float|int|string|null $value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function parse_amount(mixed $raw): float
{
    if (is_int($raw) || is_float($raw)) {
        return (float) $raw;
    }

    $str = trim((string) $raw);
    if ($str === '') {
        return 0.0;
    }

    if (strpos($str, ',') !== false) {
        $str = str_replace('.', '', $str);
        $str = str_replace(',', '.', $str);
        return (float) preg_replace('/[^0-9.]/', '', $str);
    }

    $digits = preg_replace('/[^0-9]/', '', $str);
    return $digits === '' ? 0.0 : (float) $digits;
}

function redirect_back(string $msg, string $tab = 'dashboard', bool $error = false): never
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_msg']   = $msg;
    $_SESSION['flash_error'] = $error;

    $target = 'index.php';
    if ($tab !== 'dashboard' && in_array($tab, ['transactions', 'wallets', 'goals'], true)) {
        $target .= '#' . $tab;
    }
    header('Location: ' . $target);
    exit;
}
