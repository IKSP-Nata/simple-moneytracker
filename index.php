<?php
/**
 * index.php — FlowTrack Personal Money Tracker (dinamis dari db_money)
 */
require __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Tangani form jika disubmit langsung ke index.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require __DIR__ . '/process_transaction.php';
    exit;
}

/* ---------- Data dompet ---------- */
$wallets      = $pdo->query('SELECT * FROM wallets ORDER BY id ASC')->fetchAll();
$totalBalance = (float) $pdo->query('SELECT COALESCE(SUM(balance), 0) FROM wallets')->fetchColumn();

/* ---------- Data target tabungan ---------- */
$goals = $pdo->query('SELECT * FROM savings_goals ORDER BY id ASC')->fetchAll();
foreach ($goals as &$g) {
    $g['pct'] = (float) $g['target_amount'] > 0
        ? min(100, round(((float) $g['current_amount'] / (float) $g['target_amount']) * 100))
        : 0;
}
unset($g);

// Goal unggulan untuk kartu Wishlist: goal pertama yang belum tercapai
$featuredGoal = null;
foreach ($goals as $g) {
    if ($g['pct'] < 100) { $featuredGoal = $g; break; }
}
$featuredGoal ??= $goals[0] ?? null;

/* ---------- Data transaksi (JOIN wallets) ---------- */
$transactions = $pdo->query(
    'SELECT t.*, COALESCE(w.wallet_name, "Unknown") AS wallet_name
     FROM transactions t
     LEFT JOIN wallets w ON w.id = t.wallet_id
     ORDER BY t.created_at DESC, t.id DESC'
)->fetchAll();
$recentTransactions = array_slice($transactions, 0, 5);

/* ---------- Ringkasan bulan ini ---------- */
$monthTotals = ['income' => 0.0, 'expense' => 0.0, 'goal_saving' => 0.0];
$stmt = $pdo->query(
    "SELECT type, SUM(amount) AS total FROM transactions
     WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
     GROUP BY type"
);
foreach ($stmt as $row) {
    $monthTotals[$row['type']] = (float) $row['total'];
}

/* ---------- Grafik arus kas (net income - expense per bulan, tahun ini) ---------- */
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

// Kurva halus (Catmull-Rom → Bezier), titik kontrol di-clamp agar tidak overshoot
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
$tipBelow    = $tipY < 45; // titik tinggi → tooltip ditaruh di bawah titik

function short_rp(float $v): string
{
    $sign = $v < 0 ? '-' : '';
    $a = abs($v);
    if ($a >= 1_000_000) return $sign . rtrim(rtrim(number_format($a / 1_000_000, 1, ',', '.'), '0'), ',') . 'jt';
    if ($a >= 1_000)     return $sign . round($a / 1_000) . 'k';
    return $sign . round($a);
}

/* ---------- Helper tampilan ---------- */
$walletDots  = ['bg-emerald-500', 'bg-blue-400', 'bg-mauveAccent', 'bg-amber-400'];
$walletTypes = array_values(array_unique(array_merge(
    ['Bank', 'E-Wallet', 'Tunai'],
    array_column($wallets, 'wallet_type')
)));

function is_cash_type(string $type): bool
{
    return in_array(strtolower($type), ['tunai', 'cash', 'physical'], true);
}

/* ---------- Flash message (setelah redirect) ---------- */
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
  </style>
