<?php
/**
 * FlowTrack — Personal Money Tracker
 */

require __DIR__ . '/config.php';

// --- Session & User Profile ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$userName = get_user_name($pdo);

// --- Direct Form Submission Handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require __DIR__ . '/process_transaction.php';
    exit;
}

// --- Wallets Data ---
$wallets      = $pdo->query('SELECT * FROM wallets ORDER BY id ASC')->fetchAll();
$totalBalance = (float) $pdo->query('SELECT COALESCE(SUM(balance), 0) FROM wallets')->fetchColumn();

// --- Savings Goals Data ---
$goals = $pdo->query('SELECT * FROM savings_goals ORDER BY id ASC')->fetchAll();
foreach ($goals as &$g) {
    $g['pct'] = (float) $g['target_amount'] > 0
        ? min(100, round(((float) $g['current_amount'] / (float) $g['target_amount']) * 100))
        : 0;
}
unset($g);

// Featured goal: first active goal with progress < 100%
$featuredGoal = null;
foreach ($goals as $g) {
    if ($g['pct'] < 100) { $featuredGoal = $g; break; }
}
$featuredGoal ??= $goals[0] ?? null;

// --- Transactions Data ---
$transactions = $pdo->query(
    'SELECT t.*, COALESCE(w.wallet_name, "Unknown") AS wallet_name
     FROM transactions t
     LEFT JOIN wallets w ON w.id = t.wallet_id
     ORDER BY t.created_at DESC, t.id DESC'
)->fetchAll();
$recentTransactions = array_slice($transactions, 0, 5);

// --- Current Month Summary ---
$monthTotals = ['income' => 0.0, 'expense' => 0.0, 'goal_saving' => 0.0];
$stmt = $pdo->query(
    "SELECT type, SUM(amount) AS total FROM transactions
     WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
     GROUP BY type"
);
foreach ($stmt as $row) {
    $monthTotals[$row['type']] = (float) $row['total'];
}

// --- Cashflow Performance Metric (Net Income vs Expense) ---
$monthlyNet = array_fill(1, 12, 0.0);
$stmt = $pdo->query(
    "SELECT MONTH(created_at) AS m,
            SUM(CASE WHEN type = 'income' THEN amount WHEN type = 'expense' THEN -amount ELSE 0 END) AS net
     FROM transactions
     WHERE YEAR(created_at) = YEAR(CURDATE())
     GROUP BY m"
);
foreach ($stmt as $row) {
    $monthlyNet[(int) $row['m']] = (float) $row['net'];
}

$chartMin = min(0, min($monthlyNet));
$chartMax = max(0, max($monthlyNet));
$chartMax += ($chartMax - $chartMin) * 0.1;
if ($chartMax - $chartMin < 1) { $chartMax = $chartMin + 100000; }

$points = [];
foreach ($monthlyNet as $m => $v) {
    $x = ($m - 1) * (1000 / 11);
    $y = 10 + (1 - ($v - $chartMin) / ($chartMax - $chartMin)) * 190;
    $points[] = [$x, $y];
}

// Catmull-Rom to Bezier curve smoothing
$linePath = sprintf('M %.1f %.1f', $points[0][0], $points[0][1]);
for ($i = 0; $i < 11; $i++) {
    $p0 = $points[$i - 1] ?? $points[$i];
    $p1 = $points[$i];
    $p2 = $points[$i + 1];
    $p3 = $points[$i + 2] ?? $p2;
    $lo = min($p1[1], $p2[1]);
    $hi = max($p1[1], $p2[1]);
    $linePath .= sprintf(
        ' C %.1f %.1f, %.1f %.1f, %.1f %.1f',
        $p1[0] + ($p2[0] - $p0[0]) / 6, max($lo, min($hi, $p1[1] + ($p2[1] - $p0[1]) / 6)),
        $p2[0] - ($p3[0] - $p1[0]) / 6, max($lo, min($hi, $p2[1] - ($p3[1] - $p1[1]) / 6)),
        $p2[0], $p2[1]
    );
}
$areaPath = $linePath . ' L 1000 220 L 0 220 Z';

$curMonth    = (int) date('n');
$curNet      = $monthlyNet[$curMonth];
$prevNet     = $curMonth > 1 ? $monthlyNet[$curMonth - 1] : 0.0;
$netChange   = $prevNet != 0 ? (($curNet - $prevNet) / abs($prevNet)) * 100 : null;
$tipX        = max(7, min(93, $points[$curMonth - 1][0] / 10));
$tipY        = $points[$curMonth - 1][1] / 220 * 100;
$tipBelow    = $tipY < 45;

function short_rp(float $v): string
{
    $sign = $v < 0 ? '-' : '';
    $a = abs($v);
    if ($a >= 1_000_000) return $sign . rtrim(rtrim(number_format($a / 1_000_000, 1, ',', '.'), '0'), ',') . 'jt';
    if ($a >= 1_000)     return $sign . round($a / 1_000) . 'k';
    return $sign . round($a);
}

// --- UI Helpers ---
$walletDots  = ['bg-emerald-500', 'bg-blue-400', 'bg-mauveAccent', 'bg-amber-400'];
$walletTypes = array_values(array_unique(array_merge(
    ['Bank', 'E-Wallet', 'Tunai'],
    array_column($wallets, 'wallet_type')
)));

function is_cash_type(string $type): bool
{
    return in_array(strtolower($type), ['tunai', 'cash', 'physical'], true);
}

// --- Flash Messages ---
$flashMsg   = $_SESSION['flash_msg'] ?? (isset($_GET['msg']) ? (string) $_GET['msg'] : null);
$flashError = !empty($_SESSION['flash_error']) || (($_GET['status'] ?? '') === 'error');
unset($_SESSION['flash_msg'], $_SESSION['flash_error']);
?>
<!DOCTYPE html>

<html lang="id"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>FlowTrack — Personal Money Tracker Dashboard</title>
<meta name="description" content="FlowTrack: pantau saldo dompet, transaksi, dan target tabungan pribadimu dalam Rupiah."/>
<!-- Tailwind CSS CDN with Forms & Container Queries -->
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<!-- Tailwind Configuration for Custom Mauve / Deep Dark Palette -->
<script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            appDark: '#0D0B10',
            appCard: '#15131B',
            appCardSecondary: '#1C1924',
            appBorder: 'rgba(255, 255, 255, 0.06)',
            appBorderSubtle: 'rgba(255, 255, 255, 0.03)',
            mauveGlow: '#C49BBB',
            mauveAccent: '#D6A4E8',
            mauveDark: '#4A3245',
            mauveMuted: '#9B8599',
            mauveActivePill: '#281F2B',
          },
          fontFamily: {
            sans: ['Inter', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
          },
          boxShadow: {
            'glow-mauve': '0 0 35px -5px rgba(214, 164, 232, 0.35)',
            'inner-glow': 'inset 0 1px 1px 0 rgba(255, 255, 255, 0.1)',
          }
        }
      }
    }
  </script>
