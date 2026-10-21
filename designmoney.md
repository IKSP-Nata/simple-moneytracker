# Spesifikasi Desain Web: Money Tracker Dashboard

Dokumen ini adalah panduan desain (UI/UX) untuk membangun antarmuka web aplikasi **Money Tracker (Pelacak Keuangan Pribadi)** menggunakan HTML, CSS, dan JavaScript murni. Panduan ini diadaptasi dari referensi visual berkonsep *dark-theme* dengan gaya modern, premium, dan sentuhan *subtle glow*.

---

## 1. Gaya Visual Keseluruhan
- **Tema:** Dark Mode / Premium FinTech.
- **Mood & Suasana:** Elegan, modern, futuristik namun tetap bersih (clean), profesional, dan menenangkan (calm).
- **Ciri Khas:** Penggunaan bentuk membulat (*heavily rounded/pill shapes*), efek kaca transparan (*glassmorphism* tipis) dengan border halus ber-opacity rendah, serta aksen cahaya berpendar (*glowing/radial gradients*) yang sangat halus untuk menonjolkan elemen aktif, bukan warna solid yang mencolok.

---

## 2. Palet Warna (HEX Codes)
Hindari abu-abu netral atau hitam murni. Gunakan warna gelap dengan sedikit *tint* ungu/magenta untuk kesan premium.

- **Latar Belakang (Background):**
  - Background Utama (Body): `#0F0C13` (Hitam dengan tint ungu sangat gelap). Disarankan menggunakan radial-gradient halus di tengah atas dengan warna `#1D1626` ke `#0F0C13`.
  - Permukaan Kartu (Card Surface): `#17141D`
  - Permukaan Kartu (Hover/Active): `#1E1A26`
- **Border & Garis:**
  - Border Kartu/Elemen: `rgba(255, 255, 255, 0.06)`
  - Garis Pembagi (Divider): `rgba(255, 255, 255, 0.03)`
- **Teks (Typography):**
  - Teks Utama (Heading, Angka Saldo): `#FFFFFF` atau `#F8F7FA`
  - Teks Sekunder (Label, Deskripsi): `#A19DAB`
- **Warna Aksen & Brand:**
  - Aksen Gradien (Tombol Aktif, Highlight): `linear-gradient(135deg, #D4A5C9 0%, #9D759B 100%)`
  - Teks Aksen (Nama User, Highlight Teks): `#D4A5C9`
  - Subtle Glow (Bayangan aktif): `rgba(212, 165, 201, 0.15)`
- **Status Keuangan:**
  - Pemasukan (Positif): `#34D399` (Hijau zamrud cerah)
  - Pengeluaran (Negatif): `#FB7185` (Merah muda/Rose cerah agar cocok dengan tema ungu)

---

## 3. Tipografi
Gunakan Google Fonts. Rekomendasi utama: **'Plus Jakarta Sans'** (sangat baik untuk angka/tabel finansial). Alternatif: **'Outfit'** atau **'Inter'**.

- **Font Family:** `'Plus Jakarta Sans', sans-serif`
- **Hierarki:**
  - **H1 / Angka Saldo Utama:** `32px` | Weight: `700` (Bold) | Tracking: `-0.02em`
  - **H2 / Judul Halaman (Welcome):** `24px` | Weight: `600` (SemiBold)
  - **H3 / Judul Kartu (Card Header):** `16px` | Weight: `500` (Medium)
  - **Teks Utama / Item List / Input:** `14px` | Weight: `400` (Regular)
  - **Teks Kecil / Label / Tanggal / Tab:** `12px` | Weight: `400` (Regular) atau `500` (Medium untuk Tab)

---

## 4. Layout & Struktur Grid
Menggunakan pendekatan Bento Box atau Masonry Grid yang rapi.