</head>
<body class="min-h-screen flex items-center justify-center p-3 sm:p-6 lg:p-10 relative overflow-x-hidden selection:bg-mauveAccent/30 selection:text-white">
<!-- Ambient Light Effects -->
<div class="fixed inset-0 pointer-events-none ambient-glow-left"></div>
<div class="fixed inset-0 pointer-events-none ambient-glow-right"></div>
<!-- BEGIN: MainDashboardContainer -->
<main class="relative z-10 w-full max-w-[1280px] bg-[#121017]/95 rounded-[2.2rem] border border-white/[0.07] shadow-2xl backdrop-blur-2xl p-5 md:p-7 flex flex-col lg:flex-row gap-6">
<!-- BEGIN: LeftSidebar -->
<aside class="w-full lg:w-64 flex-shrink-0 flex flex-col justify-between py-2 border-b lg:border-b-0 lg:border-r border-white/[0.06] pr-0 lg:pr-5" data-purpose="app-navigation">
<div class="space-y-8">
<!-- Logo & Brand Name -->
<div class="flex items-center gap-3 px-2">
<div class="w-8 h-8 rounded-xl bg-gradient-to-br from-mauveAccent via-mauveGlow to-mauveDark flex items-center justify-center shadow-lg shadow-mauveAccent/20">
<!-- Sleek Minimal Geometric Monogram Icon -->
<svg class="w-4 h-4 text-black" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" viewbox="0 0 24 24">
<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
</svg>
</div>
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
<a class="flex items-center px-4 py-3 rounded-2xl text-sm font-medium text-stone-400 hover:text-stone-200 hover:bg-white/[0.03] transition-all" href="#" id="nav-analysis" onclick="event.preventDefault()">
<span class="">Analysis</span>
</a></nav>
</div>
<!-- Bottom Settings & Help Section -->
<div class="pt-6 border-t border-white/[0.05] space-y-1.5 mt-6 lg:mt-0"><a class="flex items-center px-4 py-2.5 rounded-xl text-sm font-medium text-stone-400 hover:text-white hover:bg-white/[0.03] transition" href="#" onclick="event.preventDefault()">
<span class="">Settings</span>
</a>
<a class="flex items-center px-4 py-2.5 rounded-xl text-sm font-medium text-stone-400 hover:text-white hover:bg-white/[0.03] transition" href="#" onclick="event.preventDefault()">
<span class="">Help &amp; Support</span>
</a></div>
</aside>
<!-- END: LeftSidebar -->
<!-- BEGIN: DashboardBody -->
<div class="flex-1 flex flex-col gap-6 overflow-hidden">
<!-- BEGIN: TopHeader -->
<header class="flex flex-col md:flex-row md:items-center justify-between gap-4">
<!-- User Greeting & Subtitle -->
<div>
<h1 class="text-2xl md:text-3xl font-semibold text-white tracking-tight">Welcome, Kadek Nata</h1>
<p class="text-xs md:text-sm text-stone-400 mt-0.5" id="page-subtitle">
            Here's your personal financial overview today
          </p>
</div>
<!-- Right Header Items: Pills, Search, Notifications, Profile, and Add Transaction Button -->
<div class="flex flex-wrap items-center gap-3">
<!-- Add Transaction Button -->
<button class="btn-add-transaction flex items-center gap-1.5 px-3.5 py-2 rounded-2xl text-xs font-semibold bg-gradient-to-r from-[#C49BBB] to-[#A374A0] text-[#0D0B10] shadow-md shadow-[#C49BBB]/20 hover:opacity-95 active:scale-[0.98] transition-all" type="button" id="header-add-btn">
<svg class="w-3.5 h-3.5" fill="none" height="16" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" viewbox="0 0 24 24" width="16" xmlns="http://www.w3.org/2000/svg"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
<span>Add Transaction</span>
</button>
<!-- Command Prompt / Search Bar -->
<div class="relative flex items-center bg-[#17141E] border border-white/[0.07] px-3.5 py-2 rounded-2xl w-full sm:w-60 focus-within:border-mauveAccent/50 transition">
<svg aria-hidden="true" class="lucide lucide-sparkles w-4 h-4 text-mauveAccent mr-2 flex-shrink-0" data-lucide="sparkles" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"></path><path d="M20 2v4"></path><path d="M22 4h-4"></path><circle cx="4" cy="20" r="2"></circle></svg>
<input class="bg-transparent text-xs text-white placeholder-stone-400 focus:outline-none w-full border-none p-0" placeholder="Ask flow.ai anything..." type="text" id="search-input"/>
<span class="text-[10px] bg-white/[0.06] text-stone-400 px-1.5 py-0.5 rounded border border-white/[0.05]">⌘K</span>
</div>
<!-- Notification Bell -->
<button aria-label="Notifications" class="w-9 h-9 rounded-2xl bg-[#17141E] border border-white/[0.07] flex items-center justify-center text-stone-300 hover:text-white hover:border-white/20 transition" type="button" id="notif-btn">
<svg aria-hidden="true" class="lucide lucide-bell w-4 h-4" data-lucide="bell" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M10.268 21a2 2 0 0 0 3.464 0"></path><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"></path></svg>
</button>
<!-- Settings Cog -->
<button aria-label="Quick Settings" class="w-9 h-9 rounded-2xl bg-[#17141E] border border-white/[0.07] flex items-center justify-center text-stone-300 hover:text-white hover:border-white/20 transition" type="button" id="quick-settings-btn">
<svg aria-hidden="true" class="lucide lucide-sliders-horizontal w-4 h-4" data-lucide="sliders-horizontal" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M10 5H3"></path><path d="M12 19H3"></path><path d="M14 3v4"></path><path d="M16 17v4"></path><path d="M21 12h-9"></path><path d="M21 19h-5"></path><path d="M21 5h-7"></path><path d="M8 10v4"></path><path d="M8 12H3"></path></svg>
</button>
<!-- User Profile Badge -->
<div class="flex items-center gap-2.5 pl-1.5 py-1 pr-3 bg-[#17141E] border border-white/[0.07] rounded-2xl"><div class="text-left px-3 py-1">
<p class="text-xs font-semibold text-white leading-tight">Kadek Nata</p>
<p class="text-[10px] text-stone-400 leading-tight">kadek.nata@gmail.com</p>
</div></div>
</div>
</header>
<!-- END: TopHeader -->

