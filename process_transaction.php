<?php
/**
 * process_transaction.php — Logika CRUD transaksi FlowTrack.
 *
 * Form + Add Transaction:
 * - Tangkap: title, amount, type (expense | income | goal_saving), wallet_id, goal_id (opsional)
 * - INSERT ke tabel `transactions` dengan source = 'web'
 * - UPDATE tabel `wallets`: kurangi balance jika expense, tambah jika income
 * - Jika type adalah 'goal_saving': kurangi balance di `wallets` DAN tambah current_amount di tabel `savings_goals`
 * - Redirect (header location) kembali ke halaman utama (index.php)
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$action = (string) ($_POST['action'] ?? 'add');
$returnTab = in_array($_POST['return_tab'] ?? '', ['dashboard', 'transactions', 'wallets', 'goals'], true)
    ? (string) $_POST['return_tab']
    : 'dashboard';

/* ============================================================
 * 1. TAMBAH TRANSAKSI
 * ============================================================ */
if ($action === 'add') {
    // 1. Tangkap nilai dari form
    $title    = trim((string) ($_POST['title'] ?? ''));
    $amount   = parse_amount($_POST['amount'] ?? 0);
    $typeRaw  = trim((string) ($_POST['type'] ?? ''));
    $walletId = (int) ($_POST['wallet_id'] ?? 0);
    $goalId   = (int) ($_POST['goal_id'] ?? 0);

    // Normalisasi tipe transaksi (expense | income | goal_saving)
    $typeLower = strtolower($typeRaw);
    if ($typeLower === 'expense') {
        $type = 'expense';
    } elseif ($typeLower === 'income') {
        $type = 'income';
    } elseif (in_array($typeLower, ['goal_saving', 'goal', 'save to goal', 'saving'], true)) {
        $type = 'goal_saving';
    } else {
        $type = '';
    }

    // Validasi dasar
    if ($title === '' || mb_strlen($title) > 100) {
        redirect_back('Judul transaksi wajib diisi (maks. 100 karakter).', $returnTab, true);
    }
    if ($amount <= 0) {
        redirect_back('Nominal harus lebih dari 0.', $returnTab, true);
    }
    if ($type === '') {
        redirect_back('Tipe transaksi tidak valid.', $returnTab, true);
    }

    try {
        $pdo->beginTransaction();

        // Cari dompet yang dipilih (atau fallback ke dompet pertama jika belum dipilih)
        if ($walletId <= 0) {
            $walletId = (int) $pdo->query('SELECT id FROM wallets ORDER BY id ASC LIMIT 1')->fetchColumn();
        }
        $stmt = $pdo->prepare('SELECT id, wallet_name, balance FROM wallets WHERE id = ? FOR UPDATE');
        $stmt->execute([$walletId]);
        $wallet = $stmt->fetch();
        if (!$wallet) {
            throw new RuntimeException('Dompet tidak ditemukan.');
        }

        // Simpan data transaksi ke tabel `transactions` dengan source = 'web'
        $stmt = $pdo->prepare(
            "INSERT INTO transactions (wallet_id, title, amount, type, source, created_at)
             VALUES (?, ?, ?, ?, 'web', NOW())"
        );
        $stmt->execute([$walletId, $title, $amount, $type]);

        // Update saldo dompet (wallets) & target tabungan (savings_goals)
        if ($type === 'income') {
            // Tambah balance jika income
            $stmt = $pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE id = ?');
            $stmt->execute([$amount, $walletId]);
        } elseif ($type === 'expense') {
            // Kurangi balance jika expense
            $stmt = $pdo->prepare('UPDATE wallets SET balance = balance - ? WHERE id = ?');
            $stmt->execute([$amount, $walletId]);
        } elseif ($type === 'goal_saving') {
            // Jika type adalah 'goal_saving': kurangi balance di wallets
            $stmt = $pdo->prepare('UPDATE wallets SET balance = balance - ? WHERE id = ?');
            $stmt->execute([$amount, $walletId]);

            // DAN tambah current_amount di tabel savings_goals
            $targetGoalId = 0;
            if ($goalId > 0) {
                $stmt = $pdo->prepare('SELECT id FROM savings_goals WHERE id = ? FOR UPDATE');
                $stmt->execute([$goalId]);
                $targetGoalId = (int) ($stmt->fetchColumn() ?: 0);
            }
            if ($targetGoalId <= 0) {
                // Fallback ke target tabungan pertama jika goal_id tidak dikirim atau tidak ditemukan
                $targetGoalId = (int) $pdo->query('SELECT id FROM savings_goals ORDER BY id ASC LIMIT 1 FOR UPDATE')->fetchColumn();
            }

            if ($targetGoalId > 0) {
                $stmt = $pdo->prepare('UPDATE savings_goals SET current_amount = current_amount + ? WHERE id = ?');
                $stmt->execute([$amount, $targetGoalId]);
            }
        }

        $pdo->commit();

        // 2. Redirect (header location) kembali ke halaman utama agar ter-refresh
        redirect_back('Transaksi berhasil disimpan!', $returnTab);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = $ex instanceof RuntimeException ? $ex->getMessage() : 'Gagal menyimpan transaksi.';
        redirect_back($msg, $returnTab, true);
    }
}

/* ============================================================
 * 2. HAPUS TRANSAKSI (DELETE)
 * ============================================================ */
if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $tx = $stmt->fetch();
        if (!$tx) {
            throw new RuntimeException('Transaksi tidak ditemukan.');
        }

        $amount = (float) $tx['amount'];

        // Kembalikan saldo dompet
        if ($tx['type'] === 'income') {
            $pdo->prepare('UPDATE wallets SET balance = balance - ? WHERE id = ?')
                ->execute([$amount, $tx['wallet_id']]);
        } else {
            $pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE id = ?')
                ->execute([$amount, $tx['wallet_id']]);
        }

        // Kembalikan progres target tabungan jika tipe goal_saving
        if ($tx['type'] === 'goal_saving') {
            $stmt = $pdo->prepare(
                "SELECT id FROM savings_goals
                 WHERE ? LIKE CONCAT('%', goal_title, '%')
                 ORDER BY CHAR_LENGTH(goal_title) DESC LIMIT 1"
            );
            $stmt->execute([$tx['title']]);
            $matchedGoalId = $stmt->fetchColumn();
            if (!$matchedGoalId) {
                $matchedGoalId = $pdo->query('SELECT id FROM savings_goals ORDER BY id ASC LIMIT 1')->fetchColumn();
            }
            if ($matchedGoalId) {
                $pdo->prepare('UPDATE savings_goals SET current_amount = GREATEST(current_amount - ?, 0) WHERE id = ?')
                    ->execute([$amount, $matchedGoalId]);
            }
        }

        $pdo->prepare('DELETE FROM transactions WHERE id = ?')->execute([$id]);

        $pdo->commit();
        redirect_back('Transaksi dihapus & saldo dikembalikan.', $returnTab);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = $ex instanceof RuntimeException ? $ex->getMessage() : 'Gagal menghapus transaksi.';
        redirect_back($msg, $returnTab, true);
    }
}

// Redirect fallback
header('Location: index.php');
exit;
