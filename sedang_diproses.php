<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/proses_helper.php';
require_once __DIR__ . '/includes/estimasi_helper.php';
requireRole('kasir', 'owner');

$antrianNotifTerlambat = cekPerpanjanganEstimasi($pdo);

$namaKasir = htmlspecialchars($_SESSION['user']['name']);
$inisial    = strtoupper(substr($_SESSION['user']['name'], 0, 2));

// ── SIDEBAR COUNTERS ──
$dalam_antrian   = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'dalam_antrian'")->fetchColumn();
$jml_pesanan_online = $pdo->query("SELECT COUNT(*) FROM pesanan_online WHERE status = 'menunggu'")->fetchColumn();
$sedang_diproses = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'sedang_diproses'")->fetchColumn();
$siap_diambil    = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'siap_diambil'")->fetchColumn();

// ── DATA SEDANG DIPROSES (hanya yang sudah mulai dicuci) ──
$list = $pdo->query("
    SELECT t.id_transaksi, t.id_nota, t.nama_pelanggan, t.no_hp_pelanggan,
           t.total_harga, t.status_bayar, t.total_dibayar, t.tanggal_estimasi, t.pernah_terlambat,
           t.created_at,
           s.status AS status_cucian, s.tahap_proses, s.updated_at AS status_updated_at,
           up.name AS nama_petugas
    FROM transaksi t
    JOIN status_cucian s ON t.id_transaksi = s.transaksi_id
    LEFT JOIN users up   ON up.id_user = s.updated_by
    WHERE s.status = 'sedang_diproses'
    ORDER BY t.tanggal_estimasi ASC, t.created_at ASC
")->fetchAll();

// ── DETAIL ITEM (ikut tahap proses ASLI per-item dari detail_status) ──
$ids = array_column($list, 'id_transaksi');
$detailPerTrx = [];
if (!empty($ids)) {
    $in   = implode(',', array_map('intval', $ids));
    $rows = $pdo->query("
        SELECT dt.transaksi_id, COALESCE(il.nama_item, dt.keterangan, 'Barang') AS nama_layanan,
               COALESCE(il.satuan, 'pcs') AS satuan, dt.qty, dt.subtotal,
               COALESCE(il.tipe_proses, 'cuci_setrika') AS tipe_proses,
               kl.nama_kategori,
               ds.tahap_proses AS tahap_item
        FROM detail_transaksi dt
        LEFT JOIN detail_barang il    ON il.id_barang = dt.detail_barang_id
        LEFT JOIN kategori_barang kl ON kl.id_kategori = il.kategori_id
        LEFT JOIN detail_status ds   ON ds.detail_transaksi_id = dt.id_detail_transaksi
        WHERE dt.transaksi_id IN ($in)
        ORDER BY dt.id_detail_transaksi
    ")->fetchAll();
    foreach ($rows as $r) $detailPerTrx[$r['transaksi_id']][] = $r;
}

$jml_proses    = count($list);
$jml_terlambat = count(array_filter($list, fn($t) =>
    !empty($t['pernah_terlambat']) ||
    (!empty($t['tanggal_estimasi']) && strtotime($t['tanggal_estimasi']) < strtotime('today'))
));

// ── JSON UNTUK MODAL DETAIL ──
$today = strtotime('today');
$rincianJson = [];
foreach ($list as $t) {
    $items       = $detailPerTrx[$t['id_transaksi']] ?? [];
    $terlambat   = !empty($t['pernah_terlambat']) || (!empty($t['tanggal_estimasi']) && strtotime($t['tanggal_estimasi']) < $today);
    $tahapLabel  = !empty($t['tahap_proses']) ? labelTahap($t['tahap_proses'], tipeDominanTransaksi($pdo, $t['id_transaksi']), kategoriDominanTransaksi($pdo, $t['id_transaksi'])) : null;

    $rincianJson[$t['id_transaksi']] = [
        'nota'       => $t['id_nota'],
        'nama'       => $t['nama_pelanggan'],
        'no_hp'      => $t['no_hp_pelanggan'],
        'estimasi'   => !empty($t['tanggal_estimasi']) ? date('d/m/Y', strtotime($t['tanggal_estimasi'])) : '-',
        'masuk'      => date('d/m/Y, H:i', strtotime($t['created_at'])),
        'terlambat'  => $terlambat,
        'bayar'      => $t['status_bayar'],
        'dibayar'    => (float) $t['total_dibayar'],
        'sisa'       => sisaBayar($t['total_harga'], $t['total_dibayar']),
        'tahap'      => $tahapLabel,
        'total'      => (float) $t['total_harga'],
        'petugas'    => $t['nama_petugas'] ?: null,
        'diubah_pada' => !empty($t['status_updated_at']) ? date('d/m/Y H:i', strtotime($t['status_updated_at'])) : null,
        'items'      => array_map(function ($i) use ($t) {
            $qty = rtrim(rtrim($i['qty'], '0'), '.');
            $hargaSatuan = $qty > 0 ? $i['subtotal'] / $qty : 0;
            $st = statusItemAsli($i['tipe_proses'], $t['status_cucian'], $i['tahap_item'] ?? null, $i['nama_kategori'] ?? null);
            return [
                'nama'      => $i['nama_layanan'],
                'qty'       => $qty,
                'satuan'    => $i['satuan'],
                'harga'     => (float) $hargaSatuan,
                'subtotal'  => (float) $i['subtotal'],
                'st_label'  => $st['label'],
                'st_state'  => $st['state'],
                'st_icon'   => $st['icon'],
            ];
        }, $items),
    ];
}
$rincian_json_out = json_encode($rincianJson, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Sedang Diproses — Azka Laundry</title>
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
.bs-proses  { background:var(--blue-deep); color:#FFFFFF; }
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
.item-row { background:#EAF2FB; border:1px solid var(--line); border-radius:9px; padding:10px 12px; margin-bottom:8px; font-size:13px; }
.item-row .item-row-top { display:flex; align-items:center; gap:8px; }
.item-row .item-name { flex:1; font-weight:600; color:#101418; }
.item-row .item-name small { display:block; font-weight:400; color:#3A4450; }
.item-row .item-price { color:var(--blue-deep); font-weight:700; min-width:88px; text-align:right; }
.item-status { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; padding:3px 9px; border-radius:20px; margin-top:7px; }
.item-status.active  { background:var(--blue-pale); color:var(--blue-deep); }
.item-status.done    { background:#EAF2FB; color:#1B2126; }
.item-status.waiting { background:#EAF2FB; color:#3A4450; border:1px solid var(--line); }
.total-box { background:var(--blue-deep); border-radius:10px; padding:14px 16px; display:flex; justify-content:space-between; align-items:center; margin-top:12px; }
.total-box .lbl { color:rgba(255,255,255,0.8); font-size:13px; }
.total-box .val { color:#FFFFFF; font-size:19px; font-weight:700; }
.modal-footnote { display:flex; align-items:center; gap:8px; background:var(--blue-pale); border-radius:9px; padding:10px 12px; margin-top:14px; font-size:12px; color:var(--blue-deep); }

/* TOAST (tidak dipakai di halaman ini, disiapkan untuk konsistensi) */
.toast-wrap { position:fixed; bottom:24px; right:24px; z-index:9999; display:flex; flex-direction:column; gap:8px; }
.toast { padding:12px 18px; border-radius:10px; font-size:13px; font-weight:600; color:#FFFFFF; display:flex; align-items:center; gap:8px; }
.toast.ok  { background:var(--blue-deep); }
.toast.err { background:var(--ink); }

@media (max-width:768px) {
    .panel-header { flex-direction:column; align-items:stretch; }
    .panel-toolbar { flex-direction:column; align-items:stretch; }
    .search-wrap { width:100%; }
}

/* ===== TABEL -> KARTU DI HP (biar gak perlu geser2 & kepotong) ===== */
@media (max-width: 700px) {
    .warning-bar, .readonly-info { align-items:flex-start; }
    .trx-table-wrap { overflow-x:hidden; padding:6px; }
    .trx-table { table-layout:fixed; font-size:10.5px; }

    /* Nota, Pelanggan, Tahap, Total, Bayar, Estimasi */
    .trx-table th:nth-child(1), .trx-table td:nth-child(1) { width:22%; }
    .trx-table th:nth-child(2), .trx-table td:nth-child(2) { width:17%; }
    .trx-table th:nth-child(3), .trx-table td:nth-child(3) { width:14%; }
    .trx-table th:nth-child(4), .trx-table td:nth-child(4) { width:13%; }
    .trx-table th:nth-child(5), .trx-table td:nth-child(5) { width:13%; }
    .trx-table th:nth-child(6), .trx-table td:nth-child(6) { width:13%; }
    .trx-table th:nth-child(7) { display:none; }
    .trx-table td[data-label="Aksi"] { display:none; }

    .trx-table thead th { padding:6px 3px; font-size:7.5px; white-space:normal; word-break:break-word; }
    .trx-table tbody td { padding:6px 3px; font-size:9.5px; word-break:break-word; vertical-align:top; }

    .trx-table .nota-code { font-size:9px; white-space:nowrap; letter-spacing:-0.2px; }
    .trx-table .late-tag { font-size:7px; padding:1px 3px; }
    .trx-table td[data-label="Pelanggan"] div:first-child { font-size:9.5px; }
    .trx-table td[data-label="Pelanggan"] div:last-child  { font-size:8px; }
    .badge-s { font-size:8px; padding:2px 5px; white-space:normal; }
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
    <a href="pesanan_online.php">
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
    <a href="sedang_diproses.php" class="active">
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
        Sedang Diproses
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

    <?php if ($jml_terlambat > 0): ?>
    <div class="warning-bar">
            <span><strong><?= $jml_terlambat ?> nota</strong> melewati estimasi selesai &mdash; segera hubungi bagian pencucian.</span>
    </div>
    <?php endif; ?>

    <div class="readonly-info">
            <span>Halaman ini menampilkan nota yang sudah mulai dicuci. Nota yang masih mengantre ada di menu <strong>&nbsp;Antrian</strong>. Perubahan status dilakukan oleh <strong>&nbsp;Bagian Pencucian</strong>.</span>
    </div>

    <?php if (empty($list)): ?>
    <div class="empty-state" id="emptyDefault">
      <div class="ei">OK</div>
      <p>Tidak ada nota yang sedang diproses saat ini.</p>
    </div>
    <?php else: ?>
    <div class="panel">
      <div class="panel-header">
        <h5>Daftar Sedang Diproses <span class="badge-s bs-antrian" style="margin:0"><?= count($list) ?> nota</span></h5>
        <div class="panel-toolbar">
          <div class="filter-tabs" id="filterGroupStatus">
            <button class="ftab active" id="ftab-semua" onclick="setFilter('semua')">
              Semua <span class="ftab-count" id="fc-semua"><?= count($list) ?></span>
            </button>
            <?php if ($jml_terlambat > 0): ?>
            <button class="ftab" id="ftab-terlambat" onclick="setFilter('terlambat')">
              Terlambat <span class="ftab-count" id="fc-terlambat"><?= $jml_terlambat ?></span>
            </button>
            <?php endif; ?>
          </div>
          <div class="filter-tabs">
            <button class="ftab" id="sort-terbaru" onclick="setSort('terbaru')">Terbaru</button>
            <button class="ftab" id="sort-terlama" onclick="setSort('terlama')">Terlama</button>
          </div>
          <div class="search-wrap">
                        <input type="text" id="searchInput" placeholder="Cari nota / nama..." oninput="filterTabel(this.value)">
          </div>
        </div>
      </div>
      <div class="trx-table-wrap">
        <table class="trx-table" id="tabelProses">
          <thead>
            <tr>
              <th>NOTA</th>
              <th>PELANGGAN</th>
              <th>TAHAP</th>
              <th>TOTAL</th>
              <th>BAYAR</th>
              <th>ESTIMASI</th>
              <th>AKSI</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($list as $t):
            $terlambat  = !empty($t['pernah_terlambat']) || (!empty($t['tanggal_estimasi']) && strtotime($t['tanggal_estimasi']) < $today);
            $estFmt     = !empty($t['tanggal_estimasi']) ? date('d/m/Y', strtotime($t['tanggal_estimasi'])) : '-';
            $sudahLunas = $t['status_bayar'] === 'sudah_bayar';
            $sisaTrx = sisaBayar($t['total_harga'], $t['total_dibayar']);
            $dataFilter = 'semua' . ($terlambat ? ' terlambat' : '');
          ?>
          <tr id="row-<?= $t['id_transaksi'] ?>" data-filter="<?= $dataFilter ?>" data-created="<?= strtotime($t['created_at']) ?>" data-search="<?= strtolower(htmlspecialchars($t['id_nota'].' '.$t['nama_pelanggan'])) ?>">
            <td data-label="Nota">
              <span class="nota-code"><?= htmlspecialchars($t['id_nota']) ?></span>
              <?php if ($terlambat): ?><span class="late-tag">TERLAMBAT</span><?php endif; ?>
            </td>
            <td data-label="Pelanggan">
              <div style="font-weight:600;color:#101418"><?= htmlspecialchars($t['nama_pelanggan']) ?></div>
              <div style="font-size:11px;color:#4B5563"><?= htmlspecialchars($t['no_hp_pelanggan']) ?></div>
            </td>
            <td data-label="Tahap"><span class="badge-s bs-proses">Sedang Diproses</span></td>
            <td data-label="Total" style="font-weight:700;color:var(--blue-deep)">Rp<?= number_format($t['total_harga'],0,',','.') ?></td>
            <td data-label="Bayar">
              <?php if ($sudahLunas): ?>
              <span class="badge-s bs-lunas">Lunas</span>
              <?php elseif ($t['status_bayar'] === 'dp'): ?>
              <span class="badge-s bs-dp">Kurang Rp<?= number_format($sisaTrx,0,',','.') ?></span>
              <?php else: ?>
              <span class="badge-s bs-belum">Belum</span>
              <?php endif; ?>
            </td>
            <td data-label="Estimasi" class="<?= $terlambat ? 'est-late' : 'est-ok' ?>"><?= $estFmt ?></td>
            <td data-label="Aksi">
              <div class="btn-acts">
                <button type="button" class="btn-act btn-detail" onclick="showDetail(<?= $t['id_transaksi'] ?>)" title="Lihat rincian pesanan">
                  Detail
                </button>
              </div>
            </td>
          </tr>
          <tr class="trx-actions-row">
            <td colspan="7">
              <div class="btn-acts">
                <button type="button" class="btn-act btn-detail" onclick="showDetail(<?= $t['id_transaksi'] ?>)" title="Lihat rincian pesanan">
                  Detail
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($list)): ?>
    <div style="text-align:center;margin-top:14px;font-size:12px;color:#4B5563">
            <a href="sedang_diproses.php" style="color:#4B5563;text-decoration:none;margin-left:4px">Refresh untuk data terbaru</a>
    </div>
    <?php endif; ?>

  </div>
</div>

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

<div class="toast-wrap" id="toastWrap"></div>

<script>
const rincianData = <?= $rincian_json_out ?>;

function formatRp(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); }

function tutupModal(id) { document.getElementById(id)?.classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(el =>
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('active'); })
);

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
                  '<div class="item-row-top">' +
                    '<div class="item-name">' + item.nama + '<small>' + item.qty + ' ' + item.satuan + ' &middot; ' + formatRp(item.harga) + '/satuan</small></div>' +
                    '<div class="item-price">' + formatRp(item.subtotal) + '</div>' +
                  '</div>' +
                  '<span class="item-status ' + item.st_state + '">' + item.st_icon + ' ' + item.st_label + '</span>' +
                '</div>';
        });
    }

    let bayarBadge;
    if (d.bayar === 'sudah_bayar') {
        bayarBadge = '<span class="badge-s bs-lunas">Lunas</span>';
    } else if (d.bayar === 'dp') {
        bayarBadge = '<span class="badge-s bs-dp">DP ' + formatRp(d.dibayar) + ' &middot; Kurang ' + formatRp(d.sisa) + '</span>';
    } else {
        bayarBadge = '<span class="badge-s bs-belum">Belum Lunas</span>';
    }

    const estimasiHtml =
        '<div style="font-size:12px;color:#4B5563;margin-top:4px">Estimasi: ' + d.estimasi + '</div>';

    // Info petugas pencucian yang terakhir mengubah tahap nota ini --
    // penting sejak ada 2 akun bagian pencucian, supaya kelihatan siapa
    // yang mengerjakan/terakhir update tahapnya.
    const petugasHtml = d.petugas
        ? '<div style="font-size:12px;color:#4B5563;margin-top:4px">Terakhir diubah oleh: <strong style="color:#101418">' + d.petugas + '</strong>' + (d.diubah_pada ? ' &middot; ' + d.diubah_pada : '') + '</div>'
        : '';

    document.getElementById('detailBody').innerHTML =
        '<div style="margin-bottom:6px">' +
          '<div class="nota-code" style="font-size:15px">' + d.nota + (d.terlambat ? '<span class="late-tag">TERLAMBAT</span>' : '') + '</div>' +
          '<div style="font-size:13px;color:#3A4450;margin-top:2px">' + d.nama + ' &middot; ' + d.no_hp + '</div>' +
          '<div style="font-size:12px;color:#4B5563;margin-top:4px">Masuk: ' + d.masuk + '</div>' +
          estimasiHtml +
          petugasHtml +
          '<div style="margin-top:8px">' + (d.tahap ? '<span class="badge-s bs-proses">' + d.tahap + '</span> ' : '') + bayarBadge + '</div>' +
        '</div>' +
        '<div class="modal-sub-title">Rincian Item</div>' +
        itemsHtml +
        '<div class="total-box"><div class="lbl">Total</div><div class="val">' + formatRp(d.total) + '</div></div>' +
        '<div class="modal-footnote">Perubahan status hanya bisa dilakukan oleh Bagian Pencucian.</div>';

    document.getElementById('modalDetail').classList.add('active');
}

// ── FILTER TABS, URUTKAN & SEARCH ──
let activeFilter = 'semua';
let activeSort    = null;
let activeSearch = '';

function setFilter(key) {
    activeFilter = key;
    document.querySelectorAll('#filterGroupStatus .ftab').forEach(b => b.classList.remove('active'));
    document.getElementById('ftab-' + key)?.classList.add('active');
    applyFilter();
}

function setSort(key) {
    activeSort = (activeSort === key) ? null : key;
    document.getElementById('sort-terbaru')?.classList.toggle('active', activeSort === 'terbaru');
    document.getElementById('sort-terlama')?.classList.toggle('active', activeSort === 'terlama');
    applyFilter();
}

function filterTabel(kw) {
    activeSearch = kw.toLowerCase().trim();
    applyFilter();
}

function applyFilter() {
    const tbody = document.querySelector('#tabelProses tbody');
    if (!tbody) return;
    let rows = Array.from(tbody.querySelectorAll('tr[data-filter]'));

    if (activeSort) {
        rows.sort((a, b) => {
            const ta = parseInt(a.dataset.created) || 0;
            const tb = parseInt(b.dataset.created) || 0;
            return activeSort === 'terbaru' ? tb - ta : ta - tb;
        });
        rows.forEach(row => {
            const rowAksi = row.nextElementSibling;
            tbody.appendChild(row);
            if (rowAksi && rowAksi.classList.contains('trx-actions-row')) tbody.appendChild(rowAksi);
        });
    }

    let visible = 0;
    rows.forEach(row => {
        const matchFilter = activeFilter === 'semua' || row.dataset.filter.includes(activeFilter);
        const matchSearch = activeSearch === '' || row.dataset.search.includes(activeSearch);
        const show = matchFilter && matchSearch;
        row.style.display = show ? '' : 'none';
        const rowAksi = row.nextElementSibling;
        if (rowAksi && rowAksi.classList.contains('trx-actions-row')) {
            rowAksi.style.display = show ? '' : 'none';
        }
        if (show) visible++;
    });
    let emptyRow = document.getElementById('filterEmptyRow');
    if (visible === 0) {
        if (!emptyRow) {
            emptyRow = document.createElement('tr');
            emptyRow.id = 'filterEmptyRow';
            emptyRow.innerHTML = '<td colspan="7" style="text-align:center;padding:28px;color:#4B5563">Tidak ditemukan nota yang cocok.</td>';
            tbody.appendChild(emptyRow);
        }
    } else {
        emptyRow?.remove();
    }
}
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