<!-- ================================================================
     TAB: DASHBOARD
     ================================================================ -->
<section class="flex flex-col gap-6" data-panel="dashboard" id="panel-dashboard" aria-label="Dashboard">
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
<div class="bg-[#17141E] border border-white/[0.07] rounded-3xl p-5 flex flex-col justify-between" data-purpose="total-balance-card">
<div class="flex items-center justify-between mb-3">
<span class="text-xs text-stone-400 font-medium tracking-wide">Total Balance</span>
<button class="flex items-center gap-1.5 text-xs bg-black/40 text-stone-300 border border-white/[0.08] px-3 py-1 rounded-full hover:border-white/20 transition" type="button">
<span class="">This Month</span>
<i class="w-3.5 h-3.5 text-stone-400" data-lucide="chevron-down"></i>
</button>
</div>
<div>
<div class="text-3xl lg:text-4xl font-bold tracking-tight text-white mb-2" id="total-balance">
                <?= rupiah($totalBalance) ?>
              </div>
<div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
<span class="inline-flex items-center gap-0.5 text-emerald-400 font-medium">
<i class="w-3.5 h-3.5" data-lucide="trending-up"></i>
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
<!-- BEGIN: BottomWideCard (Cashflow Performance Line Chart) -->
<section class="bg-[#17141E] border border-white/[0.07] rounded-3xl p-5 md:p-6 relative" data-purpose="performance-chart-card">
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
</section>
<!-- END TAB: DASHBOARD -->

<!-- ================================================================
     TAB: TRANSACTIONS
     ================================================================ -->
<section class="hidden flex flex-col gap-6" data-panel="transactions" id="panel-transactions" aria-label="Transactions">
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
<td class="px-2 py-3 text-right">
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
</section>
<!-- END TAB: TRANSACTIONS -->

<!-- ================================================================
     TAB: MY WALLETS
     ================================================================ -->
<section class="hidden flex flex-col gap-6" data-panel="wallets" id="panel-wallets" aria-label="My Wallets">
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
</section>
<!-- END TAB: MY WALLETS -->

<!-- ================================================================
     TAB: SAVINGS GOALS
     ================================================================ -->