<!-- Google Font Inter -->
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet"/>
<!-- Lucide Icons -->
<script src="https://unpkg.com/lucide@latest"></script>
<!-- Custom Styles for Ambient Background & Specific Gradients -->
<style data-purpose="custom-theme-styles">
    body {
      background-color: #08060A;
      color: #E2DFE7;
      font-family: 'Inter', sans-serif;
    }

    /* Ambient Outer Glows */
    .ambient-glow-left {
      background: radial-gradient(circle at 10% 20%, rgba(138, 77, 126, 0.12) 0%, transparent 50%);
    }
    .ambient-glow-right {
      background: radial-gradient(circle at 90% 70%, rgba(196, 155, 187, 0.08) 0%, transparent 60%);
    }

    /* Glow Card Special Gradient */
    .card-purple-ambient {
      background: radial-gradient(130% 90% at 50% 115%, rgba(196, 155, 187, 0.32) 0%, rgba(85, 48, 80, 0.22) 45%, rgba(22, 19, 27, 0.95) 85%);
    }

    /* Active Nav Item Soft Gradient */
    .active-nav-pill {
      background: linear-gradient(90deg, rgba(74, 50, 69, 0.55) 0%, rgba(35, 26, 37, 0.8) 100%);
      border: 1px solid rgba(214, 164, 232, 0.18);
    }

    /* Tab panel fade-in */
    [data-panel] { animation: panelIn .28s ease both; }
    @keyframes panelIn {
      from { opacity: 0; transform: translateY(6px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* Custom Scrollbar */
    ::-webkit-scrollbar {
      width: 4px;
      height: 4px;
    }
    ::-webkit-scrollbar-track {
      background: #110e16;
    }
    ::-webkit-scrollbar-thumb {
      background: #2b2433;
      border-radius: 9999px;
    }

    /* Interactive Mascot / Pet */
    @media (hover: none) or (pointer: coarse) {
      #flow-pet-container {
        display: none !important;
      }
    }
    .pixelated {
      image-rendering: -moz-crisp-edges;
      image-rendering: pixelated;
    }
  </style>
</head>
<body class="w-full min-h-screen bg-[#0f0f13] text-[#E2DFE7] relative isolate overflow-x-hidden selection:bg-mauveAccent/30 selection:text-white">

<!-- Sonar Grid Fullscreen Canvas Background -->
<div id="sonar-grid-host" class="fixed inset-0 w-screen h-screen pointer-events-none -z-10 overflow-hidden" aria-hidden="true">
  <canvas id="sonar-grid-canvas" class="w-full h-full block"></canvas>
</div>

<!-- Ambient Light Effects -->
<div class="fixed inset-0 pointer-events-none ambient-glow-left"></div>
<div class="fixed inset-0 pointer-events-none ambient-glow-right"></div>

<!-- Interactive Mascot / Pet -->
<div id="flow-pet-container" class="hidden md:block fixed pointer-events-none z-50 select-none transition-opacity duration-300 opacity-0" style="top: 0; left: 0; will-change: transform;">
  <div id="flow-pet-sprite" class="w-12 h-12 flex items-center justify-center transform transition-transform duration-75 ease-out">
    <img id="flow-pet-img" src="assets/pet/Action.png" alt="Pet Mascot" class="w-12 h-12 object-contain pixelated pointer-events-none select-none drop-shadow-[0_4px_12px_rgba(0,0,0,0.5)]" draggable="false" />
  </div>
</div>

<!-- BEGIN: MainDashboardContainer (Full Screen, No Outer Border/Margin) -->
<main class="relative z-10 w-full min-h-screen flex flex-col md:flex-row bg-transparent">
<!-- BEGIN: LeftSidebar -->
<aside class="relative z-20 w-full md:w-64 flex-shrink-0 flex flex-col bg-transparent md:bg-black/10 backdrop-blur-[2px] md:min-h-screen p-5 md:p-6 border-b md:border-b-0 md:border-r border-white/5" data-purpose="app-navigation">
<div class="space-y-8">
<!-- Logo & Brand Name -->
<div class="flex items-center gap-3 px-2">
<span class="text-xl font-bold tracking-tight text-white flex items-center gap-1.5">
            FlowTrack
          </span>
</div>
<!-- Navigation Links (Tab Navigation) -->
<nav class="space-y-1.5" id="sidebar-nav">
<a class="active-nav-pill flex items-center px-4 py-3 rounded-2xl text-sm font-medium text-white shadow-sm transition-all duration-200" href="#dashboard" data-tab="dashboard" id="nav-dashboard">
<span class="">Dashboard</span>
</a>
<a class="flex items-center px-4 py-3 rounded-2xl text-sm font-medium text-stone-400 hover:text-stone-200 hover:bg-white/[0.03] transition-all" href="#transactions" data-tab="transactions" id="nav-transactions">
<span class="">Transactions</span>
</a>
<a class="flex items-center px-4 py-3 rounded-2xl text-sm font-medium text-stone-400 hover:text-stone-200 hover:bg-white/[0.03] transition-all" href="#wallets" data-tab="wallets" id="nav-wallets">
<span class="">My Wallets</span>
</a>
<a class="flex items-center px-4 py-3 rounded-2xl text-sm font-medium text-stone-400 hover:text-stone-200 hover:bg-white/[0.03] transition-all" href="#goals" data-tab="goals" id="nav-goals">
<span class="">Savings Goals</span>
</a>
</nav>
</div>
</aside>
<!-- END: LeftSidebar -->
<!-- BEGIN: DashboardBody -->
<div class="relative z-10 flex-1 flex flex-col gap-6 p-4 sm:p-6 lg:p-8 min-w-0 overflow-y-auto">
<!-- BEGIN: TopHeader -->
<header class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 w-full">
<!-- User Greeting & Subtitle -->
<div class="flex-1 min-w-0">
<h1 class="text-2xl md:text-3xl font-semibold text-white tracking-tight cursor-pointer hover:text-mauveGlow transition inline-flex items-center gap-2 group" id="greeting-title" title="Klik untuk ubah nama">
  <span>Welcome, <span id="header-user-name"><?= e($userName) ?></span></span>
  <svg class="w-4 h-4 text-stone-500 group-hover:text-mauveAccent transition" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125"/></svg>
</h1>
<p class="text-xs md:text-sm text-stone-400 mt-0.5" id="page-subtitle">
            Here's your personal financial overview today
          </p>
</div>
<!-- Right Header Items: Add Transaction Button & Profile -->
<div class="flex items-center gap-3 flex-shrink-0">
<!-- Add Transaction Button -->
<button class="btn-add-transaction flex items-center gap-1.5 px-4 py-2.5 rounded-2xl text-xs font-semibold bg-gradient-to-r from-[#C49BBB] to-[#A374A0] text-[#0D0B10] shadow-md shadow-[#C49BBB]/20 hover:opacity-95 active:scale-[0.98] transition-all" type="button" id="header-add-btn">
<svg class="w-3.5 h-3.5" fill="none" height="16" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" viewbox="0 0 24 24" width="16" xmlns="http://www.w3.org/2000/svg"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
<span>Add Transaction</span>
</button>
<!-- User Profile Badge (Clickable to Edit Name) -->
<button type="button" id="profile-btn" class="flex items-center gap-2.5 px-3.5 py-2 bg-[#17141E] border border-white/[0.07] hover:border-mauveAccent/40 rounded-2xl transition cursor-pointer text-left group" title="Klik untuk mengubah nama profil">
<div class="w-7 h-7 rounded-full bg-mauveAccent/15 border border-mauveAccent/30 flex items-center justify-center text-mauveAccent text-xs font-semibold">
<?= strtoupper(mb_substr($userName, 0, 1)) ?>
</div>
<div class="text-left">
<p class="text-xs font-semibold text-white group-hover:text-mauveGlow transition leading-tight" id="profile-display-name"><?= e($userName) ?></p>
<p class="text-[10px] text-stone-400 leading-tight">Edit Profile ✎</p>
</div>
</button>
</div>
</header>
<!-- END: TopHeader -->

<!-- ================================================================
     TAB: DASHBOARD
     ================================================================ -->
<div id="tab-dashboard" class="tab-content flex flex-col gap-6" data-panel="dashboard">
<!-- Secondary Filter Pills Row -->
<div class="flex items-center justify-between">
<div class="flex items-center gap-2">
<button class="px-5 py-1.5 rounded-full text-xs font-medium bg-black/60 text-white border border-white/10 hover:border-mauveAccent/40 transition" data-goto="dashboard" type="button" id="pill-overview">
          Overview
        </button>
<button class="px-5 py-1.5 rounded-full text-xs font-medium bg-[#1F1923] text-mauveAccent border border-mauveAccent/20 hover:bg-[#251D2A] transition" data-goto="wallets" type="button" id="pill-wallets">
          Wallets
        </button>
<button class="px-5 py-1.5 rounded-full text-xs font-medium bg-black/40 text-stone-400 border border-white/[0.06] hover:text-white transition" data-goto="goals" type="button" id="pill-goals">
          Goals
        </button>
</div>
<!-- Inline Quick Add Transaction trigger -->
<button class="btn-add-transaction hidden sm:flex items-center gap-1.5 text-xs text-mauveAccent hover:text-white transition font-medium" type="button" id="inline-add-btn">
<span class="text-stone-400 hover:text-stone-200">+ Add Transaction</span>
</button>
</div>
<!-- BEGIN: BentoGridTopSection -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 w-full items-stretch">
<!-- Column 1: Total Balance Card & Wishlist Goal Saver Card -->
<div class="flex flex-col gap-6 justify-between h-full">
<!-- Top Card: Total Balance -->
<div class="bg-[#17141E] border border-white/[0.07] rounded-3xl p-5 flex flex-col justify-between relative" data-purpose="total-balance-card">
  <div class="flex items-center justify-between mb-3">
    <span class="text-xs text-stone-400 font-medium tracking-wide">Total Balance</span>
    
    <!-- Pacing / Budget Allowance Selector Dropdown -->
    <div class="relative" id="pacing-dropdown-wrapper">
      <button
        id="pacing-btn"
        class="flex items-center gap-1.5 text-xs bg-black/40 text-stone-300 border border-white/[0.08] px-3 py-1 rounded-full hover:border-white/20 hover:text-white transition cursor-pointer select-none"
        type="button"
        aria-expanded="false"
        aria-haspopup="true"
        title="Ubah mode tampilan saldo &amp; jatah anggaran"
      >
        <span id="pacing-label">This Month</span>
        <svg id="pacing-chevron" class="w-3.5 h-3.5 text-stone-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      <!-- Custom Dropdown Menu -->
      <div
        id="pacing-menu"
        class="hidden absolute right-0 mt-1.5 w-52 bg-[#1a1a24] border border-white/10 rounded-xl shadow-2xl py-1 text-xs z-30 transition-all duration-150 transform opacity-0 translate-y-1"
        role="menu"
      >
        <button
          type="button"
          class="pacing-option w-full text-left px-3 py-2 flex items-center justify-between text-stone-300 hover:text-white hover:bg-white/[0.06] transition cursor-pointer"
          data-mode="month"
        >
          <div class="flex flex-col">
            <span class="font-medium text-white">This Month</span>
            <span class="text-[10px] text-stone-400">Total saldo bulanan</span>
          </div>
          <span class="pacing-check text-mauveAccent font-bold text-sm">✓</span>
        </button>

        <button
          type="button"
          class="pacing-option w-full text-left px-3 py-2 flex items-center justify-between text-stone-300 hover:text-white hover:bg-white/[0.06] transition cursor-pointer"
          data-mode="week"
        >
          <div class="flex flex-col">
            <span class="font-medium text-stone-200">This Week</span>
            <span class="text-[10px] text-stone-400">Estimasi jatah per minggu</span>
          </div>
          <span class="pacing-check text-mauveAccent font-bold text-sm hidden">✓</span>
        </button>

        <button
          type="button"
          class="pacing-option w-full text-left px-3 py-2 flex items-center justify-between text-stone-300 hover:text-white hover:bg-white/[0.06] transition cursor-pointer"
          data-mode="daily"
        >
          <div class="flex flex-col">
            <span class="font-medium text-stone-200">Today / Daily</span>
            <span class="text-[10px] text-stone-400">Estimasi jatah per hari</span>
          </div>
          <span class="pacing-check text-mauveAccent font-bold text-sm hidden">✓</span>
        </button>
      </div>
    </div>
  </div>
  <div>
    <div
      class="text-3xl lg:text-4xl font-bold tracking-tight text-white mb-2 tabular-nums transition-all duration-200"
      id="total-balance"
      data-raw-balance="<?= (float)$totalBalance ?>"
      data-income="<?= (float)$monthTotals['income'] ?>"
      data-expense="<?= (float)$monthTotals['expense'] ?>"
    >
      <?= rupiah($totalBalance) ?>
    </div>
    <div id="pacing-subtext" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs transition-opacity duration-200">
      <span class="inline-flex items-center gap-0.5 text-emerald-400 font-medium">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
        +<?= rupiah($monthTotals['income']) ?>
      </span>
      <span class="text-stone-300 font-medium">-<?= rupiah($monthTotals['expense']) ?></span>
      <span class="text-stone-400">this month</span>
    </div>
  </div>
</div>
<!-- Bottom Card: Glowing Goal Card -->
<div class="card-purple-ambient border border-mauveAccent/20 rounded-3xl p-5 flex flex-col justify-between relative overflow-hidden flex-1 min-h-[190px]" data-purpose="wishlist-goal-card">
<div class="relative z-10">
<div class="flex items-center justify-between">
<h3 class="text-base font-semibold text-white tracking-wide">Wishlist Goal Saver</h3>
<?php if ($featuredGoal): ?>
<span class="text-[10px] font-semibold text-mauveAccent bg-mauveAccent/10 px-2 py-0.5 rounded-full border border-mauveAccent/25"><?= $featuredGoal['pct'] ?>% Reached</span>
<?php endif; ?>
</div>
<?php if ($featuredGoal): ?>
<p class="text-xs text-stone-300/80 mt-1.5 leading-relaxed">
                <?= e($featuredGoal['goal_title']) ?> — <?= rupiah($featuredGoal['current_amount']) ?> saved of <?= rupiah($featuredGoal['target_amount']) ?> target.
              </p>
<!-- Subtle Progress Track -->
<div class="w-full bg-black/40 h-1.5 rounded-full mt-4 overflow-hidden border border-white/5">
<div class="bg-gradient-to-r from-mauveGlow to-mauveAccent h-full rounded-full transition-all duration-700" style="width: <?= $featuredGoal['pct'] ?>%"></div>
</div>
<?php if (count($goals) > 1): ?>
<p class="text-[10px] text-stone-400 mt-2">+<?= count($goals) - 1 ?> other goal<?= count($goals) > 2 ? 's' : '' ?> in progress</p>
<?php endif; ?>
<?php else: ?>
<p class="text-xs text-stone-300/80 mt-1.5 leading-relaxed">Belum ada target tabungan. Buat target pertamamu untuk mulai menabung.</p>
<?php endif; ?>
</div>
<div class="relative z-10 mt-5 flex justify-center">
<button class="w-full max-w-[210px] py-2 px-4 rounded-full text-xs font-semibold text-white bg-gradient-to-r from-[#AF77A4] to-[#C991BE] shadow-lg shadow-[#8E5281]/40 hover:opacity-95 transition-opacity active:scale-[0.98]" data-goto="goals" type="button" id="explore-goals-btn">
                <?= $featuredGoal ? 'Explore Goals' : 'Create Goal' ?>
              </button>
</div>
</div>
</div>
<!-- END: Column 1 -->
<!-- Column 2: Recent Transactions List -->
<div class="bg-[#17141E] border border-white/[0.07] rounded-3xl p-5 flex flex-col justify-between h-full" data-purpose="transactions-list-card">
<div>
<div class="flex items-center justify-between mb-4">
<h2 class="text-sm font-semibold text-white">Recent Transactions</h2>
<button class="btn-add-transaction flex items-center gap-1 text-[11px] text-mauveAccent hover:text-white transition font-medium" type="button" id="recent-add-btn">
<span>+ Add</span>
</button>
</div>
<!-- Filter Pills -->
<div class="flex items-center gap-1.5 mb-4 text-[11px]" data-filter-group="recent-list" data-limit="5">
<button class="bg-[#2A2331] text-white px-3 py-1 rounded-full font-medium" data-filter="all" type="button" id="recent-filter-all">All</button>
<button class="bg-black/30 text-stone-400 hover:text-white px-3 py-1 rounded-full transition" data-filter="income" type="button" id="recent-filter-income">Income</button>
<button class="bg-black/30 text-stone-400 hover:text-white px-3 py-1 rounded-full transition" data-filter="expense" type="button" id="recent-filter-expense">Expense</button>
</div>
<!-- Transactions Items (Clean Typographic) -->
<div class="space-y-3.5" id="recent-list">
<?php if (!$recentTransactions): ?>
<p class="text-[11px] text-stone-500 py-6 text-center" id="recent-list-empty">Belum ada transaksi.</p>
<?php else: ?>
<?php foreach ($recentTransactions as $tx):
    $isIncome = $tx['type'] === 'income';
    $isGoal   = $tx['type'] === 'goal_saving';
    $amtClass = $isIncome ? 'text-emerald-400' : ($isGoal ? 'text-mauveAccent' : 'text-stone-200');
    $subClass = $isIncome ? 'text-emerald-500/80 font-medium' : 'text-stone-400';
?>
<div class="flex items-center justify-between text-xs py-1 border-b border-white/[0.04]" data-type="<?= e($tx['type']) ?>">
<div class="min-w-0 pr-3">
<p class="font-medium text-stone-200 truncate"><?= e($tx['title']) ?></p>
<p class="text-[10px] text-stone-400"><?= e($tx['wallet_name']) ?><?= $isGoal ? ' · Goal Saving' : '' ?></p>
</div>
<div class="text-right flex-shrink-0">
<p class="font-semibold <?= $amtClass ?>"><?= $isIncome ? '+' : '-' ?><?= rupiah($tx['amount']) ?></p>
<p class="text-[10px] <?= $subClass ?>"><?= date('d M', strtotime($tx['created_at'])) ?> · <?= $tx['source'] === 'bot' ? 'Bot' : 'Web' ?></p>
</div>
</div>
<?php endforeach; ?>
<p class="hidden text-[11px] text-stone-500 py-6 text-center" id="recent-list-empty">Belum ada transaksi untuk filter ini.</p>
<?php endif; ?>
</div>
</div>
</div>
<!-- END: Column 2 -->
<!-- Column 3: 2x2 Mini Grid - My Wallets -->
<div class="bg-[#17141E] border border-white/[0.07] rounded-3xl p-5 flex flex-col justify-between h-full" data-purpose="wallets-grid-card">
<div>
<div class="flex items-center justify-between mb-4">
<h2 class="text-sm font-semibold text-white">My Wallets</h2>
<button class="flex items-center gap-1 text-[11px] bg-black/40 text-stone-300 border border-white/[0.08] px-2.5 py-1 rounded-full hover:border-white/20 transition" data-goto="wallets" type="button" id="wallets-see-all">
<span class="">See all</span>
<i class="w-3 h-3 text-stone-400" data-lucide="arrow-up-right"></i>
</button>
</div>
<!-- 2x2 Grid -->
<div class="grid grid-cols-2 gap-2.5">
<?php if (!$wallets): ?>
<p class="text-[11px] text-stone-500 py-6 col-span-2 text-center">Belum ada dompet.</p>
<?php else: ?>
<?php foreach (array_slice($wallets, 0, 4) as $i => $w):
    $isPrimary = strtolower($w['wallet_type']) === 'primary';
    $share     = $totalBalance > 0 ? round(((float) $w['balance'] / $totalBalance) * 100, 1) : 0;
?>
<div class="bg-black/40 border border-white/[0.05] rounded-2xl p-3 flex flex-col justify-between hover:border-mauveAccent/30 transition">
<div>
<p class="text-[11px] font-semibold text-white tracking-tight"><?= rupiah($w['balance']) ?></p>
<?php if ($isPrimary): ?>
<p class="text-[9px] text-emerald-400 mt-0.5"><?= $share ?>% of total</p>
<?php else: ?>
<p class="text-[9px] text-stone-400 mt-0.5"><?= e($w['wallet_type']) ?></p>
<?php endif; ?>
</div>
<div class="mt-4 flex items-center justify-between gap-1">
<span class="text-[10px] font-medium text-stone-300 truncate"><?= e($w['wallet_name']) ?></span>
<?php if ($isPrimary): ?>
<span class="text-[8px] bg-blue-500/10 text-blue-300 px-1 py-0.5 rounded">Primary</span>
<?php elseif (is_cash_type($w['wallet_type'])): ?>
<i class="w-3 h-3 text-stone-400" data-lucide="wallet"></i>
<?php else: ?>
<span class="w-2 h-2 rounded-full flex-shrink-0 <?= $walletDots[$i % count($walletDots)] ?>"></span>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php if (count($wallets) > 4): ?>
<p class="text-[10px] text-stone-500 mt-3">+<?= count($wallets) - 4 ?> dompet lainnya</p>
<?php endif; ?>
</div>
</div>
<!-- END: Column 3 -->
</div>
<!-- END: BentoGridTopSection -->

<!-- BEGIN: Kokonut UI Spending Ring Card -->
<section class="w-full bg-[#1a1a24]/90 border border-white/5 rounded-2xl p-5 md:p-6 shadow-xl relative" id="spending-ring-card">
  <!-- Card Header -->
  <div class="flex flex-col sm:flex-row sm:items-baseline justify-between gap-2 pb-4 border-b border-white/[0.05]">
    <div>
      <h2 class="text-base font-semibold text-white tracking-tight flex flex-wrap items-center gap-2">
        <span>Spending by Category</span>
        <span class="text-[10px] font-semibold text-mauveAccent bg-mauveAccent/10 px-2 py-0.5 rounded-full border border-mauveAccent/20">Interactive Ring</span>
        <button
          type="button"
          id="sr-open-modal-btn"
          class="text-xs px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-zinc-300 hover:text-white transition inline-flex items-center gap-1.5 cursor-pointer ml-0.5"
          title="Edit Kategori &amp; Pengeluaran"
        >
          <svg class="w-3.5 h-3.5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
          </svg>
          <span>Edit Kategori</span>
        </button>
      </h2>
      <p class="text-xs text-stone-400 mt-0.5">Drag or flick the ring to inspect categories, or click any item below</p>
    </div>
    <div class="sm:text-right">
      <span class="text-[10px] uppercase tracking-wider text-stone-500 font-medium block">Total Spending</span>
      <span class="font-bold text-lg text-white tabular-nums" id="sr-total-display">Rp 0</span>
    </div>
  </div>

  <!-- Interactive Ring & Legend Layout -->
  <div class="flex flex-col lg:flex-row items-center justify-around gap-6 lg:gap-12 mt-6">
    <!-- Interactive SVG Ring Surface -->
    <div
      id="spending-ring-surface"
      class="relative aspect-square w-full max-w-[240px] touch-none select-none rounded-full outline-none cursor-grab active:cursor-grabbing focus-visible:ring-2 focus-visible:ring-mauveAccent/50"
      role="slider"
      tabindex="0"
      aria-label="Spending by category. Use arrow keys or drag to turn the ring."
    >
      <svg
        aria-hidden="true"
        class="absolute inset-0 size-full overflow-visible"
        viewBox="0 0 240 240"
      >
        <g id="spending-ring-rotator" transform="rotate(0 120 120)">
          <!-- Dynamic SVG arc paths will be injected here -->
        </g>
        <!-- The top indicator triangle marker -->
        <path class="fill-white" d="M115 2 L125 2 L120 8 Z" />
      </svg>

      <!-- Center Readout -->
      <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center px-4">
        <div id="sr-readout-content" class="transition-all duration-200 ease-out">
          <p class="flex items-center justify-center gap-1.5 text-xs text-stone-400 font-medium truncate">
            <span id="sr-readout-dot" class="size-2 rounded-full bg-sky-400 shrink-0"></span>
            <span id="sr-readout-name">Housing</span>
          </p>
          <p id="sr-readout-amount" class="mt-1 font-bold text-xl sm:text-2xl text-white tabular-nums tracking-tight">
            Rp 0
          </p>
          <p id="sr-readout-share" class="mt-0.5 text-xs text-stone-400 tabular-nums">
            0% of total
          </p>
          <p id="sr-readout-change" class="mt-1 text-[11px] tabular-nums font-medium text-stone-400">
            Same as last month
          </p>
        </div>
      </div>
    </div>

    <!-- Category Legend List -->
    <div class="w-full lg:max-w-md flex-1">
      <ul id="sr-category-list" class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <!-- Dynamic Category Buttons will be injected here -->
      </ul>
    </div>
  </div>
</section>
<!-- END: Kokonut UI Spending Ring Card -->

<!-- BEGIN: BottomWideCard (Cashflow Performance Line Chart) -->
<section class="w-full bg-[#17141E] border border-white/[0.07] rounded-3xl p-5 md:p-6 relative" data-purpose="performance-chart-card">
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
<div>
<h2 class="text-base font-semibold text-white">Cashflow Performance</h2>
<p class="text-xs text-stone-400">Net cashflow (income − expense) per month in <?= date('Y') ?></p>
</div>
<!-- Timeframe Selector -->
<div class="flex items-center bg-black/50 border border-white/[0.06] rounded-full p-1 self-start sm:self-auto text-xs">
<button class="px-3 py-1 rounded-full text-stone-400 hover:text-white transition" type="button">1D</button>
<button class="px-3 py-1 rounded-full text-stone-400 hover:text-white transition" type="button">1W</button>
<button class="px-3 py-1 rounded-full text-stone-400 hover:text-white transition" type="button">1M</button>
<button class="px-3 py-1 rounded-full text-stone-400 hover:text-white transition" type="button">6M</button>
<button class="px-3 py-1 rounded-full bg-[#352538] text-mauveAccent font-semibold border border-mauveAccent/30 shadow-sm transition" type="button">1Y</button>
</div>
</div>
<!-- Chart Container with SVG Curve -->
<div class="relative w-full h-56 md:h-64 pt-2">
<!-- Background Grid Lines -->
<div class="absolute inset-0 flex flex-col justify-between pointer-events-none pb-7">
<?php for ($k = 0; $k < 5; $k++): ?>
<div class="border-b border-white/[0.04] w-full flex items-center justify-between">
<span class="text-[10px] text-stone-600 pl-1"><?= short_rp($chartMax - $k * ($chartMax - $chartMin) / 4) ?></span>
</div>
<?php endfor; ?>
</div>
<div class="relative w-full h-[calc(100%-24px)]">
<!-- Interactive Tooltip Overlay (current month) -->
<div class="absolute z-20 flex <?= $tipBelow ? 'flex-col-reverse' : 'flex-col' ?> items-center pointer-events-none" style="left: <?= round($tipX, 2) ?>%; top: <?= round($tipY, 2) ?>%; transform: translate(-50%, <?= $tipBelow ? '-6px' : 'calc(-100% + 6px)' ?>);">
<div class="bg-[#241C29] border border-mauveAccent/40 shadow-xl shadow-black/60 rounded-xl px-3 py-2 text-center backdrop-blur-md whitespace-nowrap">
<div class="flex items-center justify-center gap-2">
<span class="text-[10px] text-stone-300 font-medium"><?= date('M Y') ?></span>
<span class="w-1.5 h-1.5 rounded-full bg-mauveAccent"></span>
</div>
<div class="flex items-center gap-1.5 mt-0.5">
<span class="text-xs font-bold text-white tracking-tight"><?= $curNet < 0 ? '-' : '' ?><?= rupiah(abs($curNet)) ?></span>
<?php if ($netChange !== null): ?>
<span class="text-[9px] font-semibold <?= $netChange >= 0 ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20' : 'text-rose-300 bg-rose-500/10 border-rose-500/20' ?> px-1 py-0.2 rounded border"><?= $netChange >= 0 ? '+' : '' ?><?= round($netChange) ?>%</span>
<?php endif; ?>
</div>
</div>
<!-- Vertical Dashed Line -->
<div class="w-[1px] h-12 border-l border-dashed border-mauveAccent/70 my-1"></div>
<!-- Glowing Point Node -->
<div class="w-3 h-3 rounded-full bg-mauveAccent border-2 border-[#121017] shadow-lg shadow-mauveAccent"></div>
</div>
<!-- SVG Smooth Curved Line Chart -->
<svg class="w-full h-full overflow-visible" preserveAspectRatio="none" viewBox="0 0 1000 220">
<defs>
<!-- Mauve Fill Area Gradient -->
<linearGradient id="chartGlowArea" x1="0%" x2="0%" y1="0%" y2="100%">
<stop offset="0%" stop-color="#C49BBB" stop-opacity="0.25"></stop>
<stop offset="50%" stop-color="#8E5281" stop-opacity="0.10"></stop>
<stop offset="100%" stop-color="#17141E" stop-opacity="0.0"></stop>
</linearGradient>
<!-- Stroke Gradient -->
<linearGradient id="chartStroke" x1="0%" x2="100%" y1="0%" y2="0%">
<stop offset="0%" stop-color="#A5709B"></stop>
<stop offset="50%" stop-color="#E1B8DF"></stop>
<stop offset="100%" stop-color="#A5709B"></stop>
</linearGradient>
</defs>
<!-- Gradient Area Fill -->
<path d="<?= $areaPath ?>" fill="url(#chartGlowArea)"></path>
<!-- Smooth Main Path -->
<path d="<?= $linePath ?>" fill="none" stroke="url(#chartStroke)" stroke-linecap="round" stroke-width="2.5" vector-effect="non-scaling-stroke"></path>
</svg>
</div>
<!-- Month Labels Jan - Dec -->
<div class="flex justify-between items-center text-[10px] text-stone-500 pt-3 px-2 font-medium">
<?php foreach (['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mLabel): ?>
<span class="<?= $mi + 1 === $curMonth ? 'text-mauveAccent font-semibold' : '' ?>"><?= $mLabel ?></span>
<?php endforeach; ?>
</div>
</div>
</section>
<!-- END: BottomWideCard -->
</div>
<!-- END TAB: DASHBOARD -->

<!-- ================================================================
     TAB: TRANSACTIONS
     ================================================================ -->
<div id="tab-transactions" class="tab-content hidden flex flex-col gap-6" data-panel="transactions">
<div class="bg-[#17141E] border border-white/[0.07] rounded-3xl p-5 md:p-6">
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
<div>
<h2 class="text-base font-semibold text-white">Transaction History</h2>
<p class="text-xs text-stone-400"><?= count($transactions) ?> transaksi tercatat</p>
</div>
<div class="flex items-center gap-3">
<div class="flex items-center gap-1.5 text-[11px]" data-filter-group="all-tx-list">
<button class="bg-[#2A2331] text-white px-3 py-1 rounded-full font-medium" data-filter="all" type="button" id="tx-filter-all">All</button>
<button class="bg-black/30 text-stone-400 hover:text-white px-3 py-1 rounded-full transition" data-filter="income" type="button" id="tx-filter-income">Income</button>
<button class="bg-black/30 text-stone-400 hover:text-white px-3 py-1 rounded-full transition" data-filter="expense" type="button" id="tx-filter-expense">Expense</button>
<button class="bg-black/30 text-stone-400 hover:text-white px-3 py-1 rounded-full transition" data-filter="goal_saving" type="button" id="tx-filter-goal">Goal</button>
</div>
<button class="btn-add-transaction flex items-center gap-1 text-[11px] text-mauveAccent hover:text-white transition font-medium" type="button" id="tx-tab-add-btn">
<span>+ Add</span>
</button>
</div>
</div>
<div class="overflow-x-auto -mx-1">
<table class="w-full text-xs min-w-[640px]">
<thead>
<tr class="text-[10px] uppercase tracking-wider text-stone-500 border-b border-white/[0.06]">
<th class="text-left font-medium px-2 py-2.5">Date</th>
<th class="text-left font-medium px-2 py-2.5">Title</th>
<th class="text-left font-medium px-2 py-2.5">Wallet</th>
<th class="text-left font-medium px-2 py-2.5">Type</th>
<th class="text-left font-medium px-2 py-2.5">Source</th>
<th class="text-right font-medium px-2 py-2.5">Amount</th>
<th class="text-right font-medium px-2 py-2.5"><span class="sr-only">Action</span></th>
</tr>
</thead>
<tbody id="all-tx-list">
<?php foreach ($transactions as $tx):
    $isIncome = $tx['type'] === 'income';
    $isGoal   = $tx['type'] === 'goal_saving';
    $badge = $isIncome
        ? ['Income', 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20']
        : ($isGoal
            ? ['Goal Saving', 'text-mauveAccent bg-mauveAccent/10 border-mauveAccent/25']
            : ['Expense', 'text-stone-300 bg-white/[0.04] border-white/[0.08]']);
?>
<tr class="border-b border-white/[0.04] hover:bg-white/[0.02] transition" data-type="<?= e($tx['type']) ?>">
<td class="px-2 py-3 text-stone-400 whitespace-nowrap"><?= date('d M Y, H:i', strtotime($tx['created_at'])) ?></td>
<td class="px-2 py-3 font-medium text-stone-200"><?= e($tx['title']) ?></td>
<td class="px-2 py-3 text-stone-300"><?= e($tx['wallet_name']) ?></td>
<td class="px-2 py-3"><span class="text-[10px] font-semibold px-2 py-0.5 rounded-full border <?= $badge[1] ?>"><?= $badge[0] ?></span></td>
<td class="px-2 py-3 text-stone-400"><?= $tx['source'] === 'bot' ? 'Bot' : 'Web' ?></td>
<td class="px-2 py-3 text-right font-semibold whitespace-nowrap <?= $isIncome ? 'text-emerald-400' : ($isGoal ? 'text-mauveAccent' : 'text-stone-200') ?>"><?= $isIncome ? '+' : '-' ?><?= rupiah($tx['amount']) ?></td>
<td class="px-2 py-3 text-right whitespace-nowrap">
<button type="button" class="btn-edit-tx text-[11px] text-stone-300 bg-black/40 border border-white/[0.08] px-2.5 py-1 rounded-full hover:text-white hover:border-white/20 transition mr-1" data-id="<?= (int) $tx['id'] ?>" data-title="<?= e($tx['title']) ?>" data-amount="<?= (float) $tx['amount'] ?>" data-type="<?= e($tx['type']) ?>" data-wallet-id="<?= (int) $tx['wallet_id'] ?>" id="edit-tx-<?= (int) $tx['id'] ?>">Edit</button>
<form action="process_transaction.php" method="post" class="inline" onsubmit="return confirm('Hapus transaksi ini? Saldo dompet akan dikembalikan.');">
<input type="hidden" name="action" value="delete"/>
<input type="hidden" name="id" value="<?= (int) $tx['id'] ?>"/>
<input type="hidden" name="return_tab" value="transactions"/>
<button class="text-[11px] text-stone-400 bg-black/40 border border-white/[0.08] px-2.5 py-1 rounded-full hover:text-rose-300 hover:border-rose-400/30 transition" type="submit" id="delete-tx-<?= (int) $tx['id'] ?>">Hapus</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p class="<?= $transactions ? 'hidden' : '' ?> text-xs text-stone-500 py-10 text-center" id="all-tx-list-empty">Belum ada transaksi.</p>
</div>
</div>
</div>
<!-- END TAB: TRANSACTIONS -->

<!-- ================================================================
     TAB: MY WALLETS
     ================================================================ -->
<div id="tab-wallets" class="tab-content hidden flex flex-col gap-6" data-panel="wallets">
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
<!-- Wallet list -->
<div class="lg:col-span-2 bg-[#17141E] border border-white/[0.07] rounded-3xl p-5 md:p-6">
<div class="flex items-center justify-between mb-5">
<div>
<h2 class="text-base font-semibold text-white">My Wallets</h2>
<p class="text-xs text-stone-400"><?= count($wallets) ?> dompet · Total <?= rupiah($totalBalance) ?></p>
</div>
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
<?php foreach ($wallets as $i => $w):
    $share = $totalBalance > 0 ? ((float) $w['balance'] / $totalBalance) * 100 : 0;
?>
<div class="bg-black/40 border border-white/[0.05] rounded-2xl p-4 hover:border-mauveAccent/30 transition">
<div class="flex items-center justify-between">
<span class="text-sm font-medium text-stone-200"><?= e($w['wallet_name']) ?></span>
<span class="text-[10px] text-stone-400 bg-white/[0.04] border border-white/[0.06] px-2 py-0.5 rounded-full"><?= e($w['wallet_type']) ?></span>
</div>
<p class="text-xl font-bold tracking-tight text-white mt-3"><?= rupiah($w['balance']) ?></p>
<div class="w-full bg-black/40 h-1 rounded-full mt-3 overflow-hidden border border-white/5">
<div class="bg-gradient-to-r from-mauveGlow to-mauveAccent h-full rounded-full" style="width: <?= round($share, 1) ?>%"></div>
</div>
<p class="text-[10px] text-stone-500 mt-1.5"><?= round($share, 1) ?>% dari total saldo</p>
</div>
<?php endforeach; ?>
</div>
<?php if (!$wallets): ?>
<p class="text-xs text-stone-500 py-10 text-center">Belum ada dompet.</p>
<?php endif; ?>
</div>
<!-- Add wallet form -->
<div class="bg-[#17141E] border border-white/[0.07] rounded-3xl p-5 md:p-6">
<h2 class="text-sm font-semibold text-white">+ Tambah Dompet Baru</h2>
<p class="text-xs text-stone-400 mt-0.5 mb-5">Rekening bank, e-wallet, atau uang tunai</p>
<form action="process_wallet.php" method="post" class="space-y-4" id="wallet-form">
<div>
<label class="block text-xs font-medium text-stone-300 mb-1.5" for="wallet-name">Nama Dompet</label>
<input class="w-full bg-[#1F1B27] border border-white/10 rounded-xl px-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="wallet-name" name="wallet_name" maxlength="50" placeholder="e.g. Bank Mandiri, OVO" required type="text"/>
</div>
<div>
<label class="block text-xs font-medium text-stone-300 mb-1.5" for="wallet-type">Tipe Dompet</label>
<input class="w-full bg-[#1F1B27] border border-white/10 rounded-xl px-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="wallet-type" name="wallet_type" maxlength="30" list="wallet-type-options" placeholder="e.g. E-Wallet" required type="text"/>
<datalist id="wallet-type-options">
<?php foreach ($walletTypes as $t): ?>
<option value="<?= e($t) ?>"></option>
<?php endforeach; ?>
</datalist>
</div>
<div>
<label class="block text-xs font-medium text-stone-300 mb-1.5" for="wallet-balance">Saldo Awal (Rp)</label>
<div class="relative flex items-center">
<span class="absolute left-3.5 text-xs font-medium text-stone-400 pointer-events-none">Rp</span>
<input class="money-input w-full bg-[#1F1B27] border border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="wallet-balance" name="balance" inputmode="numeric" autocomplete="off" placeholder="0" type="text"/>
</div>
</div>
<button class="w-full py-2.5 px-4 rounded-xl text-xs font-semibold text-[#0D0B10] bg-gradient-to-r from-[#C49BBB] to-[#A374A0] hover:opacity-90 active:scale-[0.98] shadow-lg shadow-[#C49BBB]/20 transition-all" type="submit" id="wallet-submit-btn">
          Simpan Dompet
        </button>
</form>
</div>
</div>
</div>
<!-- END TAB: MY WALLETS -->

<!-- ================================================================
     TAB: SAVINGS GOALS
     ================================================================ -->
<div id="tab-goals" class="tab-content hidden flex flex-col gap-6" data-panel="goals">
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
<!-- Goals list -->
<div class="lg:col-span-2 bg-[#17141E] border border-white/[0.07] rounded-3xl p-5 md:p-6">
<div class="mb-5">
<h2 class="text-base font-semibold text-white">Savings Goals</h2>
<p class="text-xs text-stone-400"><?= count($goals) ?> target tabungan</p>
</div>
<div class="space-y-3">
<?php foreach ($goals as $g):
    $remaining = max(0, (float) $g['target_amount'] - (float) $g['current_amount']);
?>
<div class="<?= $featuredGoal && $g['id'] === $featuredGoal['id'] ? 'card-purple-ambient border-mauveAccent/20' : 'bg-black/40 border-white/[0.05]' ?> border rounded-2xl p-4 hover:border-mauveAccent/30 transition">
<div class="flex items-center justify-between gap-3">
<h3 class="text-sm font-semibold text-white"><?= e($g['goal_title']) ?></h3>
<span class="text-[10px] font-semibold text-mauveAccent bg-mauveAccent/10 px-2 py-0.5 rounded-full border border-mauveAccent/25 whitespace-nowrap"><?= $g['pct'] ?>% Reached</span>
</div>
<p class="text-xs text-stone-300/80 mt-1.5"><?= rupiah($g['current_amount']) ?> saved of <?= rupiah($g['target_amount']) ?> target.</p>
<div class="w-full bg-black/40 h-1.5 rounded-full mt-3 overflow-hidden border border-white/5">
<div class="bg-gradient-to-r from-mauveGlow to-mauveAccent h-full rounded-full transition-all duration-700" style="width: <?= $g['pct'] ?>%"></div>
</div>
<div class="flex items-center justify-between mt-3">
<span class="text-[10px] text-stone-400"><?= $remaining > 0 ? 'Sisa ' . rupiah($remaining) : 'Target tercapai' ?></span>
<div class="flex items-center gap-3">
<?php if ($g['pct'] < 100): ?>
<button class="btn-save-goal text-[11px] font-medium text-mauveAccent hover:text-white transition" data-goal-id="<?= (int) $g['id'] ?>" type="button" id="save-goal-<?= (int) $g['id'] ?>">+ Nabung</button>
<?php endif; ?>
<button class="btn-edit-goal text-[11px] font-medium text-indigo-400 hover:text-indigo-300 transition" type="button" data-goal-id="<?= (int) $g['id'] ?>" data-goal-title="<?= e($g['goal_title']) ?>" data-goal-target="<?= (float) $g['target_amount'] ?>" id="edit-goal-<?= (int) $g['id'] ?>">Edit</button>
<form action="delete_goal.php" method="post" class="inline" onsubmit="return confirm('Hapus target tabungan &quot;<?= e($g['goal_title']) ?>&quot;?');">
<input type="hidden" name="id" value="<?= (int) $g['id'] ?>"/>
<button class="text-[11px] font-medium text-red-400 hover:text-red-300 transition" type="submit" id="delete-goal-<?= (int) $g['id'] ?>">Hapus</button>
</form>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php if (!$goals): ?>
<p class="text-xs text-stone-500 py-10 text-center">Belum ada target tabungan.</p>
<?php endif; ?>
</div>
<!-- Add goal form -->
<div class="bg-[#17141E] border border-white/[0.07] rounded-3xl p-5 md:p-6">
<h2 class="text-sm font-semibold text-white">+ Tambah Target Tabungan Baru</h2>
<p class="text-xs text-stone-400 mt-0.5 mb-5">Wujudkan wishlist-mu satu per satu</p>
<form action="process_goal.php" method="post" class="space-y-4" id="goal-form">
<div>
<label class="block text-xs font-medium text-stone-300 mb-1.5" for="goal-title">Nama Target</label>
<input class="w-full bg-[#1F1B27] border border-white/10 rounded-xl px-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="goal-title" name="goal_title" maxlength="100" placeholder="e.g. Liburan ke Jepang" required type="text"/>
</div>
<div>
<label class="block text-xs font-medium text-stone-300 mb-1.5" for="goal-target">Nominal Target (Rp)</label>
<div class="relative flex items-center">
<span class="absolute left-3.5 text-xs font-medium text-stone-400 pointer-events-none">Rp</span>
<input class="money-input w-full bg-[#1F1B27] border border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="goal-target" name="target_amount" inputmode="numeric" autocomplete="off" placeholder="10.000.000" required type="text"/>
</div>
</div>
<div>
<label class="block text-xs font-medium text-stone-300 mb-1.5" for="goal-current">Sudah Terkumpul (Rp) <span class="text-stone-500 font-normal">— opsional</span></label>
<div class="relative flex items-center">
<span class="absolute left-3.5 text-xs font-medium text-stone-400 pointer-events-none">Rp</span>
<input class="money-input w-full bg-[#1F1B27] border border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="goal-current" name="current_amount" inputmode="numeric" autocomplete="off" placeholder="0" type="text"/>
</div>
</div>
<button class="w-full py-2.5 px-4 rounded-xl text-xs font-semibold text-[#0D0B10] bg-gradient-to-r from-[#C49BBB] to-[#A374A0] hover:opacity-90 active:scale-[0.98] shadow-lg shadow-[#C49BBB]/20 transition-all" type="submit" id="goal-submit-btn">
          Simpan Target
        </button>
</form>
</div>
</div>
</div>
<!-- END TAB: SAVINGS GOALS -->
</div>
<!-- END: DashboardBody -->
</main>
<!-- END: MainDashboardContainer -->
<!-- BEGIN: Add Transaction Modal Popup -->
<div aria-labelledby="modal-title" aria-modal="true" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 opacity-0 pointer-events-none transition-all duration-300" id="transaction-modal" role="dialog">
<!-- Modal Card -->
<div class="bg-[#16141C] border border-white/[0.08] rounded-2xl max-w-md w-full p-6 relative shadow-2xl shadow-black/80 transition-all duration-300 transform scale-95 opacity-0" id="modal-card">
<!-- Header -->
<div class="flex items-start justify-between mb-5">
<div>
<h3 class="text-lg font-semibold text-white tracking-tight" id="modal-title">Add Transaction</h3>
<p class="text-xs text-stone-400 mt-0.5">Log a new cashflow entry</p>
</div>
<button aria-label="Close modal" class="w-8 h-8 rounded-full bg-white/[0.04] hover:bg-white/[0.08] text-stone-400 hover:text-white flex items-center justify-center border border-white/[0.06] transition text-sm" id="modal-close-btn" type="button">
        ✕
      </button>
</div>
<!-- Form -->
<form class="space-y-4" id="transaction-form" action="process_transaction.php" method="post">
<input type="hidden" name="action" value="add"/>
<input type="hidden" name="type" id="tx-type" value="expense"/>
<input type="hidden" name="wallet_id" id="tx-wallet-id" value="<?= (int) ($wallets[0]['id'] ?? 0) ?>"/>
<input type="hidden" name="goal_id" id="tx-goal-id" value="<?= (int) ($featuredGoal['id'] ?? 0) ?>"/>
<input type="hidden" name="return_tab" id="tx-return-tab" value="dashboard"/>
<!-- 1. Transaction Title -->
<div>
<label class="block text-xs font-medium text-stone-300 mb-1.5" for="tx-title">Transaction Title</label>
<input class="w-full bg-[#1F1B27] border border-white/10 rounded-xl px-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="tx-title" name="title" maxlength="100" placeholder="e.g. Makan Siang, Beli Kopi" required="" type="text"/>
</div>
<!-- 2. Amount in Rupiah -->
<div>
<label class="block text-xs font-medium text-stone-300 mb-1.5" for="tx-amount">Amount (Rp)</label>
<div class="relative flex items-center">
<span class="absolute left-3.5 text-xs font-medium text-stone-400 pointer-events-none">Rp</span>
<input class="money-input w-full bg-[#1F1B27] border border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="tx-amount" name="amount" inputmode="numeric" autocomplete="off" placeholder="50.000" required="" type="text"/>
</div>
</div>
<!-- 3. Transaction Type Pills -->
<div>
<label class="block text-xs font-medium text-stone-300 mb-1.5">Transaction Type</label>
<div class="grid grid-cols-3 gap-2" id="type-pills">
<button class="type-pill px-3 py-2 rounded-xl text-xs font-medium text-center transition bg-[#281F2B] border border-mauveAccent/30 text-mauveAccent" data-type="expense" type="button" id="type-pill-expense">
            Expense
          </button>
<button class="type-pill px-3 py-2 rounded-xl text-xs font-medium text-center transition bg-black/40 border border-white/[0.06] text-stone-400 hover:text-white hover:border-white/15" data-type="income" type="button" id="type-pill-income">
            Income
          </button>
<button class="type-pill px-3 py-2 rounded-xl text-xs font-medium text-center transition bg-black/40 border border-white/[0.06] text-stone-400 hover:text-white hover:border-white/15" data-type="goal_saving" type="button" id="type-pill-goal">
            Save to Goal
          </button>
</div>
</div>
<!-- 3b. Goal Target Selector (khusus Save to Goal) -->
<div class="hidden" id="goal-field">
<label class="block text-xs font-medium text-stone-300 mb-1.5">Savings Goal</label>
<?php if ($goals): ?>
<div class="grid grid-cols-2 gap-2" id="goal-pills">
<?php foreach ($goals as $g):
    $active = $featuredGoal && $g['id'] === $featuredGoal['id'];
?>
<button class="goal-pill px-2 py-2 rounded-xl text-xs font-medium text-center transition truncate <?= $active ? 'bg-[#281F2B] border border-mauveAccent/30 text-mauveAccent' : 'bg-black/40 border border-white/[0.06] text-stone-400 hover:text-white hover:border-white/15' ?>" data-goal-id="<?= (int) $g['id'] ?>" type="button">
            <?= e($g['goal_title']) ?>
          </button>
<?php endforeach; ?>
</div>
<?php else: ?>
<p class="text-[11px] text-stone-500">Belum ada target tabungan. Tambahkan dulu di tab Savings Goals.</p>
<?php endif; ?>
</div>
<!-- 4. Wallet Source Selector -->
<div>
<label class="block text-xs font-medium text-stone-300 mb-1.5">Wallet Source</label>
<div class="grid grid-cols-4 gap-2" id="wallet-pills">
<?php foreach ($wallets as $i => $w): ?>
<button class="wallet-pill px-2 py-2 rounded-xl text-xs font-medium text-center transition truncate <?= $i === 0 ? 'bg-[#281F2B] border border-mauveAccent/30 text-mauveAccent' : 'bg-black/40 border border-white/[0.06] text-stone-400 hover:text-white hover:border-white/15' ?>" data-wallet-id="<?= (int) $w['id'] ?>" title="<?= e($w['wallet_name']) ?> · <?= rupiah($w['balance']) ?>" type="button">
            <?= e($w['wallet_name']) ?>
          </button>
<?php endforeach; ?>
</div>
</div>
<p class="hidden text-[11px] text-rose-300" id="tx-form-error"></p>
<!-- 5. Action Buttons -->
<div class="pt-3 flex items-center gap-3">
<button class="w-1/3 py-2.5 px-4 rounded-xl text-xs font-medium text-stone-300 bg-white/[0.04] hover:bg-white/[0.08] border border-white/10 transition" id="modal-cancel-btn" type="button">
          Cancel
        </button>
<button class="w-2/3 py-2.5 px-4 rounded-xl text-xs font-semibold text-[#0D0B10] bg-gradient-to-r from-[#C49BBB] to-[#A374A0] hover:opacity-90 active:scale-[0.98] shadow-lg shadow-[#C49BBB]/20 transition-all" id="modal-submit-btn" type="submit">
          Save Transaction
        </button>
</div>
</form>
</div>
</div>
<!-- BEGIN: Edit Profile Name Modal Popup -->
<div aria-labelledby="profile-modal-title" aria-modal="true" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 opacity-0 pointer-events-none transition-all duration-300" id="profile-modal" role="dialog">
  <div class="bg-[#16141C] border border-white/[0.08] rounded-2xl max-w-sm w-full p-6 relative shadow-2xl shadow-black/80 transition-all duration-300 transform scale-95 opacity-0" id="profile-card">
    <div class="flex items-start justify-between mb-5">
      <div>
        <h3 class="text-lg font-semibold text-white tracking-tight" id="profile-modal-title">Edit Profile Name</h3>
        <p class="text-xs text-stone-400 mt-0.5">Ubah nama yang ditampilkan di FlowTrack</p>
      </div>
      <button aria-label="Close modal" class="w-8 h-8 rounded-full bg-white/[0.04] hover:bg-white/[0.08] text-stone-400 hover:text-white flex items-center justify-center border border-white/[0.06] transition text-sm" id="profile-close-btn" type="button">
        ✕
      </button>
    </div>
    <form class="space-y-4" id="profile-form" action="update_profile.php" method="post">
      <div>
        <label class="block text-xs font-medium text-stone-300 mb-1.5" for="profile-user-name">Nama Profil / Pengguna</label>
        <input class="w-full bg-[#1F1B27] border border-white/10 rounded-xl px-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="profile-user-name" name="user_name" maxlength="100" value="<?= e($userName) ?>" required type="text" placeholder="e.g. Kadek Nata"/>
      </div>
      <div class="pt-3 flex items-center gap-3">
        <button class="w-1/3 py-2.5 px-4 rounded-xl text-xs font-medium text-stone-300 bg-white/[0.04] hover:bg-white/[0.08] border border-white/10 transition" id="profile-cancel-btn" type="button">
          Cancel
        </button>
        <button class="w-2/3 py-2.5 px-4 rounded-xl text-xs font-semibold text-[#0D0B10] bg-gradient-to-r from-[#C49BBB] to-[#A374A0] hover:opacity-90 active:scale-[0.98] shadow-lg shadow-[#C49BBB]/20 transition-all" type="submit">
          Simpan Nama
        </button>
      </div>
    </form>
  </div>
</div>
<!-- BEGIN: Edit Savings Goal Modal Popup -->
<div aria-labelledby="edit-goal-modal-title" aria-modal="true" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 opacity-0 pointer-events-none transition-all duration-300" id="edit-goal-modal" role="dialog">
  <div class="bg-[#16141C] border border-white/[0.08] rounded-2xl max-w-md w-full p-6 relative shadow-2xl shadow-black/80 transition-all duration-300 transform scale-95 opacity-0" id="edit-goal-card">
    <div class="flex items-start justify-between mb-5">
      <div>
        <h3 class="text-lg font-semibold text-white tracking-tight" id="edit-goal-modal-title">Edit Savings Goal</h3>
        <p class="text-xs text-stone-400 mt-0.5">Perbarui nama dan nominal target tabungan</p>
      </div>
      <button aria-label="Close modal" class="w-8 h-8 rounded-full bg-white/[0.04] hover:bg-white/[0.08] text-stone-400 hover:text-white flex items-center justify-center border border-white/[0.06] transition text-sm" id="edit-goal-close-btn" type="button">
        ✕
      </button>
    </div>
    <form class="space-y-4" id="edit-goal-form" action="edit_goal.php" method="post">
      <input type="hidden" name="id" id="edit-goal-id" value=""/>
      <div>
        <label class="block text-xs font-medium text-stone-300 mb-1.5" for="edit-goal-title">Nama Target</label>
        <input class="w-full bg-[#1F1B27] border border-white/10 rounded-xl px-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="edit-goal-title" name="goal_title" maxlength="100" required type="text"/>
      </div>
      <div>
        <label class="block text-xs font-medium text-stone-300 mb-1.5" for="edit-goal-target">Nominal Target (Rp)</label>
        <div class="relative flex items-center">
          <span class="absolute left-3.5 text-xs font-medium text-stone-400 pointer-events-none">Rp</span>
          <input class="money-input w-full bg-[#1F1B27] border border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="edit-goal-target" name="target_amount" inputmode="numeric" autocomplete="off" required type="text"/>
        </div>
      </div>
      <div class="pt-3 flex items-center gap-3">
        <button class="w-1/3 py-2.5 px-4 rounded-xl text-xs font-medium text-stone-300 bg-white/[0.04] hover:bg-white/[0.08] border border-white/10 transition" id="edit-goal-cancel-btn" type="button">
          Cancel
        </button>
        <button class="w-2/3 py-2.5 px-4 rounded-xl text-xs font-semibold text-[#0D0B10] bg-gradient-to-r from-[#C49BBB] to-[#A374A0] hover:opacity-90 active:scale-[0.98] shadow-lg shadow-[#C49BBB]/20 transition-all" type="submit">
          Update Target
        </button>
      </div>
    </form>
  </div>
</div>

<!-- BEGIN: Edit Transaction Modal Popup -->
<div aria-labelledby="edit-tx-modal-title" aria-modal="true" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 opacity-0 pointer-events-none transition-all duration-300" id="edit-transaction-modal" role="dialog">
  <div class="bg-[#16141C] border border-white/[0.08] rounded-2xl max-w-md w-full p-6 relative shadow-2xl shadow-black/80 transition-all duration-300 transform scale-95 opacity-0" id="edit-tx-card">
    <div class="flex items-start justify-between mb-5">
      <div>
        <h3 class="text-lg font-semibold text-white tracking-tight" id="edit-tx-modal-title">Edit Transaction</h3>
        <p class="text-xs text-stone-400 mt-0.5">Ubah rincian transaksi &amp; sesuaikan saldo</p>
      </div>
      <button aria-label="Close modal" class="w-8 h-8 rounded-full bg-white/[0.04] hover:bg-white/[0.08] text-stone-400 hover:text-white flex items-center justify-center border border-white/[0.06] transition text-sm" id="edit-tx-close-btn" type="button">
        ✕
      </button>
    </div>
    <form class="space-y-4" id="edit-transaction-form" action="edit_transaction.php" method="post">
      <input type="hidden" name="id" id="edit-tx-id" value=""/>
      <input type="hidden" name="type" id="edit-tx-type" value="expense"/>
      <input type="hidden" name="wallet_id" id="edit-tx-wallet-id" value="<?= (int) ($wallets[0]['id'] ?? 0) ?>"/>
      <input type="hidden" name="goal_id" id="edit-tx-goal-id" value="<?= (int) ($featuredGoal['id'] ?? 0) ?>"/>
      <input type="hidden" name="return_tab" value="transactions"/>

      <div>
        <label class="block text-xs font-medium text-stone-300 mb-1.5" for="edit-tx-title">Transaction Title</label>
        <input class="w-full bg-[#1F1B27] border border-white/10 rounded-xl px-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="edit-tx-title" name="title" maxlength="100" required type="text"/>
      </div>

      <div>
        <label class="block text-xs font-medium text-stone-300 mb-1.5" for="edit-tx-amount">Amount (Rp)</label>
        <div class="relative flex items-center">
          <span class="absolute left-3.5 text-xs font-medium text-stone-400 pointer-events-none">Rp</span>
          <input class="money-input w-full bg-[#1F1B27] border border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition" id="edit-tx-amount" name="amount" inputmode="numeric" autocomplete="off" required type="text"/>
        </div>
      </div>

      <div>
        <label class="block text-xs font-medium text-stone-300 mb-1.5">Transaction Type</label>
        <div class="grid grid-cols-3 gap-2" id="edit-type-pills">
          <button class="edit-type-pill px-3 py-2 rounded-xl text-xs font-medium text-center transition bg-[#281F2B] border border-mauveAccent/30 text-mauveAccent" data-type="expense" type="button">Expense</button>
          <button class="edit-type-pill px-3 py-2 rounded-xl text-xs font-medium text-center transition bg-black/40 border border-white/[0.06] text-stone-400 hover:text-white hover:border-white/15" data-type="income" type="button">Income</button>
          <button class="edit-type-pill px-3 py-2 rounded-xl text-xs font-medium text-center transition bg-black/40 border border-white/[0.06] text-stone-400 hover:text-white hover:border-white/15" data-type="goal_saving" type="button">Save to Goal</button>
        </div>
      </div>

      <div class="hidden" id="edit-goal-field">
        <label class="block text-xs font-medium text-stone-300 mb-1.5">Savings Goal</label>
        <?php if ($goals): ?>
        <div class="grid grid-cols-2 gap-2" id="edit-goal-pills">
          <?php foreach ($goals as $g): ?>
          <button class="edit-goal-pill px-2 py-2 rounded-xl text-xs font-medium text-center transition truncate bg-black/40 border border-white/[0.06] text-stone-400 hover:text-white hover:border-white/15" data-goal-id="<?= (int) $g['id'] ?>" type="button">
            <?= e($g['goal_title']) ?>
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div>
        <label class="block text-xs font-medium text-stone-300 mb-1.5">Wallet Source</label>
        <div class="grid grid-cols-4 gap-2" id="edit-wallet-pills">
          <?php foreach ($wallets as $i => $w): ?>
          <button class="edit-wallet-pill px-2 py-2 rounded-xl text-xs font-medium text-center transition truncate <?= $i === 0 ? 'bg-[#281F2B] border border-mauveAccent/30 text-mauveAccent' : 'bg-black/40 border border-white/[0.06] text-stone-400 hover:text-white hover:border-white/15' ?>" data-wallet-id="<?= (int) $w['id'] ?>" title="<?= e($w['wallet_name']) ?>" type="button">
            <?= e($w['wallet_name']) ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="pt-3 flex items-center gap-3">
        <button class="w-1/3 py-2.5 px-4 rounded-xl text-xs font-medium text-stone-300 bg-white/[0.04] hover:bg-white/[0.08] border border-white/10 transition" id="edit-tx-cancel-btn" type="button">
          Cancel
        </button>
        <button class="w-2/3 py-2.5 px-4 rounded-xl text-xs font-semibold text-[#0D0B10] bg-gradient-to-r from-[#C49BBB] to-[#A374A0] hover:opacity-90 active:scale-[0.98] shadow-lg shadow-[#C49BBB]/20 transition-all" type="submit">
          Update Transaction
        </button>
      </div>
    </form>
  </div>
</div>

<!-- BEGIN: Edit Spending Ring Categories Modal -->
<div aria-labelledby="sr-modal-title" aria-modal="true" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 opacity-0 pointer-events-none transition-all duration-300" id="sr-modal" role="dialog">
  <div class="bg-[#1a1a24]/95 border border-white/10 rounded-2xl max-w-xl w-full p-5 sm:p-6 relative shadow-2xl shadow-black/80 transition-all duration-300 transform scale-95 opacity-0" id="sr-modal-card">
    <div class="flex items-start justify-between pb-4 mb-4 border-b border-white/[0.08]">
      <div>
        <h3 class="text-lg font-semibold text-white tracking-tight" id="sr-modal-title">Edit Kategori &amp; Pengeluaran</h3>
        <p class="text-xs text-stone-400 mt-0.5">Sesuaikan nama, jumlah nominal, dan warna diagram cincin</p>
      </div>
      <button aria-label="Close modal" class="w-8 h-8 rounded-full bg-white/[0.04] hover:bg-white/[0.08] text-stone-400 hover:text-white flex items-center justify-center border border-white/[0.06] transition text-sm cursor-pointer" id="sr-modal-close-btn" type="button">
        ✕
      </button>
    </div>

    <!-- Category Rows List -->
    <div id="sr-modal-rows" class="space-y-3 max-h-[50vh] overflow-y-auto pr-1">
      <!-- Dynamic category edit rows will be rendered here -->
    </div>

    <!-- Error message display -->
    <div id="sr-modal-error" class="hidden text-xs text-rose-400 font-medium mt-3 bg-rose-500/10 border border-rose-500/20 rounded-xl px-3 py-2"></div>

    <!-- Actions -->
    <div class="mt-4 pt-3 border-t border-white/[0.08] flex flex-col sm:flex-row items-center justify-between gap-3">
      <button id="sr-modal-add-row-btn" type="button" class="w-full sm:w-auto px-3.5 py-2 rounded-xl text-xs font-medium text-mauveAccent bg-mauveAccent/10 hover:bg-mauveAccent/20 border border-mauveAccent/20 transition flex items-center justify-center gap-1.5 cursor-pointer">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span>Tambah Kategori</span>
      </button>
      
      <div class="flex items-center gap-2.5 w-full sm:w-auto">
        <button id="sr-modal-cancel-btn" type="button" class="w-1/2 sm:w-auto px-4 py-2 rounded-xl text-xs font-medium text-stone-300 bg-white/[0.04] hover:bg-white/[0.08] border border-white/10 transition cursor-pointer">
          Batal
        </button>
        <button id="sr-modal-save-btn" type="button" class="w-1/2 sm:w-auto px-5 py-2 rounded-xl text-xs font-semibold text-[#0D0B10] bg-gradient-to-r from-[#C49BBB] to-[#A374A0] hover:opacity-90 active:scale-[0.98] shadow-lg shadow-[#C49BBB]/20 transition-all cursor-pointer">
          Simpan Perubahan
        </button>
      </div>
    </div>
  </div>
</div>
<!-- END: Edit Spending Ring Categories Modal -->

<!-- Subtle Success Toast Notification -->
<div class="fixed bottom-6 right-6 z-50 pointer-events-none transform translate-y-12 opacity-0 transition-all duration-300 bg-[#1F1923] border border-mauveAccent/30 rounded-2xl px-4 py-3 shadow-2xl flex items-center gap-3" id="tx-toast" <?= $flashMsg !== null ? 'data-show="1"' : '' ?>>
<div class="w-6 h-6 rounded-full <?= $flashError ? 'bg-rose-500/20 text-rose-300' : 'bg-emerald-500/20 text-emerald-400' ?> flex items-center justify-center text-xs"><?= $flashError ? '!' : '✓' ?></div>
<p class="text-xs font-medium text-white"><?= $flashMsg !== null ? e($flashMsg) : 'Transaction recorded successfully!' ?></p>
</div>
<!-- Initialize Lucide Icons, Tabs, Filters & Modal Interactivity -->
<script data-purpose="icon-initialization">
    lucide.createIcons();

    /* ================= PACING / BUDGET ALLOWANCE SELECTOR ================= */
    (function initPacingSelector() {
      const wrapper   = document.getElementById('pacing-dropdown-wrapper');
      const btn       = document.getElementById('pacing-btn');
      const label     = document.getElementById('pacing-label');
      const chevron   = document.getElementById('pacing-chevron');
      const menu      = document.getElementById('pacing-menu');
      const balanceEl = document.getElementById('total-balance');
      const subtextEl = document.getElementById('pacing-subtext');
      const options   = document.querySelectorAll('.pacing-option');

      if (!wrapper || !btn || !menu || !balanceEl || !subtextEl) return;

      const totalBalance = parseFloat(balanceEl.dataset.rawBalance || '0');
      const income       = parseFloat(balanceEl.dataset.income || '0');
      const expense      = parseFloat(balanceEl.dataset.expense || '0');

      let currentVal = totalBalance;
      let animFrame  = null;
      let isOpen     = false;

      function formatRp(val) {
        const isNeg = val < 0;
        const abs = Math.abs(Math.round(val));
        return (isNeg ? '-Rp ' : 'Rp ') + abs.toLocaleString('id-ID');
      }

      function openMenu() {
        isOpen = true;
        btn.setAttribute('aria-expanded', 'true');
        menu.classList.remove('hidden');
        requestAnimationFrame(() => {
          menu.classList.remove('opacity-0', 'translate-y-1');
          menu.classList.add('opacity-100', 'translate-y-0');
          if (chevron) chevron.classList.add('rotate-180');
        });
      }

      function closeMenu() {
        if (!isOpen) return;
        isOpen = false;
        btn.setAttribute('aria-expanded', 'false');
        menu.classList.remove('opacity-100', 'translate-y-0');
        menu.classList.add('opacity-0', 'translate-y-1');
        if (chevron) chevron.classList.remove('rotate-180');
        setTimeout(() => {
          if (!isOpen) menu.classList.add('hidden');
        }, 150);
      }

      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (isOpen) {
          closeMenu();
        } else {
          openMenu();
        }
      });

      document.addEventListener('click', (e) => {
        if (isOpen && !wrapper.contains(e.target)) {
          closeMenu();
        }
      });

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isOpen) {
          closeMenu();
        }
      });

      function animateNumber(start, end, duration = 350) {
        if (animFrame) cancelAnimationFrame(animFrame);
        const startTime = performance.now();
        const diff = end - start;

        if (Math.abs(diff) < 1) {
          balanceEl.textContent = formatRp(end);
          currentVal = end;
          return;
        }

        function step(now) {
          const elapsed = now - startTime;
          const progress = Math.min(elapsed / duration, 1);
          const ease = 1 - Math.pow(1 - progress, 3);
          const val = Math.round(start + diff * ease);
          balanceEl.textContent = formatRp(val);

          if (progress < 1) {
            animFrame = requestAnimationFrame(step);
          } else {
            balanceEl.textContent = formatRp(end);
            currentVal = end;
            animFrame = null;
          }
        }

        animFrame = requestAnimationFrame(step);
      }

      function updatePacingMode(mode) {
        let targetBalance = totalBalance;
        let subtextHtml = '';
        let labelText = 'This Month';

        // Calculate days in current month & remaining days
        const now = new Date();
        const totalDaysInMonth = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();
        const remainingDays = Math.max(1, totalDaysInMonth - now.getDate() + 1);

        if (mode === 'month') {
          labelText = 'This Month';
          targetBalance = totalBalance;
          subtextHtml = `
            <span class="inline-flex items-center gap-0.5 text-emerald-400 font-medium">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
              +${formatRp(income)}
            </span>
            <span class="text-stone-300 font-medium">-${formatRp(expense)}</span>
            <span class="text-stone-400">this month</span>
          `;
        } else if (mode === 'week') {
          labelText = 'This Week';
          targetBalance = Math.round(totalBalance / 4);
          subtextHtml = `
            <span class="inline-flex items-center gap-1 text-mauveAccent font-medium">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
              Estimasi jatah aman per minggu
            </span>
            <span class="text-stone-400">(4 minggu)</span>
          `;
        } else if (mode === 'daily') {
          labelText = 'Today / Daily';
          targetBalance = Math.round(totalBalance / remainingDays);
          subtextHtml = `
            <span class="inline-flex items-center gap-1 text-sky-400 font-medium">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
              Estimasi jatah aman per hari
            </span>
            <span class="text-stone-400">(${remainingDays} hari tersisa)</span>
          `;
        }

        // Update button label
        label.textContent = labelText;

        // Update active checkmarks
        options.forEach(opt => {
          const isSelected = opt.dataset.mode === mode;
          const check = opt.querySelector('.pacing-check');
          const title = opt.querySelector('.font-medium');
          if (check) check.classList.toggle('hidden', !isSelected);
          if (title) {
            title.className = isSelected ? 'font-medium text-white' : 'font-medium text-stone-200';
          }
        });

        // Subtext fade transition
        subtextEl.style.opacity = '0';
        setTimeout(() => {
          subtextEl.innerHTML = subtextHtml;
          subtextEl.style.opacity = '1';
        }, 120);

        // Animate balance number
        animateNumber(currentVal, targetBalance);

        // Close dropdown
        closeMenu();
      }

      options.forEach(opt => {
        opt.addEventListener('click', () => {
          updatePacingMode(opt.dataset.mode);
        });
      });
    })();

    /* ================= TAB NAVIGATION (SPA) ================= */
    const NAV_ACTIVE = 'active-nav-pill flex items-center px-4 py-3 rounded-2xl text-sm font-medium text-white shadow-sm transition-all duration-200';
    const NAV_IDLE   = 'flex items-center px-4 py-3 rounded-2xl text-sm font-medium text-stone-400 hover:text-stone-200 hover:bg-white/[0.03] transition-all';
    const SUBTITLES  = {
      dashboard:    "Here's your personal financial overview today",
      transactions: 'Complete history of every transaction you recorded',
      wallets:      'Manage your bank accounts, e-wallets and cash',
      goals:        'Track progress toward your wishlist & savings targets',
    };

    const navLinks      = document.querySelectorAll('#sidebar-nav [data-tab]');
    const tabContainers = document.querySelectorAll('.tab-content');
    const subtitleEl    = document.getElementById('page-subtitle');
    const returnTab     = document.getElementById('tx-return-tab');

    function switchTab(tab, updateHash = true) {
      if (!SUBTITLES[tab]) tab = 'dashboard';

      // Toggle tab container visibility
      tabContainers.forEach(container => {
        const isMatch = container.id === `tab-${tab}` || container.dataset.panel === tab;
        container.classList.toggle('hidden', !isMatch);
      });

      // Update sidebar nav pill states
      navLinks.forEach(link => {
        const isActive = link.dataset.tab === tab;
        link.className = isActive ? NAV_ACTIVE : NAV_IDLE;
        link.toggleAttribute('aria-current', isActive);
      });

      // Update page subtitle and form return tab
      if (subtitleEl && SUBTITLES[tab]) {
        subtitleEl.textContent = SUBTITLES[tab];
      }
      if (returnTab) {
        returnTab.value = tab;
      }
      if (updateHash) {
        history.replaceState(null, '', '#' + tab);
      }
    }

    navLinks.forEach(link => link.addEventListener('click', e => {
      e.preventDefault();
      switchTab(link.dataset.tab);
    }));

    document.querySelectorAll('[data-goto]').forEach(btn => btn.addEventListener('click', e => {
      e.preventDefault();
      switchTab(btn.dataset.goto);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }));

    window.addEventListener('hashchange', () => switchTab(location.hash.slice(1) || 'dashboard', false));

    /* ================= FILTER PILLS (All / Income / Expense) ================= */
    const PILL_ACTIVE = 'bg-[#2A2331] text-white px-3 py-1 rounded-full font-medium';
    const PILL_IDLE   = 'bg-black/30 text-stone-400 hover:text-white px-3 py-1 rounded-full transition';

    document.querySelectorAll('[data-filter-group]').forEach(group => {
      const listId  = group.dataset.filterGroup;
      const list    = document.getElementById(listId);
      const emptyEl = document.getElementById(listId + '-empty');
      const limit   = parseInt(group.dataset.limit || '0', 10);
      const buttons = group.querySelectorAll('[data-filter]');

      function apply(filter) {
        buttons.forEach(b => b.className = b.dataset.filter === filter ? PILL_ACTIVE : PILL_IDLE);
        let shown = 0, last = null;
        list.querySelectorAll('[data-type]').forEach(item => {
          const match   = filter === 'all' || item.dataset.type === filter;
          const visible = match && (!limit || shown < limit);
          item.classList.toggle('hidden', !visible);
          item.classList.add('border-b');
          if (visible) { shown++; last = item; }
        });
        if (last) last.classList.remove('border-b');
        if (emptyEl) emptyEl.classList.toggle('hidden', shown > 0);
      }

      buttons.forEach(b => b.addEventListener('click', () => apply(b.dataset.filter)));
      apply('all');
    });

    /* ================= RUPIAH INPUT FORMATTER ================= */
    document.querySelectorAll('.money-input').forEach(input => {
      input.addEventListener('input', () => {
        const digits = input.value.replace(/\D/g, '');
        input.value = digits ? Number(digits).toLocaleString('id-ID') : '';
      });
    });

    /* ================= MODAL ================= */
    const modal      = document.getElementById('transaction-modal');
    const modalCard  = document.getElementById('modal-card');
    const openBtns   = document.querySelectorAll('.btn-add-transaction');
    const closeBtn   = document.getElementById('modal-close-btn');
    const cancelBtn  = document.getElementById('modal-cancel-btn');
    const form       = document.getElementById('transaction-form');
    const toast      = document.getElementById('tx-toast');
    const typeInput  = document.getElementById('tx-type');
    const walletInput= document.getElementById('tx-wallet-id');
    const goalInput  = document.getElementById('tx-goal-id');
    const goalField  = document.getElementById('goal-field');
    const formError  = document.getElementById('tx-form-error');
    const submitBtn  = document.getElementById('modal-submit-btn');

    const PILL_SEL   = 'bg-[#281F2B] border border-mauveAccent/30 text-mauveAccent';
    const PILL_UNSEL = 'bg-black/40 border border-white/[0.06] text-stone-400 hover:text-white hover:border-white/15';

    function selectPill(pills, activePill, base) {
      pills.forEach(p => p.className = base + ' ' + PILL_UNSEL);
      if (activePill) activePill.className = base + ' ' + PILL_SEL;
    }

    const typePills   = document.querySelectorAll('.type-pill');
    const walletPills = document.querySelectorAll('.wallet-pill');
    const goalPills   = document.querySelectorAll('.goal-pill');
    const TYPE_BASE   = 'type-pill px-3 py-2 rounded-xl text-xs font-medium text-center transition';
    const WALLET_BASE = 'wallet-pill px-2 py-2 rounded-xl text-xs font-medium text-center transition truncate';
    const GOAL_BASE   = 'goal-pill px-2 py-2 rounded-xl text-xs font-medium text-center transition truncate';

    function selectType(type) {
      typeInput.value = type;
      selectPill(typePills, document.querySelector(`.type-pill[data-type="${type}"]`), TYPE_BASE);
      goalField.classList.toggle('hidden', type !== 'goal_saving');
    }
    function selectGoal(id) {
      const pill = document.querySelector(`.goal-pill[data-goal-id="${id}"]`);
      if (!pill) return;
      goalInput.value = id;
      selectPill(goalPills, pill, GOAL_BASE);
    }

    typePills.forEach(p => p.addEventListener('click', () => selectType(p.dataset.type)));
    goalPills.forEach(p => p.addEventListener('click', () => selectGoal(p.dataset.goalId)));
    walletPills.forEach(p => p.addEventListener('click', () => {
      walletInput.value = p.dataset.walletId;
      selectPill(walletPills, p, WALLET_BASE);
    }));

    function openModal(type = 'expense', goalId = null) {
      form.reset();
      returnTab.value = location.hash.slice(1) || 'dashboard';
      selectType(type);
      if (goalId) {
        selectGoal(goalId);
      } else {
        const firstGoal = document.querySelector('.goal-pill');
        if (firstGoal) selectGoal(firstGoal.dataset.goalId);
      }
      const firstWallet = document.querySelector('.wallet-pill');
      if (firstWallet) {
        walletInput.value = firstWallet.dataset.walletId;
        selectPill(walletPills, firstWallet, WALLET_BASE);
      }
      formError.classList.add('hidden');
      submitBtn.disabled = false;
      submitBtn.classList.remove('pointer-events-none', 'opacity-70');
      submitBtn.textContent = 'Save Transaction';
      modal.classList.remove('pointer-events-none', 'opacity-0');
      modalCard.classList.remove('scale-95', 'opacity-0');
      modalCard.classList.add('scale-100', 'opacity-100');
      document.body.classList.add('overflow-hidden');
      setTimeout(() => document.getElementById('tx-title').focus(), 150);
    }

    function closeModal() {
      modalCard.classList.remove('scale-100', 'opacity-100');
      modalCard.classList.add('scale-95', 'opacity-0');
      modal.classList.add('opacity-0', 'pointer-events-none');
      document.body.classList.remove('overflow-hidden');
    }

    openBtns.forEach(btn => btn.addEventListener('click', () => openModal()));
    document.querySelectorAll('.btn-save-goal').forEach(btn =>
      btn.addEventListener('click', () => openModal('goal_saving', btn.dataset.goalId)));
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    // Click outside on backdrop
    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        closeModal();
      }
    });

    // Escape key listener
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        if (!modal.classList.contains('pointer-events-none')) closeModal();
        if (profileModal && !profileModal.classList.contains('pointer-events-none')) closeProfileModal();
        if (editGoalModal && !editGoalModal.classList.contains('pointer-events-none')) closeEditGoal();
        if (editTxModal && !editTxModal.classList.contains('pointer-events-none')) closeEditTx();
      }
    });

    /* ================= PROFILE NAME MODAL ================= */
    const profileModal  = document.getElementById('profile-modal');
    const profileCard   = document.getElementById('profile-card');
    const profileBtn    = document.getElementById('profile-btn');
    const greetingTitle = document.getElementById('greeting-title');
    const profileClose  = document.getElementById('profile-close-btn');
    const profileCancel = document.getElementById('profile-cancel-btn');
    const profileInput  = document.getElementById('profile-user-name');

    function openProfileModal() {
      if (!profileModal) return;
      profileModal.classList.remove('pointer-events-none', 'opacity-0');
      profileCard.classList.remove('scale-95', 'opacity-0');
      profileCard.classList.add('scale-100', 'opacity-100');
      document.body.classList.add('overflow-hidden');
      setTimeout(() => { if (profileInput) { profileInput.focus(); profileInput.select(); } }, 150);
    }

    function closeProfileModal() {
      if (!profileModal) return;
      profileCard.classList.remove('scale-100', 'opacity-100');
      profileCard.classList.add('scale-95', 'opacity-0');
      profileModal.classList.add('opacity-0', 'pointer-events-none');
      document.body.classList.remove('overflow-hidden');
    }

    if (profileBtn) profileBtn.addEventListener('click', openProfileModal);
    if (greetingTitle) greetingTitle.addEventListener('click', openProfileModal);
    if (profileClose) profileClose.addEventListener('click', closeProfileModal);
    if (profileCancel) profileCancel.addEventListener('click', closeProfileModal);
    if (profileModal) {
      profileModal.addEventListener('click', (e) => {
        if (e.target === profileModal) closeProfileModal();
      });
    }

    /* ================= EDIT GOAL MODAL ================= */
    const editGoalModal = document.getElementById('edit-goal-modal');
    const editGoalCard  = document.getElementById('edit-goal-card');
    const editGoalClose = document.getElementById('edit-goal-close-btn');
    const editGoalCancel= document.getElementById('edit-goal-cancel-btn');
    const editGoalId    = document.getElementById('edit-goal-id');
    const editGoalTitle = document.getElementById('edit-goal-title');
    const editGoalTarget= document.getElementById('edit-goal-target');

    function openEditGoal(id, title, target) {
      editGoalId.value    = id;
      editGoalTitle.value = title;
      editGoalTarget.value= target ? Number(target).toLocaleString('id-ID') : '';
      editGoalModal.classList.remove('pointer-events-none', 'opacity-0');
      editGoalCard.classList.remove('scale-95', 'opacity-0');
      editGoalCard.classList.add('scale-100', 'opacity-100');
      document.body.classList.add('overflow-hidden');
      setTimeout(() => editGoalTitle.focus(), 150);
    }

    function closeEditGoal() {
      editGoalCard.classList.remove('scale-100', 'opacity-100');
      editGoalCard.classList.add('scale-95', 'opacity-0');
      editGoalModal.classList.add('opacity-0', 'pointer-events-none');
      document.body.classList.remove('overflow-hidden');
    }

    document.querySelectorAll('.btn-edit-goal').forEach(btn => {
      btn.addEventListener('click', () => {
        openEditGoal(btn.dataset.goalId, btn.dataset.goalTitle, btn.dataset.goalTarget);
      });
    });
    if (editGoalClose) editGoalClose.addEventListener('click', closeEditGoal);
    if (editGoalCancel) editGoalCancel.addEventListener('click', closeEditGoal);
    if (editGoalModal) {
      editGoalModal.addEventListener('click', (e) => {
        if (e.target === editGoalModal) closeEditGoal();
      });
    }

    /* ================= EDIT TRANSACTION MODAL ================= */
    const editTxModal     = document.getElementById('edit-transaction-modal');
    const editTxCard      = document.getElementById('edit-tx-card');
    const editTxClose     = document.getElementById('edit-tx-close-btn');
    const editTxCancel    = document.getElementById('edit-tx-cancel-btn');
    const editTxId        = document.getElementById('edit-tx-id');
    const editTxTitle     = document.getElementById('edit-tx-title');
    const editTxAmount    = document.getElementById('edit-tx-amount');
    const editTxType      = document.getElementById('edit-tx-type');
    const editTxWalletId  = document.getElementById('edit-tx-wallet-id');
    const editTxGoalId    = document.getElementById('edit-tx-goal-id');
    const editGoalField   = document.getElementById('edit-goal-field');

    const editTypePills   = document.querySelectorAll('.edit-type-pill');
    const editWalletPills = document.querySelectorAll('.edit-wallet-pill');
    const editGoalPills   = document.querySelectorAll('.edit-goal-pill');

    function selectEditType(type) {
      editTxType.value = type;
      selectPill(editTypePills, document.querySelector(`.edit-type-pill[data-type="${type}"]`), TYPE_BASE);
      if (editGoalField) editGoalField.classList.toggle('hidden', type !== 'goal_saving');
    }
    function selectEditWallet(id) {
      editTxWalletId.value = id;
      selectPill(editWalletPills, document.querySelector(`.edit-wallet-pill[data-wallet-id="${id}"]`), WALLET_BASE);
    }
    function selectEditGoal(id) {
      if (!id) return;
      editTxGoalId.value = id;
      selectPill(editGoalPills, document.querySelector(`.edit-goal-pill[data-goal-id="${id}"]`), GOAL_BASE);
    }

    editTypePills.forEach(p => p.addEventListener('click', () => selectEditType(p.dataset.type)));
    editWalletPills.forEach(p => p.addEventListener('click', () => selectEditWallet(p.dataset.walletId)));
    editGoalPills.forEach(p => p.addEventListener('click', () => selectEditGoal(p.dataset.goalId)));

    function openEditTx(id, title, amount, type, walletId) {
      editTxId.value     = id;
      editTxTitle.value  = title;
      editTxAmount.value = amount ? Number(amount).toLocaleString('id-ID') : '';
      selectEditType(type || 'expense');
      selectEditWallet(walletId);
      const firstEditGoal = document.querySelector('.edit-goal-pill');
      if (firstEditGoal) selectEditGoal(firstEditGoal.dataset.goalId);

      editTxModal.classList.remove('pointer-events-none', 'opacity-0');
      editTxCard.classList.remove('scale-95', 'opacity-0');
      editTxCard.classList.add('scale-100', 'opacity-100');
      document.body.classList.add('overflow-hidden');
      setTimeout(() => editTxTitle.focus(), 150);
    }

    function closeEditTx() {
      editTxCard.classList.remove('scale-100', 'opacity-100');
      editTxCard.classList.add('scale-95', 'opacity-0');
      editTxModal.classList.add('opacity-0', 'pointer-events-none');
      document.body.classList.remove('overflow-hidden');
    }

    document.querySelectorAll('.btn-edit-tx').forEach(btn => {
      btn.addEventListener('click', () => {
        openEditTx(btn.dataset.id, btn.dataset.title, btn.dataset.amount, btn.dataset.type, btn.dataset.walletId);
      });
    });
    if (editTxClose) editTxClose.addEventListener('click', closeEditTx);
    if (editTxCancel) editTxCancel.addEventListener('click', closeEditTx);
    if (editTxModal) {
      editTxModal.addEventListener('click', (e) => {
        if (e.target === editTxModal) closeEditTx();
      });
    }

    // Client-side transaction form validation
    form.addEventListener('submit', (e) => {
      let error = '';
      const amount = Number(document.getElementById('tx-amount').value.replace(/\D/g, ''));
      if (!document.getElementById('tx-title').value.trim()) error = 'Judul transaksi wajib diisi.';
      else if (!amount) error = 'Nominal harus lebih dari 0.';
      else if (!Number(walletInput.value)) error = 'Pilih dompet terlebih dahulu.';
      else if (typeInput.value === 'goal_saving' && !Number(goalInput.value)) error = 'Pilih target tabungan terlebih dahulu.';

      if (error) {
        e.preventDefault();
        formError.textContent = error;
        formError.classList.remove('hidden');
        return;
      }
      submitBtn.classList.add('pointer-events-none', 'opacity-70');
      submitBtn.textContent = 'Saving...';
    });

    // --- Toast Notification ---
    if (toast && toast.dataset.show) {
      requestAnimationFrame(() => toast.classList.remove('translate-y-12', 'opacity-0'));
      setTimeout(() => toast.classList.add('translate-y-12', 'opacity-0'), 3200);
      history.replaceState(null, '', location.pathname + location.hash);
    }

    // Initialize active tab from URL hash
    switchTab(location.hash.slice(1) || 'dashboard', false);

    /* ================= INTERACTIVE MASCOT / PET ================= */
    (function initMascotPet() {
      // Disable on touch devices & mobile screens
      if (window.matchMedia('(hover: none) or (pointer: coarse)').matches) {
        return;
      }

      const petContainer = document.getElementById('flow-pet-container');
      const petSprite    = document.getElementById('flow-pet-sprite');
      const petImg       = document.getElementById('flow-pet-img');

      if (!petContainer || !petSprite || !petImg) return;

      const SPRITES = {
        action: 'assets/pet/Action.png',
        bark:   'assets/pet/Bark.png',
        blink:  'assets/pet/Blink.png'
      };

      // Preload images to avoid any flicker
      Object.values(SPRITES).forEach(src => {
        const img = new Image();
        img.src = src;
      });

      let mouseX = window.innerWidth / 2;
      let mouseY = window.innerHeight / 2;
      let petX = mouseX;
      let petY = mouseY;
      let facingRight = true;
      let petState = 'idle'; // 'idle' | 'moving'
      let isBlinking = false;
      let blinkTimeout = null;
      let nextBlinkTimer = null;
      let hasInteracted = false;

      function setSprite(src) {
        if (petImg.getAttribute('src') !== src) {
          petImg.src = src;
        }
      }

      function scheduleNextBlink() {
        clearTimeout(nextBlinkTimer);
        if (petState !== 'idle') return;

        // Random delay between 3000ms and 5000ms (3-5 seconds)
        const delay = Math.floor(Math.random() * 2000) + 3000;
        nextBlinkTimer = setTimeout(() => {
          if (petState === 'idle') {
            isBlinking = true;
            setSprite(SPRITES.blink);

            clearTimeout(blinkTimeout);
            blinkTimeout = setTimeout(() => {
              isBlinking = false;
              if (petState === 'idle') {
                setSprite(SPRITES.action);
              }
              scheduleNextBlink();
            }, 200); // 200ms blink duration
          }
        }, delay);
      }

      window.addEventListener('mousemove', (e) => {
        mouseX = e.clientX;
        mouseY = e.clientY;

        if (!hasInteracted) {
          hasInteracted = true;
          petX = mouseX + 24;
          petY = mouseY + 24;
          petContainer.classList.remove('opacity-0');
          scheduleNextBlink();
        }
      }, { passive: true });

      function animatePet(time) {
        if (hasInteracted) {
          // Follow cursor slightly offset so it doesn't obstruct mouse clicks
          const targetOffsetX = facingRight ? 24 : -24;
          const targetOffsetY = 24;
          const targetX = mouseX + targetOffsetX;
          const targetY = mouseY + targetOffsetY;

          // Smooth lerp easing
          const ease = 0.085;
          const dx = targetX - petX;
          const dy = targetY - petY;
          const stepX = dx * ease;
          const stepY = dy * ease;
          petX += stepX;
          petY += dy * stepY;

          // Horizontal movement & flip orientation
          if (dx > 2) {
            facingRight = true;
          } else if (dx < -2) {
            facingRight = false;
          }

          // Speed calculation
          const speed = Math.hypot(stepX, stepY);

          // State switching
          if (speed > 0.5) {
            // Moving state: chase mouse and switch to Bark.png
            if (petState !== 'moving') {
              petState = 'moving';
              clearTimeout(nextBlinkTimer);
              clearTimeout(blinkTimeout);
              isBlinking = false;
              setSprite(SPRITES.bark);
            }
          } else {
            // Idle state: near cursor, switch to Action.png and start blink timer
            if (petState !== 'idle') {
              petState = 'idle';
              isBlinking = false;
              setSprite(SPRITES.action);
              scheduleNextBlink();
            }
          }

          // Orient transform: scaleX(1) or scaleX(-1)
          const scaleX = facingRight ? 1 : -1;

          if (petState === 'moving') {
            // Playful bobbing when chasing
            const bobY = Math.sin(time * 0.018) * 3;
            petSprite.style.transform = `scaleX(${scaleX}) translateY(${bobY}px)`;
          } else {
            // Gentle breathing when idle
            const breatheY = Math.sin(time * 0.003) * 2;
            petSprite.style.transform = `scaleX(${scaleX}) translateY(${breatheY}px)`;
          }

          // GPU-accelerated container positioning (offset 24px so center is aligned with 48x48 sprite)
          petContainer.style.transform = `translate3d(${petX - 24}px, ${petY - 24}px, 0)`;
        }

        requestAnimationFrame(animatePet);
      }

      requestAnimationFrame(animatePet);
    })();

    /* ================= KOKONUT SPENDING RING ================= */
    (function initSpendingRing() {
      const SIZE = 240;
      const C = SIZE / 2; // 120
      const R = 90;
      const STROKE = 22;
      const GAP = 2.4;
      const RATE = 11;
      const DAMPING = 0.78;
      const PROJECT = 0.2;
      const TAP_SLOP = 5;

      const STORAGE_KEY = 'flowtrack_spending_ring_data';

      const COLOR_OPTIONS = [
        { name: 'Sky', hex: '#38bdf8' },
        { name: 'Emerald', hex: '#34d399' },
        { name: 'Rose', hex: '#fb7185' },
        { name: 'Amber', hex: '#fbbf24' },
        { name: 'Violet', hex: '#a78bfa' },
        { name: 'Pink', hex: '#f472b6' },
        { name: 'Zinc', hex: '#a1a1aa' },
      ];

      const DEFAULT_CATEGORIES = [
        {
          name: "Housing",
          amount: 3500000,
          change: 0,
          color: "#38bdf8",
        },
        {
          name: "Groceries",
          amount: 1850000,
          change: -6.1,
          color: "#34d399",
        },
        {
          name: "Shopping",
          amount: 1200000,
          change: 22.4,
          color: "#fb7185",
        },
        {
          name: "Dining out",
          amount: 850000,
          change: 12.8,
          color: "#fbbf24",
        },
        {
          name: "Transport",
          amount: 600000,
          change: -3.5,
          color: "#a78bfa",
        },
        {
          name: "Subscriptions",
          amount: 250000,
          change: 0,
          color: "#D6A4E8",
        },
      ];

      const surfaceEl     = document.getElementById('spending-ring-surface');
      const rotatorEl     = document.getElementById('spending-ring-rotator');
      const listEl        = document.getElementById('sr-category-list');
      const totalDisplay  = document.getElementById('sr-total-display');
      const readoutDot    = document.getElementById('sr-readout-dot');
      const readoutName   = document.getElementById('sr-readout-name');
      const readoutAmount = document.getElementById('sr-readout-amount');
      const readoutShare  = document.getElementById('sr-readout-share');
      const readoutChange = document.getElementById('sr-readout-change');

      // Modal Elements
      const modalEl       = document.getElementById('sr-modal');
      const modalCardEl   = document.getElementById('sr-modal-card');
      const openBtnEl     = document.getElementById('sr-open-modal-btn');
      const closeBtnEl    = document.getElementById('sr-modal-close-btn');
      const cancelBtnEl   = document.getElementById('sr-modal-cancel-btn');
      const saveBtnEl     = document.getElementById('sr-modal-save-btn');
      const addRowBtnEl   = document.getElementById('sr-modal-add-row-btn');
      const rowsContainer = document.getElementById('sr-modal-rows');
      const errorEl       = document.getElementById('sr-modal-error');

      if (!surfaceEl || !rotatorEl || !listEl) return;

      function escapeHtml(str) {
        if (!str) return '';
        return String(str)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function formatRp(val) {
        return 'Rp ' + Math.round(val).toLocaleString('id-ID');
      }

      function loadCategories() {
        try {
          const raw = localStorage.getItem(STORAGE_KEY);
          if (raw) {
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed) && parsed.length > 0) {
              return parsed.map(c => ({
                name: String(c.name || '').trim() || 'Kategori',
                amount: Math.max(0, parseInt(c.amount, 10) || 0),
                change: typeof c.change === 'number' ? c.change : 0,
                color: c.color || '#38bdf8',
              }));
            }
          }
        } catch (e) {
          console.warn('Failed to load spending ring data from localStorage:', e);
        }
        return JSON.parse(JSON.stringify(DEFAULT_CATEGORIES));
      }

      function saveCategories(data) {
        try {
          localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        } catch (e) {
          console.error('Failed to save to localStorage:', e);
        }
      }

      let categories = loadCategories();
      let total = 0;
      let spans = [];
      let selected = 0;
      let spinning = false;

      const wrap = (deg) => ((((deg + 180) % 360) + 360) % 360) - 180;

      function polar(deg, r = R) {
        const rad = (deg * Math.PI) / 180;
        return [C + r * Math.sin(rad), C - r * Math.cos(rad)];
      }

      function arc(from, to) {
        const [x0, y0] = polar(from);
        const [x1, y1] = polar(to);
        const large = to - from > 180 ? 1 : 0;
        return `M${x0.toFixed(2)} ${y0.toFixed(2)} A${R} ${R} 0 ${large} 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`;
      }

      function sliceAt(spansList, deg) {
        const a = ((deg % 360) + 360) % 360;
        const index = spansList.findIndex((s) => a >= s.from && a < s.to);
        return index === -1 ? 0 : index;
      }

      function springStep(state, target, dt) {
        const slices = Math.ceil(dt / (1 / 240));
        const h = dt / slices;
        for (let i = 0; i < slices; i++) {
          const accel = -RATE * RATE * (state.a - target) - 2 * DAMPING * RATE * state.v;
          state.v += accel * h;
          state.a += state.v * h;
        }
        return Math.abs(state.a - target) < 0.05 && Math.abs(state.v) < 2;
      }

      const sim = {
        a: 0,
        v: 0,
        target: 0,
        raf: 0,
        last: 0,
        coast: false,
        drag: null,
      };

      function updateVisuals(index) {
        selected = index;
        const current = categories[index];
        if (!current) return;

        // Update center readout
        if (readoutDot) {
          readoutDot.style.backgroundColor = current.color;
        }
        if (readoutName) {
          readoutName.textContent = current.name;
        }
        if (readoutAmount) {
          readoutAmount.textContent = formatRp(current.amount);
        }
        if (readoutShare) {
          const share = total ? (current.amount / total) * 100 : 0;
          readoutShare.textContent = `${share.toFixed(0)}% of total`;
        }
        if (readoutChange) {
          const chg = current.change ?? 0;
          if (chg === 0) {
            readoutChange.className = 'mt-1 text-[11px] tabular-nums font-medium text-stone-400';
            readoutChange.textContent = 'Same as last month';
          } else if (chg > 0) {
            readoutChange.className = 'mt-1 text-[11px] tabular-nums font-medium text-rose-400';
            readoutChange.textContent = `▲ ${chg.toFixed(1)}% vs last month`;
          } else {
            readoutChange.className = 'mt-1 text-[11px] tabular-nums font-medium text-emerald-400';
            readoutChange.textContent = `▼ ${Math.abs(chg).toFixed(1)}% vs last month`;
          }
        }

        // Update SVG stroke-width and opacity
        for (let i = 0; i < categories.length; i++) {
          const p = document.getElementById(`sr-path-${i}`);
          if (p) {
            p.setAttribute('stroke-width', i === index ? (STROKE + 8).toString() : STROKE.toString());
            p.setAttribute('opacity', i === index ? '1' : '0.45');
          }
          const btn = document.getElementById(`sr-btn-${i}`);
          if (btn) {
            const on = i === index;
            btn.className = `sr-category-btn flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-xs transition-all duration-150 border ${on ? 'bg-white/10 text-white border-white/10 shadow-sm' : 'border-transparent text-stone-400 hover:text-white hover:bg-white/[0.04]'}`;
          }
        }
      }

      function paint() {
        rotatorEl.setAttribute('transform', `rotate(${sim.a.toFixed(3)} ${C} ${C})`);
      }

      function track() {
        const index = sliceAt(spans, -sim.a);
        if (index !== selected) {
          updateVisuals(index);
        }
      }

      function tick(now) {
        sim.raf = 0;
        if (sim.drag) return;

        const dt = sim.last ? Math.min((now - sim.last) / 1000, 1 / 30) : 1 / 60;
        sim.last = now;
        const state = { a: sim.a, v: sim.v };
        const settled = springStep(state, sim.target, dt);
        sim.a = settled ? sim.target : state.a;
        sim.v = settled ? 0 : state.v;
        paint();

        if (sim.coast) {
          track();
        }

        if (settled) {
          sim.last = 0;
          sim.coast = false;
          spinning = false;
          surfaceEl.classList.remove('cursor-grabbing');
          surfaceEl.classList.add('cursor-grab');
        } else {
          sim.raf = requestAnimationFrame(tick);
        }
      }

      function turnTo(index, rest = sim.a, coast = false) {
        if (!spans[index]) return;
        const goal = -(spans[index].mid ?? 0);
        sim.target = rest + wrap(goal - rest);
        sim.coast = coast;
        if (!coast) {
          updateVisuals(index);
        }

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
          sim.a = sim.target;
          sim.v = 0;
          sim.coast = false;
          paint();
          track();
          return;
        }

        spinning = true;
        surfaceEl.classList.remove('cursor-grab');
        surfaceEl.classList.add('cursor-grabbing');

        if (!sim.raf) {
          sim.last = 0;
          sim.raf = requestAnimationFrame(tick);
        }
      }

      function rebuildRing(preserveSelection = false) {
        total = categories.reduce((sum, c) => sum + (Number(c.amount) || 0), 0);
        if (totalDisplay) {
          totalDisplay.textContent = formatRp(total);
        }

        let at = 0;
        spans = categories.map((c) => {
          const amt = Number(c.amount) || 0;
          const sweep = total ? (amt / total) * 360 : 360 / categories.length;
          const span = { from: at, to: at + sweep, mid: at + sweep / 2 };
          at += sweep;
          return span;
        });

        if (!preserveSelection || selected >= categories.length || selected < 0) {
          selected = 0;
        }

        // Build SVG Paths
        rotatorEl.innerHTML = spans.map((span, i) => {
          const category = categories[i];
          const d = arc(span.from + GAP / 2, span.to - GAP / 2);
          const isActive = (i === selected);
          return `<path
            id="sr-path-${i}"
            class="transition-[stroke-width,opacity] duration-200 ease-[cubic-bezier(0.23,1,0.32,1)]"
            d="${d}"
            fill="none"
            stroke="${category.color}"
            stroke-width="${isActive ? STROKE + 8 : STROKE}"
            opacity="${isActive ? '1' : '0.45'}"
          />`;
        }).join('');

        // Build Legend List Items
        listEl.innerHTML = categories.map((category, i) => {
          const isActive = (i === selected);
          return `
            <li>
              <button
                type="button"
                id="sr-btn-${i}"
                class="sr-category-btn flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-xs transition-all duration-150 border ${isActive ? 'bg-white/10 text-white border-white/10 shadow-sm' : 'border-transparent text-stone-400 hover:text-white hover:bg-white/[0.04]'}"
                data-index="${i}"
              >
                <span class="size-2 shrink-0 rounded-full" style="background-color: ${category.color};"></span>
                <span class="min-w-0 flex-1 truncate font-medium">${escapeHtml(category.name)}</span>
                <span class="tabular-nums font-semibold">${formatRp(category.amount)}</span>
              </button>
            </li>
          `;
        }).join('');

        // Re-attach button click listeners
        listEl.querySelectorAll('.sr-category-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            const idx = parseInt(btn.dataset.index, 10);
            turnTo(idx);
          });
        });

        // Reset rotator transform & center readout
        const goal = -(spans[selected]?.mid ?? 0);
        sim.a = goal;
        sim.v = 0;
        sim.target = goal;
        paint();
        updateVisuals(selected);
      }

      function pointerAngle(event) {
        const rect = surfaceEl.getBoundingClientRect();
        const x = event.clientX - rect.left - rect.width / 2;
        const y = event.clientY - rect.top - rect.height / 2;
        return (Math.atan2(x, -y) * 180) / Math.PI;
      }

      surfaceEl.addEventListener('pointerdown', (e) => {
        if (sim.drag || (e.pointerType === 'mouse' && e.button !== 0)) return;
        surfaceEl.setPointerCapture(e.pointerId);
        cancelAnimationFrame(sim.raf);
        sim.raf = 0;
        sim.v = 0;
        sim.drag = {
          id: e.pointerId,
          angle: pointerAngle(e),
          time: e.timeStamp,
          moved: 0,
          x: e.clientX,
          y: e.clientY,
        };
        surfaceEl.classList.remove('cursor-grab');
        surfaceEl.classList.add('cursor-grabbing');
      });

      surfaceEl.addEventListener('pointermove', (e) => {
        const drag = sim.drag;
        if (!drag || drag.id !== e.pointerId) return;

        drag.moved = Math.max(drag.moved, Math.hypot(e.clientX - drag.x, e.clientY - drag.y));
        if (drag.moved < TAP_SLOP) return;

        const angle = pointerAngle(e);
        const delta = wrap(angle - drag.angle);
        const dt = Math.max((e.timeStamp - drag.time) / 1000, 1 / 240);
        sim.a += delta;
        sim.v = sim.v * 0.6 + (delta / dt) * 0.4;
        drag.angle = angle;
        drag.time = e.timeStamp;
        paint();
        track();
      });

      function release(e) {
        const drag = sim.drag;
        if (!drag || drag.id !== e.pointerId) return;

        sim.drag = null;
        try {
          surfaceEl.releasePointerCapture(e.pointerId);
        } catch (_) {}

        if (drag.moved < TAP_SLOP) {
          const rect = surfaceEl.getBoundingClientRect();
          const r = Math.hypot(
            e.clientX - rect.left - rect.width / 2,
            e.clientY - rect.top - rect.height / 2
          );
          if (r > ((R - STROKE) / SIZE) * rect.width) {
            turnTo(sliceAt(spans, pointerAngle(e) - sim.a));
          } else {
            surfaceEl.classList.remove('cursor-grabbing');
            surfaceEl.classList.add('cursor-grab');
          }
          return;
        }

        if (e.timeStamp - drag.time > 80) {
          sim.v = 0;
        }
        sim.v = Math.max(-1440, Math.min(1440, sim.v));
        const rest = sim.a + sim.v * PROJECT;
        turnTo(sliceAt(spans, -rest), rest, true);
      }

      surfaceEl.addEventListener('pointerup', release);
      surfaceEl.addEventListener('pointercancel', release);
      surfaceEl.addEventListener('lostpointercapture', release);

      surfaceEl.addEventListener('keydown', (e) => {
        const step = {
          ArrowRight: 1,
          ArrowDown: 1,
          ArrowLeft: -1,
          ArrowUp: -1,
        };
        let next = null;
        if (e.key in step) {
          next = (selected + step[e.key] + categories.length) % categories.length;
        } else if (e.key === 'Home') {
          next = 0;
        } else if (e.key === 'End') {
          next = categories.length - 1;
        }
        if (next !== null) {
          e.preventDefault();
          turnTo(next);
        }
      });

      /* ================= MODAL EDIT KATEGORI LOGIC ================= */
      function showError(msg) {
        if (!errorEl) return;
        errorEl.textContent = msg;
        errorEl.classList.remove('hidden');
      }

      function hideError() {
        if (!errorEl) return;
        errorEl.textContent = '';
        errorEl.classList.add('hidden');
      }

      function openModal() {
        if (!modalEl || !modalCardEl) return;
        hideError();
        renderRows(categories);
        modalEl.classList.remove('pointer-events-none', 'opacity-0');
        modalCardEl.classList.remove('scale-95', 'opacity-0');
        modalCardEl.classList.add('scale-100', 'opacity-100');
        document.body.classList.add('overflow-hidden');
      }

      function closeModal() {
        if (!modalEl || !modalCardEl) return;
        modalCardEl.classList.remove('scale-100', 'opacity-100');
        modalCardEl.classList.add('scale-95', 'opacity-0');
        modalEl.classList.add('opacity-0', 'pointer-events-none');
        document.body.classList.remove('overflow-hidden');
      }

      function createRowElement(item, index) {
        const row = document.createElement('div');
        row.className = 'sr-edit-row flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5 p-3 rounded-xl bg-black/40 border border-white/[0.06] hover:border-white/10 transition';

        const currentColor = (item.color || '#38bdf8').toLowerCase();
        const optionsHtml = COLOR_OPTIONS.map(opt => {
          const isSelected = opt.hex.toLowerCase() === currentColor || 
            (opt.name === 'Pink' && currentColor === '#d6a4e8');
          return `<option value="${opt.hex}" ${isSelected ? 'selected' : ''}>${opt.name}</option>`;
        }).join('');

        const selectedOpt = COLOR_OPTIONS.find(opt => 
          opt.hex.toLowerCase() === currentColor || (opt.name === 'Pink' && currentColor === '#d6a4e8')
        );
        const dotColor = selectedOpt ? selectedOpt.hex : (item.color || '#38bdf8');

        row.innerHTML = `
          <div class="flex-1 min-w-[130px]">
            <input
              type="text"
              class="sr-row-name w-full bg-[#1F1B27] border border-white/10 rounded-xl px-3 py-2 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition"
              placeholder="Nama Kategori"
              value="${escapeHtml(item.name || '')}"
              maxlength="40"
              required
            />
          </div>
          <div class="w-full sm:w-36 relative flex items-center">
            <span class="absolute left-3 text-xs text-stone-500 pointer-events-none font-medium">Rp</span>
            <input
              type="text"
              inputmode="numeric"
              class="sr-row-amount w-full bg-[#1F1B27] border border-white/10 rounded-xl pl-9 pr-3 py-2 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-[#C49BBB] focus:ring-1 focus:ring-[#C49BBB] transition"
              placeholder="0"
              value="${Number(item.amount || 0).toLocaleString('id-ID')}"
              required
            />
          </div>
          <div class="flex items-center gap-2 shrink-0 justify-between sm:justify-start">
            <div class="flex items-center gap-1.5 bg-[#1F1B27] border border-white/10 rounded-xl px-2.5 py-1.5">
              <span class="sr-row-color-dot size-3.5 rounded-full border border-white/20 shrink-0 shadow-sm" style="background-color: ${dotColor};"></span>
              <select class="sr-row-color bg-transparent text-xs text-white focus:outline-none cursor-pointer pr-1">
                ${optionsHtml}
              </select>
            </div>
            <button
              type="button"
              class="sr-row-delete-btn p-2 text-stone-500 hover:text-rose-400 hover:bg-rose-500/10 rounded-xl transition cursor-pointer"
              title="Hapus Kategori"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>
        `;

        const colorSelect = row.querySelector('.sr-row-color');
        const colorDot = row.querySelector('.sr-row-color-dot');
        colorSelect.addEventListener('change', () => {
          colorDot.style.backgroundColor = colorSelect.value;
        });

        const amountInput = row.querySelector('.sr-row-amount');
        amountInput.addEventListener('input', () => {
          const digits = amountInput.value.replace(/\D/g, '');
          amountInput.value = digits ? Number(digits).toLocaleString('id-ID') : '';
        });

        const deleteBtn = row.querySelector('.sr-row-delete-btn');
        deleteBtn.addEventListener('click', () => {
          const rows = rowsContainer.querySelectorAll('.sr-edit-row');
          if (rows.length <= 1) {
            showError('Minimal harus ada 1 kategori aktif.');
            return;
          }
          row.remove();
          hideError();
        });

        return row;
      }

      function renderRows(data) {
        if (!rowsContainer) return;
        rowsContainer.innerHTML = '';
        data.forEach((cat, idx) => {
          rowsContainer.appendChild(createRowElement(cat, idx));
        });
      }

      if (openBtnEl) openBtnEl.addEventListener('click', openModal);
      if (closeBtnEl) closeBtnEl.addEventListener('click', closeModal);
      if (cancelBtnEl) cancelBtnEl.addEventListener('click', closeModal);
      if (modalEl) {
        modalEl.addEventListener('click', (e) => {
          if (e.target === modalEl) closeModal();
        });
      }

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modalEl && !modalEl.classList.contains('pointer-events-none')) {
          closeModal();
        }
      });

      if (addRowBtnEl) {
        addRowBtnEl.addEventListener('click', () => {
          const currentCount = rowsContainer.querySelectorAll('.sr-edit-row').length;
          const nextColor = COLOR_OPTIONS[currentCount % COLOR_OPTIONS.length].hex;
          const newRow = createRowElement({
            name: '',
            amount: 500000,
            change: 0,
            color: nextColor
          }, currentCount);
          rowsContainer.appendChild(newRow);
          hideError();
          rowsContainer.scrollTop = rowsContainer.scrollHeight;
          const nameInput = newRow.querySelector('.sr-row-name');
          if (nameInput) {
            nameInput.focus();
          }
        });
      }

      if (saveBtnEl) {
        saveBtnEl.addEventListener('click', () => {
          const rowEls = rowsContainer.querySelectorAll('.sr-edit-row');
          if (rowEls.length === 0) {
            showError('Minimal harus ada 1 kategori aktif.');
            return;
          }

          const newCategories = [];
          rowEls.forEach((row, i) => {
            const nameInput = row.querySelector('.sr-row-name');
            const amountInput = row.querySelector('.sr-row-amount');
            const colorSelect = row.querySelector('.sr-row-color');

            const name = (nameInput.value || '').trim() || `Kategori ${i + 1}`;
            const rawDigits = amountInput.value.replace(/\D/g, '');
            const amount = rawDigits ? parseInt(rawDigits, 10) : 0;
            const color = colorSelect.value || '#38bdf8';

            const oldCat = categories.find(c => c.name.toLowerCase() === name.toLowerCase());
            const change = oldCat && typeof oldCat.change === 'number' ? oldCat.change : 0;

            newCategories.push({
              name,
              amount,
              change,
              color
            });
          });

          const totalAmt = newCategories.reduce((s, c) => s + c.amount, 0);
          if (totalAmt <= 0) {
            showError('Total nominal pengeluaran harus lebih besar dari 0.');
            return;
          }

          saveCategories(newCategories);
          categories = newCategories;
          rebuildRing(false);
          closeModal();
        });
      }

      // Initial ring build
      rebuildRing(false);
    })();

    /* ================= SONAR GRID CANVAS BACKGROUND ================= */
    (function initSonarGrid() {
      const host = document.getElementById('sonar-grid-host');
      const canvas = document.getElementById('sonar-grid-canvas');
      if (!host || !canvas) return;

      const ctx = canvas.getContext('2d');
      if (!ctx) return;

      const opts = {
        spacing: 26,
        dotRadius: 1.4,
        baseOpacity: 0.20,
        color: '#8b5cf6', // Neon violet/indigo accent
        pingEvery: 2.8,   // Ambient sonar ping every 2.8s
        speed: 260,
        ringWidth: 90,
        amplitude: 2.2,
        interactive: true,
        maxRings: 6,
        seedPing: true,
        pingArea: [0.15, 0.2, 0.85, 0.8]
      };

      const MAX_DPR = 2;
      const TAU = Math.PI * 2;
      const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

      let width = 0;
      let height = 0;
      let raf = 0;
      let timer = 0;
      let seeded = false;
      let rings = [];
      let nextPing = performance.now() + opts.pingEvery * 1000;

      const addRing = (x, y, born) => {
        rings.push({ x, y, born });
        while (rings.length > opts.maxRings) {
          rings.shift();
        }
      };

      const draw = (now) => {
        const o = opts;
        const lifetime = (Math.hypot(width, height) + o.ringWidth) / o.speed;
        rings = rings.filter((r) => (now - r.born) / 1000 < lifetime);
        const live = rings.map((r) => {
          const age = (now - r.born) / 1000;
          const radius = age * o.speed;
          return {
            x: r.x,
            y: r.y,
            radius,
            reach: radius + o.ringWidth,
            fade: 1 - age / lifetime
          };
        });

        ctx.clearRect(0, 0, width, height);
        ctx.fillStyle = o.color;

        const cols = Math.ceil(width / o.spacing) + 1;
        const rows = Math.ceil(height / o.spacing) + 1;
        const offsetX = (width - (cols - 1) * o.spacing) / 2;
        const offsetY = (height - (rows - 1) * o.spacing) / 2;

        // Pass 1: every resting dot in a single path and a single fill.
        const hot = [];
        ctx.globalAlpha = o.baseOpacity;
        ctx.beginPath();
        for (let i = 0; i < cols; i++) {
          const cx = offsetX + i * o.spacing;
          for (let j = 0; j < rows; j++) {
            const cy = offsetY + j * o.spacing;
            let energy = 0;
            for (let rIdx = 0; rIdx < live.length; rIdx++) {
              const r = live[rIdx];
              if (Math.abs(cx - r.x) > r.reach || Math.abs(cy - r.y) > r.reach) continue;
              const dist = Math.abs(Math.hypot(cx - r.x, cy - r.y) - r.radius);
              if (dist >= o.ringWidth) continue;
              const t = 1 - dist / o.ringWidth;
              const k = t * t * (3 - 2 * t) * r.fade; // smoothstep, fading with age
              if (k > energy) energy = k;
            }
            if (energy < 0.01) {
              ctx.moveTo(cx + o.dotRadius, cy);
              ctx.arc(cx, cy, o.dotRadius, 0, TAU);
            } else {
              hot.push(cx, cy, energy);
            }
          }
        }
        ctx.fill();

        // Pass 2: only the dots on a wavefront get their own alpha and radius.
        for (let k = 0; k < hot.length; k += 3) {
          const energy = hot[k + 2];
          ctx.globalAlpha = o.baseOpacity + (1 - o.baseOpacity) * energy;
          ctx.beginPath();
          ctx.arc(hot[k], hot[k + 1], o.dotRadius * (1 + o.amplitude * energy), 0, TAU);
          ctx.fill();
        }
        ctx.globalAlpha = 1;
      };

      const resize = () => {
        width = Math.max(1, Math.round(window.innerWidth));
        height = Math.max(1, Math.round(window.innerHeight));
        const dpr = Math.min(window.devicePixelRatio || 1, MAX_DPR);
        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        if (!seeded) {
          seeded = true;
          const [x0, y0, x1, y1] = opts.pingArea;
          if (opts.seedPing && !reduceMotion.matches) {
            addRing(width * (x0 + (x1 - x0) * 0.68), height * (y0 + (y1 - y0) * 0.34), performance.now() - 500);
          }
        }
        draw(performance.now());
      };

      const scheduleIdle = (delay) => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => tick(performance.now()), Math.max(16, delay));
      };

      const tick = (now) => {
        raf = 0;
        if (document.hidden) return;
        if (reduceMotion.matches) {
          rings = [];
          draw(now);
          return;
        }
        if (opts.pingEvery > 0 && now >= nextPing) {
          const [x0, y0, x1, y1] = opts.pingArea;
          addRing(width * (x0 + Math.random() * (x1 - x0)), height * (y0 + Math.random() * (y1 - y0)), now);
          nextPing = now + opts.pingEvery * 1000;
        }
        draw(now);
        if (rings.length > 0) {
          raf = requestAnimationFrame(tick);
        } else if (opts.pingEvery > 0) {
          scheduleIdle(nextPing - now);
        }
      };

      const wake = () => {
        if (!raf) {
          window.clearTimeout(timer);
          raf = requestAnimationFrame(tick);
        }
      };

      // Click on background triggers new sonar ring (active across entire screen including sidebar)
      window.addEventListener('pointerdown', (e) => {
        if (!opts.interactive || reduceMotion.matches) return;
        if (e.target.closest('button, a, input, select, textarea, [role="button"], [role="slider"], #desktop-pet, #flow-pet-container, #sr-modal, #transaction-modal, #profile-modal, #edit-goal-modal, #edit-transaction-modal')) {
          return;
        }
        const x = e.clientX;
        const y = e.clientY;
        if (x >= 0 && x <= width && y >= 0 && y <= height) {
          addRing(x, y, performance.now());
          wake();
        }
      }, { passive: true });

      // Pause when tab is inactive, resume when active
      document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
          if (raf) cancelAnimationFrame(raf);
          window.clearTimeout(timer);
          raf = 0;
        } else {
          wake();
        }
      });

      window.addEventListener('resize', resize);
      reduceMotion.addEventListener('change', wake);

      resize();
      wake();
    })();
