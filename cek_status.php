<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/proses_helper.php';

// Cek nota
$hasil_cek  = null;
$detail_cek = [];
$riwayat_bayar = [];
$status_cek = null;
$error_cek  = null;
$keyword    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_nota'])) {
    $keyword = strtoupper(trim($_POST['id_nota']));

    $stmt = $pdo->prepare("SELECT * FROM transaksi WHERE id_nota = ?");
    $stmt->execute([$keyword]);
    $hasil_cek = $stmt->fetch();

    if ($hasil_cek) {
        $stmt_status = $pdo->prepare("SELECT * FROM status_cucian WHERE transaksi_id = ?");
        $stmt_status->execute([$hasil_cek['id_transaksi']]);
        $status_cek = $stmt_status->fetch();

        $stmt2 = $pdo->prepare("
            SELECT dt.*,
                   COALESCE(il.nama_item, dt.keterangan, 'Barang') AS nama_layanan,
                   COALESCE(il.satuan, 'pcs') AS satuan,
                   il.nama_item AS nama_item_detail, il.harga,
                   COALESCE(il.tipe_proses, 'cuci_setrika') AS tipe_proses,
                   kl.nama_kategori,
                   ds.tahap_proses AS tahap_item
            FROM detail_transaksi dt
            LEFT JOIN detail_barang il    ON il.id_barang = dt.detail_barang_id
            LEFT JOIN kategori_barang kl ON kl.id_kategori = il.kategori_id
            LEFT JOIN detail_status ds   ON ds.detail_transaksi_id = dt.id_detail_transaksi
            WHERE dt.transaksi_id = ?
        ");
        $stmt2->execute([$hasil_cek['id_transaksi']]);
        $detail_cek = $stmt2->fetchAll();

        $stmt3 = $pdo->prepare("SELECT jumlah_bayar, metode, created_at FROM pembayaran WHERE transaksi_id = ? ORDER BY created_at ASC");
        $stmt3->execute([$hasil_cek['id_transaksi']]);
        $riwayat_bayar = $stmt3->fetchAll();
    } else {
        $error_cek = "ID Nota tidak ditemukan. Pastikan ID nota yang Anda masukkan sudah benar.";
    }
}

if (!function_exists('formatRupiah')) {
    function formatRupiah($nominal) {
        return 'Rp ' . number_format((float)$nominal, 0, ',', '.');
    }
}

