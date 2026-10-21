/* =========================================================
   utils.js — helper murni (tanpa akses DOM / storage)
   Format Rupiah, parsing nominal, tanggal & bulan Indonesia.
   ========================================================= */
const Utils = (() => {
  const MONTHS_LONG = [
    "Januari", "Februari", "Maret", "April", "Mei", "Juni",
    "Juli", "Agustus", "September", "Oktober", "November", "Desember",
  ];
  const MONTHS_SHORT = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
  const WEEKDAYS = ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];

  // minimumFractionDigits wajib 0 juga, karena default IDR = 2 (bisa memicu RangeError)
  const currencyFormatter = new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  });
  const numberFormatter = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 0 });
  const decimalFormatter = new Intl.NumberFormat("id-ID", { minimumFractionDigits: 1, maximumFractionDigits: 1 });

  /** 1250000 → "Rp 1.250.000" (spasi dinormalkan jadi spasi biasa tak terputus) */
  function formatRupiah(amount) {
    return currencyFormatter.format(Math.round(Number(amount) || 0)).replace(/\s/g, "\u00A0");
  }

  /** (50000, "expense") → "−Rp 50.000" ; income → "+Rp 50.000" */
  function formatSignedRupiah(amount, tipe) {
    return `${tipe === "income" ? "+" : "\u2212"}${formatRupiah(Math.abs(amount))}`;
  }

  /** 1250000 → "1.250.000" (untuk isi input nominal) */
  function formatNumber(amount) {
    return numberFormatter.format(Math.round(Number(amount) || 0));
  }

  /** "1.250.000" / "Rp 1.250.000" → 1250000 */
  function parseNominal(text) {
    const digits = String(text ?? "").replace(/\D/g, "");
    return digits ? parseInt(digits, 10) : 0;
  }

  /** 15.234 → "+15,2%", -4.8 → "-4,8%", 0 → "0,0%" */
  function formatPercent(value) {
    if (!Number.isFinite(value)) return "—";
    const sign = value > 0 ? "+" : value < 0 ? "-" : "";
    return `${sign}${decimalFormatter.format(Math.abs(value))}%`;
  }

  /** Persentase perubahan; null jika pembanding 0 */
  function percentChange(current, previous) {
    if (!previous) return null;
    return ((current - previous) / Math.abs(previous)) * 100;
  }

  /* ---------- Tanggal ---------- */
  const pad2 = (n) => String(n).padStart(2, "0");

  /** Date → "YYYY-MM-DD" (pakai waktu lokal, bukan UTC) */
  function toISODate(date = new Date()) {
    return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
  }

  /** "YYYY-MM-DD" → Date lokal */
  function parseISODate(iso) {
    const [y, m, d] = String(iso).split("-").map(Number);
    return new Date(y, (m || 1) - 1, d || 1);
  }

  function isValidISODate(iso) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(iso))) return false;
    const date = parseISODate(iso);
    return toISODate(date) === iso;
  }

  /** "2026-10-09" → "9 Okt 2026" */
  function formatTanggal(iso) {
    const [y, m, d] = String(iso).split("-").map(Number);
    if (!y || !m || !d) return "";
    return `${d} ${MONTHS_SHORT[m - 1]} ${y}`;
  }

  /** Label grup harian: "Hari ini · 9 Okt 2026", "Kemarin · …", "Rabu, 7 Okt 2026" */
  function formatDayLabel(iso, today = new Date()) {
    const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);
    if (iso === toISODate(today)) return `Hari ini · ${formatTanggal(iso)}`;
    if (iso === toISODate(yesterday)) return `Kemarin · ${formatTanggal(iso)}`;
    return `${WEEKDAYS[parseISODate(iso).getDay()]}, ${formatTanggal(iso)}`;
  }

  /** Date | "YYYY-MM-DD" → "YYYY-MM" */
  function getMonthKey(value = new Date()) {
    if (typeof value === "string") return value.slice(0, 7);
    return `${value.getFullYear()}-${pad2(value.getMonth() + 1)}`;
  }

  /** "2026-10" → "Oktober 2026" (atau "Okt 2026" jika short) */
  function formatMonthLabel(monthKey, short = false) {
    const [y, m] = String(monthKey).split("-").map(Number);
    if (!y || !m) return "";
    return `${(short ? MONTHS_SHORT : MONTHS_LONG)[m - 1]} ${y}`;
  }

  /** Nama bulan pendek saja: "2026-10" → "Okt" */
  function formatMonthShort(monthKey) {
    const m = Number(String(monthKey).split("-")[1]);
    return MONTHS_SHORT[m - 1] || "";
  }

  /** Geser bulan: ("2026-01", -1) → "2025-12" */
  function shiftMonth(monthKey, delta) {
    const [y, m] = String(monthKey).split("-").map(Number);
    const date = new Date(y, m - 1 + delta, 1);
    return getMonthKey(date);
  }

  /** Sapaan sesuai jam lokal */
  function getGreeting(date = new Date()) {
    const hour = date.getHours();
    if (hour < 11) return "pagi";
    if (hour < 15) return "siang";
    if (hour < 18) return "sore";
    return "malam";
  }

  /* ---------- Lain-lain ---------- */
  function escapeHtml(text) {
    return String(text ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function generateId() {
    if (window.crypto?.randomUUID) return crypto.randomUUID();
    return `tx_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`;
  }

  return {
    MONTHS_LONG,
    MONTHS_SHORT,
    formatRupiah,
    formatSignedRupiah,
    formatNumber,
    parseNominal,
    formatPercent,
    percentChange,
    toISODate,
    parseISODate,
    isValidISODate,
    formatTanggal,
    formatDayLabel,
    getMonthKey,
    formatMonthLabel,
    formatMonthShort,
    shiftMonth,
    getGreeting,
    escapeHtml,
    generateId,
  };
})();