</script>
</body>
<!-- Interactive Mascot / Pet -->
<div id="desktop-pet" class="fixed pointer-events-none z-[9999] select-none" style="top: 150px; left: 150px; width: 48px; height: 48px;">
  <img id="pet-avatar" src="assets/pet/Action.png" alt="Pet" class="w-full h-full object-contain" style="image-rendering: pixelated;" />
</div>

<script>
  (function () {
    const pet = document.getElementById('desktop-pet');
    const avatar = document.getElementById('pet-avatar');
    if (!pet || !avatar) return;

    let posX = 150, posY = 150;
    let targetX = 150, targetY = 150;
    let isMoving = false;
    let blinkTimeout = null;

    window.addEventListener('mousemove', (e) => {
      targetX = e.clientX - 24;
      targetY = e.clientY - 24;
    });

    function loop() {
      const dx = targetX - posX;
      const dy = targetY - posY;
      const dist = Math.hypot(dx, dy);

      posX += dx * 0.08;
      posY += dy * 0.08;

      pet.style.left = `${posX}px`;
      pet.style.top = `${posY}px`;

      if (dist > 3) {
        if (!isMoving) {
          isMoving = true;
          avatar.src = 'assets/pet/Bark.png';
        }
        pet.style.transform = dx < 0 ? 'scaleX(-1)' : 'scaleX(1)';
      } else {
        if (isMoving) {
          isMoving = false;
          avatar.src = 'assets/pet/Action.png';
        }
      }

      requestAnimationFrame(loop);
    }
    loop();

    setInterval(() => {
      if (!isMoving) {
        avatar.src = 'assets/pet/Blink.png';
        clearTimeout(blinkTimeout);
        blinkTimeout = setTimeout(() => {
          if (!isMoving) avatar.src = 'assets/pet/Action.png';
        }, 250);
      }
    }, 4000);
  })();
</script></html>