function statusInfo($status) {
    return match($status) {
        'dalam_antrian'   => ['label' => 'Dalam Antrian',   'color' => '#3A4450', 'bg' => '#D6E7F7', 'step' => 0, 'icon' => ''],
        'sedang_diproses' => ['label' => 'Sedang Diproses', 'color' => '#1F5FA6', 'bg' => '#EAF2FB', 'step' => 1, 'icon' => ''],
        'siap_diambil'    => ['label' => 'Siap Diambil',    'color' => '#1F5FA6', 'bg' => '#D6E7F7', 'step' => 2, 'icon' => ''],
        'selesai'         => ['label' => 'Selesai',         'color' => '#2E74C0', 'bg' => '#EAF2FB', 'step' => 3, 'icon' => ''],
        default           => ['label' => $status,           'color' => '#3A4450', 'bg' => '#EAF2FB', 'step' => 0, 'icon' => ''],
    };
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cek Status Cucian — Azka Laundry</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
<style>
:root {
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

/* ── NAVBAR (sama dengan index.php) ── */
nav { background:rgba(255,255,255,0.85); backdrop-filter:blur(10px); position:sticky; top:0; z-index:99; border-bottom:1px solid var(--border); }
.nav-inner { max-width:1320px; margin:auto; padding:14px 32px; display:flex; justify-content:space-between; align-items:center; position:relative; }
.logo { display:flex; align-items:center; text-decoration:none; }
.logo img { height:56px; width:auto; display:block; }
.nav-menu { display:flex; gap:6px; align-items:center; }
.nav-menu a { text-decoration:none; color:#3A4450; font-size:14px; font-weight:500; padding:8px 14px; border-radius:8px; transition:all 0.2s; }
.nav-menu a:hover, .nav-menu a.active { color:#101418; background:var(--sky-soft); }
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

/* ── PAGE HEADER ── */
.page-header { background:linear-gradient(180deg, #EAF2FB 0%, var(--sky-pale) 55%, var(--sky-pale) 100%); padding:20px 24px 6px; text-align:center; position:relative; overflow:hidden; }
.page-header::before { content:''; position:absolute; inset:0; background:radial-gradient(ellipse at 50% -10%, rgba(78,144,217,0.18) 0%, transparent 55%); pointer-events:none; }
.label-chip { display:inline-block; background:var(--sky-soft); color:var(--sky-deep); font-size:12px; font-weight:700; padding:5px 15px; border-radius:30px; margin-bottom:8px; letter-spacing:0.5px; position:relative; z-index:1; }
.page-header h1 { font-family:'Fraunces', serif; font-weight:600; font-size:22px; color:#101418; margin-bottom:0; position:relative; z-index:1; letter-spacing:-0.4px; }
.page-header p { max-width:520px; margin:0 auto; color:#3A4450; font-size:14px; line-height:1.5; position:relative; z-index:1; }

section { padding:0 24px 40px; }
.section-inner { max-width:1100px; margin:auto; }

/* ── CEK FORM ── */
.cek-wrap { background:var(--cloud); border-radius:22px; border:1px solid var(--border); padding:26px; max-width:720px; margin:0 auto; box-shadow:0 10px 32px rgba(78,144,217,0.14); position:relative; z-index:2; }
.cek-wrap h3 { font-family:'Fraunces', serif; font-weight:600; font-size:18px; color:#101418; margin-bottom:4px; }
.cek-wrap .sub { font-size:13px; color:#3A4450; margin-bottom:16px; }

.cek-input-row {
    display:flex; align-items:center;
    background:var(--sky-pale);
    border:1.5px solid var(--border);
    border-radius:50px; padding:6px 6px 6px 22px;
    transition:border-color 0.2s, box-shadow 0.2s;
}
.cek-input-row:focus-within { border-color:var(--sky); box-shadow:0 4px 16px rgba(78,144,217,0.18); }
.cek-input-row input {
    flex:1; background:transparent; border:none; outline:none;
    color:#101418; font-size:15px; font-family:inherit;
    letter-spacing:1.5px; text-transform:uppercase; padding:8px 0;
}
.cek-input-row input::placeholder { color:#4B5563; text-transform:none; letter-spacing:0; font-size:14px; }
.cek-input-row button {
    background:var(--sky); color:#FFFFFF; border:none;
    border-radius:40px; padding:12px 26px;
    font-size:14px; font-weight:700; font-family:inherit;
    cursor:pointer; white-space:nowrap; transition:background 0.2s;
    flex-shrink:0;
}
.cek-input-row button:hover { background:var(--sky-deep); }

.error-box { margin-top:18px; background:#EAF2FB; border:1px solid #D6E7F7; border-radius:14px; padding:14px 18px; color:#101418; font-size:13.5px; display:flex; align-items:center; gap:10px; }

.result-box { margin-top:16px; border:1px solid var(--border); border-radius:18px; overflow:hidden; box-shadow:0 6px 20px rgba(78,144,217,0.1); }
.result-header { padding:16px 22px; background:linear-gradient(135deg, var(--sky), var(--sky-deep)); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
.result-nota { font-family:'SFMono-Regular', Consolas, monospace; font-size:20px; font-weight:700; color:#FFFFFF; letter-spacing:0.5px; }
.result-nota small { font-family:'Plus Jakarta Sans', sans-serif; font-size:11.5px; color:rgba(255,255,255,0.75); display:block; font-weight:600; margin-bottom:3px; letter-spacing:0.4px; text-transform:uppercase; }
.status-pill { padding:8px 18px; border-radius:30px; font-size:13px; font-weight:700; box-shadow:0 2px 8px rgba(22,74,133,0.18); }
.result-body { padding:18px 22px; }

.steps { display:flex; align-items:flex-start; margin-bottom:18px; }
.step { flex:1; text-align:center; position:relative; }
.step-circle { width:42px; height:42px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 8px; font-size:18px; background:#EAF2FB; border:2px solid var(--border); position:relative; z-index:1; transition:all 0.2s; }
.step-circle.done { background:var(--sky-soft); border-color:var(--sky); }
.step-circle.active { background:var(--sky); border-color:var(--sky); box-shadow:0 4px 14px rgba(78,144,217,0.35); }
.step-lbl { font-size:12px; color:#4B5563; font-weight:500; }
.step-lbl.done { color:var(--sky-deep); }
.step-lbl.active { color:#101418; font-weight:700; }
.step-line { position:absolute; top:21px; left:calc(50% + 21px); width:calc(100% - 42px); height:2px; background:var(--border); z-index:0; }
.step-line.done { background:var(--sky); }

.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:22px; }
.info-item { background:var(--sky-pale); border:1px solid var(--border); border-radius:12px; padding:13px 16px; }
.info-item .lbl { font-size:11px; color:#4B5563; font-weight:600; margin-bottom:4px; letter-spacing:0.3px; text-transform:uppercase; }
.info-item .val { font-size:14px; color:#101418; font-weight:700; }

.items-table { border:1px solid var(--border); border-radius:12px; overflow:hidden; }
.item-row { display:flex; justify-content:space-between; padding:11px 16px; font-size:13px; border-bottom:1px solid var(--border); color:#101418; }
.item-row:last-child { border-bottom:none; }
.item-price { color:var(--sky-deep); font-weight:700; }
.total-row { display:flex; justify-content:space-between; padding:15px 16px; background:var(--sky-soft); font-weight:800; font-size:15px; color:#101418; }

.siap-box { background:#EAF2FB; border:1px solid #D6E7F7; border-radius:14px; padding:18px; text-align:center; margin-top:18px; }
.siap-box p { color:#1B2126; font-weight:700; font-size:14.5px; margin-bottom:4px; }
.siap-box small { color:#1B2126; font-size:12px; }

.empty-hint { text-align:center; padding:26px 4px 4px; color:#4B5563; font-size:13.5px; }

/* ── TOMBOL DETAIL ── */
.btn-detail {
    background:rgba(255,255,255,0.18); color:#FFFFFF; border:1.5px solid rgba(255,255,255,0.55);
    border-radius:30px; padding:7px 16px; font-size:12.5px; font-weight:700;
    font-family:inherit; cursor:pointer; display:inline-flex; align-items:center; gap:6px;
    transition:background 0.2s;
}
.btn-detail:hover { background:rgba(255,255,255,0.3); }

/* ── MODAL DETAIL PROSES PER ITEM ── */
.modal-overlay {
    display:none; position:fixed; inset:0; background:rgba(22,74,133,0.45);
    z-index:200; align-items:center; justify-content:center; padding:20px;
    backdrop-filter:blur(2px);
}
.modal-overlay.open { display:flex; }
.modal-box {
    background:var(--cloud); border-radius:20px; max-width:480px; width:100%;
    max-height:85vh; overflow-y:auto; box-shadow:0 20px 60px rgba(22,74,133,0.25);
}
.modal-head {
    padding:20px 24px; border-bottom:1px solid var(--border);
    display:flex; justify-content:space-between; align-items:flex-start; gap:12px;
    position:sticky; top:0; background:var(--cloud); z-index:1;
}
.modal-head h4 { font-family:'Fraunces', serif; font-weight:600; font-size:17px; color:#101418; margin-bottom:4px; }
.modal-head p { font-size:12.5px; color:#3A4450; }
.modal-close {
    background:var(--sky-pale); border:none; width:30px; height:30px; border-radius:50%;
    color:#3A4450; font-size:16px; cursor:pointer; flex-shrink:0; line-height:1;
    display:flex; align-items:center; justify-content:center; transition:background 0.2s;
}
.modal-close:hover { background:var(--border); }
.modal-body { padding:18px 24px 24px; }

.detail-item-row {
    display:flex; align-items:center; gap:12px; padding:13px 14px;
    border:1px solid var(--border); border-radius:14px; margin-bottom:10px;
    background:var(--sky-pale);
}
.detail-item-row:last-child { margin-bottom:0; }
.detail-item-icon {
    width:38px; height:38px; border-radius:11px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:17px;
    background:var(--cloud); border:1px solid var(--border);
}
.detail-item-info { flex:1; min-width:0; }
.detail-item-name { font-size:13.5px; font-weight:700; color:#101418; }
.detail-item-qty { font-size:11.5px; color:#4B5563; margin-top:1px; }
.detail-item-status {
    font-size:11px; font-weight:700; padding:5px 11px; border-radius:20px; white-space:nowrap; flex-shrink:0;
}
.detail-item-status.active  { background:#EAF2FB; color:#1B2126; }
.detail-item-status.done    { background:#D6E7F7; color:#1B2126; }
.detail-item-status.waiting { background:#EAF2FB; color:#3A4450; }

.modal-note {
    margin-top:14px; font-size:12px; color:#3A4450; background:var(--sky-soft);
    border-radius:12px; padding:11px 14px; line-height:1.6;
}

/* ── FOOTER (sama dengan index.php) ── */
footer { background:linear-gradient(135deg, var(--sky-ink), var(--sky-deep)); color:rgba(255,255,255,0.8); padding:44px 24px; text-align:center; }
footer .logo-f { font-family:'Fraunces', serif; font-weight:600; font-size:22px; color:#FFFFFF; margin-bottom:8px; }
footer p { font-size:13px; margin-bottom:4px; }
footer .copy { font-size:12px; color:rgba(255,255,255,0.45); margin-top:20px; }

@media (max-width:860px) {
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
}

@media (max-width:600px) {
    .cek-wrap { padding:20px; margin-top:0; }
    .info-grid { grid-template-columns:1fr; }
}

@media (max-width:480px) {
    .nav-inner { padding:10px 14px; }
    .logo img { height:40px; }
    .nav-menu { left:10px; right:10px; }
    .page-header { padding:18px 16px 6px; }
    .page-header h1 { font-size:19px; }
    .page-header p { font-size:13px; }
    section { padding:0 12px 56px; }
    .cek-wrap { padding:18px; border-radius:18px; }
    .cek-input-row {
        flex-direction:column; align-items:stretch;
        border-radius:20px; padding:10px; gap:8px;
    }
    .cek-input-row input { text-align:center; padding:10px 8px; }
    .cek-input-row button { width:100%; border-radius:14px; padding:12px; }
    .result-header { padding:16px 18px; }
    .result-nota { font-size:16px; }
    .result-body { padding:16px; }
    .steps { flex-wrap:nowrap; }
    .step-lbl { font-size:10.5px; }
    .step-circle { width:34px; height:34px; font-size:15px; }
    .step-line { top:17px; }
    .modal-head { padding:16px 18px; }
    .modal-body { padding:14px 18px 18px; }
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav>
  <div class="nav-inner">
    <a href="index.php" class="logo">
      <picture>
        <source media="(max-width:480px)" srcset="assets/img/logo-badge.svg">
        <img src="assets/img/logo.svg" alt="Azka Laundry">
      </picture>
    </a>
    <div class="nav-menu" id="navMenu">
      <a href="index.php">Beranda</a>
      <a href="estimasi.php">Estimasi dan Pesan</a>
      <a href="cek_status.php" class="active">Cek Cucian</a>
      <a href="index.php#kontak">Kontak</a>
      <a href="login.php" class="btn-login">Login Pegawai</a>
    </div>
    <button type="button" class="nav-toggle" id="navToggle" onclick="document.getElementById('navMenu').classList.toggle('open')" aria-label="Buka menu">
      <span></span>
    </button>
  </div>
</nav>

<!-- HEADER -->
<div class="page-header">
  <div class="label-chip">LACAK PESANAN</div>
  <h1>Cek Status Cucian Anda</h1>
</div>

<section>
  <div class="section-inner">
    <div class="cek-wrap">
      <h3>Masukkan ID Nota</h3>
      <p class="sub">Contoh format: AZK250519ABCD</p>

      <form method="POST" action="cek_status.php#hasil-cek">
        <div class="cek-input-row">
          <input type="text" name="id_nota"
                 placeholder="Masukkan ID Nota Anda"
                 value="<?= htmlspecialchars($keyword) ?>"
                 autocomplete="off" required>
          <button type="submit">Cek Status</button>
        </div>
      </form>

      <?php if ($error_cek): ?>
      <div class="error-box"><?= htmlspecialchars($error_cek) ?></div>
      <?php endif; ?>

      <?php if ($hasil_cek):
        $status_val = $status_cek['status'] ?? 'dalam_antrian';
        $st = statusInfo($status_val);
        if ($status_val === 'sedang_diproses' && !empty($status_cek['tahap_proses'])) {
            $tipeDominan = tipeDominanTransaksi($pdo, $hasil_cek['id_transaksi']);
            $kategoriDominan = kategoriDominanTransaksi($pdo, $hasil_cek['id_transaksi']);
            $st['label'] .= ' — ' . labelTahap($status_cek['tahap_proses'], $tipeDominan, $kategoriDominan);
        }
        $steps = [
          ['label'=>'Dalam Antrian',   'icon'=>''],
          ['label'=>'Sedang Diproses', 'icon'=>''],
          ['label'=>'Siap Diambil',    'icon'=>''],
        ];
        $cur = min($st['step'], count($steps) - 1);
      ?>
      <div id="hasil-cek" class="result-box">
        <div class="result-header">
          <div class="result-nota">
            <small>ID Nota</small>
            <?= htmlspecialchars($hasil_cek['id_nota']) ?>
          </div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span class="status-pill" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>">
              <?= $st['icon'] ?> <?= $st['label'] ?>
            </span>
            <?php if (!empty($detail_cek)): ?>
            <button type="button" class="btn-detail" onclick="document.getElementById('modalDetailProses').classList.add('open')">
              Detail
            </button>
            <?php endif; ?>
          </div>
        </div>
        <div class="result-body">
          <div class="steps">
            <?php foreach ($steps as $i => $step): ?>
            <div class="step">
              <?php if ($i < count($steps)-1): ?>
              <div class="step-line <?= $i < $cur ? 'done' : '' ?>"></div>
              <?php endif; ?>
              <div class="step-circle <?= $i < $cur ? 'done' : ($i === $cur ? 'active' : '') ?>">
                <?= $i <= $cur ? $step['icon'] : '○' ?>
              </div>
              <div class="step-lbl <?= $i < $cur ? 'done' : ($i === $cur ? 'active' : '') ?>">
                <?= $step['label'] ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="info-grid">
            <div class="info-item">
              <div class="lbl">Nama Pelanggan</div>
              <div class="val"><?= htmlspecialchars($hasil_cek['nama_pelanggan']) ?></div>
            </div>
            <div class="info-item">
              <div class="lbl">Tanggal Masuk</div>
              <div class="val"><?= date('d M Y', strtotime($hasil_cek['created_at'])) ?></div>
            </div>
            <div class="info-item">
              <div class="lbl">Estimasi Selesai</div>
              <div class="val">
                <?= $hasil_cek['tanggal_estimasi']
                    ? date('d M Y', strtotime($hasil_cek['tanggal_estimasi']))
                    : '-' ?>
              </div>
            </div>
            <div class="info-item">
              <div class="lbl">Status Pembayaran</div>
              <div class="val">
                <?php if ($hasil_cek['status_bayar'] === 'sudah_bayar'): ?>
                <span style="color:#1B2126;font-weight:700">Lunas</span>
                <?php elseif ($hasil_cek['status_bayar'] === 'dp'): ?>
                <span style="color:#3A4450;font-weight:700">DP &middot; Kurang <?= 'Rp' . number_format(sisaBayar($hasil_cek['total_harga'], $hasil_cek['total_dibayar']),0,',','.') ?></span>
                <?php else: ?>
                <span style="color:#3A4450;font-weight:700">Belum Lunas</span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?php if (!empty($riwayat_bayar)): ?>
          <p style="font-size:11px;font-weight:700;color:#4B5563;margin-top:16px;margin-bottom:8px;letter-spacing:0.5px;text-transform:uppercase">Riwayat Pembayaran</p>
          <div class="items-table">
            <?php foreach ($riwayat_bayar as $b):
              $isTransfer = $b['metode'] === 'transfer';
            ?>
            <div class="item-row">
              <span>
                <?= date('d M Y, H:i', strtotime($b['created_at'])) ?>
                <span style="display:inline-block;margin-left:8px;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;
                  background:var(--sky-soft);color:var(--sky-deep)">
                  <?= $isTransfer ? 'Transfer' : 'Tunai' ?>
                </span>
              </span>
              <span class="item-price"><?= formatRupiah($b['jumlah_bayar']) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if (!empty($detail_cek)): ?>
          <p style="font-size:11px;font-weight:700;color:#4B5563;margin-bottom:8px;letter-spacing:0.5px;text-transform:uppercase">Detail Item</p>
          <div class="items-table">
            <?php foreach ($detail_cek as $d): ?>
            <div class="item-row">
              <span><?= htmlspecialchars($d['nama_layanan']) ?> × <?= rtrim(rtrim($d['qty'], '0'), '.') ?> <?= htmlspecialchars($d['satuan']) ?></span>
              <span class="item-price"><?= formatRupiah($d['subtotal']) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="total-row">
            <span>Total</span>
            <span><?= formatRupiah($hasil_cek['total_harga']) ?></span>
          </div>
          <?php endif; ?>

          <?php if ($status_val === 'siap_diambil'): ?>
          <div class="siap-box">
            <p>Cucian Anda sudah siap diambil!</p>
            <small>Silakan datang ke Azka Laundry — Jl. Cisitu Lama 1 No. 9A, Bandung</small>
          </div>
          <?php elseif ($status_val === 'selesai'): ?>
          <div class="siap-box">
            <p>Cucian sudah selesai diambil.</p>
            <small>Terima kasih telah menggunakan layanan Azka Laundry</small>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- MODAL DETAIL PROSES PER ITEM -->
      <div id="modalDetailProses" class="modal-overlay" onclick="if(event.target===this) this.classList.remove('open')">
        <div class="modal-box">
          <div class="modal-head">
            <div>
              <h4>Rincian Proses per Item</h4>
              <p>Nota <?= htmlspecialchars($hasil_cek['id_nota']) ?></p>
            </div>
            <button type="button" class="modal-close" onclick="document.getElementById('modalDetailProses').classList.remove('open')">Tutup</button>
          </div>
          <div class="modal-body">
            <?php foreach ($detail_cek as $d):
              $itemSt = statusItemAsli($d['tipe_proses'], $status_val, $d['tahap_item'] ?? null, $d['nama_kategori'] ?? null);
            ?>
            <div class="detail-item-row">
              <div class="detail-item-icon"><?= $itemSt['icon'] ?></div>
              <div class="detail-item-info">
                <div class="detail-item-name"><?= htmlspecialchars($d['nama_layanan']) ?></div>
                <div class="detail-item-qty">× <?= rtrim(rtrim($d['qty'], '0'), '.') ?> <?= htmlspecialchars($d['satuan']) ?></div>
              </div>
              <span class="detail-item-status <?= $itemSt['state'] ?>"><?= $itemSt['label'] ?></span>
            </div>
            <?php endforeach; ?>

            <?php if ($status_val === 'sedang_diproses'): ?>
            <div class="modal-note">
              ℹ️ Setiap jenis item punya alur berbeda — ada yang cuci basah saja, ada yang sampai disetrika. Item yang tahapnya lebih pendek bisa selesai lebih dulu dan menunggu dikemas bersama item lainnya.
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php elseif (!$error_cek): ?>
      <div class="empty-hint">Belum ada pencarian. Masukkan ID Nota di atas untuk melihat status cucian Anda.</div>
      <?php endif; ?>

    </div>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <div class="logo-f">Azka Laundry</div>
  <p>Bersih · Rapih · Wangi</p>
  <p>Jl. Cisitu Lama 1 No. 9A, Bandung · 0895-1215-7474</p>
  <p class="copy">© <?= date('Y') ?> Azka Laundry. All rights reserved.</p>
</footer>

</body>
</html>