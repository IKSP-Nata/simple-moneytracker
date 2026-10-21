/* =========================================================
   storage.js — lapisan data localStorage
   Hanya baca/tulis + normalisasi data. Tidak menyentuh DOM.
   ========================================================= */
const DataStore = (() => {
  const KEYS = {
    transactions: "mt_transactions",
    budgets: "mt_budgets",
    prefs: "mt_prefs",
  };
  const NOTE_MAX = 80;
  const VALID_TYPES = ["income", "expense"];

  /* ---------- Low-level ---------- */
  function read(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      return raw ? JSON.parse(raw) : fallback;
    } catch (error) {
      console.warn(`[DataStore] Gagal membaca "${key}", memakai nilai default.`, error);
      return fallback;
    }
  }

  // Sengaja melempar error (quota penuh / diblokir) agar UI bisa memberi tahu pengguna
  function write(key, value) {
    localStorage.setItem(key, JSON.stringify(value));
  }

  /** Pastikan bentuk transaksi valid; kembalikan null jika rusak */
  function normalizeTransaction(raw) {
    if (!raw || typeof raw !== "object") return null;
    const nominal = Math.round(Number(raw.nominal));
    if (!raw.id || !Utils.isValidISODate(raw.tanggal)) return null;
    if (!(nominal > 0) || !VALID_TYPES.includes(raw.tipe) || !raw.kategori) return null;
    return {
      id: String(raw.id),
      tanggal: raw.tanggal,
      nominal,
      tipe: raw.tipe,
      kategori: String(raw.kategori),
      catatan: String(raw.catatan ?? "").trim().slice(0, NOTE_MAX),
    };
  }

  /* ---------- Transaksi ---------- */
  function getTransactions() {
    const data = read(KEYS.transactions, []);
    return Array.isArray(data) ? data.map(normalizeTransaction).filter(Boolean) : [];
  }

  function saveTransactions(list) {
    write(KEYS.transactions, list);
  }

  /** Transaksi baru disisipkan di depan → urutan "terbaru dulu" untuk tanggal yang sama */
  function addTransaction(input) {
    const transaction = normalizeTransaction({ ...input, id: Utils.generateId() });
    if (!transaction) throw new Error("Data transaksi tidak valid.");
    const list = getTransactions();
    list.unshift(transaction);
    saveTransactions(list);
    return transaction;
  }

  function updateTransaction(id, input) {
    const list = getTransactions();
    const index = list.findIndex((tx) => tx.id === id);
    if (index === -1) throw new Error("Transaksi tidak ditemukan.");
    const transaction = normalizeTransaction({ ...list[index], ...input, id });
    if (!transaction) throw new Error("Data transaksi tidak valid.");
    list[index] = transaction;
    saveTransactions(list);
    return transaction;
  }

  function deleteTransaction(id) {
    const list = getTransactions();
    const next = list.filter((tx) => tx.id !== id);
    saveTransactions(next);
    return next.length !== list.length;
  }

  /* ---------- Anggaran: { kategoriId: nominal } ---------- */
  function getBudgets() {
    const data = read(KEYS.budgets, {});
    return data && typeof data === "object" && !Array.isArray(data) ? data : {};
  }

  function saveBudgets(budgets) {
    write(KEYS.budgets, budgets);
  }

  /* ---------- Preferensi ---------- */
  function getPrefs() {
    const data = read(KEYS.prefs, {});
    return data && typeof data === "object" ? data : {};
  }

  function savePrefs(prefs) {
    write(KEYS.prefs, { ...getPrefs(), ...prefs });
  }

  /** Hapus transaksi & anggaran (preferensi tema dipertahankan) */
  function clearAll() {
    localStorage.removeItem(KEYS.transactions);
    localStorage.removeItem(KEYS.budgets);
  }

  return {
    KEYS,
    NOTE_MAX,
    getTransactions,
    saveTransactions,
    addTransaction,
    updateTransaction,
    deleteTransaction,
    getBudgets,
    saveBudgets,
    getPrefs,
    savePrefs,
    clearAll,
  };
})();
