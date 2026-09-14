<?php
require_once __DIR__ . '/includes/config.php';
requireRole('kasir', 'owner');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: dashboard_kasir.php?error=ID+tidak+valid');
    exit;
}

// Ambil data transaksi
$stmt = $pdo->prepare("
    SELECT t.*, s.status AS status_cucian
    FROM transaksi t
    LEFT JOIN status_cucian s ON t.id_transaksi = s.transaksi_id
    WHERE t.id_transaksi = ?
");
$stmt->execute([$id]);
$trx = $stmt->fetch();

if (!$trx) {
    header('Location: dashboard_kasir.php?error=Transaksi+tidak+ditemukan');
    exit;
}

// Ambil metode pembayaran yang sudah ada (kalau transaksi ini sudah lunas sebelumnya)
$stmtBayar = $pdo->prepare("SELECT metode, nama_pengirim, no_pengirim FROM pembayaran WHERE transaksi_id = ? LIMIT 1");
$stmtBayar->execute([$id]);
$bayarExisting = $stmtBayar->fetch();
$metode_bayar_existing = $bayarExisting['metode'] ?? 'tunai';
$nama_pengirim_existing = $bayarExisting['nama_pengirim'] ?? '';
$no_pengirim_existing   = $bayarExisting['no_pengirim'] ?? '';

// Ambil detail item transaksi (berbasis katalog detail_barang)
$detail = $pdo->prepare("
    SELECT dt.id_detail_transaksi, dt.detail_barang_id, dt.qty, dt.harga_satuan, dt.subtotal, dt.keterangan,
           COALESCE(il.nama_item, dt.keterangan, 'Barang') AS nama_layanan,
           COALESCE(il.satuan, 'pcs') AS satuan,
           il.harga
    FROM detail_transaksi dt
    LEFT JOIN detail_barang il ON il.id_barang = dt.detail_barang_id
    WHERE dt.transaksi_id = ?
");
$detail->execute([$id]);
$items = $detail->fetchAll();

// Ambil kategori & item layanan aktif untuk form pemilihan
$kategori_list = $pdo->query("
    SELECT id_kategori, nama_kategori FROM kategori_barang WHERE is_aktif = 1 ORDER BY urutan
")->fetchAll();

$all_items = $pdo->query("
    SELECT il.id_barang, il.kategori_id, il.nama_item, il.harga, il.satuan, kl.nama_kategori
    FROM detail_barang il
    JOIN kategori_barang kl ON kl.id_kategori = il.kategori_id
    WHERE il.is_aktif = 1 AND kl.is_aktif = 1
    ORDER BY kl.urutan, il.urutan
")->fetchAll();
$item_json = json_encode($all_items, JSON_UNESCAPED_UNICODE);

$error  = '';
$sukses = '';

// ── PROSES SIMPAN EDIT ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Sesi kedaluwarsa, silakan muat ulang halaman dan coba lagi.';
    } else {
    $nama         = trim($_POST['nama_pelanggan'] ?? '');
    $no_hp        = trim($_POST['no_hp_pelanggan'] ?? '');
    $estimasi     = $_POST['tanggal_estimasi'] ?? null;
    $status_bayar = $_POST['status_bayar'] ?? 'belum_bayar';
    if (!in_array($status_bayar, ['belum_bayar', 'dp', 'sudah_bayar'], true)) {
        $status_bayar = 'belum_bayar';
    }
    $metode_bayar = $_POST['metode_bayar'] ?? 'tunai';
    $nama_pengirim = trim($_POST['nama_pengirim'] ?? '');
    $no_pengirim   = trim($_POST['no_pengirim'] ?? '');
    $jumlah_dp    = (float)($_POST['jumlah_dp'] ?? 0);
    $status_cuci  = $_POST['status_cucian'] ?? 'dalam_antrian';
    $item_ids     = $_POST['detail_barang_id'] ?? [];
    $qtys         = $_POST['qty'] ?? [];
    $hargas       = $_POST['harga_satuan'] ?? [];
    $catatans     = $_POST['catatan_item'] ?? [];
    $total_harga  = (float)($_POST['total_harga'] ?? 0);

    // Pastikan status cucian yang dikirim valid
    if (!in_array($status_cuci, ['dalam_antrian', 'sedang_diproses', 'siap_diambil'])) {
        $status_cuci = 'dalam_antrian';
    }

    if (!$nama || !$no_hp || empty($item_ids)) {
        $error = 'Nama, nomor HP, dan minimal 1 barang wajib diisi.';
    } elseif ($status_bayar === 'dp' && ($jumlah_dp <= 0 || $jumlah_dp >= $total_harga)) {
        $error = 'Jumlah DP harus diisi, lebih dari 0, dan kurang dari total tagihan.';
    } elseif ($metode_bayar === 'transfer' && in_array($status_bayar, ['dp', 'sudah_bayar'], true) && $nama_pengirim === '') {
        $error = 'Nama Pengirim wajib diisi untuk pembayaran Transfer.';
    } else {
        try {
            $pdo->beginTransaction();

            // total_dibayar baru sesuai status_bayar yang dipilih
            $total_dibayar_baru = $status_bayar === 'sudah_bayar' ? $total_harga : ($status_bayar === 'dp' ? $jumlah_dp : 0);

            // Update transaksi
            $pdo->prepare("
                UPDATE transaksi
                SET nama_pelanggan=?, no_hp_pelanggan=?, tanggal_estimasi=?,
                    status_bayar=?, total_dibayar=?, total_harga=?, updated_at=NOW()
                WHERE id_transaksi=?
            ")->execute([$nama, $no_hp, $estimasi ?: null, $status_bayar, $total_dibayar_baru, $total_harga, $id]);

            // Update status cucian
            $pdo->prepare("UPDATE status_cucian SET status=? WHERE transaksi_id=?")
                ->execute([$status_cuci, $id]);

            // Hapus detail lama, insert baru
            $pdo->prepare("DELETE FROM detail_transaksi WHERE transaksi_id=?")->execute([$id]);

            $stmtItem   = $pdo->prepare("SELECT nama_item, harga FROM detail_barang WHERE id_barang = ?");
            $stmtDetail = $pdo->prepare("
                INSERT INTO detail_transaksi (transaksi_id, detail_barang_id, qty, harga_satuan, subtotal, keterangan)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach ($item_ids as $i => $iid) {
                $iid = (int)$iid;
                $qty = (float)($qtys[$i] ?? 1);
                $catatan = trim($catatans[$i] ?? '') ?: null;

                if ($iid <= 0) {
                    // Baris arsip lama (tidak terhubung ke katalog detail_barang) —
                    // simpan apa adanya memakai harga & keterangan yang sudah ada,
                    // supaya tidak hilang saat transaksi lama ini diedit.
                    $harga    = (float)($hargas[$i] ?? 0);
                    $subtotal = $harga * $qty;
                    $stmtDetail->execute([$id, null, $qty, $harga, $subtotal, $catatan]);
                    continue;
                }

                $stmtItem->execute([$iid]);
                $itemRow = $stmtItem->fetch();
                if (!$itemRow) continue;

                $harga = isset($hargas[$i]) ? (float)$hargas[$i] : (float)$itemRow['harga'];
                if ($harga < 0) $harga = (float)$itemRow['harga'];

                $subtotal = $harga * $qty;

                $stmtDetail->execute([$id, $iid, $qty, $harga, $subtotal, $catatan]);
            }

            // ── SINKRONKAN TABEL PEMBAYARAN DENGAN STATUS_BAYAR TERBARU ──
            // Edit transaksi menyederhanakan histori pembayaran jadi SATU baris
            // yang mencerminkan status baru: dihapus semua baris lama lalu dibuat
            // ulang sesuai pilihan kasir (Belum Bayar / DP sekian / Sudah Lunas).
            // Kalau butuh menambah pembayaran bertahap tanpa mengubah item,
            // pakai tombol "Input DP / Tambah Bayar" di dashboard, bukan form edit ini.
            $pdo->prepare("DELETE FROM pembayaran WHERE transaksi_id = ?")->execute([$id]);

            if ($status_bayar === 'sudah_bayar') {
                $pdo->prepare("INSERT INTO pembayaran (transaksi_id, jumlah_bayar, metode, nama_pengirim, no_pengirim, kasir_id) VALUES (?,?,?,?,?,?)")
                    ->execute([$id, $total_harga, $metode_bayar, $metode_bayar === 'transfer' ? $nama_pengirim : null, $metode_bayar === 'transfer' ? $no_pengirim : null, $_SESSION['user']['id_user']]);
            } elseif ($status_bayar === 'dp') {
                $pdo->prepare("INSERT INTO pembayaran (transaksi_id, jumlah_bayar, metode, nama_pengirim, no_pengirim, kasir_id) VALUES (?,?,?,?,?,?)")
                    ->execute([$id, $jumlah_dp, $metode_bayar, $metode_bayar === 'transfer' ? $nama_pengirim : null, $metode_bayar === 'transfer' ? $no_pengirim : null, $_SESSION['user']['id_user']]);
            }

            $pdo->commit();

            // Refresh data
            $stmt->execute([$id]);
            $trx = $stmt->fetch();
            $detail->execute([$id]);
            $items = $detail->fetchAll();
            $stmtBayar->execute([$id]);
            $bayarExisting = $stmtBayar->fetch();
            $metode_bayar_existing = $bayarExisting['metode'] ?? 'tunai';
            $nama_pengirim_existing = $bayarExisting['nama_pengirim'] ?? '';
            $no_pengirim_existing   = $bayarExisting['no_pengirim'] ?? '';

            $sukses = 'Transaksi berhasil diperbarui.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
    }
}

// ── SIDEBAR COUNTERS (sama seperti halaman dashboard/transaksi lain) ──
$dalam_antrian   = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'dalam_antrian'")->fetchColumn();
$jml_pesanan_online = $pdo->query("SELECT COUNT(*) FROM pesanan_online WHERE status = 'menunggu'")->fetchColumn();
$sedang_diproses = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'sedang_diproses'")->fetchColumn();
$siap_diambil    = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'siap_diambil'")->fetchColumn();

$namaKasir = htmlspecialchars($_SESSION['user']['name']);
$inisial   = strtoupper(substr($_SESSION['user']['name'], 0, 2));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Edit Transaksi — Azka Laundry</title>
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
body { margin:0; background:var(--sky-pale); color:#101418; }

.sidebar { width:230px; height:100vh; height:100dvh; background:var(--sky-deep); position:fixed; left:0; top:0; display:flex; flex-direction:column; z-index:100; overflow-y:auto; }
.sidebar-brand { padding:20px; border-bottom:1px solid rgba(255,255,255,0.14); display:flex; align-items:center; gap:10px; }
.sidebar-brand svg { width:28px; height:28px; flex-shrink:0; }
.sidebar-brand h4 { color:#FFFFFF; font-size:16px; font-weight:700; margin:0; }
.sidebar-brand small { color:rgba(255,255,255,0.55); font-size:11px; }
.sidebar-menu { padding:14px 10px; flex:1; }
.sidebar-menu a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.68); text-decoration:none; padding:10px 12px; border-radius:8px; margin-bottom:2px; font-size:13px; font-weight:500; transition:all 0.2s; }
.sidebar-menu a:hover, .sidebar-menu a.active { background:rgba(255,255,255,0.14); color:#FFFFFF; }
.sidebar-menu a.active { border-left:3px solid #FFFFFF; }
.sidebar-menu a i { width:16px; text-align:center; }
.sidebar-badge { background:var(--cloud); color:#101418; font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; margin-left:auto; }
.sidebar-badge.green { background:var(--ink); color:#FFFFFF; }
.sidebar-footer { padding:14px 10px calc(14px + env(safe-area-inset-bottom)); border-top:1px solid rgba(255,255,255,0.14); flex-shrink:0; }
.sidebar-footer a { display:flex; align-items:center; gap:10px; color:rgba(255,255,255,0.55); text-decoration:none; padding:9px 12px; border-radius:8px; font-size:13px; transition:all 0.2s; }
.sidebar-footer a:hover { background:rgba(255,255,255,0.14); color:#FFFFFF; }

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
}
.topbar { background:var(--cloud); border-bottom:1px solid var(--border); padding:14px 26px; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:99; }
.topbar-title { font-size:17px; font-weight:700; color:#101418; }
.topbar-title small { display:block; font-size:12px; color:#3A4450; font-weight:400; margin-top:2px; }
.topbar-right { display:flex; align-items:center; gap:12px; }
.avatar { width:36px; height:36px; border-radius:50%; background:var(--sky); display:flex; align-items:center; justify-content:center; color:#FFFFFF; font-weight:700; font-size:12px; }
.user-name { font-size:13px; font-weight:600; color:#101418; }
.user-role { font-size:11px; color:#4B5563; }
.content { padding:22px 26px; }
.btn-back { display:inline-flex; align-items:center; gap:6px; color:var(--sky-deep); text-decoration:none; font-size:13px; font-weight:600; margin-bottom:14px; }
.btn-back:hover { color:#101418; }

/* FORM — tidak dibungkus kotak putih terpisah, langsung menyatu dengan latar dashboard */
.form-section-title { font-size:13px; font-weight:700; color:#101418; text-transform:uppercase; letter-spacing:0.4px; margin:20px 0 16px; padding-bottom:10px; border-bottom:1px solid var(--border); }
.form-section-title:first-child { margin-top:4px; }
.form-label { font-size:12px; font-weight:700; color:#3A4450; margin-bottom:5px; display:block; }
.form-control, .form-select { display:block; width:100%; border:1.5px solid var(--border); border-radius:9px; font-size:13px; padding:10px 14px; font-family:inherit; background:var(--cloud); color:#101418; transition:border-color 0.2s; }
.form-control:focus, .form-select:focus { outline:none; border-color:var(--sky); box-shadow:0 0 0 3px rgba(46,116,192,0.12); }
.bank-chip-row { display:flex; flex-wrap:wrap; gap:6px; }
.bank-chip { padding:6px 12px; border-radius:20px; border:1.5px solid var(--border); background:var(--cloud); color:#3A4450; font-size:11.5px; font-weight:600; cursor:pointer; font-family:inherit; }
.bank-chip:hover { border-color:var(--sky); color:var(--sky-deep); background:var(--sky-pale); }
.bank-chip.active { background:var(--sky); border-color:var(--sky); color:#FFFFFF; }
.mb-3 { margin-bottom:16px; }
.mt-3 { margin-top:16px; }

.form-grid { display:grid; grid-template-columns:repeat(2, 1fr); gap:16px; }
@media (max-width:700px) { .form-grid { grid-template-columns:1fr; } }
.form-grid .span-2 { grid-column:1 / -1; }

.trx-grid { display:grid; grid-template-columns:1fr 360px; gap:28px; align-items:start; }
.trx-col-right { position:sticky; top:22px; }
@media (max-width:900px) {
    .trx-grid { grid-template-columns:1fr; }
    .trx-col-right { position:static; }
}

.cart-panel { background:#EAF2FB; border:1px solid var(--border); border-radius:12px; padding:16px; }
.cart-panel-title { font-size:13px; font-weight:700; color:#101418; display:flex; align-items:center; gap:7px; margin-bottom:12px; }
.cart-items-wrap { max-height:340px; overflow-y:auto; padding-right:2px; }
.cart-items-wrap::-webkit-scrollbar { width:6px; }
.cart-items-wrap::-webkit-scrollbar-thumb { background:var(--border); border-radius:6px; }
.cart-empty { text-align:center; color:#4B5563; padding:20px 10px; font-size:12.5px; border:2px dashed var(--border); border-radius:10px; }

.item-picker-row { display:flex; gap:8px; margin-bottom:6px; }
.item-picker-row select { flex:1; }
.item-picker-row .btn-tambah-item { background:var(--sky); color:#FFFFFF; border:none; border-radius:9px; padding:0 16px; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; }
.item-picker-row .btn-tambah-item:hover { background:var(--sky-deep); }
.item-hint { font-size:11px; color:#4B5563; margin-bottom:14px; }

.item-row { display:flex; align-items:center; gap:8px; background:var(--cloud); border:1px solid var(--border); border-radius:9px; padding:10px; margin-bottom:8px; font-size:12.5px; }
.item-row .item-name  { flex:1 1 100%; font-weight:600; color:#101418; }
.item-row .item-name small { display:block; color:#4B5563; font-weight:400; }
.item-row .item-price { color:var(--sky-deep); font-weight:700; margin-left:auto; }
.qty-ctrl { display:flex; align-items:center; gap:5px; }
.qty-btn { width:24px; height:24px; border-radius:6px; border:1px solid var(--border); background:var(--cloud); font-size:14px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--sky-deep); transition:all 0.15s; }
.qty-btn:hover { background:var(--sky); color:#FFFFFF; border-color:var(--sky); }
.qty-input { width:42px; text-align:center; padding:3px 4px; border:1px solid var(--border); border-radius:6px; font-size:12px; font-weight:600; }
.harga-input-wrap { display:flex; align-items:center; gap:4px; font-size:11.5px; color:#3A4450; }
.harga-input-wrap input { width:72px; border:1.5px solid var(--border); border-radius:8px; padding:4px 6px; font-size:11.5px; text-align:right; }
.remove-btn { background:none; border:none; color:#101418; cursor:pointer; font-size:14px; padding:4px; opacity:0.5; }
.remove-btn:hover { opacity:1; }

.total-box { background:var(--sky-deep); border-radius:10px; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; margin-top:12px; }
.total-box .lbl { color:rgba(255,255,255,0.8); font-size:13px; }
.total-box .val { color:#FFFFFF; font-size:19px; font-weight:700; }

.btn-simpan { width:100%; padding:13px; background:var(--sky); color:#FFFFFF; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; margin-top:12px; font-family:inherit; transition:background 0.2s; }
.btn-simpan:hover { background:var(--sky-deep); }

.alert-ok  { background:#D6E7F7; border:1px solid #AECBEF; color:#1B2126; border-radius:12px; padding:13px 16px; margin-bottom:16px; font-size:13px; font-weight:600; }
.alert-err { background:#EAF2FB; border:1px solid #AECBEF; color:#101418; border-radius:12px; padding:13px 16px; margin-bottom:16px; font-size:13px; font-weight:600; }

.info-status { background:#EAF2FB; border:1px solid var(--border); border-radius:10px; padding:10px 14px; font-size:12px; color:#3A4450; margin-top:6px; display:flex; align-items:center; gap:8px; }
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
        Edit Transaksi
        <small>Nota: <?= htmlspecialchars($trx['id_nota']) ?> &middot; Dibuat: <?= date('d M Y H:i', strtotime($trx['created_at'])) ?></small>
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

    <?php if ($sukses): ?>
    <div class="alert-ok"><?= $sukses ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert-err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="formEdit">
      <?= csrf_field() ?>
      <div class="trx-grid">

        <div class="trx-col-left">

          <div class="form-section-title">Data Pelanggan</div>
          <div class="form-grid">
            <div>
              <label class="form-label">Nama Pelanggan</label>
              <input type="text" name="nama_pelanggan" class="form-control"
                     value="<?= htmlspecialchars($trx['nama_pelanggan']) ?>" required>
            </div>
            <div>
              <label class="form-label">Nomor HP / WhatsApp</label>
              <input type="text" name="no_hp_pelanggan" class="form-control"
                     value="<?= htmlspecialchars($trx['no_hp_pelanggan']) ?>" required>
            </div>
            <div>
              <label class="form-label">Estimasi Selesai</label>
              <input type="date" name="tanggal_estimasi" class="form-control"
                     value="<?= $trx['tanggal_estimasi'] ?? '' ?>">
            </div>
            <div>
              <label class="form-label">Status Cucian</label>
              <select name="status_cucian" class="form-select">
                <option value="dalam_antrian"   <?= $trx['status_cucian']==='dalam_antrian'  ?'selected':'' ?>>Dalam Antrian</option>
                <option value="sedang_diproses" <?= $trx['status_cucian']==='sedang_diproses'?'selected':'' ?>>Sedang Diproses</option>
                <option value="siap_diambil"    <?= $trx['status_cucian']==='siap_diambil'   ?'selected':'' ?>>Siap Diambil</option>
              </select>
            </div>
          </div>

          <div class="form-section-title">Barang</div>
          <label class="form-label">Pilih Barang</label>
          <div class="item-picker-row">
            <select class="form-select" id="pilihKategori" onchange="renderPilihanItem()">
              <option value="">Semua Kategori</option>
              <?php foreach ($kategori_list as $k): ?>
              <option value="<?= $k['id_kategori'] ?>"><?= htmlspecialchars($k['nama_kategori']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="item-picker-row">
            <select class="form-select" id="pilihItem"></select>
            <button type="button" class="btn-tambah-item" onclick="tambahItemKeranjang()">Tambah</button>
          </div>
          <div class="item-hint">Pilih kategori lalu jenis barangnya, lalu klik Tambah. Jumlah &amp; harga bisa diatur langsung di daftar barang di samping.</div>
          <!-- Data katalog layanan (kategori_barang + detail_barang) untuk JS -->
          <!-- Data katalog layanan (kategori_barang + detail_barang) dipakai di script bawah -->
          <div id="hiddenInputs"></div>

        </div>

        <div class="trx-col-right">
          <div class="cart-panel">
            <div class="cart-panel-title">Barang Ditambahkan</div>
            <div class="cart-items-wrap">
              <div id="itemList"></div>
            </div>
            <div class="total-box">
              <div class="lbl">Total</div>
              <div class="val" id="totalHarga">Rp 0</div>
            </div>
            <input type="hidden" name="total_harga" id="inputTotal" value="0">

            <div class="mb-3 mt-3">
              <label class="form-label">Status Pembayaran</label>
              <select name="status_bayar" id="inputStatusBayar" class="form-select" onchange="toggleMetodeBayar()">
                <option value="belum_bayar" <?= $trx['status_bayar']==='belum_bayar'?'selected':'' ?>>Belum Bayar</option>
                <option value="dp" <?= $trx['status_bayar']==='dp'?'selected':'' ?>>DP (Bayar Sebagian)</option>
                <option value="sudah_bayar" <?= $trx['status_bayar']==='sudah_bayar'?'selected':'' ?>>Sudah Lunas</option>
              </select>
            </div>
            <div class="mb-3" id="wrapJumlahDp" style="display:none">
              <label class="form-label">Jumlah DP Dibayar</label>
              <input type="number" name="jumlah_dp" id="inputJumlahDp" class="form-control" min="1" step="1"
                     value="<?= $trx['status_bayar']==='dp' ? (float)$trx['total_dibayar'] : '' ?>">
            </div>
            <div class="mb-3" id="wrapMetodeBayar" style="display:none">
              <label class="form-label">Metode Pembayaran</label>
              <select name="metode_bayar" id="inputMetodeBayar" class="form-select" onchange="toggleTransferFields()">
                <option value="tunai" <?= $metode_bayar_existing==='tunai'?'selected':'' ?>>Tunai (Cash)</option>
                <option value="transfer" <?= $metode_bayar_existing==='transfer'?'selected':'' ?>>Transfer</option>
              </select>
            </div>
            <div class="mb-3" id="wrapTransferFields" style="display:none">
              <label class="form-label">Nama Pengirim</label>
              <input type="text" name="nama_pengirim" id="inputNamaPengirim" class="form-control"
                     placeholder="Nama sesuai rekening/e-wallet pengirim" value="<?= htmlspecialchars($nama_pengirim_existing) ?>">
              <label class="form-label" style="margin-top:8px">Bank / E-wallet & No. Rekening</label>
              <div class="bank-chip-row">
                <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirim','BCA')">BCA</button>
                <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirim','Mandiri')">Mandiri</button>
                <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirim','BRI')">BRI</button>
                <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirim','BNI')">BNI</button>
                <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirim','ShopeePay')">ShopeePay</button>
                <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirim','DANA')">DANA</button>
                <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirim','OVO')">OVO</button>
                <button type="button" class="bank-chip" onclick="pilihBankCepat('inputNoPengirim','GoPay')">GoPay</button>
              </div>
              <input type="text" name="no_pengirim" id="inputNoPengirim" class="form-control" style="margin-top:8px"
                     placeholder="Contoh: BCA 1234567890, atau ShopeePay saja" value="<?= htmlspecialchars($no_pengirim_existing) ?>">
            </div>

            <button type="submit" class="btn-simpan">Simpan Perubahan</button>
          </div>
        </div>

      </div>
    </form>

  </div>
</div>

<script>
const itemLayananData = <?= $item_json ?>;
let keranjang = {};
let slotCounter = 0;

function formatRp(n) {
    return 'Rp ' + Math.round(n).toLocaleString('id-ID');
}

// ── TAMPIL/SEMBUNYIKAN METODE PEMBAYARAN & JUMLAH DP ──
function toggleMetodeBayar() {
    const status  = document.getElementById('inputStatusBayar').value;
    const wrap    = document.getElementById('wrapMetodeBayar');
    const wrapDp  = document.getElementById('wrapJumlahDp');
    const inputDp = document.getElementById('inputJumlahDp');
    wrap.style.display   = (status === 'sudah_bayar' || status === 'dp') ? 'block' : 'none';
    wrapDp.style.display = status === 'dp' ? 'block' : 'none';
    inputDp.required     = status === 'dp';
    toggleTransferFields();
}
toggleMetodeBayar();

// Field "Nama Pengirim" / "No. Pengirim" cuma relevan & wajib diisi kalau
// metode bayarnya Transfer -- lihat transaksi_baru.php untuk penjelasan
// lengkap kenapa ini penting untuk penelusuran transfer.
function toggleTransferFields() {
    const wrapMetode = document.getElementById('wrapMetodeBayar');
    const metode     = document.getElementById('inputMetodeBayar').value;
    const wrapT      = document.getElementById('wrapTransferFields');
    const namaP      = document.getElementById('inputNamaPengirim');
    const noP        = document.getElementById('inputNoPengirim');
    const tampil     = wrapMetode.style.display !== 'none' && metode === 'transfer';
    wrapT.style.display = tampil ? 'block' : 'none';
    namaP.required = tampil;
    if (!tampil) { namaP.value = ''; noP.value = ''; }
}

// Tombol pilihan cepat Bank/E-wallet -- cuma bantu isi cepat field
// "no_pengirim" yang sudah ada (teks bebas), jadi TIDAK perlu kolom baru
// di database. Kasir tinggal lanjut ketik nomor rekening/HP setelahnya
// kalau perlu, atau dibiarkan nama e-wallet-nya saja (mis. "ShopeePay").
function pilihBankCepat(inputId, nama) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.value = nama + ' ';
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);
    const row = input.closest('.mb-3, .form-group')?.querySelector('.bank-chip-row');
    if (row) {
        row.querySelectorAll('.bank-chip').forEach(c => c.classList.toggle('active', c.textContent.trim() === nama));
    }
}

document.getElementById('formEdit').addEventListener('submit', function(e) {
    const status = document.getElementById('inputStatusBayar').value;
    if (status !== 'dp') return;
    const total = parseFloat(document.getElementById('inputTotal')?.value) || 0;
    const dp    = parseFloat(document.getElementById('inputJumlahDp').value) || 0;
    if (dp <= 0 || (total > 0 && dp >= total)) {
        e.preventDefault();
        alert('Jumlah DP harus diisi, lebih dari 0, dan kurang dari total tagihan.');
    }
});

// ── KONFIGURASI PER SATUAN ──
const satuanConfig = {
    'kg'     : { step: 0.5, min: 0.5 },
    'liter'  : { step: 0.5, min: 0.5 },
    'm2'     : { step: 0.5, min: 0.5 },
    'm²'     : { step: 0.5, min: 0.5 },
    'pcs'    : { step: 1,   min: 1 },
    'set'    : { step: 1,   min: 1 },
    'pasang' : { step: 1,   min: 1 },
    'lembar' : { step: 1,   min: 1 },
    'potong' : { step: 1,   min: 1 },
};
function getSatuanConfig(satuan) {
    return satuanConfig[satuan] || { step: 1, min: 1 };
}

// Muat barang yang sudah ada di transaksi ini
<?php foreach ($items as $item): ?>
(function() {
    const slot = 'slot' + (++slotCounter);
    keranjang[slot] = {
        itemId  : <?= (int)$item['detail_barang_id'] ?: 0 ?>,
        nama    : <?= json_encode($item['nama_layanan']) ?>,
        satuan  : <?= json_encode($item['satuan']) ?>,
        harga   : <?= (float)$item['harga_satuan'] ?>,
        qty     : <?= (float)$item['qty'] ?>,
        catatan : <?= json_encode($item['keterangan'] ?? '') ?>
    };
})();
<?php endforeach; ?>

function renderPilihanItem() {
    const kategoriId = document.getElementById('pilihKategori').value;
    const sel = document.getElementById('pilihItem');
    const filtered = itemLayananData.filter(it => !kategoriId || String(it.kategori_id) === String(kategoriId));

    if (filtered.length === 0) {
        sel.innerHTML = '<option value="">Tidak ada barang di kategori ini</option>';
        return;
    }
    sel.innerHTML = filtered.map(function(it) {
        return '<option value="' + it.id_barang + '">' + it.nama_item + ' (' + it.satuan + ')</option>';
    }).join('');
}

function tambahItemKeranjang() {
    const itemId = document.getElementById('pilihItem').value;
    if (!itemId) return;
    const item = itemLayananData.find(it => String(it.id_barang) === String(itemId));
    if (!item) return;

    const cfg = getSatuanConfig(item.satuan);
    const slot = 'slot' + (++slotCounter);
    keranjang[slot] = {
        itemId : item.id_barang,
        nama   : item.nama_item,
        satuan : item.satuan,
        harga  : parseFloat(item.harga),
        qty    : cfg.min,
        catatan: ''
    };
    renderItems();
}

function changeQty(slot, delta) {
    if (!keranjang[slot]) return;
    const cfg = getSatuanConfig(keranjang[slot].satuan);
    let q = Math.round((parseFloat(keranjang[slot].qty) + parseFloat(delta)) * 100) / 100;
    if (q < cfg.min) q = cfg.min;
    keranjang[slot].qty = q;
    const inp = document.getElementById('qty-' + slot);
    if (inp) inp.value = q;
    updateSubtotal(slot);
}

function setQty(slot, val) {
    if (!keranjang[slot]) return;
    const cfg = getSatuanConfig(keranjang[slot].satuan);
    let q = parseFloat(val);
    if (isNaN(q) || q < cfg.min) q = cfg.min;
    keranjang[slot].qty = Math.round(q * 100) / 100;
    updateSubtotal(slot);
}

function setHarga(slot, val) {
    if (!keranjang[slot]) return;
    let h = parseFloat(val);
    if (isNaN(h) || h < 0) h = 0;
    keranjang[slot].harga = h;
    const inp = document.getElementById('harga-' + slot);
    if (inp) inp.value = h;
    updateSubtotal(slot);
}

function updateSubtotal(slot) {
    const sub = document.getElementById('sub-' + slot);
    if (sub) sub.textContent = formatRp(keranjang[slot].harga * keranjang[slot].qty);
    const hq = document.getElementById('hqty-' + slot);
    if (hq) hq.value = keranjang[slot].qty;
    const hh = document.getElementById('hharga-' + slot);
    if (hh) hh.value = keranjang[slot].harga;
    updateTotal();
}

function hapusItem(slot) {
    delete keranjang[slot];
    renderItems();
}

function renderItems() {
    const slots  = Object.keys(keranjang);
    const list   = document.getElementById('itemList');
    const hidden = document.getElementById('hiddenInputs');

    if (slots.length === 0) {
        list.innerHTML = '<div class="cart-empty">Belum ada barang.<br>Pilih barang di kolom kiri.</div>';
        hidden.innerHTML = '';
        updateTotal();
        return;
    }

    let html = '', hiddenHtml = '';
    slots.forEach(function(slot) {
        const item = keranjang[slot];
        const cfg = getSatuanConfig(item.satuan);
        html +=
            '<div class="item-row" style="flex-wrap:wrap">' +
              '<div class="item-name">' + item.nama +
                '<small>Harga: ' + formatRp(item.harga) + ' / ' + item.satuan + '</small>' +
              '</div>' +
              '<div class="qty-ctrl">' +
                '<button type="button" class="qty-btn" onclick="changeQty(\'' + slot + '\',-' + cfg.step + ')">&minus;</button>' +
                '<input id="qty-' + slot + '" class="qty-input" type="number" min="' + cfg.min + '" step="' + cfg.step + '" value="' + item.qty + '" onchange="setQty(\'' + slot + '\',this.value)">' +
                '<button type="button" class="qty-btn" onclick="changeQty(\'' + slot + '\',' + cfg.step + ')">+</button>' +
              '</div>' +
              '<div class="harga-input-wrap">Rp <input id="harga-' + slot + '" type="number" min="0" value="' + item.harga + '" onchange="setHarga(\'' + slot + '\',this.value)"></div>' +
              '<div id="sub-' + slot + '" class="item-price">' + formatRp(item.harga * item.qty) + '</div>' +
              '<button type="button" class="remove-btn" onclick="hapusItem(\'' + slot + '\')">Tutup</button>' +
            '</div>';
        hiddenHtml +=
            '<input type="hidden" name="detail_barang_id[]" value="' + item.itemId + '">' +
            '<input type="hidden" name="qty[]" id="hqty-' + slot + '" value="' + item.qty + '">' +
            '<input type="hidden" name="harga_satuan[]" id="hharga-' + slot + '" value="' + item.harga + '">' +
            '<input type="hidden" name="catatan_item[]" value="' + (item.itemId ? '' : item.nama.replace(/"/g, '&quot;')) + '">';
    });
    list.innerHTML   = html;
    hidden.innerHTML = hiddenHtml;
    updateTotal();
}

function updateTotal() {
    const slots = Object.keys(keranjang);
    let total = 0;
    slots.forEach(function(slot) {
        total += keranjang[slot].harga * keranjang[slot].qty;
    });
    document.getElementById('totalHarga').textContent = formatRp(total);
    document.getElementById('inputTotal').value = Math.round(total);
}

renderPilihanItem();
renderItems();
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
document.querySelectorAll('.sidebar-menu a, .sidebar-footer a').forEach(function(a){
    a.addEventListener('click', closeSidebar);
});
</script>
</body>
</html>