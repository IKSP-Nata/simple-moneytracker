<?php
/**
 * delete_goal.php — Hapus target tabungan dari tabel savings_goals
 * Mendukung request method POST maupun GET.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

$id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int) $_POST['id'];
} elseif (isset($_GET['id'])) {
    $id = (int) $_GET['id'];
}

if ($id > 0) {
    try {
        $stmt = $pdo->prepare('DELETE FROM savings_goals WHERE id = ?');
        $stmt->execute([$id]);

        if (function_exists('redirect_back')) {
            redirect_back('Target tabungan berhasil dihapus!', 'goals');
        } else {
            header('Location: index.php#goals');
            exit;
        }
    } catch (PDOException $e) {
        if (function_exists('redirect_back')) {
            redirect_back('Gagal menghapus target tabungan: ' . $e->getMessage(), 'goals', true);
        } else {
            header('Location: index.php#goals');
            exit;
        }
    }
}

// Jika ID tidak valid, redirect kembali
if (function_exists('redirect_back')) {
    redirect_back('ID target tabungan tidak valid.', 'goals', true);
} else {
    header('Location: index.php#goals');
    exit;
}