<section class="hidden flex flex-col gap-6" data-panel="goals" id="panel-goals" aria-label="Savings Goals">
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
<?php if ($g['pct'] < 100): ?>
<button class="btn-save-goal text-[11px] font-medium text-mauveAccent hover:text-white transition" data-goal-id="<?= (int) $g['id'] ?>" type="button" id="save-goal-<?= (int) $g['id'] ?>">+ Nabung</button>
<?php endif; ?>
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
</section>
<!-- END TAB: SAVINGS GOALS -->
</div>
<!-- END: DashboardBody -->
</main>
<!-- END: MainDashboardContainer -->
<!-- BEGIN: Add Transaction Modal Popup -->
<div aria-labelledby="modal-title" aria-modal="true" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-md opacity-0 pointer-events-none transition-all duration-300" id="transaction-modal" role="dialog">
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
<!-- Subtle Success Toast Notification -->
<div class="fixed bottom-6 right-6 z-50 pointer-events-none transform translate-y-12 opacity-0 transition-all duration-300 bg-[#1F1923] border border-mauveAccent/30 rounded-2xl px-4 py-3 shadow-2xl flex items-center gap-3" id="tx-toast" <?= $flashMsg !== null ? 'data-show="1"' : '' ?>>
<div class="w-6 h-6 rounded-full <?= $flashError ? 'bg-rose-500/20 text-rose-300' : 'bg-emerald-500/20 text-emerald-400' ?> flex items-center justify-center text-xs"><?= $flashError ? '!' : '✓' ?></div>
<p class="text-xs font-medium text-white"><?= $flashMsg !== null ? e($flashMsg) : 'Transaction recorded successfully!' ?></p>
</div>
<!-- Initialize Lucide Icons, Tabs, Filters & Modal Interactivity -->
<script data-purpose="icon-initialization">
    lucide.createIcons();

    /* ================= TAB NAVIGATION ================= */
    const NAV_ACTIVE = 'active-nav-pill flex items-center px-4 py-3 rounded-2xl text-sm font-medium text-white shadow-sm transition-all duration-200';
    const NAV_IDLE   = 'flex items-center px-4 py-3 rounded-2xl text-sm font-medium text-stone-400 hover:text-stone-200 hover:bg-white/[0.03] transition-all';
    const SUBTITLES  = {
      dashboard:    "Here's your personal financial overview today",
      transactions: 'Complete history of every transaction you recorded',
      wallets:      'Manage your bank accounts, e-wallets and cash',
      goals:        'Track progress toward your wishlist & savings targets',
    };

    const navLinks   = document.querySelectorAll('[data-tab]');
    const panels     = document.querySelectorAll('[data-panel]');
    const subtitleEl = document.getElementById('page-subtitle');
    const returnTab  = document.getElementById('tx-return-tab');

    function showTab(tab, updateHash = true) {
      if (!SUBTITLES[tab]) tab = 'dashboard';
      panels.forEach(p => p.classList.toggle('hidden', p.dataset.panel !== tab));
      navLinks.forEach(a => {
        a.className = a.dataset.tab === tab ? NAV_ACTIVE : NAV_IDLE;
        a.toggleAttribute('aria-current', a.dataset.tab === tab);
      });
      subtitleEl.textContent = SUBTITLES[tab];
      returnTab.value = tab;
      if (updateHash) history.replaceState(null, '', '#' + tab);
    }

    navLinks.forEach(a => a.addEventListener('click', e => {
      e.preventDefault();
      showTab(a.dataset.tab);
    }));
    document.querySelectorAll('[data-goto]').forEach(btn => btn.addEventListener('click', e => {
      e.preventDefault();
      showTab(btn.dataset.goto);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }));
    window.addEventListener('hashchange', () => showTab(location.hash.slice(1), false));

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
      if (e.key === 'Escape' && !modal.classList.contains('pointer-events-none')) {
        closeModal();
      }
    });

    // Validasi sebelum dikirim ke process_transaction.php
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

    /* ================= TOAST (setelah redirect) ================= */
    if (toast && toast.dataset.show) {
      requestAnimationFrame(() => toast.classList.remove('translate-y-12', 'opacity-0'));
      setTimeout(() => toast.classList.add('translate-y-12', 'opacity-0'), 3200);
      history.replaceState(null, '', location.pathname + location.hash);
    }

    // Tab awal dari hash URL (#transactions, #wallets, #goals)
    showTab(location.hash.slice(1) || 'dashboard', false);
</script>
</body></html>
