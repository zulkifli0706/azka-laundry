<?php
require_once __DIR__ . '/includes/config.php';
requireRole('kasir', 'owner');

$namaKasir = htmlspecialchars($_SESSION['user']['name']);
$inisial    = strtoupper(substr($_SESSION['user']['name'], 0, 2));

// ── SIDEBAR COUNTERS ── (dipakai badge angka di menu kiri, konsisten dengan halaman kasir lain)
$dalam_antrian   = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'dalam_antrian'")->fetchColumn();
$sedang_diproses = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'sedang_diproses'")->fetchColumn();
$siap_diambil    = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'siap_diambil'")->fetchColumn();


// ── DAFTAR PESANAN ONLINE YANG MASIH MENUNGGU ──
// Pesanan yang sudah 'dikonversi'/'dibatalkan' TIDAK ditampilkan di sini
// (tujuannya halaman ini murni "kerjaan yang perlu ditindaklanjuti kasir").
$list = $pdo->query("
    SELECT id_pesanan_online, kode, nama_pelanggan, no_hp_pelanggan, created_at
    FROM pesanan_online
    WHERE status = 'menunggu'
    ORDER BY created_at ASC
")->fetchAll();

$ids = array_column($list, 'id_pesanan_online');
$detailPerPesanan = [];
if (!empty($ids)) {
    $in = implode(',', array_map('intval', $ids));
    $rows = $pdo->query("
        SELECT pesanan_online_id, nama_item, satuan, qty_estimasi, harga_satuan, subtotal_estimasi
        FROM pesanan_online_detail
        WHERE pesanan_online_id IN ($in)
        ORDER BY id_pesanan_online_detail
    ")->fetchAll();
    foreach ($rows as $r) $detailPerPesanan[$r['pesanan_online_id']][] = $r;
}

$jml_pesanan_online = count($list);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Pesanan Online — Azka Laundry</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --blue:      #2E74C0;
    --blue-deep: #1F5FA6;
    --blue-pale: #EAF2FB;
    --line:      #D6E7F7;
    --ink:       #164A85;
    --gray:      #2E74C0;
    --gray-soft: #4E90D9;
    --white:     #FFFFFF;
}
* { font-family:'Plus Jakarta Sans',sans-serif; box-sizing:border-box; }
body { margin:0; background:#EAF2FB; color:#101418; }

/* SIDEBAR */
.sidebar { width:230px; height:100vh; height:100dvh; background:var(--blue-deep); position:fixed; left:0; top:0; display:flex; flex-direction:column; z-index:100; overflow-y:auto; }
.sidebar-brand { padding:20px; border-bottom:1px solid rgba(255,255,255,0.14); display:flex; align-items:center; gap:10px; }
.sidebar-brand svg { width:28px; height:28px; flex-shrink:0; }
.sidebar-brand h4 { color:#FFFFFF; font-size:16px; font-weight:700; margin:0; }
.sidebar-brand small { color:rgba(255,255,255,0.55); font-size:11px; }
.sidebar-menu { padding:14px 10px; flex:1; }
.sidebar-menu a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.68); text-decoration:none; padding:10px 12px; border-radius:8px; margin-bottom:2px; font-size:13px; font-weight:500; }
.sidebar-menu a:hover, .sidebar-menu a.active { background:rgba(255,255,255,0.14); color:#FFFFFF; }
.sidebar-menu a i { width:16px; text-align:center; }
.sidebar-badge { background:var(--white); color:var(--blue-deep); font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; margin-left:auto; }
.sidebar-badge.green { background:var(--ink); color:#FFFFFF; }
.sidebar-footer { padding:14px 10px calc(14px + env(safe-area-inset-bottom)); border-top:1px solid rgba(255,255,255,0.14); flex-shrink:0; }
.sidebar-footer a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.55); text-decoration:none; padding:9px 12px; border-radius:8px; font-size:13px; }
.sidebar-footer a:hover { background:rgba(255,255,255,0.14); color:#FFFFFF; }

/* MAIN */
.main { margin-left:230px; }

/* ===== MOBILE SIDEBAR DRAWER ===== */
.hamburger-btn {
    display:none; position:fixed; top:12px; left:14px; z-index:200;
    background:var(--blue-deep); color:#FFFFFF; border:none; width:42px; height:42px;
    border-radius:10px; font-size:18px; align-items:center; justify-content:center;
    cursor:pointer; box-shadow:0 3px 10px rgba(16,20,24,0.28);
}
.hamburger-btn:active { transform:scale(0.94); }
.sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(22,74,133,0.5); z-index:100; }
.sidebar-overlay.show { display:block; }
.sidebar { transition:transform 0.28s ease; }
@media (max-width: 900px) {
    .hamburger-btn { display:flex; }
    .sidebar { transform:translateX(-100%); box-shadow:2px 0 20px rgba(16,20,24,0.28); }
    .sidebar.open { transform:translateX(0); }
    .main { margin-left:0 !important; }
    .topbar { padding:12px 16px 12px 62px !important; flex-wrap:wrap; row-gap:8px; }
    .topbar-title { font-size:15px !important; }
    .content { padding:16px !important; }
}
@media (max-width: 480px) {
    .topbar-right { gap:8px !important; }
    .stat-num { font-size:20px !important; }
    .content { padding:10px !important; }
    .trx-table-wrap { padding:0 !important; }
}
.topbar { background:var(--white); border-bottom:1px solid var(--line); padding:14px 26px; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:99; }
.topbar-title { font-size:17px; font-weight:700; color:#101418; }
.topbar-title small { display:block; font-size:12px; color:#3A4450; font-weight:400; margin-top:2px; }
.avatar { width:36px; height:36px; border-radius:50%; background:var(--blue); display:flex; align-items:center; justify-content:center; color:#FFFFFF; font-weight:700; font-size:12px; }
.topbar-right { display:flex; align-items:center; gap:12px; }
.user-name { font-size:13px; font-weight:600; color:#101418; }
.user-role { font-size:11px; color:#4B5563; }
.content { padding:22px 26px; }

/* WARNING */
.warning-bar { background:var(--blue-pale); border:1px solid var(--line); border-left:4px solid var(--ink); border-radius:12px; padding:13px 18px; margin-bottom:18px; display:flex; align-items:center; gap:10px; font-size:13px; color:#101418; font-weight:600; }
.warning-bar i { font-size:14px; }

/* READONLY INFO */
.readonly-info { display:flex; align-items:center; gap:9px; background:var(--blue-pale); border:1px solid var(--line); border-radius:12px; padding:11px 16px; margin-bottom:18px; font-size:12.5px; color:var(--blue-deep); font-weight:500; }
.readonly-info i { color:var(--blue-deep); font-size:14px; }

.search-wrap-po { display:flex; align-items:center; gap:8px; background:var(--white); border:1px solid var(--line); border-radius:10px; padding:10px 14px; margin-bottom:18px; color:#4B5563; max-width:420px; }
.search-wrap-po svg { flex-shrink:0; }
.search-wrap-po input { flex:1; border:none; background:transparent; outline:none; font-size:13px; color:#101418; font-family:inherit; }
.search-wrap-po input::placeholder { color:#8A94A3; }

/* STAT */
.stat-row { display:flex; gap:16px; margin-bottom:18px; flex-wrap:wrap; }
.stat-chip { background:var(--white); border-radius:12px; padding:16px 20px; border:1px solid var(--line); border-left:4px solid var(--blue-deep); display:flex; align-items:center; gap:14px; flex:1; min-width:220px; }
.stat-chip.danger { border-left-color:var(--ink); }
.stat-chip .stat-icon-wrap { width:44px; height:44px; border-radius:10px; background:var(--blue-pale); color:var(--blue-deep); display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.stat-chip.danger .stat-icon-wrap { color:#101418; }
.stat-chip .num { font-size:24px; font-weight:800; color:#101418; line-height:1; }
.stat-chip .lbl { font-size:11px; color:#3A4450; margin-top:3px; font-weight:600; letter-spacing:0.3px; text-transform:uppercase; }

/* PANEL / TABLE */
.panel { background:var(--white); border-radius:14px; border:1px solid var(--line); overflow:hidden; }
.panel-header { padding:16px 20px; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
.panel-header h5 { font-size:15px; font-weight:700; color:#101418; margin:0; display:flex; align-items:center; gap:8px; }
.panel-toolbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

.search-wrap { position:relative; width:230px; max-width:100%; }
.search-wrap input { width:100%; padding:9px 14px 9px 34px; border:1px solid var(--line); border-radius:9px; font-size:13px; font-family:inherit; outline:none; background:#EAF2FB; }
.search-wrap input:focus { border-color:var(--blue); background:var(--white); }
.search-wrap i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#4B5563; font-size:12px; }

/* FILTER TABS */
.filter-tabs { display:flex; background:var(--blue-pale); border-radius:9px; padding:3px; gap:2px; border:1px solid var(--line); }
.ftab { padding:7px 13px; border:none; background:transparent; border-radius:7px; font-size:12px; font-weight:600; color:#3A4450; cursor:pointer; font-family:inherit; white-space:nowrap; display:inline-flex; align-items:center; gap:6px; }
.ftab:hover { color:var(--blue-deep); }
.ftab.active { background:var(--white); color:var(--blue-deep); border:1px solid var(--line); }
.ftab-count { display:inline-block; background:rgba(46,116,192,0.14); border-radius:20px; padding:1px 7px; font-size:10px; font-weight:700; }
.ftab.active .ftab-count { background:rgba(31,95,166,0.14); color:var(--blue-deep); }

.trx-table-wrap { overflow-x:auto; }
.trx-table { width:100%; border-collapse:collapse; font-size:13px; }
.trx-table thead th { background:#EAF2FB; color:#3A4450; font-size:11px; font-weight:700; letter-spacing:0.4px; padding:10px 16px; border-top:1px solid var(--line); border-bottom:1px solid var(--line); white-space:nowrap; text-align:left; }
.trx-table tbody td { padding:12px 16px; border-bottom:1px solid var(--line); vertical-align:middle; }
.trx-table tbody tr:hover td { background:#EAF2FB; }
.trx-table tbody tr:last-child td { border-bottom:none; }

.nota-code { font-family:monospace; font-weight:700; color:var(--blue-deep); font-size:13px; }
.late-tag { display:inline-block; background:var(--ink); color:#FFFFFF; font-size:9px; font-weight:700; padding:2px 7px; border-radius:5px; margin-left:6px; letter-spacing:0.3px; }
.est-late { color:#101418; font-weight:700; font-size:12px; }
.est-ok   { color:#3A4450; font-size:12px; }

/* BADGE */
.badge-s { padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; }
.bs-lunas   { background:var(--blue); color:#FFFFFF; }
.bs-dp      { background:#D6E7F7; color:#3A4450; }
.bs-belum   { background:var(--white); color:#101418; border:1px solid var(--line); }
.bs-antrian { background:var(--white); color:#101418; border:1px solid var(--line); }

/* AKSI BUTTONS */
.btn-acts { display:flex; gap:6px; flex-wrap:wrap; }
.btn-act { padding:7px 12px; border-radius:8px; font-size:11.5px; font-weight:700; border:1.5px solid #1B2126; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-family:inherit; white-space:nowrap; background:#FFFFFF; color:#1B2126; }
.btn-act:hover { background:#1B2126; color:#FFFFFF; }
.btn-detail { background:#FFFFFF; color:#1B2126; border-color:#1B2126; }
.btn-detail:hover { background:#1B2126; color:#FFFFFF; }

/* Baris aksi khusus HP: tombol berjejer horizontal di bawah data, bukan menumpuk di kolom sempit */
.trx-actions-row { display:none; }

/* EMPTY */
.empty-state { text-align:center; padding:48px 20px; color:#4B5563; }
.empty-state .ei { width:50px; height:50px; border-radius:50%; background:var(--blue-pale); color:var(--blue-deep); display:flex; align-items:center; justify-content:center; font-size:19px; margin:0 auto 12px; }
.empty-state p { font-size:13px; color:#3A4450; margin:0; }

/* MODAL DETAIL */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(22,74,133,0.45); z-index:999; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.active { display:flex; }
.modal-box { background:var(--white); border-radius:14px; width:440px; max-width:92vw; max-height:85vh; overflow-y:auto; text-align:left; }
.modal-head { background:var(--blue-deep); padding:16px 20px; color:#FFFFFF; font-size:15px; font-weight:700; display:flex; align-items:center; gap:8px; }
.modal-head-close { margin-left:auto; background:none; border:none; color:#FFFFFF; font-size:15px; cursor:pointer; }
.modal-body { padding:20px; }
.modal-sub-title { font-size:11px; font-weight:700; color:#3A4450; text-transform:uppercase; letter-spacing:0.5px; margin:14px 0 8px; }
.modal-sub-title:first-child { margin-top:0; }
.item-row { display:flex; align-items:center; gap:8px; background:#EAF2FB; border:1px solid var(--line); border-radius:9px; padding:9px 12px; margin-bottom:7px; font-size:13px; }
.item-row .item-name { flex:1; font-weight:600; color:#101418; }
.item-row .item-name small { display:block; font-weight:400; color:#3A4450; }
.item-row .item-price { color:var(--blue-deep); font-weight:700; min-width:88px; text-align:right; }
.total-box { background:var(--blue-deep); border-radius:10px; padding:14px 16px; display:flex; justify-content:space-between; align-items:center; margin-top:12px; }
.total-box .lbl { color:rgba(255,255,255,0.8); font-size:13px; }
.total-box .val { color:#FFFFFF; font-size:19px; font-weight:700; }
.modal-footnote { display:flex; align-items:center; gap:8px; background:var(--blue-pale); border-radius:9px; padding:10px 12px; margin-top:14px; font-size:12px; color:var(--blue-deep); }

/* TOAST */
.toast-wrap { position:fixed; bottom:24px; right:24px; z-index:9999; display:flex; flex-direction:column; gap:8px; }
.toast { padding:12px 18px; border-radius:10px; font-size:13px; font-weight:600; color:#FFFFFF; display:flex; align-items:center; gap:8px; }
.toast.ok  { background:var(--blue-deep); }
.toast.err { background:var(--ink); }

/* MODAL KONFIRMASI (dipakai untuk konfirmasi batalkan pesanan) */
.modal-confirm-box { background:var(--white); border-radius:14px; padding:26px; max-width:380px; width:92vw; text-align:center; }
.modal-title { font-size:16px; font-weight:800; color:#101418; margin-bottom:6px; }
.modal-nota { color:var(--blue-deep); font-weight:700; font-size:15px; }
.confirm-btns { display:flex; gap:10px; margin-top:20px; }
.confirm-btns button { flex:1; padding:12px; border-radius:9px; font-size:13.5px; font-weight:700; font-family:inherit; cursor:pointer; border:none; }
.btn-cancel { background:var(--blue-pale); color:#3A4450; }
.btn-ok { background:var(--ink); color:#FFFFFF; }
.btn-ok:disabled { opacity:0.6; cursor:not-allowed; }

/* ── KARTU PESANAN ONLINE ── */
.po-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:16px; }
.po-card { background:var(--white); border:1px solid var(--line); border-radius:14px; padding:18px; display:flex; flex-direction:column; }
.po-card-head { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:12px; padding-bottom:12px; border-bottom:1px dashed var(--line); }
.po-kode { font-size:13px; font-weight:800; color:var(--blue-deep); letter-spacing:0.3px; }
.po-nama { font-size:14.5px; font-weight:700; color:#101418; margin-top:3px; }
.po-hp { font-size:12px; color:#4B5563; margin-top:1px; }
.po-waktu { font-size:11px; color:#8A94A3; white-space:nowrap; }

.po-timer { display:inline-block; font-size:11px; font-weight:700; color:#B8791A; background:#FFF7E6; border:1px solid #F5D896; border-radius:20px; padding:3px 11px; margin-bottom:12px; }
.po-timer.po-timer-urgent { color:#B8302B; background:#FBEAEA; border-color:#F0B8B8; }
.po-items { flex:1; margin-bottom:12px; }
.po-item-row { display:flex; justify-content:space-between; gap:10px; font-size:12.5px; color:#3A4450; padding:5px 0; }
.po-total-row { display:flex; justify-content:space-between; align-items:center; background:var(--blue-pale); border-radius:9px; padding:10px 14px; font-size:13px; color:#101418; margin-bottom:14px; }
.po-total-row strong { color:var(--blue-deep); font-size:15px; }
.po-btns { display:flex; gap:8px; }
.po-btns button, .po-btns a { flex:1; text-align:center; padding:11px; border-radius:9px; font-size:12.5px; font-weight:700; font-family:inherit; cursor:pointer; border:none; text-decoration:none; }
.po-btn-batal { background:#FBEAEA; color:#B8302B; }
.po-btn-batal:hover { background:#F5D6D6; }
.po-btn-konversi { background:var(--blue-deep); color:#FFFFFF; }
.po-btn-konversi:hover { background:var(--blue); }

@media (max-width:768px) {
    .panel-header { flex-direction:column; align-items:stretch; }
    .panel-toolbar { flex-direction:column; align-items:stretch; }
    .search-wrap { width:100%; }
}

/* ===== TABEL -> KARTU DI HP ===== */
@media (max-width: 700px) {
    .warning-bar, .readonly-info { align-items:flex-start; }
    .trx-table-wrap { overflow-x:hidden; padding:6px; }
    .trx-table { table-layout:fixed; font-size:10.5px; }

    /* Nota, Pelanggan, Total, Bayar, Estimasi */
    .trx-table th:nth-child(1), .trx-table td:nth-child(1) { width:26%; }
    .trx-table th:nth-child(2), .trx-table td:nth-child(2) { width:21%; }
    .trx-table th:nth-child(3), .trx-table td:nth-child(3) { width:17%; }
    .trx-table th:nth-child(4), .trx-table td:nth-child(4) { width:18%; }
    .trx-table th:nth-child(5), .trx-table td:nth-child(5) { width:18%; }
    .trx-table th:nth-child(6) { display:none; }
    .trx-table td[data-label="Aksi"] { display:none; }

    .trx-table thead th { padding:6px 3px; font-size:7.5px; white-space:normal; word-break:break-word; }
    .trx-table tbody td { padding:6px 3px; font-size:9.5px; word-break:break-word; vertical-align:top; }

    .trx-table .nota-code { font-size:9px; white-space:nowrap; letter-spacing:-0.2px; }
    .trx-table .late-tag { font-size:7px; padding:1px 3px; }
    .trx-table td[data-label="Pelanggan"] div:first-child { font-size:9.5px; }
    .trx-table td[data-label="Pelanggan"] div:last-child  { font-size:8px; }
    .trx-table td.est-late, .trx-table td.est-ok { font-size:9px; }

    /* Baris tombol aksi: tampil sebagai baris penuh di bawah data, tombol berjejer horizontal & bisa digeser */
    /* Baris data & baris aksi adalah 1 nota yang sama -> hilangkan garis di antara keduanya,
       garis pemisah cuma muncul setelah baris aksi (baru pindah ke nota berikutnya) */
    .trx-table tbody tr:not(.trx-actions-row) td { border-bottom:none; }
    .trx-actions-row { display:table-row; }
    .trx-actions-row td { border-bottom:1px solid var(--sky-soft); }
    .trx-actions-row td { padding:8px 3px 10px; }
    .trx-actions-row .btn-acts {
        flex-direction:row; flex-wrap:wrap; gap:6px;
        row-gap:8px; overflow-x:visible; padding-bottom:2px;
    }
    .trx-actions-row .btn-act {
        flex:0 0 auto; padding:7px 12px; font-size:10.5px; line-height:1.2; white-space:nowrap;
    }

    /* Badge status bayar (mis. "Kurang Rp40.000") pakai white-space:nowrap di CSS
       dasar -- di kolom "Bayar" yang cuma 18% lebar layar HP, teks sepanjang itu
       jadi meluber/menimpa kolom "Estimasi" di sebelahnya. Di HP, izinkan teksnya
       membungkus (wrap) ke baris berikutnya di dalam badge, bukan meluber keluar. */
    .trx-table td[data-label="Bayar"] .badge-s {
        white-space:normal; word-break:break-word; text-align:center;
        line-height:1.3; max-width:100%; padding:3px 7px; font-size:8.5px;
    }
}
</style>
</head>
<body>

<button type="button" class="hamburger-btn" id="hamburgerBtn" aria-label="Buka menu" onclick="toggleSidebar()"><span style="display:block;width:20px;height:2px;background:#fff;border-radius:2px;box-shadow:0 6px 0 #fff,0 -6px 0 #fff"></span></button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="sidebar" id="sidebarEl">
  <div class="sidebar-brand">
    <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
      <g transform="translate(32,34)">
        <circle cx="0" cy="2" r="22" fill="none" stroke="#FFFFFF" stroke-width="4"/>
        <circle cx="0" cy="4" r="12" fill="none" stroke="#FFFFFF" stroke-width="3.5"/>
        <rect x="-9" y="-26" width="18" height="7" rx="3.5" fill="#FFFFFF"/>
      </g>
    </svg>
    <div>
      <h4>Azka Laundry</h4>
      <small>Kasir Panel</small>
    </div>
  </div>
  <div class="sidebar-menu">
    <a href="dashboard_kasir.php">Dashboard</a>
    <a href="transaksi_baru.php">Tambah Transaksi</a>
    <a href="pesanan_online.php" class="active">
      Pesanan Online
      <?php if ($jml_pesanan_online > 0): ?>
      <span class="sidebar-badge green"><?= $jml_pesanan_online ?></span>
      <?php endif; ?>
    </a>
    <a href="antrian_kasir.php">
      Antrian
      <?php if ($dalam_antrian > 0): ?>
      <span class="sidebar-badge"><?= $dalam_antrian ?></span>
      <?php endif; ?>
    </a>
    <a href="sedang_diproses.php">
      Sedang Diproses
      <?php if ($sedang_diproses > 0): ?>
      <span class="sidebar-badge"><?= $sedang_diproses ?></span>
      <?php endif; ?>
    </a>
    <a href="siap_diambil.php">
      Siap Diambil
      <?php if ($siap_diambil > 0): ?>
      <span class="sidebar-badge green"><?= $siap_diambil ?></span>
      <?php endif; ?>
    </a>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php">Logout</a>
  </div>
</div>

<div class="main">
  <div class="topbar">
    <div>
      <div class="topbar-title">
        Pesanan Online
        <small>Selamat datang, <?= $namaKasir ?> &middot; <?= date('d M Y') ?></small>
      </div>
    </div>
    <div class="topbar-right">
      <div style="text-align:right">
        <div class="user-name"><?= $namaKasir ?></div>
        <div class="user-role">Kasir</div>
      </div>
      <div class="avatar"><?= $inisial ?></div>
    </div>
  </div>

  <div class="content">

    <div class="readonly-info">
      <span>Pesanan dari kalkulator estimasi di website. Pelanggan tetap harus datang membawa cucian. Klik <strong>&nbsp;Konversi jadi Transaksi</strong> untuk memproses — barang kiloan WAJIB ditimbang ulang di form berikutnya, qty di sini cuma perkiraan pelanggan sendiri. Pesanan yang tidak ditindaklanjuti otomatis terhapus setelah <strong>24 jam</strong>.</span>
    </div>

    <?php if (!empty($list)): ?>
    <div class="search-wrap-po">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
      <input type="text" id="searchPo" placeholder="Cari kode pesanan, nama, atau No. HP..." oninput="filterPesananOnline(this.value)" autocomplete="off">
    </div>
    <?php endif; ?>

    <?php if (empty($list)): ?>
    <div class="empty-state" id="emptyDefault">
      <div class="ei">OK</div>
      <p>Belum ada pesanan online yang masuk saat ini.</p>
    </div>
    <?php else: ?>

    <div id="poEmptySearch" class="empty-state" style="display:none">
      <div class="ei">–</div>
      <p>Tidak ada pesanan yang cocok dengan pencarian ini.</p>
    </div>

    <div class="po-grid" id="poGrid">
      <?php foreach ($list as $p):
        $items = $detailPerPesanan[$p['id_pesanan_online']] ?? [];
        $totalEstimasi = array_sum(array_column($items, 'subtotal_estimasi'));
        $waktuMasuk = date('d/m/Y H:i', strtotime($p['created_at']));
        $dataSearch = strtolower($p['kode'].' '.$p['nama_pelanggan'].' '.$p['no_hp_pelanggan']);
        $deadlineEpoch = strtotime($p['created_at']) + 24 * 3600;
      ?>
      <div class="po-card" data-search="<?= htmlspecialchars($dataSearch, ENT_QUOTES) ?>">
        <div class="po-card-head">
          <div>
            <div class="po-kode"><?= htmlspecialchars($p['kode']) ?></div>
            <div class="po-nama"><?= htmlspecialchars($p['nama_pelanggan']) ?></div>
            <div class="po-hp"><?= htmlspecialchars($p['no_hp_pelanggan']) ?></div>
          </div>
          <div class="po-waktu"><?= $waktuMasuk ?></div>
        </div>
        <div class="po-timer" data-deadline="<?= $deadlineEpoch * 1000 ?>">Menghitung sisa waktu...</div>
        <div class="po-items">
          <?php foreach ($items as $it): ?>
          <div class="po-item-row">
            <span><?= htmlspecialchars($it['nama_item']) ?> &middot; <?= rtrim(rtrim(number_format($it['qty_estimasi'], 2, '.', ''), '0'), '.') ?> <?= htmlspecialchars($it['satuan']) ?></span>
            <span><?= 'Rp' . number_format($it['subtotal_estimasi'], 0, ',', '.') ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="po-total-row">
          <span>Estimasi Total</span>
          <strong>Rp<?= number_format($totalEstimasi, 0, ',', '.') ?></strong>
        </div>
        <div class="po-btns">
          <button type="button" class="po-btn-batal" onclick="batalkanPesanan(<?= $p['id_pesanan_online'] ?>, '<?= htmlspecialchars($p['kode'], ENT_QUOTES) ?>')">Batalkan</button>
          <a href="transaksi_baru.php?dari_online=<?= $p['id_pesanan_online'] ?>" class="po-btn-konversi">Konversi jadi Transaksi</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
</div>


<!-- MODAL KONFIRMASI BATALKAN PESANAN -->
<div class="modal-overlay" id="modalBatal">
  <div class="modal-confirm-box">
    <div class="modal-title">Batalkan Pesanan Online?</div>
    <div class="modal-nota" id="mKodeBatal"></div>
    <p style="font-size:12.5px;color:#4B5563;margin:10px 0 0">Pesanan ini tidak akan bisa dikonversi lagi setelah dibatalkan.</p>
    <div class="confirm-btns">
      <button class="btn-cancel" onclick="tutupModal('modalBatal')">Tidak Jadi</button>
      <button class="btn-ok" id="btnKonfirmBatal">Ya, Batalkan</button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
function formatRp(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); }

function tutupModal(id) { document.getElementById(id)?.classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(el =>
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('active'); })
);

function showToast(icon, msg, tipe) {
    const wrap = document.getElementById('toastWrap');
    if (!wrap) { alert(msg); return; }
    const el = document.createElement('div');
    el.className = 'toast ' + (tipe === 'err' ? 'err' : 'ok');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

let _batalId = null;
// ── PENCARIAN KODE/NAMA/HP ──
// ── TIMER HITUNG MUNDUR (batas 24 jam sebelum pesanan otomatis dihapus) ──
function updateSemuaTimerPo() {
    document.querySelectorAll('.po-timer[data-deadline]').forEach(el => {
        const deadline = parseInt(el.dataset.deadline, 10);
        const sisaMs = deadline - Date.now();
        if (sisaMs <= 0) {
            el.textContent = 'Kadaluwarsa — refresh halaman';
            el.classList.add('po-timer-urgent');
            return;
        }
        const totalDetik = Math.floor(sisaMs / 1000);
        const jam   = Math.floor(totalDetik / 3600);
        const menit = Math.floor((totalDetik % 3600) / 60);
        const detik = totalDetik % 60;
        const pad = n => String(n).padStart(2, '0');
        el.textContent = 'Berlaku ' + pad(jam) + 'j ' + pad(menit) + 'm ' + pad(detik) + 'd lagi';
        el.classList.toggle('po-timer-urgent', sisaMs < 60 * 60 * 1000); // kurang dari 1 jam
    });
}
updateSemuaTimerPo();
setInterval(updateSemuaTimerPo, 1000);

function filterPesananOnline(q) {
    q = q.toLowerCase().trim();
    const cards = document.querySelectorAll('#poGrid .po-card');
    let visible = 0;
    cards.forEach(card => {
        const cocok = q === '' || card.dataset.search.includes(q);
        card.style.display = cocok ? '' : 'none';
        if (cocok) visible++;
    });
    document.getElementById('poEmptySearch').style.display = (q !== '' && visible === 0) ? 'block' : 'none';
}

function batalkanPesanan(id, kode) {
    _batalId = id;
    document.getElementById('mKodeBatal').textContent = kode;
    document.getElementById('modalBatal').classList.add('active');
}

document.getElementById('btnKonfirmBatal').addEventListener('click', async function () {
    if (!_batalId) return;
    const btn = this;
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('id', _batalId);
        fd.append('csrf_token', CSRF_TOKEN);
        const res = await fetch('batalkan_pesanan_online.php', { method: 'POST', body: fd });
        const data = await res.json();
        tutupModal('modalBatal');
        if (data.success) {
            showToast('ok', 'Pesanan dibatalkan.', 'ok');
            setTimeout(() => location.reload(), 600);
        } else {
            showToast('err', data.message || 'Gagal membatalkan pesanan.', 'err');
        }
    } catch (e) {
        showToast('err', 'Gagal terhubung ke server.', 'err');
    } finally {
        btn.disabled = false;
    }
});
</script>

<script>
function toggleSidebar() {
    document.getElementById('sidebarEl').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}
function closeSidebar() {
    document.getElementById('sidebarEl').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
}
// Tutup sidebar otomatis saat pindah halaman/menu di mobile
document.querySelectorAll('.sidebar-menu a, .sidebar-footer a').forEach(function(a){
    a.addEventListener('click', closeSidebar);
});
</script>
</body>