- **Lebar Maksimal Container:** `1440px` (dengan margin auto di tengah untuk layar sangat lebar).
- **Sidebar Kiri:** Fix width `260px`, padding dalam `24px`. Tinggi `100vh` atau menyesuaikan kontainer dengan margin luar `16px`.
- **Area Konten Utama:** Mengambil sisa ruang (menggunakan CSS Grid `1fr`).
- **Jarak Antar Elemen (Gap):** Konsisten menggunakan `24px` antar kartu, dan `16px` untuk elemen di dalam kartu.
- **Padding Kartu (Inner Padding):** `24px` rata di semua sisi.
- **Perilaku Responsif (Mobile & Tablet):**
  - **< 1024px (Tablet):** Sidebar mengecil jadi hanya ikon (lebar `80px`). Grid utama berubah dari 3 kolom/2 kolom menjadi 2 kolom penuh.
  - **< 768px (Mobile):** Sidebar hilang, berubah menjadi *Bottom Navigation Bar*. Grid utama menjadi 1 kolom tumpuk ke bawah (100% width).

---

## 5. Spesifikasi Komponen

- **Kartu (Cards):**
  - `border-radius: 24px;`
  - `background-color: var(--bg-card);`
  - `border: 1px solid var(--border-color);`
  - `box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);`
- **Tombol Pill (Pill Buttons & Tabs):**
  - `border-radius: 9999px;` (bulat sempurna di ujung)
  - Padding: `8px 16px` untuk tab, `12px 24px` untuk tombol utama.
  - State Inaktif: Background transparan, border `1px solid var(--border-color)`, warna teks sekunder.
  - State Aktif: Background `var(--accent-gradient)`, border none, warna teks `#FFFFFF`, box-shadow/glow: `0 0 16px var(--accent-glow)`.
- **Item Menu Sidebar:**
  - `border-radius: 12px;`
  - Padding: `12px 16px;`
  - Transisi: `all 0.3s ease;`
  - State Hover: Background `rgba(255,255,255, 0.03)`
  - State Aktif: Memiliki background radial/linear gradien ungu pudar dengan border halus (seperti tombol Dashboard di gambar).
- **Grafik Area (Area Chart - Arus Kas):**
  - Garis (Stroke): Menggunakan warna gradien aksen (tebal `2px` - `3px`), kurva sangat halus (*smooth/spline curve*).
  - Fill (Area): Gradien linear vertikal dari `rgba(212, 165, 201, 0.2)` di atas memudar menjadi transparan `rgba(212, 165, 201, 0)` di bawah.
  - Tooltip Hover: Kotak kecil membulat (`radius: 8px`), background sangat gelap (`#000000` dengan opacity `80%`), teks putih bersih. Harus ada titik (*dot*) terang/glowing di garis saat di-hover.
- **Daftar Transaksi (List Items):**
  - Berjejer vertikal, padding vertikal `12px`, dipisahkan oleh garis batas transparan (`border-bottom`).
  - Flexbox space-between: Kiri (Ikon + Kategori + Tanggal), Kanan (Nominal).

---

## 6. Design Tokens (CSS Variables :root)
Salin kode berikut ke dalam file CSS:

```css
:root {
  /* Colors */
  --bg-body: #0F0C13;
  --bg-card: #17141D;
  --bg-card-hover: #1E1A26;
  --border-color: rgba(255, 255, 255, 0.06);
  --divider: rgba(255, 255, 255, 0.03);
  
  /* Text Colors */
  --text-main: #F8F7FA;
  --text-muted: #A19DAB;
  --text-accent: #D4A5C9;
  
  /* Status Colors */
  --color-income: #34D399;
  --color-expense: #FB7185;
  
  /* Accent & Gradients */
  --accent-primary: #D4A5C9;
  --accent-gradient: linear-gradient(135deg, #D4A5C9 0%, #9D759B 100%);
  --accent-glow: rgba(212, 165, 201, 0.15);
  
  /* Typography */
  --font-family: 'Plus Jakarta Sans', sans-serif;
  
  /* Spacing & Radii */
  --radius-card: 24px;
  --radius-pill: 9999px;
  --radius-sm: 12px;
  --gap-main: 24px;
  --pad-card: 24px;
}
```

---

## 7. Adaptasi Konten Menjadi Money Tracker
Transformasi dari gambar (Investasi) ke Finansial Pribadi:

