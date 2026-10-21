/* =========================================================
   app.js — state UI, navigasi antar view, render header
   Fase 1: routing hash + pemilih periode.
   ========================================================= */
const App = (() => {
  // Metadata tiap view (judul null = pakai sapaan dashboard)
  const VIEWS = {
    dashboard:    { title: null, subtitle: null },
    transactions: { title: "Transaksi", subtitle: "Semua catatan pemasukan dan pengeluaranmu." },
    budget:       { title: "Anggaran", subtitle: "Atur batas pengeluaran bulanan per kategori." },
    reports:      { title: "Laporan", subtitle: "Lihat ke mana uangmu pergi." },
    settings:     { title: "Pengaturan", subtitle: "Tampilan dan pengelolaan data." },
  };

  // State UI (di memori, bukan data transaksi)
  const state = {
    view: "dashboard",
    period: Utils.getMonthKey(new Date()), // "YYYY-MM" atau "all"
  };

  const dom = {};

  function cacheDom() {
    dom.main = document.getElementById("main");
    dom.pageTitle = document.getElementById("page-title");
    dom.pageSubtitle = document.getElementById("page-subtitle");
    dom.periodLabel = document.getElementById("period-label");
    dom.periodPrev = document.getElementById("period-prev");
    dom.periodNext = document.getElementById("period-next");
    dom.periodAll = document.getElementById("period-all");
    dom.balanceChip = document.getElementById("balance-period-chip");
    dom.skipLink = document.getElementById("skip-link");
    dom.navLinks = document.querySelectorAll(".nav-link[data-view]");
    dom.panels = document.querySelectorAll("[data-view-panel]");
  }

  /* ---------- Navigasi ---------- */
  function getViewFromHash() {
    const view = location.hash.replace("#", "");
    return VIEWS[view] ? view : null;
  }

  function showView(view, { focus = false } = {}) {
    state.view = view;
    dom.panels.forEach((panel) => { panel.hidden = panel.dataset.viewPanel !== view; });
    dom.navLinks.forEach((link) => {
      if (link.dataset.view === view) link.setAttribute("aria-current", "page");
      else link.removeAttribute("aria-current");
    });
    renderHeader();
    if (focus) dom.main.focus({ preventScroll: true });
    window.scrollTo({ top: 0 });
  }

  /* ---------- Render ---------- */
  function getPeriodText() {
    return state.period === "all" ? "semua periode" : Utils.formatMonthLabel(state.period);
  }

  function renderHeader() {
    const meta = VIEWS[state.view];
    if (meta.title) {
      dom.pageTitle.textContent = meta.title;
      dom.pageSubtitle.textContent = meta.subtitle;
    } else {
      dom.pageTitle.innerHTML = `Selamat <span class="text-accent">${Utils.getGreeting()}</span>`;
      dom.pageSubtitle.textContent = `Ini ringkasan keuanganmu untuk ${getPeriodText()}.`;
    }
    document.title = meta.title ? `${meta.title} — MoneyTracker` : "MoneyTracker — Pelacak Keuangan Pribadi";
  }

  function renderPeriod() {
    const isAll = state.period === "all";
    dom.periodLabel.textContent = isAll ? "Semua periode" : Utils.formatMonthLabel(state.period);
    dom.periodAll.setAttribute("aria-pressed", String(isAll));
    dom.balanceChip.textContent = isAll ? "Semua" : Utils.formatMonthLabel(state.period, true);
    renderHeader();
  }

  function changePeriod(delta) {
    const base = state.period === "all" ? Utils.getMonthKey(new Date()) : state.period;
    state.period = state.period === "all" ? base : Utils.shiftMonth(base, delta);
    renderPeriod();
  }

  function togglePeriodAll() {
    state.period = state.period === "all" ? Utils.getMonthKey(new Date()) : "all";
    renderPeriod();
  }

  /* ---------- Event ---------- */
  function bindEvents() {
    window.addEventListener("hashchange", () => {
      const view = getViewFromHash();
      if (view) showView(view, { focus: true });
    });

    // Skip link: fokus ke konten tanpa memicu routing
    dom.skipLink.addEventListener("click", (event) => {
      event.preventDefault();
      dom.main.focus();
    });

    dom.periodPrev.addEventListener("click", () => changePeriod(-1));
    dom.periodNext.addEventListener("click", () => changePeriod(1));
    dom.periodAll.addEventListener("click", togglePeriodAll);
  }

  function init() {
    cacheDom();
    bindEvents();
    showView(getViewFromHash() || "dashboard");
    renderPeriod();
  }

  document.addEventListener("DOMContentLoaded", init);

  return { state };
})();
