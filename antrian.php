<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/estimasi_helper.php';
requireRole('bagian_pencucian', 'owner');

$antrianNotifTerlambat = cekPerpanjanganEstimasi($pdo);

$namaPetugas = htmlspecialchars($_SESSION['user']['name']);
$inisial     = strtoupper(substr($_SESSION['user']['name'], 0, 2));

// ── STATISTIK (untuk badge sidebar/topbar ringkas) ──
$jml_antrian   = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'dalam_antrian'")->fetchColumn();
$jml_terlambat_antrian = $pdo->query("
    SELECT COUNT(*) FROM transaksi t
    JOIN status_cucian s ON t.id_transaksi = s.transaksi_id
    WHERE (t.tanggal_estimasi < CURDATE() OR t.pernah_terlambat = 1)
    AND s.status = 'dalam_antrian'
")->fetchColumn();

// ── DATA ANTRIAN ──
$tab_antrian = $pdo->query("
    SELECT t.id_transaksi, t.id_nota, t.nama_pelanggan, t.no_hp_pelanggan,
           t.tanggal_estimasi, t.pernah_terlambat, t.created_at
    FROM transaksi t
    JOIN status_cucian s ON t.id_transaksi = s.transaksi_id
    WHERE s.status = 'dalam_antrian'
    ORDER BY t.tanggal_estimasi ASC, t.created_at ASC
")->fetchAll();

// ── DETAIL ITEM + KATEGORI ──
$semuaIds = array_column($tab_antrian, 'id_transaksi');
$detailPerTrx   = [];
$kategoriPerTrx = [];
if (!empty($semuaIds)) {
    $in = implode(',', array_map('intval', $semuaIds));
    $rows = $pdo->query("
        SELECT dt.transaksi_id, COALESCE(il.nama_item, dt.keterangan, 'Item') AS nama_item,
               COALESCE(il.satuan, 'pcs') AS satuan, dt.qty, dt.subtotal,
               kl.nama_kategori
        FROM detail_transaksi dt
        LEFT JOIN detail_barang il      ON il.id_barang = dt.detail_barang_id
        LEFT JOIN kategori_barang kl  ON kl.id_kategori = il.kategori_id
        WHERE dt.transaksi_id IN ($in)
        ORDER BY dt.id_detail_transaksi
    ")->fetchAll();
    foreach ($rows as $r) {
        $detailPerTrx[$r['transaksi_id']][] = $r;
        if (!empty($r['nama_kategori'])) {
            $kategoriPerTrx[$r['transaksi_id']][$r['nama_kategori']] = true;
        }
    }
}

// ── JSON UNTUK MODAL DETAIL & AKSI PROSES ──
$rincianJson = [];
foreach ($tab_antrian as $t) {
    $items = $detailPerTrx[$t['id_transaksi']] ?? [];
    $rincianJson[$t['id_transaksi']] = [
        'nota'      => $t['id_nota'],
        'nama'      => $t['nama_pelanggan'],
        'no_hp'     => $t['no_hp_pelanggan'],
        'estimasi'  => !empty($t['tanggal_estimasi']) ? date('d/m/Y', strtotime($t['tanggal_estimasi'])) : '-',
        'terlambat' => !empty($t['pernah_terlambat']) || (!empty($t['tanggal_estimasi']) && strtotime($t['tanggal_estimasi']) < strtotime('today')),
        'kategori'  => array_keys($kategoriPerTrx[$t['id_transaksi']] ?? []),
        'items'     => array_map(fn($i) => [
            'nama'     => $i['nama_item'],
            'kategori' => $i['nama_kategori'] ?: '-',
            'qty'      => rtrim(rtrim($i['qty'], '0'), '.'),
            'satuan'   => $i['satuan'],
            'subtotal' => (float) $i['subtotal'],
        ], $items),
    ];
}
$rincian_json_out = json_encode($rincianJson, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$csrf_token_out    = json_encode(csrf_token());
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Antrian Cucian — Azka Laundry</title>
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
.content { padding:22px 26px; }

/* WARNING */
.warning-bar { background:var(--white); border:1px solid var(--ink); border-left:4px solid var(--ink); border-radius:10px; padding:11px 16px; margin-bottom:16px; font-size:13px; color:#101418; font-weight:600; display:flex; align-items:center; gap:9px; }

/* PANEL */
.panel { background:var(--white); border-radius:14px; border:1px solid var(--line); overflow:hidden; }
.panel-header { padding:16px 20px; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
.panel-header h5 { font-size:15px; font-weight:700; color:#101418; margin:0; display:flex; align-items:center; gap:9px; }
.panel-header h5 .badge-total { background:var(--blue-pale); color:var(--blue-deep); font-size:12px; font-weight:700; padding:3px 10px; border-radius:20px; margin-left:2px; }

/* TOOLBAR */
.toolbar { display:flex; justify-content:space-between; align-items:center; margin:14px 18px 10px; flex-wrap:wrap; gap:8px; }
.toolbar-info { font-size:13px; color:#3A4450; }
.toolbar-info strong { color:#101418; }

.search-wrap { position:relative; width:230px; max-width:100%; }
.search-wrap input { width:100%; padding:9px 14px 9px 34px; border:1px solid var(--line); border-radius:9px; font-size:13px; font-family:inherit; outline:none; background:#EAF2FB; box-sizing:border-box; }
.search-wrap input:focus { border-color:var(--blue); background:var(--white); }
.search-wrap i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#4B5563; font-size:12px; }

.filter-tabs { display:flex; background:var(--blue-pale); border-radius:9px; padding:3px; gap:2px; border:1px solid var(--line); }
.ftab { padding:7px 13px; border:none; background:transparent; border-radius:7px; font-size:12px; font-weight:600; color:#3A4450; cursor:pointer; font-family:inherit; white-space:nowrap; display:inline-flex; align-items:center; gap:6px; }
.ftab:hover { color:var(--blue-deep); }
.ftab.active { background:var(--white); color:var(--blue-deep); border:1px solid var(--line); }
.ftab-count { display:inline-block; background:rgba(46,116,192,0.14); border-radius:20px; padding:1px 7px; font-size:10px; font-weight:700; }
.ftab.active .ftab-count { background:rgba(31,95,166,0.14); color:var(--blue-deep); }

/* TABLE (full width, stretched rows) */
.trx-table-wrap { margin:0 0 4px; overflow-x:auto; }
.trx-table { width:100%; border-collapse:collapse; font-size:13px; }
.trx-table thead th { background:#EAF2FB; color:#3A4450; font-size:11px; font-weight:700; letter-spacing:0.4px; padding:10px 16px; border-top:1px solid var(--line); border-bottom:1px solid var(--line); white-space:nowrap; text-align:left; }
.trx-table thead th:first-child { width:40px; text-align:center; }
.trx-table tbody td { padding:12px 16px; border-bottom:1px solid var(--line); vertical-align:middle; }
.trx-table tbody tr:hover td { background:#EAF2FB; }
.trx-table tbody tr.row-selected td { background:var(--blue-pale); }
.trx-table tbody tr:last-child td { border-bottom:none; }
.trx-table td:first-child { text-align:center; }
.cb { width:17px; height:17px; accent-color:var(--blue); cursor:pointer; }

.nota-code { font-family:monospace; font-weight:700; color:var(--blue-deep); font-size:13px; }
.late-tag { display:inline-block; background:var(--ink); color:#FFFFFF; font-size:9px; font-weight:700; padding:2px 7px; border-radius:5px; margin-left:6px; letter-spacing:0.3px; }
.est-late { color:#101418; font-weight:700; font-size:12px; }
.est-warn { color:#3A4450; font-weight:700; font-size:12px; }
.est-ok   { color:#3A4450; font-size:12px; }

/* KATEGORI CHIPS */
.kat-chip { display:inline-block; background:var(--blue-pale); color:var(--blue-deep); border:1px solid var(--line); font-size:11px; font-weight:600; padding:2px 9px; border-radius:20px; margin:1px 3px 1px 0; }
.kat-more { color:#4B5563; font-size:11px; }

/* BADGE (bayar) */
.badge-s { padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; }
.bs-lunas { background:var(--blue); color:#FFFFFF; }
.bs-dp      { background:#D6E7F7; color:#3A4450; }
.bs-belum { background:var(--white); color:#101418; border:1px solid var(--line); }

/* AKSI BUTTONS */
.btn-acts { display:flex; gap:6px; flex-wrap:wrap; }
.btn-act { padding:7px 12px; border-radius:8px; font-size:11.5px; font-weight:700; border:1.5px solid #1B2126; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-family:inherit; white-space:nowrap; background:#FFFFFF; color:#1B2126; }
.btn-act:hover { background:#1B2126; color:#FFFFFF; }
.btn-detail { background:#FFFFFF; color:#1B2126; border-color:#1B2126; }
.btn-detail:hover { background:#1B2126; color:#FFFFFF; }
.btn-proses-satu { background:var(--blue); color:#FFFFFF; border-color:var(--blue); }
.btn-proses-satu:hover { background:var(--blue-deep); }

/* Baris aksi khusus HP: tombol berjejer horizontal di bawah data, bukan menumpuk di kolom sempit */
.trx-actions-row { display:none; }

/* BULK ACTION BUTTON */
.btn-action { padding:9px 18px; border-radius:10px; font-size:13px; font-weight:700; border:none; cursor:pointer; font-family:inherit; display:inline-flex; align-items:center; gap:7px; }
.btn-proses { background:var(--blue); color:#FFFFFF; }
.btn-proses:hover { background:var(--blue-deep); }
.btn-proses:disabled { background:var(--gray-soft); cursor:not-allowed; }
.btn-refresh { background:var(--white); border:1px solid var(--line); padding:7px 14px; border-radius:8px; font-size:12px; font-weight:600; color:#3A4450; cursor:pointer; font-family:inherit; }
.btn-refresh:hover { background:var(--blue-pale); }

/* EMPTY */
.empty-state { text-align:center; padding:40px 20px; color:#4B5563; }
.empty-state .ei { width:50px; height:50px; border-radius:50%; background:var(--blue-pale); color:var(--blue-deep); display:flex; align-items:center; justify-content:center; font-size:19px; margin:0 auto 12px; }
.empty-state p { font-size:13px; color:#3A4450; margin:0; }

/* MODAL (shared) */
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

/* MODAL PROSES (konfirmasi) */
.modal-confirm-box { background:var(--white); border-radius:14px; padding:26px; max-width:420px; width:90%; text-align:center; }
.modal-icon-wrap { width:52px; height:52px; border-radius:50%; background:var(--blue-pale); color:var(--blue-deep); display:flex; align-items:center; justify-content:center; font-size:20px; margin:0 auto 14px; }
.modal-title { font-size:16px; font-weight:700; color:#101418; margin-bottom:8px; }
.modal-sub   { font-size:13px; color:#3A4450; margin-bottom:14px; line-height:1.6; }
.modal-list  { background:#EAF2FB; border:1px solid var(--line); border-radius:10px; padding:10px 14px; margin-bottom:16px; text-align:left; max-height:180px; overflow-y:auto; }
.modal-list-item { padding:6px 0; border-bottom:1px solid var(--line); font-size:13px; display:flex; justify-content:space-between; align-items:center; gap:8px; }
.modal-list-item:last-child { border-bottom:none; }
.modal-list-item .nota-txt { font-weight:700; font-family:monospace; color:var(--blue-deep); }
.modal-list-item .nota-nama { font-weight:400; color:#3A4450; }
.modal-warning { background:var(--white); border:1px solid var(--ink); border-radius:10px; padding:9px 12px; margin-bottom:14px; font-size:12px; color:#101418; font-weight:600; display:flex; align-items:center; gap:7px; text-align:left; }
.confirm-btns { display:flex; gap:10px; justify-content:center; }
.btn-ok     { padding:10px 24px; background:var(--blue); color:#FFFFFF; border:none; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; }
.btn-ok:hover { background:var(--blue-deep); }
.btn-cancel { padding:10px 24px; background:var(--white); color:#3A4450; border:1px solid var(--line); border-radius:9px; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; }
.btn-cancel:hover { background:#EAF2FB; }

/* TOAST */
.toast-wrap { position:fixed; bottom:24px; right:24px; z-index:9999; display:flex; flex-direction:column; gap:8px; }
.toast { padding:12px 18px; border-radius:10px; font-size:13px; font-weight:600; color:#FFFFFF; display:flex; align-items:center; gap:8px; }
.toast.ok  { background:var(--blue-deep); }
.toast.err { background:var(--ink); }

/* ===== TABEL TETAP TABEL DI HP, DIKECILIN SUPAYA MUAT (GAK SCROLL SAMPING) ===== */
@media (max-width: 700px) {
    .warning-bar { align-items:flex-start; }
    .trx-table-wrap { overflow-x:hidden; padding:6px; }
    .trx-table { table-layout:fixed; font-size:10.5px; }

    /* Checkbox, Nota, Pelanggan, Kategori, Estimasi */
    .trx-table th:nth-child(1), .trx-table td:nth-child(1) { width:7%; padding-left:2px; padding-right:0; }
    .trx-table th:nth-child(2), .trx-table td:nth-child(2) { width:28%; }
    .trx-table th:nth-child(3), .trx-table td:nth-child(3) { width:21%; }
    .trx-table th:nth-child(4), .trx-table td:nth-child(4) { width:21%; }
    .trx-table th:nth-child(5), .trx-table td:nth-child(5) { width:23%; }
    .trx-table th:nth-child(6) { display:none; }
    .trx-table td[data-label="Aksi"] { display:none; }

    .trx-table thead th { padding:6px 3px; font-size:7.5px; white-space:normal; word-break:break-word; }
    .trx-table tbody td { padding:6px 3px; font-size:9.5px; word-break:break-word; vertical-align:top; }
    .trx-table td:first-child .cb { width:15px; height:15px; }

    .trx-table .nota-code { font-size:9px; white-space:nowrap; letter-spacing:-0.2px; }
    .trx-table .late-tag { font-size:7px; padding:1px 3px; }
    .trx-table td[data-label="Pelanggan"] div:first-child { font-size:9.5px; }
    .trx-table td[data-label="Pelanggan"] div:last-child  { font-size:8px; }
    .kat-chip { font-size:7.5px; padding:1px 4px; margin:0 1px 1px 0; }
    .kat-more { font-size:7.5px; }
    .trx-table td.est-late, .trx-table td.est-warn, .trx-table td.est-ok { font-size:9px; }

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
      <small>Panel Pencucian</small>
    </div>
  </div>
  <div class="sidebar-menu">
    <a href="dashboard_pencucian.php">Dashboard</a>
    <a href="antrian.php" class="active">Antrian</a>
    <a href="proses.php">Sedang Diproses</a>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php">Logout</a>
  </div>
</div>

<div class="main">
  <div class="topbar">
    <div>
      <div class="topbar-title">
        Antrian Cucian
        <small><?= date('l, d M Y') ?> &middot; <?= $namaPetugas ?></small>
      </div>
    </div>
    <div class="topbar-right">
      <div style="text-align:right">
        <div class="user-name"><?= $namaPetugas ?></div>
        <div style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;background:var(--blue-pale);color:var(--blue-deep);display:inline-block;margin-top:2px">Bagian Pencucian</div>
      </div>
      <div class="avatar"><?= $inisial ?></div>
    </div>
  </div>

  <div class="content">

    <?php if ($jml_terlambat_antrian > 0): ?>
    <div class="warning-bar"><span>Ada <strong><?= $jml_terlambat_antrian ?> cucian dalam antrian</strong> yang melewati estimasi selesai.</span></div>
    <?php endif; ?>

    <!-- PANEL -->
    <div class="panel">
      <div class="panel-header">
        <h5>Daftar Antrian <span class="badge-total"><?= count($tab_antrian) ?> nota</span></h5>
        <button class="btn-refresh" onclick="location.reload()">
          Refresh
        </button>
      </div>

      <?php if (empty($tab_antrian)): ?>
      <div class="empty-state"><div class="ei">OK</div><p>Tidak ada cucian dalam antrian.</p></div>
      <?php else: ?>

      <div class="toolbar">
        <div class="toolbar-info">
          Pilih nota lalu klik <strong>Mulai Proses</strong> &nbsp;·&nbsp;
          <span id="selCountA">0</span> dipilih
        </div>
        <div class="filter-tabs">
          <button class="ftab active" id="ftab-semua" onclick="setFilterA('semua')">
            Semua <span class="ftab-count" id="fc-semua"><?= count($tab_antrian) ?></span>
          </button>
          <?php if ($jml_terlambat_antrian > 0): ?>
          <button class="ftab" id="ftab-terlambat" onclick="setFilterA('terlambat')">
            Terlambat <span class="ftab-count" id="fc-terlambat"><?= $jml_terlambat_antrian ?></span>
          </button>
          <?php endif; ?>
        </div>
        <div class="filter-tabs">
          <button class="ftab" id="sort-terbaru" onclick="setSortA('terbaru')">Terbaru</button>
          <button class="ftab" id="sort-terlama" onclick="setSortA('terlama')">Terlama</button>
        </div>
        <div class="search-wrap">
          <input type="text" id="searchInputA" placeholder="Cari nota / nama..." oninput="filterTabelA(this.value)">
        </div>
        <button class="btn-action btn-proses" id="btnMulaiProses" disabled onclick="konfirmasiProses()">
          Mulai Proses (<span id="selCountABtn">0</span>)
        </button>
      </div>

      <div class="trx-table-wrap">
        <table class="trx-table">
          <thead>
            <tr>
              <th><input type="checkbox" class="cb" id="cbAllA" onchange="toggleAll(this)"></th>
              <th>NOTA</th>
              <th>PELANGGAN</th>
              <th>KATEGORI</th>
              <th>ESTIMASI</th>
              <th>AKSI</th>
            </tr>
          </thead>
          <tbody id="tbodyAntrian">
          <?php foreach ($tab_antrian as $t):
            $terlambat = !empty($t['pernah_terlambat']) || (!empty($t['tanggal_estimasi']) && strtotime($t['tanggal_estimasi']) < strtotime('today'));
            $estFmt    = !empty($t['tanggal_estimasi']) ? date('d/m/Y', strtotime($t['tanggal_estimasi'])) : '-';
            $estClass  = $terlambat ? 'est-late' : ((!empty($t['tanggal_estimasi']) && strtotime($t['tanggal_estimasi']) <= strtotime('+1 day')) ? 'est-warn' : 'est-ok');
            $katList   = array_keys($kategoriPerTrx[$t['id_transaksi']] ?? []);
          ?>
          <tr id="rowA-<?= $t['id_transaksi'] ?>" data-id="<?= $t['id_transaksi'] ?>" data-filter="semua<?= $terlambat ? ' terlambat' : '' ?>" data-created="<?= strtotime($t['created_at']) ?>" data-search="<?= strtolower(htmlspecialchars($t['id_nota'].' '.$t['nama_pelanggan'], ENT_QUOTES)) ?>">
            <td data-label="">
              <input type="checkbox" class="cb cbA" id="cbA-<?= $t['id_transaksi'] ?>"
                     data-id="<?= $t['id_transaksi'] ?>"
                     onchange="onCb(this)">
            </td>
            <td data-label="Nota">
              <span class="nota-code"><?= htmlspecialchars($t['id_nota']) ?></span>
              <?php if ($terlambat): ?><span class="late-tag">TERLAMBAT</span><?php endif; ?>
              <div style="font-size:11px;color:#4B5563;margin-top:2px"><?= date('d/m H:i', strtotime($t['created_at'])) ?></div>
            </td>
            <td data-label="Pelanggan">
              <div style="font-weight:600;color:#101418"><?= htmlspecialchars($t['nama_pelanggan']) ?></div>
              <div style="font-size:11px;color:#4B5563"><?= htmlspecialchars($t['no_hp_pelanggan']) ?></div>
            </td>
            <td data-label="Kategori">
              <?php if (!empty($katList)): ?>
                <span class="kat-chip"><?= htmlspecialchars($katList[0]) ?></span>
                <?php if (count($katList) > 1): ?><span class="kat-more">+<?= count($katList) - 1 ?> lainnya</span><?php endif; ?>
              <?php else: ?>
                <span style="color:#4B5563;font-size:12px">-</span>
              <?php endif; ?>
            </td>
            <td data-label="Estimasi" class="<?= $estClass ?>"><?= $estFmt ?></td>
            <td data-label="Aksi">
              <div class="btn-acts">
                <button type="button" class="btn-act btn-detail" onclick="showDetail(<?= $t['id_transaksi'] ?>)" title="Lihat rincian pesanan">
                  Detail
                </button>
                <button type="button" class="btn-act btn-proses-satu" onclick="prosesSatu(<?= $t['id_transaksi'] ?>)" title="Mulai proses nota ini">
                  Proses
                </button>
              </div>
            </td>
          </tr>
          <tr class="trx-actions-row">
            <td colspan="6">
              <div class="btn-acts">
                <button type="button" class="btn-act btn-detail" onclick="showDetail(<?= $t['id_transaksi'] ?>)" title="Lihat rincian pesanan">
                  Detail
                </button>
                <button type="button" class="btn-act btn-proses-satu" onclick="prosesSatu(<?= $t['id_transaksi'] ?>)" title="Mulai proses nota ini">
                  Proses
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div><!-- /panel -->
  </div><!-- /content -->
</div><!-- /main -->

<!-- MODAL DETAIL RINCIAN -->
<div class="modal-overlay" id="modalDetail">
  <div class="modal-box">
    <div class="modal-head">
      Rincian Pesanan
      <button type="button" class="modal-head-close" onclick="tutupModal('modalDetail')">Tutup</button>
    </div>
    <div class="modal-body" id="detailBody"></div>
  </div>
</div>

<!-- MODAL PROSES -->
<div class="modal-overlay" id="modalProses">
  <div class="modal-confirm-box">
    <div class="modal-icon-wrap">Mulai</div>
    <div class="modal-title">Mulai Proses Cucian?</div>
    <div class="modal-sub">Nota berikut akan dipindahkan ke <strong>Sedang Diproses</strong>:</div>
    <div class="modal-warning" id="modalWarningTerlambat" style="display:none">
            <span id="modalWarningTerlambatText"></span>
    </div>
    <div class="modal-list" id="listProses"></div>
    <div class="confirm-btns">
      <button class="btn-cancel" onclick="tutupModal('modalProses')">Batal</button>
      <button class="btn-ok" onclick="jalankan()">Ya, Proses</button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>
const rincianData = <?= $rincian_json_out ?>;
const CSRF_TOKEN   = <?= $csrf_token_out ?>;
const selA = new Set();

// ── FILTER "Terlambat", URUTKAN "Terbaru/Terlama" & PENCARIAN ──
let activeFilterA = 'semua';
let activeSortA   = null; // null = urutan asli server (estimasi terdekat dulu)
let activeSearchA = '';
function setFilterA(key) {
    activeFilterA = key;
    document.querySelectorAll('#ftab-semua, #ftab-terlambat').forEach(b => b.classList.remove('active'));
    document.getElementById('ftab-' + key)?.classList.add('active');
    applyFilterA();
}
function setSortA(key) {
    activeSortA = (activeSortA === key) ? null : key;
    document.getElementById('sort-terbaru')?.classList.toggle('active', activeSortA === 'terbaru');
    document.getElementById('sort-terlama')?.classList.toggle('active', activeSortA === 'terlama');
    applyFilterA();
}
function filterTabelA(kw) {
    activeSearchA = kw.toLowerCase().trim();
    applyFilterA();
}
function applyFilterA() {
    const tbody = document.getElementById('tbodyAntrian');
    if (!tbody) return;
    let rows = Array.from(tbody.querySelectorAll('tr[data-filter]'));

    if (activeSortA) {
        rows.sort((a, b) => {
            const ta = parseInt(a.dataset.created) || 0;
            const tb = parseInt(b.dataset.created) || 0;
            return activeSortA === 'terbaru' ? tb - ta : ta - tb;
        });
        rows.forEach(row => {
            const rowAksi = row.nextElementSibling;
            tbody.appendChild(row);
            if (rowAksi && rowAksi.classList.contains('trx-actions-row')) tbody.appendChild(rowAksi);
        });
    }

    let visible = 0;
    rows.forEach(row => {
        const matchFilter = activeFilterA === 'semua' || row.dataset.filter.includes(activeFilterA);
        const matchSearch = activeSearchA === '' || row.dataset.search.includes(activeSearchA);
        const show = matchFilter && matchSearch;
        row.style.display = show ? '' : 'none';
        const rowAksi = row.nextElementSibling;
        if (rowAksi && rowAksi.classList.contains('trx-actions-row')) {
            rowAksi.style.display = show ? '' : 'none';
        }
        if (show) visible++;
    });
    let emptyRow = document.getElementById('filterEmptyRowA');
    if (visible === 0) {
        if (!emptyRow) {
            emptyRow = document.createElement('tr');
            emptyRow.id = 'filterEmptyRowA';
            emptyRow.innerHTML = '<td colspan="6" style="text-align:center;padding:28px;color:#4B5563">Tidak ditemukan nota yang cocok.</td>';
            tbody.appendChild(emptyRow);
        }
    } else {
        emptyRow?.remove();
    }
}

function formatRp(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); }

// ── CHECKBOX ──
function onCb(cb) {
    const id = cb.dataset.id;
    if (cb.checked) selA.add(id); else selA.delete(id);
    document.getElementById('rowA-'+id)?.classList.toggle('row-selected', cb.checked);
    updateToolbar();
}

function toggleAll(cbAll) {
    const cbs = document.querySelectorAll('.cbA');
    selA.clear();
    cbs.forEach(cb => {
        cb.checked = cbAll.checked;
        const row  = document.getElementById('rowA-'+cb.dataset.id);
        if (cbAll.checked) { selA.add(cb.dataset.id); row?.classList.add('row-selected'); }
        else { row?.classList.remove('row-selected'); }
    });
    updateToolbar();
}

function updateToolbar() {
    const n = selA.size;
    document.getElementById('selCountA').textContent    = n;
    document.getElementById('selCountABtn').textContent = n;
    document.getElementById('btnMulaiProses').disabled  = n===0;
}

// ── MODAL DETAIL ──
function showDetail(id) {
    const d = rincianData[id];
    if (!d) return;

    let itemsHtml = '';
    if (d.items.length === 0) {
        itemsHtml = '<div style="color:#4B5563;font-size:13px">Tidak ada rincian item.</div>';
    } else {
        d.items.forEach(item => {
            itemsHtml +=
                '<div class="item-row">' +
                  '<div class="item-name">' + item.nama + '<small>' + item.kategori + ' &middot; ' + item.qty + ' ' + item.satuan + '</small></div>' +
                  '<div class="item-price">' + formatRp(item.subtotal) + '</div>' +
                '</div>';
        });
    }

    document.getElementById('detailBody').innerHTML =
        '<div style="margin-bottom:6px">' +
          '<div class="nota-code" style="font-size:15px">' + d.nota + (d.terlambat ? '<span class="late-tag">TERLAMBAT</span>' : '') + '</div>' +
          '<div style="font-size:13px;color:#3A4450;margin-top:2px">' + d.nama + ' &middot; ' + d.no_hp + '</div>' +
          '<div style="font-size:12px;color:#4B5563;margin-top:4px">Estimasi selesai: ' + d.estimasi + '</div>' +
        '</div>' +
        '<div class="modal-sub-title">Rincian Item</div>' +
        itemsHtml;

    document.getElementById('modalDetail').classList.add('active');
}

// ── AKSI PROSES ──
let _aksiIds = [];

function konfirmasiProses() {
    if (selA.size === 0) return;
    bukaModalProses(Array.from(selA));
}

function prosesSatu(id) {
    bukaModalProses([String(id)]);
}

function bukaModalProses(ids) {
    _aksiIds = ids;
    const list = document.getElementById('listProses');
    list.innerHTML = '';
    let jmlTerlambat = 0;

    _aksiIds.forEach(id => {
        const d = rincianData[id];
        if (!d) return;
        if (d.terlambat) jmlTerlambat++;

        const el = document.createElement('div');
        el.className = 'modal-list-item';
        el.innerHTML =
            '<span><span class="nota-txt">' + d.nota + '</span> <span class="nota-nama">— ' + d.nama + '</span></span>' +
            (d.terlambat ? '<span class="late-tag">TERLAMBAT</span>' : (d.estimasi !== '-' ? '<span style="color:#4B5563;font-size:11px">' + d.estimasi + '</span>' : ''));
        list.appendChild(el);
    });

    const warnBox = document.getElementById('modalWarningTerlambat');
    if (jmlTerlambat > 0) {
        document.getElementById('modalWarningTerlambatText').textContent =
            jmlTerlambat + ' dari ' + _aksiIds.length + ' nota sudah melewati estimasi selesai.';
        warnBox.style.display = 'flex';
    } else {
        warnBox.style.display = 'none';
    }

    document.getElementById('modalProses').classList.add('active');
}

async function jalankan() {
    tutupModal('modalProses');
    let ok=0, gagal=0;

    for (const id of _aksiIds) {
        const fd = new FormData();
        fd.append('transaksi_id', id);
        fd.append('status', 'sedang_diproses');
        fd.append('csrf_token', CSRF_TOKEN);
        try {
            const res  = await fetch('update_status_cucian.php', {method:'POST',body:fd,credentials:'same-origin'});
            const data = await res.json();
            if (data.success) {
                ok++;
                const rowA = document.getElementById('rowA-'+id);
                const rowAksi = rowA?.nextElementSibling;
                if (rowAksi && rowAksi.classList.contains('trx-actions-row')) rowAksi.remove();
                rowA?.remove();
                selA.delete(id);
            } else { gagal++; }
        } catch(e) { gagal++; }
    }

    updateToolbar();
    const cbAll = document.getElementById('cbAllA');
    if (cbAll) cbAll.checked = false;

    if (ok>0)    showToast('fa-circle-check', ok+' nota berhasil dipindahkan ke proses.', 'ok');
    if (gagal>0) showToast('fa-circle-exclamation', gagal+' nota gagal diproses.', 'err');

    // Baris yang berhasil sudah dihapus langsung dari tampilan di atas,
    // jadi halaman tidak perlu di-reload lagi (biar posisi & filter yang
    // sedang dilihat tidak ikut ter-reset). Angka statistik/badge sidebar
    // akan otomatis akurat lagi saat halaman ini dibuka ulang secara wajar.
}

// ── UTILS ──
function tutupModal(id) { document.getElementById(id)?.classList.remove('active'); }
function showToast(iconClass, msg, type) {
    const wrap=document.getElementById('toastWrap');
    const el=document.createElement('div');
    el.className='toast '+type;
    el.innerHTML=msg;
    wrap.appendChild(el);
    setTimeout(()=>el.remove(),3000);
}
document.querySelectorAll('.modal-overlay').forEach(el=>
    el.addEventListener('click',e=>{if(e.target===el)el.classList.remove('active');})
);
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

<?php
$csrfTokenJs = csrf_token();
$antrianNotifWaAwal = array_map(fn($id) => ['type' => 'terlambat', 'transaksi_id' => $id], $antrianNotifTerlambat);
require __DIR__ . '/includes/notif_wa_widget.php';
?>
</body>
</html>