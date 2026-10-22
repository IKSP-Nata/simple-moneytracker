<?php
/**
 * Edit Transaction Handler
 * Reverts previous financial impact and applies updated values atomically.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

// --- Request Method Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php#transactions');
    exit;
}

// --- Input Processing ---
$id        = (int) ($_POST['id'] ?? 0);
$title     = trim((string) ($_POST['title'] ?? ''));
$amount    = parse_amount($_POST['amount'] ?? 0);
$typeRaw   = trim((string) ($_POST['type'] ?? ''));
$walletId  = (int) ($_POST['wallet_id'] ?? 0);
$goalId    = (int) ($_POST['goal_id'] ?? 0);
$returnTab = in_array($_POST['return_tab'] ?? '', ['dashboard', 'transactions', 'wallets', 'goals'], true)
    ? (string) $_POST['return_tab']
    : 'transactions';

// Normalize transaction type
$typeLower = strtolower($typeRaw);
if ($typeLower === 'expense') {
    $type = 'expense';
} elseif ($typeLower === 'income') {
    $type = 'income';
} elseif (in_array($typeLower, ['goal_saving', 'goal', 'saving'], true)) {
    $type = 'goal_saving';
} else {
    $type = '';
}

// --- Input Validation ---
if ($id <= 0) {
    redirect_back('ID transaksi tidak valid.', $returnTab, true);
}
if ($title === '' || mb_strlen($title) > 100) {
    redirect_back('Judul transaksi wajib diisi (maks. 100 karakter).', $returnTab, true);
}
if ($amount <= 0) {
    redirect_back('Nominal harus lebih dari 0.', $returnTab, true);
}
if ($type === '') {
    redirect_back('Tipe transaksi tidak valid.', $returnTab, true);
}
if ($walletId <= 0) {
    redirect_back('Pilih dompet yang valid.', $returnTab, true);
}

// --- Atomic Transaction Processing ---
try {
    $pdo->beginTransaction();

    // 1. Fetch current transaction record with row lock
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $oldTx = $stmt->fetch();
    if (!$oldTx) {
        throw new RuntimeException('Transaksi tidak ditemukan.');
    }

    $oldAmount   = (float) $oldTx['amount'];
    $oldWalletId = (int) $oldTx['wallet_id'];
    $oldType     = (string) $oldTx['type'];
    $oldTitle    = (string) $oldTx['title'];

    // 2. Revert impact of existing transaction
    if ($oldType === 'income') {
        $pdo->prepare('UPDATE wallets SET balance = balance - ? WHERE id = ?')
            ->execute([$oldAmount, $oldWalletId]);
    } elseif ($oldType === 'expense') {
        $pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE id = ?')
            ->execute([$oldAmount, $oldWalletId]);
    } elseif ($oldType === 'goal_saving') {
        $pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE id = ?')
            ->execute([$oldAmount, $oldWalletId]);

        $stmt = $pdo->prepare(
            "SELECT id FROM savings_goals
             WHERE ? LIKE CONCAT('%', goal_title, '%')
             ORDER BY CHAR_LENGTH(goal_title) DESC LIMIT 1"
        );
        $stmt->execute([$oldTitle]);
        $oldGoalId = (int) ($stmt->fetchColumn() ?: 0);
        if ($oldGoalId <= 0) {
            $oldGoalId = (int) ($pdo->query('SELECT id FROM savings_goals ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
        }
        if ($oldGoalId > 0) {
            $pdo->prepare('UPDATE savings_goals SET current_amount = GREATEST(current_amount - ?, 0) WHERE id = ?')
                ->execute([$oldAmount, $oldGoalId]);
        }
    }

    // Verify new target wallet exists
    $stmt = $pdo->prepare('SELECT id FROM wallets WHERE id = ? FOR UPDATE');
    $stmt->execute([$walletId]);
    if (!$stmt->fetch()) {
        throw new RuntimeException('Dompet tujuan tidak ditemukan.');
    }

    // 3. Apply impact of updated transaction
    if ($type === 'income') {
        $pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE id = ?')
            ->execute([$amount, $walletId]);
    } elseif ($type === 'expense') {
        $pdo->prepare('UPDATE wallets SET balance = balance - ? WHERE id = ?')
            ->execute([$amount, $walletId]);
    } elseif ($type === 'goal_saving') {
        $pdo->prepare('UPDATE wallets SET balance = balance - ? WHERE id = ?')
            ->execute([$amount, $walletId]);

        $targetGoalId = $goalId;
        if ($targetGoalId <= 0) {
            $stmt = $pdo->prepare(
                "SELECT id FROM savings_goals
                 WHERE ? LIKE CONCAT('%', goal_title, '%')
                 ORDER BY CHAR_LENGTH(goal_title) DESC LIMIT 1"
            );
            $stmt->execute([$title]);
            $targetGoalId = (int) ($stmt->fetchColumn() ?: 0);
        }
        if ($targetGoalId <= 0) {
            $targetGoalId = (int) ($pdo->query('SELECT id FROM savings_goals ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
        }
        if ($targetGoalId > 0) {
            $pdo->prepare('UPDATE savings_goals SET current_amount = current_amount + ? WHERE id = ?')
                ->execute([$amount, $targetGoalId]);
        }
    }

    // 4. Update transaction row
    $stmt = $pdo->prepare('UPDATE transactions SET title = ?, amount = ?, type = ?, wallet_id = ? WHERE id = ?');
    $stmt->execute([$title, $amount, $type, $walletId, $id]);

    $pdo->commit();
    redirect_back('Transaksi berhasil diperbarui & saldo disesuaikan!', $returnTab);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Gagal memperbarui transaksi.';
    redirect_back($msg, $returnTab, true);
}