1. **Header Kiri Atas (Helios Investments):** Ubah menjadi "MoneyTracker" dengan ikon dompet atau grafik naik SVG yang minimalis.
2. **Total Holding:** Ubah menjadi **"Total Saldo"**. Angkanya adalah gabungan dari semua akun (Misal: Rp 45.500.000). Filter sebelahnya (6M, 1Y) biarkan untuk memfilter perhitungan saldo per periode.
3. **Decisions Powered by Data (AI Insights):** Ubah menjadi **"Insight & Tips"**. Isinya: "Pengeluaran makan Anda naik 15% bulan ini. Yuk, kurangi jajan di luar!" Tombolnya menjadi "Lihat Rincian".
4. **Watchlist:** Ubah menjadi **"Transaksi Terbaru"**. 
   - Tab "Gain/Lose" ubah menjadi "Semua", "Masuk", "Keluar".
   - Logo perusahaan ganti dengan ikon kategori berformat SVG sederhana (misal: 🍔 untuk Makan, 🛒 untuk Belanja, ⚡ untuk Tagihan, 🚗 untuk Transport).
   - Teks ticker saham ganti dengan Nama Transaksi dan Kategori.
5. **My Portfolio:** Ubah menjadi **"Ringkasan Finansial"**.
   - Berisi grid kecil kartu untuk: "Pemasukan", "Pengeluaran", dan "Tabungan/Sisa".
   - Tampilkan nominal uang dan persentase perbandingan dengan bulan lalu (contoh: hijau `+12%`, merah `-5%`).
6. **Portfolio Performance (Grafik Bawah):** Ubah menjadi **"Arus Kas (Cash Flow)"**.
   - Menggambarkan grafik garis naik turun dari Saldo Harian atau perbandingan Pemasukan vs Pengeluaran dalam sebulan terakhir.

---

## 8. Format Angka & Tanggal (Lokalisasi Indonesia)
- **Mata Uang:** Harus ada spasi antara "Rp" dan angka. Gunakan titik sebagai pemisah ribuan. Tanpa desimal koma nol nol (`,00`) agar bersih.
  - *Benar:* `Rp 12.500.000`
  - *Salah:* `Rp12500000`, `Rp. 12.500.000,00`, `IDR 12,500,000`
- **Persentase:** Gunakan tanda plus atau minus.
  - *Income:* `+15,2%` (warna `--color-income`)
  - *Expense:* `-4,8%` (warna `--color-expense`)
- **Tanggal:** Format Indonesia yang ringkas (Contoh: `9 Okt 2026`, `12 Nov 2026`).

---

## 9. Panduan Kritis: Menghindari Tampilan "Generik AI"
Agar hasil akhir murni tidak terlihat murahan atau seperti *template* Bootstrap/Tailwind bawaan, AI coding agent **HARUS** memperhatikan ini:
1. **Jangan Gunakan Hitam Pekat:** Jangan pernah memakai `#000` atau abu-abu standar `#333`. Kunci elegan di desain ini adalah kombinasi gelap ungu-abu (`#17141D`).
2. **Tingkat Transparansi (Opacity):** Keindahan desain ini bergantung pada border yang nyaris tak terlihat. Border kartu maksimal 5-8% opacity putih (`rgba(255,255,255,0.06)`). Jangan membuat border tebal yang kaku.
3. **Hierarchy yang Ketat:** Jangan cetak tebal (bold) semua teks! Hanya angka saldo utama (H1) yang mendapat *Bold*. Judul kartu *Medium*, dan teks sekunder **wajib** menggunakan warna redup (`--text-muted`) dan *Regular weight*. Kontras ini krusial.
4. **Bentuk (Shapes):** Patuhi `border-radius: 24px` untuk kartu besar. Ini memberikan kesan organik. Sudut tajam akan menghancurkan estetika dari referensi gambar.
5. **Bayangan (Shadows):** Jangan gunakan *drop shadow* terang. Gunakan shadow gelap luas (`0 8px 32px rgba(0, 0, 0, 0.2)`) untuk memberikan dimensi kedalaman, seakan kartu mengambang di atas background.