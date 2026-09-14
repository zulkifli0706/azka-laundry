<?php
require_once __DIR__ . '/includes/config.php';

// Ambil kategori & item layanan (dipakai untuk kalkulator bergaya katalog: pilih kategori → daftar barang)
$kategori_items = $pdo->query("
    SELECT k.id_kategori as kategori_id, k.nama_kategori, k.deskripsi_kategori,
           i.id_barang as item_id, i.nama_item, i.detail_perawatan,
           i.harga, i.satuan, i.estimasi_hari
    FROM kategori_barang k
    JOIN detail_barang i ON i.kategori_id = k.id_kategori
    WHERE k.is_aktif = 1 AND i.is_aktif = 1
    ORDER BY k.urutan, i.urutan
")->fetchAll();

// Group by kategori
$katalog = [];
foreach ($kategori_items as $row) {
    $katalog[$row['kategori_id']]['nama'] = $row['nama_kategori'];
    $katalog[$row['kategori_id']]['deskripsi'] = $row['deskripsi_kategori'];
    $katalog[$row['kategori_id']]['items'][] = $row;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Estimasi dan Pesan Online — Azka Laundry</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
<style>
:root {
    /* ── Tema: Biru Langit & Putih ── */
    --sky:        #4E90D9;
    --sky-deep:   #2E74C0;
    --sky-ink:    #164A85;
    --sky-soft:   #EAF2FB;
    --sky-pale:   #EAF2FB;
    --cloud:      #FFFFFF;
    --border:     #D6E7F7;
    --ink:        #164A85;
    --gray:       #2E74C0;
    --gray-soft:  #4E90D9;
}
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Plus Jakarta Sans', sans-serif; background:var(--sky-pale); color:#101418; overflow-x:hidden; }
::selection { background:var(--sky); color:#FFFFFF; }

/* ── NAVBAR ── */
nav { background:rgba(255,255,255,0.85); backdrop-filter:blur(10px); position:sticky; top:0; z-index:99; border-bottom:1px solid var(--border); }
.nav-inner { max-width:1320px; margin:auto; padding:14px 32px; display:flex; justify-content:space-between; align-items:center; position:relative; }
.logo { display:flex; align-items:center; text-decoration:none; }
.logo svg { height:56px; width:auto; display:block; }
.logo .logo-compact { display:none; height:40px; }
.nav-menu { display:flex; gap:6px; align-items:center; }
.nav-menu a { text-decoration:none; color:#3A4450; font-size:14px; font-weight:500; padding:8px 14px; border-radius:8px; transition:all 0.2s; }
.nav-menu a:hover { color:#101418; background:var(--sky-soft); }
.nav-menu a.active { color:#101418; background:var(--sky-soft); font-weight:700; }
.nav-menu .btn-login { background:var(--sky); color:#FFFFFF !important; padding:9px 20px; border-radius:30px; font-weight:700; }
.nav-menu .btn-login:hover { background:var(--sky-deep); }
/* Tombol hamburger — hanya tampil di layar sempit */
.nav-toggle {
    display:none; width:38px; height:38px; border-radius:10px;
    background:var(--sky-soft); border:none; cursor:pointer;
    align-items:center; justify-content:center; flex-shrink:0;
}
.nav-toggle span, .nav-toggle span::before, .nav-toggle span::after {
    content:''; display:block; width:18px; height:2px; background:var(--sky-ink);
    border-radius:2px; position:relative; transition:all 0.2s;
}
.nav-toggle span::before { position:absolute; top:-6px; }
.nav-toggle span::after { position:absolute; top:6px; }

/* ── PAGE HEADER (versi ringkas, hanya identifikasi kecil) ── */
.page-header-mini {
    background:linear-gradient(180deg, #EAF2FB 0%, var(--sky-pale) 100%);
    padding:20px 24px; display:flex; align-items:center; justify-content:center;
    text-align:center;
    position:relative; overflow:visible;
}
.phm-inner { position:relative; display:inline-flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:center; }
.page-header-mini .phm-chip {
    display:inline-block; background:var(--cloud); border:1px solid var(--border);
    color:var(--sky-deep); font-size:11px; font-weight:700; padding:5px 14px;
    border-radius:30px; letter-spacing:0.4px; white-space:nowrap;
}
.page-header-mini h1 {
    font-family:'Fraunces', serif; font-weight:600; font-size:17px;
    color:#101418; margin:0; letter-spacing:-0.2px;
}

/* Kartu kecil melayang + gelembung dekoratif — ditempel ke KOTAK JUDUL (.phm-inner),
   bukan ke lebar section penuh, supaya selalu nempel & jelas kelihatan dekat judul,
   di layar lebar berapa pun (tidak lagi "kabur" ke ujung layar). Posisinya absolute
   jadi tidak menambah tinggi halaman -- kalkulator tetap langsung kelihatan. */
.phm-float-chip {
    position:absolute; top:50%; display:flex; align-items:center; gap:7px;
    background:var(--cloud); border:1.5px solid var(--sky); border-radius:30px;
    padding:7px 14px 7px 7px; font-size:12px; font-weight:700; color:#101418;
    box-shadow:0 10px 22px rgba(22,74,133,0.18); white-space:nowrap; z-index:2;
    animation:phmBob 4.5s ease-in-out infinite;
}
.phm-float-chip .pfc-ico {
    width:22px; height:22px; border-radius:50%; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    background:var(--sky); color:#FFFFFF;
}
.phm-float-chip .pfc-ico svg { width:12px; height:12px; }
.phm-chip-left  { right:100%; top:0; margin-right:18px; transform:translateY(-8px); animation-delay:0s; }
.phm-chip-right { left:100%; top:0; margin-left:18px; transform:translateY(14px); animation-delay:1.4s; }
.phm-bubble {
    position:absolute; border-radius:50%; background:var(--sky);
    opacity:0.16; pointer-events:none; animation:phmDrift 6s ease-in-out infinite;
}
@keyframes phmBob { 0%, 100% { margin-top:0; } 50% { margin-top:-8px; } }
@keyframes phmDrift { 0%, 100% { transform:translateY(0) scale(1); } 50% { transform:translateY(-10px) scale(1.08); } }
@media (prefers-reduced-motion: reduce) {
    .phm-float-chip, .phm-bubble { animation:none; }
}
@media (max-width: 900px) {
    .phm-float-chip, .phm-bubble { display:none; }
}

/* ── SECTION ── */
section { padding:24px 24px 64px; }
.section-inner { max-width:1140px; margin:auto; }

/* ── KALKULATOR ── */
.kalk-wrap {
    background:var(--sky-pale); border-radius:24px;
    border:1px solid var(--border); padding:8px;
    max-width:980px; margin:0 auto;
    box-shadow:0 8px 32px rgba(78,144,217,0.1);
}
.kalk-grid { display:grid; grid-template-columns:210px 1.2fr 1fr; gap:8px; }
.kalk-col { background:var(--cloud); border-radius:18px; padding:26px; min-width:0; }
.kalk-col-kategori { padding:24px 12px; }
.kalk-wrap h3 { font-family:'Fraunces', serif; font-weight:600; font-size:20px; color:#101418; margin-bottom:6px; }
.kalk-wrap h4 { font-family:'Fraunces', serif; font-weight:600; font-size:16px; color:#101418; margin-bottom:14px; }
.kalk-wrap .sub { font-size:13.5px; color:#3A4450; margin-bottom:24px; }

/* Pilih kategori — kolom vertikal di desktop */
.kalk-kat-list { display:flex; flex-direction:column; gap:6px; }
.kalk-kat-tab {
    flex-shrink:0; border:1.5px solid var(--border); border-radius:12px;
    padding:10px 14px; background:var(--cloud); color:#3A4450;
    font-size:13px; font-weight:700; cursor:pointer; white-space:normal;
    overflow-wrap:break-word; word-break:break-word; line-height:1.35;
    text-align:left; width:100%; transition:all 0.18s;
}
.kalk-kat-tab:hover { border-color:var(--sky); color:#101418; transform:translateY(-1px); }
.kalk-kat-tab.active { background:var(--sky-ink); border-color:var(--sky-ink); color:#FFFFFF; }

/* Pencarian barang */
.kalk-search-wrap { position:relative; margin-bottom:16px; }
.kalk-search-wrap .kalk-search-ico {
    position:absolute; left:14px; top:50%; transform:translateY(-50%);
    color:#4B5563; font-size:14px; pointer-events:none;
}
.kalk-search-input {
    width:100%; padding:11px 16px 11px 38px;
    border:1.5px solid var(--border); border-radius:12px;
    font-size:13.5px; font-family:inherit; outline:none;
    background:var(--sky-pale); color:#101418; transition:all 0.18s;
}
.kalk-search-input:focus { border-color:var(--sky); background:var(--cloud); box-shadow:0 0 0 3px rgba(78,144,217,0.12); }
.kalk-search-input::placeholder { color:#4B5563; }
.kalk-no-result { text-align:center; padding:32px 16px; color:#4B5563; font-size:13.5px; border:2px dashed var(--border); border-radius:14px; }

/* Katalog barang per kategori */
.kalk-catalog { display:flex; flex-direction:column; gap:10px; max-height:500px; overflow-y:auto; padding-right:4px; }
.kalk-catalog-desc { font-size:12px; color:#4B5563; margin:-4px 0 4px; line-height:1.5; }
.kalk-catalog-item {
    display:flex; align-items:center; gap:14px;
    border:1.5px solid var(--border); border-radius:14px;
    padding:13px 14px; background:var(--cloud);
    transition:all 0.18s;
}
.kalk-catalog-item:hover { border-color:var(--sky); background:var(--sky-soft); transform:translateY(-2px); box-shadow:0 6px 16px rgba(78,144,217,0.14); }
.kalk-catalog-item.selected { border-color:var(--sky); background:var(--sky-soft); box-shadow:0 3px 10px rgba(78,144,217,0.18); }
.kci-info { flex:1; min-width:0; }
.kci-nama { font-size:13.5px; font-weight:700; color:#101418; overflow-wrap:break-word; }
.kci-detail { font-size:11.5px; color:#4B5563; line-height:1.5; margin-top:2px; overflow-wrap:break-word; }
.kci-harga { font-size:12.5px; color:var(--sky-deep); font-weight:600; margin-top:4px; overflow-wrap:break-word; word-break:break-word; }
.kci-harga span { color:#4B5563; font-weight:400; }
.kci-estimasi { font-size:11px; color:#4B5563; margin-top:3px; display:flex; align-items:center; gap:4px; }
.kci-estimasi .dot { color:var(--border); }
.kci-add {
    flex-shrink:0; width:36px; height:36px; border-radius:50%;
    border:1.5px solid var(--sky); background:#FFFFFF; color:#101418;
    font-size:18px; font-weight:700; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:all 0.18s;
}
.kci-add:hover { background:var(--sky); color:#FFFFFF; }
.kci-add .ico-check { display:none; }
.kalk-catalog-item.selected .kci-add { background:var(--sky-ink); border-color:var(--sky-ink); color:#FFFFFF; }
.kalk-catalog-item.selected .kci-add .ico-plus { display:none; }
.kalk-catalog-item.selected .kci-add .ico-check { display:inline; }

/* Item qty rows */
.kalk-items { margin-bottom:0; }
.kalk-item-row {
    display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between;
    gap:8px 10px; padding:13px 14px; background:var(--sky-pale);
    border-radius:12px; margin-bottom:8px;
    border:1px solid var(--border);
}
/* item-info selalu mengambil satu baris penuh (flex-basis 100%) supaya nama & harga
   tidak pernah diperas jadi lebar mendekati 0 oleh saudara-saudaranya yang non-shrink
   (qty-ctrl, item-subtotal, remove-btn) -- itu penyebab teks pecah per-huruf secara vertikal. */
.kalk-item-row .item-info { flex:1 1 100%; min-width:0; }
.kalk-item-row .item-info .i-name { font-size:13px; font-weight:700; color:#101418; overflow-wrap:break-word; word-break:break-word; }
.kalk-item-row .item-info .i-price { font-size:11.5px; color:#3A4450; white-space:nowrap; margin-top:1px; }
.kalk-item-row .item-info .i-estimasi { font-size:11px; color:var(--sky-deep); font-weight:700; margin-top:2px; }
.kalk-item-row .qty-ctrl { display:flex; align-items:center; gap:6px; }
.qty-btn {
    width:28px; height:28px; border-radius:8px;
    border:1px solid var(--border); background:var(--cloud);
    font-size:16px; font-weight:600; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    color:#101418; transition:all 0.15s; flex-shrink:0;
}
.qty-btn:hover { background:var(--sky); color:#FFFFFF; border-color:var(--sky); }
.qty-input {
    width:50px; text-align:center; padding:5px;
    border:1px solid var(--border); border-radius:8px;
    font-size:13px; font-weight:600; color:#101418;
    font-family:inherit; outline:none; background:var(--cloud);
}
.qty-input:focus { border-color:var(--sky); }
.kalk-item-row .item-subtotal { font-size:13px; font-weight:700; color:var(--sky-deep); min-width:84px; text-align:right; flex-shrink:0; }
.remove-btn { background:none; border:none; cursor:pointer; color:#3A4450; font-size:16px; padding:4px 6px; border-radius:6px; transition:background 0.15s; flex-shrink:0; }
.remove-btn:hover { background:#EAF2FB; }

/* Empty state */
.kalk-empty {
    text-align:center; padding:36px 20px;
    color:#4B5563; font-size:13.5px;
    border:2px dashed var(--border); border-radius:14px;
}

/* Ringkasan total */
.kalk-estimasi-box {
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    background:var(--sky-soft); border:1px solid var(--border); border-radius:12px;
    padding:12px 14px; margin-bottom:14px;
}
.kalk-estimasi-label { font-size:12.5px; font-weight:700; color:var(--sky-ink); display:flex; align-items:center; gap:6px; }
.kalk-estimasi-value { font-size:13px; font-weight:800; color:var(--sky-ink); text-align:right; display:inline-block; }
.kalk-estimasi-hint { font-size:11px; color:#4B5563; margin:-8px 0 14px; line-height:1.5; }
.kalk-summary-rows { margin-bottom:14px; }
.kalk-sum-row { display:flex; justify-content:space-between; font-size:13px; color:#3A4450; padding:5px 0; }
.kalk-sum-row.total { display:flex; justify-content:space-between; align-items:baseline; font-size:24px; font-weight:800; color:#101418; padding-top:14px; margin-top:6px; border-top:1px solid var(--border); }
.kalk-sum-row.total .sum-label { color:#3A4450; font-size:13px; font-weight:600; }
.kalk-sum-row.total span:last-child { font-family:'Fraunces', serif; display:inline-block; }

/* Pulsa singkat tiap kali total/estimasi berubah — memberi kesan angkanya "hidup"/reaktif */
.kalk-pulse { animation:kalkPulse 0.35s ease-out; }
@keyframes kalkPulse { 0% { transform:scale(1); } 40% { transform:scale(1.12); color:var(--sky-deep); } 100% { transform:scale(1); } }
@media (prefers-reduced-motion: reduce) {
    .kalk-pulse { animation:none; }
}
.kalk-note { font-size:11.5px; color:#4B5563; margin-top:10px; line-height:1.5; }
.kalk-reset {
    margin-top:16px; width:100%; padding:11px;
    background:var(--cloud); color:var(--sky-deep);
    border:1.5px solid var(--sky); border-radius:10px;
    font-size:13px; font-weight:700; font-family:inherit;
    cursor:pointer; transition:all 0.2s;
}
.kalk-reset:hover { background:var(--sky-soft); border-color:var(--sky-deep); }

.kalk-pesan-btn {
    margin-top:10px; width:100%; padding:13px;
    background:var(--sky); color:#FFFFFF; border:none; border-radius:10px;
    font-size:14px; font-weight:700; font-family:inherit;
    cursor:pointer; transition:all 0.2s; box-shadow:0 4px 14px rgba(78,144,217,0.3);
}
.kalk-pesan-btn:hover { background:var(--sky-deep); }

/* ── MODAL PESAN ONLINE ── */
.pesan-overlay { display:none; position:fixed; inset:0; background:rgba(22,74,133,0.5); z-index:999; align-items:center; justify-content:center; padding:16px; }
.pesan-overlay.active { display:flex; }
.pesan-box { background:#FFFFFF; border-radius:16px; padding:26px; max-width:400px; width:100%; }
.pesan-box h4 { font-size:17px; font-weight:800; color:#101418; margin-bottom:4px; }
.pesan-box .pesan-sub { font-size:12.5px; color:#4B5563; margin-bottom:18px; line-height:1.5; }
.pesan-field { margin-bottom:14px; }
.pesan-field label { display:block; font-size:12.5px; font-weight:700; color:#3A4450; margin-bottom:5px; }
.pesan-field input { width:100%; padding:11px 13px; border:1px solid var(--border); border-radius:9px; font-size:14px; font-family:inherit; outline:none; }
.pesan-field input:focus { border-color:var(--sky); }
.pesan-btns { display:flex; gap:10px; margin-top:18px; }
.pesan-btns button { flex:1; padding:12px; border-radius:9px; font-size:13.5px; font-weight:700; font-family:inherit; cursor:pointer; border:none; }
.pesan-btn-batal { background:var(--sky-pale); color:#3A4450; }
.pesan-btn-kirim { background:var(--sky); color:#FFFFFF; }
.pesan-btn-kirim:disabled { opacity:0.6; cursor:not-allowed; }
.pesan-error { color:#C0392B; font-size:12px; margin-top:10px; display:none; }

/* ── MODAL SUKSES (setelah pesanan tersimpan) ── */
.pesan-sukses-icon { width:52px; height:52px; border-radius:50%; background:var(--sky-soft); color:var(--sky-deep); display:flex; align-items:center; justify-content:center; font-size:24px; margin:0 auto 14px; }
.pesan-kode { text-align:center; font-size:20px; font-weight:800; color:var(--sky-deep); letter-spacing:0.5px; margin:6px 0 14px; }

.pesan-btn-salin {
    display:flex; align-items:center; justify-content:center; gap:7px;
    width:100%; margin:0 0 16px; padding:10px;
    background:var(--sky-pale); border:1px solid var(--border); border-radius:9px;
    color:var(--sky-deep); font-size:12.5px; font-weight:700; font-family:inherit;
    cursor:pointer; transition:all 0.15s;
}
.pesan-btn-salin:hover { background:var(--sky-soft); }
.pesan-btn-salin.tersalin { background:#E3F5E9; border-color:#B9E4C7; color:#1E7A43; }
.pesan-instruksi { background:var(--sky-pale); border-radius:10px; padding:14px 16px; font-size:12.5px; color:#3A4450; line-height:1.7; margin-bottom:6px; }

.pesan-timer { text-align:center; font-size:14px; font-weight:800; color:#B8791A; background:#FFF7E6; border:1px solid #F5D896; border-radius:9px; padding:9px; margin:12px 0 6px; }
.pesan-timer.pesan-timer-urgent { color:#B8302B; background:#FBEAEA; border-color:#F0B8B8; }
.pesan-timer-note { font-size:11px; color:#8A94A3; text-align:center; margin-bottom:4px; }

.btn { padding:13px 28px; border-radius:30px; font-size:14px; font-weight:700; text-decoration:none; font-family:inherit; cursor:pointer; border:none; transition:all 0.2s; display:inline-flex; align-items:center; gap:8px; }
.btn-primary { background:var(--sky); color:#FFFFFF; box-shadow:0 4px 14px rgba(78,144,217,0.35); }
.btn-primary:hover { background:var(--sky-deep); transform:translateY(-1px); box-shadow:0 6px 18px rgba(78,144,217,0.4); }

/* ── FOOTER ── */
footer { background:linear-gradient(135deg, var(--sky-ink), var(--sky-deep)); color:rgba(255,255,255,0.8); padding:44px 24px; text-align:center; }
footer .logo-f { font-family:'Fraunces', serif; font-weight:600; font-size:22px; color:#FFFFFF; margin-bottom:8px; }
footer p { font-size:13px; margin-bottom:4px; }
footer .copy { font-size:12px; color:rgba(255,255,255,0.45); margin-top:20px; }

/* ── RESPONSIVE ── */
@media (max-width: 860px) {
    .page-header-mini h1 { font-size:15px; }
    .kalk-grid { grid-template-columns:1fr; }
    /* Navbar: link disembunyikan ke dalam menu dropdown (hamburger) supaya tidak sesak/berjejer terpotong */
    .nav-toggle { display:flex; }
    .nav-menu {
        display:none; position:absolute; top:100%; left:16px; right:16px;
        flex-direction:column; align-items:stretch; gap:3px;
        background:var(--cloud); border:1px solid var(--border); border-radius:16px;
        padding:10px; margin-top:8px; box-shadow:0 16px 32px rgba(78,144,217,0.18);
    }
    .nav-menu.open { display:flex; }
    .nav-menu a:not(.btn-login) { width:100%; padding:12px 14px; font-size:14.5px; border-radius:10px; }
    .nav-menu .btn-login { width:100%; text-align:center; padding:12px; margin-top:4px; }
    /* Kategori jadi baris horizontal yang bisa discroll di layar sempit */
    .kalk-col-kategori { padding:16px; }
    .kalk-kat-list {
        flex-direction:row; overflow-x:auto; -webkit-overflow-scrolling:touch;
        scrollbar-width:thin; padding-bottom:4px;
    }
    .kalk-kat-list::-webkit-scrollbar { height:5px; }
    .kalk-kat-list::-webkit-scrollbar-thumb { background:var(--border); border-radius:10px; }
    .kalk-kat-list .kalk-kat-tab { border-radius:999px; width:auto; flex-shrink:0; white-space:nowrap; text-align:center; }
    .kalk-catalog-item { border-color:var(--sky); box-shadow:0 2px 8px rgba(78,144,217,0.1); }
    /* Sembunyikan deskripsi barang & kategori di layar sempit — biar kartu ringkas, tidak melebar/geser ke samping */
    .kci-detail, .kalk-catalog-desc { display:none; }
}

@media (max-width: 480px) {
    .nav-inner { padding:10px 14px; }
    .logo .logo-full { display:none; }
    .logo .logo-compact { display:block; }
    .nav-menu { left:10px; right:10px; }
    .page-header-mini { padding:10px 14px; gap:6px; }
    .page-header-mini h1 { font-size:13.5px; }
    .page-header-mini .phm-chip { font-size:10px; padding:4px 10px; }
    section { padding:14px 10px 36px; }
    .kalk-wrap { padding:6px; border-radius:18px; }
    .kalk-col { padding:14px; }
    .kalk-col-kategori { padding:12px; }
    .kalk-catalog-item { padding:11px 12px; gap:10px; }
    .kci-nama, .kalk-wrap .sub { overflow-wrap:break-word; word-break:break-word; }
    .kalk-search-input { font-size:13px; padding:10px 14px 10px 34px; }
    .kalk-item-row { flex-wrap:wrap; gap:8px; }
    .kalk-item-row .item-subtotal { min-width:auto; }
    .kalk-sum-row.total { font-size:19px; }
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav>
  <div class="nav-inner">
    <a href="index.php" class="logo">
      <svg class="logo-full" viewBox="0 0 640 160" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="badgeGrad2" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#4E90D9"/>
            <stop offset="100%" stop-color="#2E74C0"/>
          </linearGradient>
        </defs>
        <circle cx="60" cy="60" r="48" fill="url(#badgeGrad2)"/>
        <g transform="translate(60,60)">
          <circle cx="0" cy="2" r="22" fill="none" stroke="#FFFFFF" stroke-width="4"/>
          <circle cx="0" cy="4" r="12" fill="none" stroke="#FFFFFF" stroke-width="3.5"/>
          <rect x="-9" y="-26" width="18" height="7" rx="3.5" fill="#FFFFFF"/>
        </g>
        <text x="138" y="70" font-family="'Fraunces', Georgia, serif" font-weight="700" font-size="42" fill="#164A85">Azka Laundry</text>
        <text x="139" y="94" font-family="'Plus Jakarta Sans', Arial, sans-serif" font-weight="500" font-size="15" fill="#4E90D9" letter-spacing="0.3">Bersih &middot; Rapih &middot; Wangi</text>
      </svg>
      <svg class="logo-compact" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="badgeGradM" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#4E90D9"/>
            <stop offset="100%" stop-color="#2E74C0"/>
          </linearGradient>
        </defs>
        <circle cx="60" cy="60" r="58" fill="url(#badgeGradM)"/>
        <g transform="translate(60,62)">
          <circle cx="0" cy="2" r="26" fill="none" stroke="#FFFFFF" stroke-width="5"/>
          <circle cx="0" cy="5" r="14" fill="none" stroke="#FFFFFF" stroke-width="4.5"/>
          <rect x="-11" y="-31" width="22" height="8" rx="4" fill="#FFFFFF"/>
        </g>
      </svg>
    </a>
    <div class="nav-menu" id="navMenu">
      <a href="index.php">Beranda</a>
      <a href="estimasi.php" class="active">Estimasi dan Pesan</a>
      <a href="cek_status.php">Cek Cucian</a>
      <a href="index.php#kontak">Kontak</a>
      <a href="login.php" class="btn-login">Login Pegawai</a>
    </div>
    <button type="button" class="nav-toggle" id="navToggle" onclick="document.getElementById('navMenu').classList.toggle('open')" aria-label="Buka menu">
      <span></span>
    </button>
  </div>
</nav>

<!-- PAGE HEADER (versi ringkas — hanya identifikasi kecil, agar kalkulator langsung terlihat tanpa scroll di PC/laptop) -->
<div class="page-header-mini">
  <!-- Gelembung melayang -- dekorasi absolute, tidak menambah tinggi halaman -->
  <span class="phm-bubble" style="width:20px;height:20px;top:8px;left:20%;"></span>
  <span class="phm-bubble" style="width:13px;height:13px;top:34px;left:36%;animation-delay:1.8s"></span>
  <span class="phm-bubble" style="width:16px;height:16px;top:10px;right:22%;animation-delay:0.9s"></span>

  <div class="phm-inner">
    <!-- Kartu kecil melayang, nempel tepat di kiri/kanan judul -->
    <div class="phm-float-chip phm-chip-left">
      <span class="pfc-ico">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>
      </span>
      Hitung Otomatis
    </div>
    <div class="phm-float-chip phm-chip-right">
      <span class="pfc-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12l5 5L20 6"/></svg>
      </span>
      Harga Transparan
    </div>

    <span class="phm-chip">Kalkulator</span>
    <h1>Estimasi dan Pesan Online</h1>
  </div>
</div>

<!-- KALKULATOR -->
<section style="padding-top:14px">
  <div class="section-inner">
    <div class="kalk-wrap">
      <div class="kalk-grid">

        <!-- Kolom 1: daftar kategori -->
        <div class="kalk-col kalk-col-kategori">
          <h4>Kategori</h4>
          <div class="kalk-kat-list" id="kalkKategoriTabs">
            <?php $noKat = 0; foreach ($katalog as $katId => $kat): $noKat++; ?>
            <button type="button"
                    class="kalk-kat-tab<?= $noKat === 1 ? ' active' : '' ?>"
                    data-kat="<?= $katId ?>"
                    onclick="pilihKategori(<?= $katId ?>)">
              <?= htmlspecialchars($kat['nama']) ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Kolom 2: pencarian + katalog barang dari kategori terpilih -->
        <div class="kalk-col">
          <h3>Pilih Barang</h3>
          <p class="sub">Pilih kategori di sebelah kiri, atau cari nama barang, lalu klik <strong>+</strong> untuk menambahkannya</p>

          <div class="kalk-search-wrap">
            <span class="kalk-search-ico">🔍</span>
            <input type="text" id="kalkSearch" class="kalk-search-input"
                   placeholder="Cari nama barang, contoh: sepatu, bed cover..."
                   oninput="filterKatalog(this.value)" autocomplete="off">
          </div>

          <div class="kalk-catalog" id="kalkCatalog">
            <?php $noKat = 0; foreach ($katalog as $katId => $kat): $noKat++; ?>
            <div class="kalk-catalog-group" data-kat="<?= $katId ?>" <?= $noKat === 1 ? '' : 'style="display:none"' ?>>
              <?php if (!empty($kat['deskripsi'])): ?>
              <p class="kalk-catalog-desc"><?= htmlspecialchars($kat['deskripsi']) ?></p>
              <?php endif; ?>
              <?php foreach ($kat['items'] as $item): ?>
              <div class="kalk-catalog-item"
                   data-id="<?= $item['item_id'] ?>"
                   data-nama="<?= htmlspecialchars($item['nama_item']) ?>"
                   data-harga="<?= $item['harga'] ?>"
                   data-satuan="<?= htmlspecialchars($item['satuan']) ?>"
                   data-estimasi="<?= (int) $item['estimasi_hari'] ?>">
                <div class="kci-info">
                  <div class="kci-nama"><?= htmlspecialchars($item['nama_item']) ?></div>
                  <?php if (!empty($item['detail_perawatan'])): ?>
                  <div class="kci-detail"><?= htmlspecialchars($item['detail_perawatan']) ?></div>
                  <?php endif; ?>
                  <div class="kci-harga"><?= formatRupiah($item['harga']) ?> <span>/ <?= htmlspecialchars($item['satuan']) ?></span></div>
                  <div class="kci-estimasi">⏱ Estimasi <?= (int) $item['estimasi_hari'] ?> hari</div>
                </div>
                <button type="button" class="kci-add" onclick="toggleService(this.closest('.kalk-catalog-item'))" aria-label="Tambah <?= htmlspecialchars($item['nama_item']) ?>">
                  <span class="ico-plus">+</span>
                  <span class="ico-check">✓</span>
                </button>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="kalk-no-result" id="kalkNoResult" style="display:none">Barang tidak ditemukan. Coba kata kunci lain.</div>
        </div>

        <!-- Kolom 3: keranjang + ringkasan -->
        <div class="kalk-col">
          <h3>Rincian & Total</h3>
          <p class="sub">Atur jumlah kg / pcs sesuai kebutuhan</p>

          <div id="kalkItemsWrapper">
            <div class="kalk-empty">
              <div>Belum ada barang dipilih.<br>Klik barang di sebelah kiri untuk mulai menghitung.</div>
            </div>
          </div>

          <div class="kalk-summary" id="kalkSummary" style="display:none; margin-top:18px;">
            <div class="kalk-estimasi-box" id="kalkEstimasiBox">
              <span class="kalk-estimasi-label">⏱ Estimasi Selesai</span>
              <span class="kalk-estimasi-value" id="kalkEstimasiValue">-</span>
            </div>
            <p class="kalk-estimasi-hint" id="kalkEstimasiHint"></p>
            <div class="kalk-summary-rows" id="kalkSummaryRows"></div>
            <div class="kalk-sum-row total">
              <span class="sum-label">Estimasi Total</span>
              <span id="kalkTotal">Rp 0</span>
            </div>
            <p class="kalk-note">* Estimasi dihitung dari harga minimum tiap kategori barang — harga aktual mengikuti kondisi &amp; ukuran barang saat dicek langsung oleh kasir.</p>
            <button class="kalk-pesan-btn" onclick="bukaModalPesan()">Pesan Sekarang</button>
            <button class="kalk-reset" onclick="resetKalkulator()">Reset Kalkulator</button>
          </div>
        </div>

      </div>
    </div>

    <div style="text-align:center; margin-top:28px">
      <a href="index.php" class="btn btn-primary">&larr; Kembali ke Beranda</a>
    </div>
  </div>
</section>

<!-- MODAL: ISI NAMA & NO. HP UNTUK PESAN ONLINE -->
<div class="pesan-overlay" id="pesanOverlay">
  <div class="pesan-box">
    <h4>Pesan Sekarang</h4>
    <p class="pesan-sub">Isi data kamu, lalu tetap datang ke Azka Laundry membawa cucianmu. Untuk barang kiloan, berat final akan ditimbang ulang oleh kasir di tempat — total di atas cuma estimasi awal.</p>
    <div class="pesan-field">
      <label>Nama Lengkap</label>
      <input type="text" id="inputPesanNama" placeholder="Contoh: Budi Santoso" maxlength="100">
    </div>
    <div class="pesan-field">
      <label>Nomor HP / WhatsApp</label>
      <input type="text" id="inputPesanHp" placeholder="08xxxxxxxxxx" maxlength="20">
    </div>
    <div class="pesan-error" id="pesanError"></div>
    <div class="pesan-btns">
      <button class="pesan-btn-batal" onclick="tutupModalPesan()">Batal</button>
      <button class="pesan-btn-kirim" id="btnKirimPesan" onclick="kirimPesananOnline()">Kirim Pesanan</button>
    </div>
  </div>
</div>

<!-- MODAL: SUKSES (kode pesanan + instruksi lanjutan) -->
<div class="pesan-overlay" id="suksesOverlay">
  <div class="pesan-box" style="text-align:center">
    <div class="pesan-sukses-icon">✓</div>
    <h4>Pesanan Online Tercatat!</h4>
    <p class="pesan-sub">Tunjukkan kode ini ke kasir saat datang ke laundry:</p>
    <div class="pesan-kode" id="suksesKode">PO-000000-0000</div>
    <button type="button" class="pesan-btn-salin" id="btnSalinKode" onclick="salinKodePesanan()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
      <span id="labelSalinKode">Salin Kode</span>
    </button>
    <div class="pesan-instruksi">
      Silakan datang ke Azka Laundry membawa cucianmu. Untuk barang kiloan, berat & harga final baru dipastikan setelah ditimbang langsung oleh kasir di tempat.
    </div>
    <div class="pesan-timer" id="suksesTimer">Berlaku 24j 00m 00d lagi</div>
    <div class="pesan-timer-note">Pesanan otomatis hangus kalau lewat dari batas waktu di atas &mdash; segera datang ke laundry ya!</div>
    <div class="pesan-btns" style="margin-top:16px">
      <button class="pesan-btn-kirim" style="flex:1" onclick="tutupModalSukses()">Selesai</button>
    </div>
  </div>
</div>

<!-- FOOTER -->
<footer>
  <div class="logo-f">Azka Laundry</div>
  <p>Bersih · Rapih · Wangi</p>
  <p>Jl. Cisitu Lama 1 No. 9A, Bandung · 0895-1215-7474</p>
  <p class="copy">© <?= date('Y') ?> Azka Laundry. All rights reserved.</p>
</footer>

<!-- ══ KALKULATOR SCRIPT ══ -->
<script>
// keranjang: { "id" : { nama, harga, satuan, qty, estimasi } }
let keranjang = {};

function formatRp(n) {
    return 'Rp ' + Math.round(n).toLocaleString('id-ID');
}

/* Step kuantitas per satuan: hanya barang timbangan (kg) yang masuk akal
   dipesan dalam pecahan (mis. 2.5 kg cucian). Barang hitungan seperti
   pasang/pcs/set harus kelipatan bulat -- tidak ada "0.5 pasang sepatu". */
function stepUntukSatuan(satuan) {
    return satuan === 'kg' ? 0.5 : 1;
}

/* ── Pilih kategori (tab) ── */
function pilihKategori(katId) {
    // Reset pencarian setiap kali kategori dipilih manual
    const search = document.getElementById('kalkSearch');
    if (search && search.value) search.value = '';
    document.querySelectorAll('.kalk-catalog-item').forEach(function(item) {
        item.style.display = '';
    });
    document.getElementById('kalkNoResult').style.display = 'none';

    document.querySelectorAll('.kalk-kat-tab').forEach(function(t) {
        t.classList.toggle('active', String(t.dataset.kat) === String(katId));
    });
    document.querySelectorAll('.kalk-catalog-group').forEach(function(g) {
        g.style.display = (String(g.dataset.kat) === String(katId)) ? '' : 'none';
    });
}

/* ── Cari barang lintas kategori ── */
function filterKatalog(query) {
    const q = query.trim().toLowerCase();
    const tabsWrap = document.getElementById('kalkKategoriTabs');
    const noResult = document.getElementById('kalkNoResult');

    if (!q) {
        // Kembalikan ke tampilan kategori aktif seperti semula
        tabsWrap.classList.remove('kalk-kat-list-disabled');
        const activeTab = document.querySelector('.kalk-kat-tab.active') || document.querySelector('.kalk-kat-tab');
        document.querySelectorAll('.kalk-catalog-item').forEach(function(item) { item.style.display = ''; });
        if (activeTab) {
            document.querySelectorAll('.kalk-catalog-group').forEach(function(g) {
                g.style.display = (String(g.dataset.kat) === String(activeTab.dataset.kat)) ? '' : 'none';
            });
        }
        noResult.style.display = 'none';
        return;
    }

    // Saat mencari: tampilkan barang cocok dari SEMUA kategori
    let anyMatch = false;
    document.querySelectorAll('.kalk-catalog-group').forEach(function(g) {
        let groupHasMatch = false;
        g.querySelectorAll('.kalk-catalog-item').forEach(function(item) {
            const nama = (item.dataset.nama || '').toLowerCase();
            const match = nama.indexOf(q) !== -1;
            item.style.display = match ? '' : 'none';
            if (match) { groupHasMatch = true; anyMatch = true; }
        });
        g.style.display = groupHasMatch ? '' : 'none';
    });
    noResult.style.display = anyMatch ? 'none' : 'block';
}

/* ── Pilih / batal layanan ── */
function toggleService(card) {
    const id = card.dataset.id;

    if (keranjang[id]) {
        delete keranjang[id];
        card.classList.remove('selected');
    } else {
        const satuan = card.dataset.satuan;
        const step   = stepUntukSatuan(satuan);
        keranjang[id] = {
            nama     : card.dataset.nama,
            harga    : parseFloat(card.dataset.harga),
            satuan   : satuan,
            qty      : step,
            estimasi : parseInt(card.dataset.estimasi, 10) || 1
        };
        card.classList.add('selected');
    }
    render();
}

/* ── Tambah / kurang qty via tombol ── */
function changeQty(id, delta) {
    if (!keranjang[id]) return;
    const step = stepUntukSatuan(keranjang[id].satuan);
    let q = Math.round((parseFloat(keranjang[id].qty) + parseFloat(delta)) * 10) / 10;
    if (q < step) q = step;
    keranjang[id].qty = q;
    const inp = document.getElementById('qty-' + id);
    const sub = document.getElementById('sub-' + id);
    if (inp) inp.value = q;
    if (sub) sub.textContent = formatRp(keranjang[id].harga * q);
    renderSummary();
}

/* ── Ketik langsung di input ── */
function setQty(id, val) {
    if (!keranjang[id]) return;
    const step = stepUntukSatuan(keranjang[id].satuan);
    let q = parseFloat(val);
    if (isNaN(q) || q < step) q = step;
    q = Math.round(q * 10) / 10;
    keranjang[id].qty = q;
    const sub = document.getElementById('sub-' + id);
    if (sub) sub.textContent = formatRp(keranjang[id].harga * q);
    renderSummary();
}

/* ── Hapus satu item ── */
function removeItem(id) {
    delete keranjang[id];
    const card = document.querySelector('.kalk-catalog-item[data-id="' + id + '"]');
    if (card) card.classList.remove('selected');
    render();
}

/* ── Render semua item ── */
function render() {
    const ids     = Object.keys(keranjang);
    const wrapper = document.getElementById('kalkItemsWrapper');

    if (ids.length === 0) {
        wrapper.innerHTML =
            '<div class="kalk-empty">' +
            '<div>Belum ada barang dipilih.<br>Klik barang di sebelah kiri untuk mulai menghitung.</div>' +
            '</div>';
        document.getElementById('kalkSummary').style.display = 'none';
        return;
    }

    let html = '';
    ids.forEach(function(id) {
        const item = keranjang[id];
        const sub  = item.harga * item.qty;
        const step = String(stepUntukSatuan(item.satuan));
        const min  = step;
        html +=
            '<div class="kalk-item-row">' +
              '<div class="item-info">' +
                '<div class="i-name">' + item.nama + '</div>' +
                '<div class="i-price">' + formatRp(item.harga) + ' / ' + item.satuan + '</div>' +
                '<div class="i-estimasi">⏱ Estimasi ' + item.estimasi + ' hari</div>' +
              '</div>' +
              '<div class="qty-ctrl">' +
                '<button class="qty-btn" onclick="changeQty(\'' + id + '\',-' + step + ')">−</button>' +
                '<input id="qty-' + id + '" class="qty-input" type="number"' +
                       ' min="' + min + '" step="' + step + '" value="' + item.qty + '"' +
                       ' onchange="setQty(\'' + id + '\',this.value)">' +
                '<button class="qty-btn" onclick="changeQty(\'' + id + '\',' + step + ')">+</button>' +
              '</div>' +
              '<div class="item-subtotal" id="sub-' + id + '">' + formatRp(sub) + '</div>' +
              '<button class="remove-btn" onclick="removeItem(\'' + id + '\')" title="Hapus">✕</button>' +
            '</div>';
    });
    wrapper.innerHTML = html;
    renderSummary();
}

/* ── Render ringkasan total ── */
function renderSummary() {
    const ids     = Object.keys(keranjang);
    const summary = document.getElementById('kalkSummary');
    const rows    = document.getElementById('kalkSummaryRows');
    const totalEl = document.getElementById('kalkTotal');

    if (ids.length === 0) { summary.style.display = 'none'; return; }
    summary.style.display = 'block';

    let total = 0;
    let html  = '';
    ids.forEach(function(id) {
        const item = keranjang[id];
        const sub  = item.harga * item.qty;
        total += sub;
        html += '<div class="kalk-sum-row">' +
                  '<span>' + item.nama + ' × ' + item.qty + ' ' + item.satuan + '</span>' +
                  '<span>' + formatRp(sub) + '</span>' +
                '</div>';
    });
    rows.innerHTML = html;
    totalEl.textContent = formatRp(total);
    pulse(totalEl);

    /* ── Estimasi selesai: ambil dari barang dengan proses TERLAMA (nilai maksimum),
       karena semua barang dikerjakan bersamaan, jadi waktu selesai mengikuti yang
       paling lambat, bukan dijumlah semua. ── */
    let terlama = null;
    ids.forEach(function(id) {
        const item = keranjang[id];
        if (!terlama || item.estimasi > terlama.estimasi) terlama = item;
    });

    const estimasiEl = document.getElementById('kalkEstimasiValue');
    const hintEl      = document.getElementById('kalkEstimasiHint');
    if (terlama) {
        const hari = terlama.estimasi;
        estimasiEl.textContent = hari + ' hari kerja';
        hintEl.textContent = 'Mengikuti barang dengan proses paling lama: ' + terlama.nama + ' (' + hari + ' hari).';
        pulse(estimasiEl);
    } else {
        estimasiEl.textContent = '-';
        hintEl.textContent = '';
    }
}

/* ── Efek pulsa singkat: kasih kesan angka "hidup" tiap kali berubah ── */
function pulse(el) {
    if (!el) return;
    el.classList.remove('kalk-pulse');
    void el.offsetWidth; // paksa reflow supaya animasi bisa dipicu ulang walau class-nya sama
    el.classList.add('kalk-pulse');
}

/* ── Reset semua ── */
function resetKalkulator() {
    keranjang = {};
    document.querySelectorAll('.kalk-catalog-item').forEach(function(c) {
        c.classList.remove('selected');
    });
    render();
}

/* ── PESAN ONLINE ── */
function bukaModalPesan() {
    if (Object.keys(keranjang).length === 0) return;
    document.getElementById('pesanError').style.display = 'none';
    document.getElementById('pesanOverlay').classList.add('active');
}
function tutupModalPesan() {
    document.getElementById('pesanOverlay').classList.remove('active');
}
function tutupModalSukses() {
    document.getElementById('suksesOverlay').classList.remove('active');
    if (_timerPesananInterval) { clearInterval(_timerPesananInterval); _timerPesananInterval = null; }
    resetKalkulator();
}

/* ── TIMER HITUNG MUNDUR 24 JAM (sampai pesanan otomatis dihapus sistem) ── */
let _timerPesananInterval = null;
function mulaiTimerPesanan() {
    const deadline = Date.now() + 24 * 3600 * 1000;
    const el = document.getElementById('suksesTimer');
    function tick() {
        const sisaMs = deadline - Date.now();
        if (sisaMs <= 0) {
            el.textContent = 'Batas waktu habis';
            el.classList.add('pesan-timer-urgent');
            clearInterval(_timerPesananInterval);
            return;
        }
        const totalDetik = Math.floor(sisaMs / 1000);
        const jam   = Math.floor(totalDetik / 3600);
        const menit = Math.floor((totalDetik % 3600) / 60);
        const detik = totalDetik % 60;
        const pad = n => String(n).padStart(2, '0');
        el.textContent = 'Berlaku ' + pad(jam) + 'j ' + pad(menit) + 'm ' + pad(detik) + 'd lagi';
        el.classList.toggle('pesan-timer-urgent', sisaMs < 60 * 60 * 1000);
    }
    if (_timerPesananInterval) clearInterval(_timerPesananInterval);
    tick();
    _timerPesananInterval = setInterval(tick, 1000);
}

/* Salin kode pesanan ke clipboard -- pakai Clipboard API modern, dengan
   fallback manual (execCommand) untuk browser lama/WebView yang belum
   dukung navigator.clipboard (mis. sebagian in-app browser di WA/IG). */
async function salinKodePesanan() {
    const kode = document.getElementById('suksesKode').textContent.trim();
    const btn  = document.getElementById('btnSalinKode');
    const label = document.getElementById('labelSalinKode');

    let berhasil = false;
    try {
        await navigator.clipboard.writeText(kode);
        berhasil = true;
    } catch (e) {
        try {
            const tmp = document.createElement('textarea');
            tmp.value = kode;
            tmp.style.position = 'fixed';
            tmp.style.opacity = '0';
            document.body.appendChild(tmp);
            tmp.select();
            document.execCommand('copy');
            document.body.removeChild(tmp);
            berhasil = true;
        } catch (e2) {
            berhasil = false;
        }
    }

    if (berhasil) {
        btn.classList.add('tersalin');
        label.textContent = 'Tersalin!';
        setTimeout(() => {
            btn.classList.remove('tersalin');
            label.textContent = 'Salin Kode';
        }, 2000);
    } else {
        label.textContent = 'Gagal menyalin, salin manual ya';
    }
}

async function kirimPesananOnline() {
    const nama = document.getElementById('inputPesanNama').value.trim();
    const hp   = document.getElementById('inputPesanHp').value.trim();
    const errEl = document.getElementById('pesanError');

    if (!nama || !hp) {
        errEl.textContent = 'Nama dan No. HP wajib diisi.';
        errEl.style.display = 'block';
        return;
    }
    const hpDigit = hp.replace(/[^0-9]/g, '');
    if (hpDigit.length < 8) {
        errEl.textContent = 'Nomor HP tidak valid.';
        errEl.style.display = 'block';
        return;
    }

    const items = Object.keys(keranjang).map(function(id) {
        return { detail_barang_id: id, qty: keranjang[id].qty };
    });

    const btn = document.getElementById('btnKirimPesan');
    btn.disabled = true;
    btn.textContent = 'Mengirim...';

    try {
        const fd = new FormData();
        fd.append('nama_pelanggan', nama);
        fd.append('no_hp_pelanggan', hp);
        fd.append('items', JSON.stringify(items));
        const res = await fetch('simpan_pesanan_online.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            tutupModalPesan();
            document.getElementById('suksesKode').textContent = data.kode;
            document.getElementById('suksesOverlay').classList.add('active');
            mulaiTimerPesanan();
        } else {
            errEl.textContent = data.message || 'Gagal mengirim pesanan, coba lagi.';
            errEl.style.display = 'block';
        }
    } catch (e) {
        errEl.textContent = 'Gagal terhubung ke server, coba lagi.';
        errEl.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Kirim Pesanan';
    }
}
</script>

</body>
</html>