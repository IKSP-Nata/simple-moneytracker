<?php
/**
 * process_transaction.php — Tambah & hapus transaksi.
 *
 * POST action=add    : title, amount, type (expense|income|goal_saving), wallet_id, goal_id (khusus goal_saving)
 * POST action=delete : id  (saldo dompet / goal dikembalikan seperti semula)
 */

require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$action = $_POST['action'] ?? 'add';
$returnTab = in_array($_POST['return_tab'] ?? '', ['dashboard', 'transactions', 'wallets', 'goals'], true)
    ? $_POST['return_tab']
    : 'dashboard';

/* ============================================================
 * TAMBAH TRANSAKSI
 * ============================================================ */
if ($action === 'add') {
    $title    = trim((string) ($_POST['title'] ?? ''));
    $amount   = parse_amount($_POST['amount'] ?? '');
    $type     = (string) ($_POST['type'] ?? '');
    $walletId = (int) ($_POST['wallet_id'] ?? 0);
    $goalId   = (int) ($_POST['goal_id'] ?? 0);

    if ($title === '' || mb_strlen($title) > 100) {
        redirect_back('Judul transaksi wajib diisi (maks. 100 karakter).', $returnTab, true);
    }
    if ($amount <= 0) {
        redirect_back('Nominal harus lebih dari 0.', $returnTab, true);
    }
    if (!in_array($type, ['expense', 'income', 'goal_saving'], true)) {
        redirect_back('Tipe transaksi tidak valid.', $returnTab, true);
    }

    try {
        $pdo->beginTransaction();

        // Kunci baris dompet agar saldo konsisten
        $stmt = $pdo->prepare('SELECT id, wallet_name, balance FROM wallets WHERE id = ? FOR UPDATE');
        $stmt->execute([$walletId]);
        $wallet = $stmt->fetch();
        if (!$wallet) {
            throw new RuntimeException('Dompet tidak ditemukan.');
        }

        $goal = null;
        if ($type === 'goal_saving') {
            $stmt = $pdo->prepare('SELECT id, goal_title FROM savings_goals WHERE id = ? FOR UPDATE');
            $stmt->execute([$goalId]);
            $goal = $stmt->fetch();
            if (!$goal) {
                throw new RuntimeException('Pilih target tabungan terlebih dahulu.');
            }
            // Sertakan nama goal di judul agar transaksi bisa dilacak saat dihapus
            if (mb_stripos($title, $goal['goal_title']) === false) {
                $suffix = ' — ' . $goal['goal_title'];
                $maxLen = 100 - mb_strlen($suffix);
                $title  = ($maxLen > 0 ? mb_substr($title, 0, $maxLen) : '') . $suffix;
            }
        }

        if ($type !== 'income' && (float) $wallet['balance'] < $amount) {
            throw new RuntimeException('Saldo ' . $wallet['wallet_name'] . ' tidak mencukupi.');
        }

        // 1) Simpan transaksi
        $stmt = $pdo->prepare(
            "INSERT INTO transactions (wallet_id, title, amount, type, source, created_at)
             VALUES (?, ?, ?, ?, 'web', NOW())"
        );
        $stmt->execute([$walletId, $title, $amount, $type]);

        // 2) Update saldo dompet
        $delta = $type === 'income' ? $amount : -$amount;
        $pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE id = ?')
            ->execute([$delta, $walletId]);

        // 3) Tambah ke target tabungan
        if ($goal) {
            $pdo->prepare('UPDATE savings_goals SET current_amount = current_amount + ? WHERE id = ?')
                ->execute([$amount, $goal['id']]);
        }

        $pdo->commit();
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
 * HAPUS TRANSAKSI (dengan rollback saldo)
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
        $delta = $tx['type'] === 'income' ? -$amount : $amount;
        $pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE id = ?')
            ->execute([$delta, $tx['wallet_id']]);

        // Kembalikan progres target tabungan (dicocokkan lewat nama goal di judul)
        if ($tx['type'] === 'goal_saving') {
            $stmt = $pdo->prepare(
                "SELECT id FROM savings_goals
                 WHERE ? LIKE CONCAT('%', goal_title, '%')
                 ORDER BY CHAR_LENGTH(goal_title) DESC LIMIT 1"
            );
            $stmt->execute([$tx['title']]);
            if ($goalId = $stmt->fetchColumn()) {
                $pdo->prepare('UPDATE savings_goals SET current_amount = GREATEST(current_amount - ?, 0) WHERE id = ?')
                    ->execute([$amount, $goalId]);
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

header('Location: index.php');
exit;
