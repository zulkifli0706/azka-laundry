<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/estimasi_helper.php';
requireRole('bagian_pencucian', 'owner');

$antrianNotifTerlambat = cekPerpanjanganEstimasi($pdo);

$namaPetugas = htmlspecialchars($_SESSION['user']['name']);
$inisial     = strtoupper(substr($_SESSION['user']['name'], 0, 2));

// ── STATISTIK ──
$jml_antrian   = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'dalam_antrian'")->fetchColumn();
$jml_proses    = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'sedang_diproses'")->fetchColumn();
$jml_siap      = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'siap_diambil'")->fetchColumn();
$jml_terlambat = $pdo->query("
    SELECT COUNT(*) FROM transaksi t
    JOIN status_cucian s ON t.id_transaksi = s.transaksi_id
    WHERE (t.tanggal_estimasi < CURDATE() OR t.pernah_terlambat = 1)
    AND s.status IN ('dalam_antrian', 'sedang_diproses')
")->fetchColumn();

// ── NOTA URGENT (terlambat / paling dekat estimasi) DI ANTRIAN & PROSES ──
// LIMIT dinaikkan dari 5 -> 20 supaya tab filter "Terlambat" di bawah punya
// cukup data buat ditampilkan (bukan cuma 5 teratas berdasar estimasi
// terdekat) -- tetap ringkas karena ini cuma widget ringkasan, daftar
// lengkap & bisa dicari ada di antrian.php / proses.php.
$tab_urgent = $pdo->query("
    SELECT t.id_transaksi, t.id_nota, t.nama_pelanggan, t.no_hp_pelanggan, t.tanggal_estimasi, t.pernah_terlambat, t.created_at,
           s.status, s.updated_at AS status_updated_at, up.name AS nama_petugas
    FROM transaksi t
    JOIN status_cucian s ON t.id_transaksi = s.transaksi_id
    LEFT JOIN users up   ON up.id_user = s.updated_by
    WHERE s.status IN ('dalam_antrian', 'sedang_diproses')
    ORDER BY t.tanggal_estimasi ASC, t.created_at ASC
    LIMIT 20
")->fetchAll();

// ── DETAIL ITEM + KATEGORI UNTUK MODAL DETAIL (klik nama/nota) ──
$detailPerTrx   = [];
$kategoriPerTrx = [];
$semuaIds = array_column($tab_urgent, 'id_transaksi');
if (!empty($semuaIds)) {
    $in = implode(',', array_map('intval', $semuaIds));
    $rows = $pdo->query("
        SELECT dt.transaksi_id, COALESCE(il.nama_item, dt.keterangan, 'Item') AS nama_item,
               COALESCE(il.satuan, 'pcs') AS satuan, dt.qty,
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

$rincianJson = [];
foreach ($tab_urgent as $u) {
    $items = $detailPerTrx[$u['id_transaksi']] ?? [];
    $rincianJson[$u['id_transaksi']] = [
        'nota'      => $u['id_nota'],
        'nama'      => $u['nama_pelanggan'],
        'no_hp'     => $u['no_hp_pelanggan'],
        'estimasi'  => !empty($u['tanggal_estimasi']) ? date('d/m/Y', strtotime($u['tanggal_estimasi'])) : '-',
        'terlambat' => !empty($u['pernah_terlambat']) || (!empty($u['tanggal_estimasi']) && strtotime($u['tanggal_estimasi']) < strtotime('today')),
        'status'    => $u['status'],
        'petugas'    => $u['nama_petugas'] ?: null,
        'diubah_pada' => !empty($u['status_updated_at']) ? date('d/m/Y H:i', strtotime($u['status_updated_at'])) : null,
        'kategori'  => array_keys($kategoriPerTrx[$u['id_transaksi']] ?? []),
        'items'     => array_map(fn($i) => [
            'nama'     => $i['nama_item'],
            'kategori' => $i['nama_kategori'] ?: '-',
            'qty'      => rtrim(rtrim($i['qty'], '0'), '.'),
            'satuan'   => $i['satuan'],
        ], $items),
    ];
}
$rincian_json_out = json_encode($rincianJson, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Dashboard Pencucian — Azka Laundry</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --sky:        #2E74C0;
    --sky-deep:   #1F5FA6;
    --sky-ink:    #1F5FA6;
    --sky-soft:   #EAF2FB;
    --sky-pale:   #EAF2FB;
    --cloud:      #FFFFFF;
    --border:     #D6E7F7;
    --ink:        #164A85;
    --gray:       #2E74C0;
    --gray-soft:  #4E90D9;
}
* { font-family:'Plus Jakarta Sans',sans-serif; box-sizing:border-box; }
body { background:var(--sky-pale); color:#101418; }

/* SIDEBAR */
.sidebar { width:230px; height:100vh; height:100dvh; background:var(--sky-ink); position:fixed; left:0; top:0; display:flex; flex-direction:column; z-index:100; overflow-y:auto; }
.sidebar-brand { padding:20px; border-bottom:1px solid rgba(255,255,255,0.14); display:flex; align-items:center; gap:10px; }
.sidebar-brand svg { width:28px; height:28px; flex-shrink:0; }
.sidebar-brand h4 { color:#FFFFFF; font-size:16px; font-weight:700; margin:0; }
.sidebar-brand small { color:rgba(255,255,255,0.55); font-size:11px; }
.sidebar-menu { padding:14px 10px; flex:1; }
.sidebar-menu a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.68); text-decoration:none; padding:10px 12px; border-radius:8px; margin-bottom:2px; font-size:13px; font-weight:500; transition:all 0.2s; }
.sidebar-menu a:hover, .sidebar-menu a.active { background:rgba(255,255,255,0.14); color:#FFFFFF; }
.sidebar-menu a.active { border-left:3px solid #FFFFFF; }
.sidebar-menu a i { width:16px; text-align:center; }
.sidebar-footer { padding:14px 10px calc(14px + env(safe-area-inset-bottom)); border-top:1px solid rgba(255,255,255,0.14); flex-shrink:0; }
.sidebar-footer a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.55); text-decoration:none; padding:9px 12px; border-radius:8px; font-size:13px; transition:all 0.2s; }
.sidebar-footer a:hover { background:rgba(58,68,80,0.2); color:#D6E7F7; }

/* MAIN */
.main { margin-left:230px; }

/* ===== MOBILE SIDEBAR DRAWER ===== */
.hamburger-btn {
    display:none; position:fixed; top:12px; left:14px; z-index:200;
    background:var(--sky-ink); color:#FFFFFF; border:none; width:42px; height:42px;
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
}
.topbar { background:var(--cloud); border-bottom:1px solid var(--border); padding:14px 26px; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:99; }
.topbar-title { font-size:17px; font-weight:700; color:#101418; }
.topbar-title small { display:block; font-family:'Plus Jakarta Sans',sans-serif; font-size:12px; color:#3A4450; font-weight:400; }
.avatar { width:36px; height:36px; border-radius:50%; background:var(--sky); display:flex; align-items:center; justify-content:center; color:#FFFFFF; font-weight:700; font-size:12px; }
.topbar-right { display:flex; align-items:center; gap:12px; }
.user-name { font-size:13px; font-weight:600; color:#101418; }
.content { padding:22px 26px; }

/* SECTION LABEL */
.section-label { font-size:11px; font-weight:700; color:#4B5563; letter-spacing:0.6px; text-transform:uppercase; margin-bottom:10px; }

/* STAT GRID — baris kotak-kotak KPI di paling atas (Dalam Antrian / Sedang Diproses / Siap Diambil) */
.stat-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:16px; margin-bottom:20px; }
@media (max-width:700px) { .stat-grid { grid-template-columns:1fr; } }
.stat-card.no-link { cursor:default; }
.stat-card.no-link:hover { transform:none; box-shadow:none; }

/* STATS — now act as the primary shortcuts too */
.stat-card { background:var(--cloud); border-radius:16px; padding:20px 22px; border:1px solid var(--border); border-top:3px solid var(--sky); text-decoration:none; display:flex; align-items:center; gap:16px; transition:transform 0.15s, box-shadow 0.15s; }
.stat-card:hover { transform:translateY(-2px); box-shadow:0 10px 24px rgba(22,74,133,0.08); }
.stat-card.warn  { border-top-color:#3A4450; }
.stat-card.info  { border-top-color:#4E90D9; }
.stat-card.danger{ border-top-color:#3A4450; }
.stat-icon-wrap { width:50px; height:50px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; background:var(--sky-soft); color:var(--sky-deep); }
.stat-card.warn   .stat-icon-wrap { background:#EAF2FB; color:#3A4450; }
.stat-card.info   .stat-icon-wrap { background:#EAF2FB; color:#3A4450; }
.stat-card.danger .stat-icon-wrap { background:#EAF2FB; color:#3A4450; }
.stat-body { flex:1; min-width:0; }
.stat-label { font-size:12px; color:#3A4450; margin-bottom:2px; font-weight:500; }
.stat-num   { font-size:24px; font-weight:800; color:#101418; line-height:1; }
.stat-arrow { color:#4B5563; font-size:13px; flex-shrink:0; }
.stat-card:hover .stat-arrow { color:var(--sky-deep); }

/* WARNING */
.warning-bar { background:#EAF2FB; border:1px solid #D6E7F7; border-radius:12px; padding:11px 16px; margin-bottom:18px; font-size:13px; color:#3A4450; font-weight:600; display:flex; align-items:center; gap:9px; }

/* PANEL — primary focus area */
.panel { background:var(--cloud); border-radius:16px; border:1px solid var(--border); overflow:hidden; }
.panel-header { padding:16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
.panel-header h5 { font-size:15px; font-weight:700; color:#101418; margin:0; display:flex; align-items:center; gap:9px; }
.panel-header .panel-sub { font-size:12px; color:#4B5563; font-weight:500; margin-left:2px; }
.panel-header .panel-links { display:flex; gap:14px; }
.panel-header .panel-links a { font-size:12px; font-weight:600; color:var(--sky-deep); text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.panel-header .panel-links a:hover { color:#101418; }

.filter-tabs { display:flex; background:var(--sky-pale); border-radius:9px; padding:3px; gap:2px; border:1px solid var(--border); }
.ftab { padding:7px 13px; border:none; background:transparent; border-radius:7px; font-size:12px; font-weight:600; color:#3A4450; cursor:pointer; font-family:inherit; white-space:nowrap; display:inline-flex; align-items:center; gap:6px; }
.ftab:hover { color:var(--sky-deep); }
.ftab.active { background:#FFFFFF; color:var(--sky-deep); border:1px solid var(--border); }
.ftab-count { display:inline-block; background:rgba(46,116,192,0.14); border-radius:20px; padding:1px 7px; font-size:10px; font-weight:700; }
.ftab.active .ftab-count { background:rgba(31,95,166,0.14); color:var(--sky-deep); }

.urgent-list { padding:4px 0; }
.urgent-item { display:flex; align-items:center; gap:14px; padding:14px 20px; border-bottom:1px solid var(--sky-soft); transition:background 0.15s; }
.urgent-item:last-child { border-bottom:none; }
.urgent-item:hover { background:var(--sky-pale); }
.urgent-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
.urgent-dot.late { background:#3A4450; box-shadow:0 0 0 3px rgba(58,68,80,0.15); }
.urgent-dot.warn { background:#3A4450; box-shadow:0 0 0 3px rgba(58,68,80,0.15); }
.urgent-dot.ok   { background:#1F5FA6; box-shadow:0 0 0 3px rgba(31,95,166,0.15); }
.urgent-item.clickable { cursor:pointer; }
.urgent-info { min-width:0; }
.urgent-nota { font-family:monospace; font-weight:700; color:#101418; font-size:13px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.urgent-nama { font-size:12px; color:#3A4450; margin-top:1px; }
.late-tag { display:inline-block; background:#3A4450; color:#FFFFFF; font-size:9px; font-weight:700; padding:2px 7px; border-radius:5px; letter-spacing:0.3px; }
.urgent-status { font-size:10px; font-weight:700; padding:4px 10px; border-radius:20px; margin-left:auto; flex-shrink:0; }
.urgent-status.antri { background:#D6E7F7; color:#3A4450; }
.urgent-status.proses { background:#EAF2FB; color:#3A4450; }
.urgent-est { font-size:12px; font-weight:600; min-width:80px; text-align:right; flex-shrink:0; }
.urgent-est .urgent-est-tag { display:block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:1px; }
.urgent-est.late { color:#3A4450; }
.urgent-est.warn { color:#3A4450; }
.urgent-est.ok   { color:#1B2126; }

/* EMPTY */
.empty-state { text-align:center; padding:40px 20px; color:#4B5563; }
.empty-state .ei { width:54px; height:54px; border-radius:50%; background:var(--sky-soft); color:var(--sky-deep); display:flex; align-items:center; justify-content:center; font-size:21px; margin:0 auto 12px; }
.empty-state p { font-size:13px; color:#3A4450; margin:0; }

/* MODAL DETAIL */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(22,74,133,0.45); z-index:999; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.active { display:flex; }
.modal-box { background:var(--cloud); border-radius:14px; width:440px; max-width:92vw; max-height:85vh; overflow-y:auto; text-align:left; }
.modal-head { background:var(--sky-deep); padding:16px 20px; color:#FFFFFF; font-size:15px; font-weight:700; display:flex; align-items:center; gap:8px; }
.modal-head-close { margin-left:auto; background:none; border:none; color:#FFFFFF; font-size:15px; cursor:pointer; }
.modal-body { padding:20px; }
.modal-sub-title { font-size:11px; font-weight:700; color:#3A4450; text-transform:uppercase; letter-spacing:0.5px; margin:14px 0 8px; }
.modal-sub-title:first-child { margin-top:0; }
.item-row { display:flex; align-items:center; gap:8px; background:var(--sky-soft); border:1px solid var(--border); border-radius:9px; padding:9px 12px; margin-bottom:7px; font-size:13px; }
.item-row .item-name { flex:1; font-weight:600; color:#101418; }
.item-row .item-name small { display:block; font-weight:400; color:#3A4450; }
.modal-status-badge { font-size:10px; font-weight:700; padding:4px 10px; border-radius:20px; }
.modal-status-badge.antri { background:#D6E7F7; color:#3A4450; }
.modal-status-badge.proses { background:#EAF2FB; color:#3A4450; }
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
    <a href="dashboard_pencucian.php" class="active">Dashboard</a>
    <a href="antrian.php">Antrian</a>
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
        Panel Pencucian
        <small><?= date('l, d M Y') ?> &middot; <?= $namaPetugas ?></small>
      </div>
    </div>
    <div class="topbar-right">
      <div style="text-align:right">
        <div class="user-name"><?= $namaPetugas ?></div>
        <div style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;background:var(--sky-soft);color:var(--sky-deep);display:inline-block;margin-top:2px">Bagian Pencucian</div>
      </div>
      <div class="avatar"><?= $inisial ?></div>
    </div>
  </div>

  <div class="content">

    <!-- KOTAK-KOTAK STATISTIK -->
    <div class="stat-grid">
      <a href="antrian.php" class="stat-card">
        <div class="stat-icon-wrap">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div class="stat-body">
          <div class="stat-label">Dalam Antrian</div>
          <div class="stat-num"><?= $jml_antrian ?></div>
        </div>
        <span class="stat-arrow">&#8250;</span>
      </a>
      <a href="proses.php" class="stat-card info">
        <div class="stat-icon-wrap">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"></path><path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path><path d="M3 22v-6h6"></path><path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path></svg>
        </div>
        <div class="stat-body">
          <div class="stat-label">Sedang Diproses</div>
          <div class="stat-num"><?= $jml_proses ?></div>
        </div>
        <span class="stat-arrow">&#8250;</span>
      </a>
      <div class="stat-card no-link">
        <div class="stat-icon-wrap">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
        </div>
        <div class="stat-body">
          <div class="stat-label">Siap Diambil</div>
          <div class="stat-num"><?= $jml_siap ?></div>
        </div>
      </div>
    </div>

    <?php if ($jml_terlambat > 0): ?>
    <div class="warning-bar"><span>Ada <strong><?= $jml_terlambat ?> cucian</strong> yang melewati estimasi selesai.</span></div>
    <?php endif; ?>

    <div class="section-label">Perlu Dikerjakan Sekarang</div>
    <div class="panel">
      <div class="panel-header">
        <h5>Diurutkan dari estimasi paling dekat</h5>
        <div class="filter-tabs" id="filterGroupStatusU">
          <button class="ftab active" id="ftab-semua" onclick="setFilterU('semua')">
            Semua <span class="ftab-count" id="fc-semua"><?= count($tab_urgent) ?></span>
          </button>
          <?php $jmlUrgentTerlambat = count(array_filter($tab_urgent, fn($u) => !empty($u['pernah_terlambat']) || (!empty($u['tanggal_estimasi']) && strtotime($u['tanggal_estimasi']) < strtotime('today')))); ?>
          <?php if ($jmlUrgentTerlambat > 0): ?>
          <button class="ftab" id="ftab-terlambat" onclick="setFilterU('terlambat')">
            Terlambat <span class="ftab-count" id="fc-terlambat"><?= $jmlUrgentTerlambat ?></span>
          </button>
          <?php endif; ?>
        </div>
        <div class="filter-tabs">
          <button class="ftab" id="sort-terbaru" onclick="setSortU('terbaru')">Terbaru</button>
          <button class="ftab" id="sort-terlama" onclick="setSortU('terlama')">Terlama</button>
        </div>
        <div class="panel-links">
          <a href="antrian.php">Antrian</a>
          <a href="proses.php">Diproses</a>
        </div>
      </div>
      <div class="urgent-list" id="urgentList">
        <?php if (empty($tab_urgent)): ?>
        <div class="empty-state"><div class="ei">OK</div><p>Tidak ada cucian dalam antrian maupun proses.</p></div>
        <?php else: ?>
        <?php foreach ($tab_urgent as $u):
          $terlambat = !empty($u['pernah_terlambat']) || (!empty($u['tanggal_estimasi']) && strtotime($u['tanggal_estimasi']) < strtotime('today'));
          $segera    = !$terlambat && !empty($u['tanggal_estimasi']) && strtotime($u['tanggal_estimasi']) <= strtotime('+1 day');
          $dotClass  = $terlambat ? 'late' : ($segera ? 'warn' : 'ok');
          $estClass  = $dotClass;
          $estTag    = $terlambat ? 'Terlambat' : ($segera ? 'Segera' : 'Estimasi');
          $estFmt    = !empty($u['tanggal_estimasi']) ? date('d/m/Y', strtotime($u['tanggal_estimasi'])) : '-';
        ?>
        <div class="urgent-item clickable" data-filter="semua<?= $terlambat ? ' terlambat' : '' ?>" data-created="<?= strtotime($u['created_at']) ?>" onclick="showDetail(<?= $u['id_transaksi'] ?>)" title="Lihat rincian pesanan">
          <span class="urgent-dot <?= $dotClass ?>"></span>
          <div class="urgent-info">
            <div class="urgent-nota">
              <?= htmlspecialchars($u['id_nota']) ?>
              <?php if ($terlambat): ?><span class="late-tag">TERLAMBAT</span><?php endif; ?>
            </div>
            <div class="urgent-nama"><?= htmlspecialchars($u['nama_pelanggan']) ?></div>
          </div>
          <span class="urgent-status <?= $u['status']==='dalam_antrian' ? 'antri' : 'proses' ?>">
            <?= $u['status']==='dalam_antrian' ? 'ANTRIAN' : 'PROSES' ?>
          </span>
          <span class="urgent-est <?= $estClass ?>">
            <span class="urgent-est-tag"><?= $estTag ?></span><?= $estFmt ?>
          </span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

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

<script>
const rincianData = <?= $rincian_json_out ?>;

function tutupModal(id) { document.getElementById(id)?.classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(el =>
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('active'); })
);

// ── FILTER "Terlambat" untuk widget ringkasan ini ──
let activeFilterU = 'semua';
let activeSortU   = null;

function setFilterU(key) {
    activeFilterU = key;
    document.querySelectorAll('#filterGroupStatusU .ftab').forEach(b => b.classList.remove('active'));
    document.getElementById('ftab-' + key)?.classList.add('active');
    renderFilterU();
}

function setSortU(key) {
    activeSortU = (activeSortU === key) ? null : key;
    document.getElementById('sort-terbaru')?.classList.toggle('active', activeSortU === 'terbaru');
    document.getElementById('sort-terlama')?.classList.toggle('active', activeSortU === 'terlama');
    renderFilterU();
}

function renderFilterU() {
    const list = document.getElementById('urgentList');
    let items = Array.from(list.querySelectorAll('.urgent-item[data-filter]'));

    if (activeSortU) {
        items.sort((a, b) => {
            const ta = parseInt(a.dataset.created) || 0;
            const tb = parseInt(b.dataset.created) || 0;
            return activeSortU === 'terbaru' ? tb - ta : ta - tb;
        });
        items.forEach(el => list.appendChild(el));
    }

    let visible = 0;
    items.forEach(el => {
        const show = activeFilterU === 'semua' || el.dataset.filter.includes(activeFilterU);
        el.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    let kosong = document.getElementById('urgentEmpty');
    if (visible === 0) {
        if (!kosong) {
            kosong = document.createElement('div');
            kosong.id = 'urgentEmpty';
            kosong.className = 'empty-state';
            kosong.innerHTML = '<div class="ei">OK</div><p>Tidak ada nota terlambat saat ini.</p>';
            document.getElementById('urgentList').appendChild(kosong);
        }
    } else {
        kosong?.remove();
    }
}

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
                '</div>';
        });
    }

    const statusBadge = d.status === 'dalam_antrian'
        ? '<span class="modal-status-badge antri">ANTRIAN</span>'
        : '<span class="modal-status-badge proses">PROSES</span>';

    const petugasHtml = d.petugas
        ? '<div style="font-size:12px;color:#4B5563;margin-top:4px">Terakhir diubah oleh: <strong style="color:#101418">' + d.petugas + '</strong>' + (d.diubah_pada ? ' &middot; ' + d.diubah_pada : '') + '</div>'
        : '';

    document.getElementById('detailBody').innerHTML =
        '<div style="margin-bottom:6px">' +
          '<div class="urgent-nota" style="font-size:15px">' + d.nota + (d.terlambat ? '<span class="late-tag">TERLAMBAT</span>' : '') + '</div>' +
          '<div style="font-size:13px;color:#3A4450;margin-top:2px">' + d.nama + ' &middot; ' + d.no_hp + '</div>' +
          '<div style="font-size:12px;color:#4B5563;margin-top:4px">Estimasi selesai: ' + d.estimasi + '</div>' +
          petugasHtml +
          '<div style="margin-top:8px">' + statusBadge + '</div>' +
        '</div>' +
        '<div class="modal-sub-title">Rincian Item</div>' +
        itemsHtml;

    document.getElementById('modalDetail').classList.add('active');
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

<script>
// ── HEARTBEAT: ping server tiap 30 menit selama tab dashboard ini terbuka ──
// Supaya pengecekan otomatis "estimasi diperpanjang / terlambat" tetap
// kepicu tepat waktu walau tidak ada request halaman lain yang masuk ke
// server (lihat heartbeat.php & includes/config.php untuk detail).
(function () {
    var INTERVAL_MS = 30 * 60 * 1000; // 30 menit
    function kirimHeartbeat() {
        fetch('heartbeat.php', { method: 'GET', credentials: 'same-origin' }).catch(function () {});
    }
    setInterval(kirimHeartbeat, INTERVAL_MS);
})();
</script>
</body>
</html>