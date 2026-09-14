<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/proses_helper.php';
requireRole('owner');

// Helper: terjemahkan pesan error PDO umum jadi pesan yang mudah dipahami pengguna
function pesanErrorRamah(PDOException $e, string $konteks): string {
    $kode = $e->errorInfo[1] ?? 0;
    if ($kode == 1062) { // Duplicate entry
        return "Gagal $konteks: data sudah digunakan.";
    }
    if ($kode == 1451 || $kode == 1452) { // Foreign key constraint
        return "Gagal $konteks: data ini masih terhubung dengan data lain (mis. transaksi), sehingga tidak bisa dihapus.";
    }
    error_log("CRUD error ($konteks): " . $e->getMessage());
    return "Gagal $konteks. Silakan coba lagi.";
}

// ── VERIFIKASI CSRF UNTUK SEMUA AKSI POST DI HALAMAN INI ──
$crud_msg   = '';
$crud_error = '';

// Pola Post-Redirect-Get supaya posisi scroll & refresh halaman aman
// (lihat catatan yang sama di kelola_karyawan.php).
function redirectCrud(?string $ok = null, ?string $err = null, ?int $bukaKategori = null): void {
    $params = [];
    if ($ok !== null)  $params['crud_ok']    = $ok;
    if ($err !== null) $params['crud_error'] = $err;
    if ($bukaKategori !== null) $params['buka_kategori'] = $bukaKategori;
    $qs = http_build_query($params);
    header('Location: kelola_barang.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf()) {
    redirectCrud(null, 'Sesi kedaluwarsa, silakan muat ulang halaman dan coba lagi.');
}

// Pesan hasil aksi (dikirim lewat query string oleh redirectCrud di atas)
if (isset($_GET['crud_ok']))    $crud_msg   = $_GET['crud_ok'];
if (isset($_GET['crud_error'])) $crud_error = $_GET['crud_error'];
$buka_kategori = isset($_GET['buka_kategori']) ? (int)$_GET['buka_kategori'] : null;

// ── CRUD KATEGORI ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_kategori']) && !$crud_error) {
    $action = $_POST['action_kategori'];
    try {
        if ($action === 'tambah') {
            $urutan = (int)$pdo->query("SELECT COALESCE(MAX(urutan),0)+1 FROM kategori_barang")->fetchColumn();
            $pdo->prepare("INSERT INTO kategori_barang (nama_kategori,deskripsi_kategori,urutan,is_aktif) VALUES (?,?,?,?)")
                ->execute([trim($_POST['nama_kategori']), trim($_POST['deskripsi_kategori']), $urutan, isset($_POST['is_aktif'])?1:0]);
            $idBaru = (int)$pdo->lastInsertId();
            redirectCrud('Kategori berhasil ditambahkan.', null, $idBaru);
        } elseif ($action === 'edit') {
            $idKat = (int)$_POST['id'];
            $pdo->prepare("UPDATE kategori_barang SET nama_kategori=?,deskripsi_kategori=?,is_aktif=? WHERE id_kategori=?")
                ->execute([trim($_POST['nama_kategori']), trim($_POST['deskripsi_kategori']), isset($_POST['is_aktif'])?1:0, $idKat]);
            redirectCrud('Kategori diperbarui.', null, $idKat);
        } elseif ($action === 'hapus') {
            // Kategori akan ikut menghapus semua barang di dalamnya (ON DELETE CASCADE)
            $pdo->prepare("DELETE FROM kategori_barang WHERE id_kategori=?")->execute([(int)$_POST['id']]);
            redirectCrud('Kategori dihapus.');
        }
    } catch (PDOException $e) {
        redirectCrud(null, pesanErrorRamah($e, 'menyimpan kategori'));
    }
}

// ── CRUD BARANG (item di dalam kategori) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_item']) && !$crud_error) {
    $action = $_POST['action_item'];
    $estimasi_hari = max(1, (int)($_POST['estimasi_hari'] ?? 1));
    $tipeProsesValid = ['cuci_kering', 'cuci_setrika', 'setrika_saja', 'item_khusus'];
    $tipeProses = in_array($_POST['tipe_proses'] ?? '', $tipeProsesValid, true) ? $_POST['tipe_proses'] : 'cuci_setrika';

    // ── URUTAN PROSES KUSTOM (opsional) ──
    // Owner boleh mengatur sendiri urutan tahap (Cuci/Keringkan/Setrika)
    // lewat 3 dropdown "Tahap 1/2/3", menggantikan template tipe_proses.
    // Kalau checkbox "pakai_custom_tahap" tidak dicentang, atau semua
    // dropdown dikosongkan, custom_tahap disimpan NULL (pakai template).
    $customTahap = null;
    if (isset($_POST['pakai_custom_tahap'])) {
        $tahapKustomValid = ['cuci', 'keringkan', 'setrika', 'kemas'];
        $pilihan = [];
        foreach (['custom_tahap_1', 'custom_tahap_2', 'custom_tahap_3', 'custom_tahap_4'] as $key) {
            $val = $_POST[$key] ?? '';
            if ($val !== '' && in_array($val, $tahapKustomValid, true) && !in_array($val, $pilihan, true)) {
                $pilihan[] = $val;
            }
        }
        if (!empty($pilihan)) {
            $customTahap = implode(',', $pilihan);
        }
    }

    try {
        $kategoriId = (int)($_POST['kategori_id'] ?? 0);
        if ($action === 'tambah') {
            $urutan = (int)$pdo->query("SELECT COALESCE(MAX(urutan),0)+1 FROM detail_barang WHERE kategori_id=" . $kategoriId)->fetchColumn();
            $pdo->prepare("INSERT INTO detail_barang (kategori_id,nama_item,detail_perawatan,harga,satuan,tipe_proses,custom_tahap,estimasi_hari,urutan,is_aktif) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $kategoriId, trim($_POST['nama_item']), trim($_POST['detail_perawatan']),
                    (float)$_POST['harga'], trim($_POST['satuan']), $tipeProses, $customTahap, $estimasi_hari, $urutan,
                    isset($_POST['is_aktif'])?1:0,
                ]);
            redirectCrud('Cucian berhasil ditambahkan.', null, $kategoriId);
        } elseif ($action === 'edit') {
            $pdo->prepare("UPDATE detail_barang SET kategori_id=?,nama_item=?,detail_perawatan=?,harga=?,satuan=?,tipe_proses=?,custom_tahap=?,estimasi_hari=?,is_aktif=? WHERE id_barang=?")
                ->execute([
                    $kategoriId, trim($_POST['nama_item']), trim($_POST['detail_perawatan']),
                    (float)$_POST['harga'], trim($_POST['satuan']), $tipeProses, $customTahap, $estimasi_hari,
                    isset($_POST['is_aktif'])?1:0, (int)$_POST['id'],
                ]);
            redirectCrud('Cucian diperbarui.', null, $kategoriId);
        } elseif ($action === 'hapus') {
            // detail_transaksi.detail_barang_id di-SET NULL otomatis (ON DELETE SET NULL),
            // histori transaksi lama tetap aman.
            $pdo->prepare("DELETE FROM detail_barang WHERE id_barang=?")->execute([(int)$_POST['id']]);
            redirectCrud('Cucian dihapus.');
        }
    } catch (PDOException $e) {
        redirectCrud(null, pesanErrorRamah($e, 'menyimpan barang'));
    }
}

// ── MASTER: KATEGORI + BARANG ──
$semua_kategori = $pdo->query("
    SELECT id_kategori, nama_kategori, deskripsi_kategori, urutan, is_aktif
    FROM kategori_barang ORDER BY urutan
")->fetchAll();

$semua_item = $pdo->query("
    SELECT il.id_barang, il.kategori_id, il.nama_item, il.detail_perawatan, il.harga,
           il.satuan, il.tipe_proses, il.custom_tahap, il.estimasi_hari, il.urutan, il.is_aktif, COUNT(dt.id_detail_transaksi) AS pemakaian,
           kl.nama_kategori
    FROM detail_barang il
    LEFT JOIN kategori_barang kl  ON kl.id_kategori = il.kategori_id
    LEFT JOIN detail_transaksi dt ON dt.detail_barang_id = il.id_barang
    GROUP BY il.id_barang
    ORDER BY il.kategori_id, il.urutan
")->fetchAll();

// Kelompokkan barang per kategori supaya mudah dirender di tiap panel kategori
$itemPerKategori = [];
foreach ($semua_item as $it) {
    $itemPerKategori[$it['kategori_id']][] = $it;
}

$namaOwner = htmlspecialchars($_SESSION['user']['name']);
$inisial   = strtoupper(substr($_SESSION['user']['name'], 0, 2));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Kelola Kategori &amp; Cucian — Azka Laundry</title>
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
* { margin:0; padding:0; box-sizing:border-box; font-family:'Plus Jakarta Sans',sans-serif; }
body { color:#101418; background:var(--sky-pale); min-height:100vh; }

.sidebar { width:230px; height:100vh; height:100dvh; background:var(--sky-deep); position:fixed; left:0; top:0; display:flex; flex-direction:column; z-index:100; overflow-y:auto; }
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
@media (max-width: 600px) {
    .form-row { grid-template-columns:1fr 1fr !important; }
}
@media (max-width: 420px) {
    .form-row { grid-template-columns:1fr !important; }
}
.topbar { background:var(--cloud); border-bottom:1px solid var(--border); padding:14px 26px; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:99; }
.topbar-title { font-size:17px; font-weight:700; color:#101418; }
.topbar-title small { display:block; font-size:12px; color:#3A4450; font-weight:400; margin-top:2px; }
.topbar-right { display:flex; align-items:center; gap:12px; }
.avatar { width:36px; height:36px; border-radius:50%; background:var(--sky); display:flex; align-items:center; justify-content:center; color:#FFFFFF; font-weight:700; font-size:12px; }
.user-name { font-size:13px; font-weight:600; color:#101418; }
.user-role { font-size:11px; color:#4B5563; }
.content { padding:22px 26px; }

.container { max-width:1300px; margin:auto; }
.card { background:var(--cloud); border-radius:14px; padding:20px; border:1px solid var(--border); }
.card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.card-title { font-size:15px; font-weight:700; color:#101418; display:flex; align-items:center; gap:8px; }

.crud-msg { background:#EAF2FB; border:1px solid #AECBEF; border-radius:12px; padding:12px 16px; margin-bottom:20px; font-size:13px; color:#1B2126; font-weight:600; }
.crud-err { background:#EAF2FB; border:1px solid #D6E7F7; border-radius:12px; padding:12px 16px; margin-bottom:20px; font-size:13px; color:#101418; font-weight:600; }

.lay-row { display:flex; justify-content:space-between; align-items:center; padding:10px 0 10px 14px; border-bottom:1px solid var(--border); font-size:13px; }
.lay-row:last-child { border-bottom:none; }
.lay-name  { font-weight:700; color:#101418; }
.lay-meta  { font-size:11px; color:#4B5563; margin-top:2px; }
.lay-harga { font-weight:700; color:var(--sky-deep); }
.badge-kustom { display:inline-flex; align-items:center; gap:5px; background:#EAF2FB; color:#3A4450; border:1px solid #D6E7F7; border-radius:999px; padding:2px 10px; font-size:10.5px; font-weight:700; margin-left:6px; }
.lay-pemakaian { font-size:11px; color:#4B5563; text-align:right; margin-top:2px; }

.kategori-block { border:1px solid var(--border); border-radius:12px; margin-bottom:12px; background:var(--sky-pale); overflow:hidden; }
.kategori-block:last-child { margin-bottom:0; }
.kategori-head { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; padding:14px 16px; cursor:pointer; user-select:none; }
.kategori-head:hover { background:rgba(31,95,166,0.06); }
.kategori-head-left { display:flex; align-items:flex-start; gap:10px; min-width:0; }
.kategori-caret { margin-top:3px; color:#4B5563; font-size:12px; transition:transform 0.2s ease; flex-shrink:0; }
.kategori-block.open .kategori-caret { transform:rotate(90deg); }
.kategori-count { font-size:11px; color:#4B5563; font-weight:500; margin-left:6px; }
.kategori-nama { font-weight:700; font-size:14px; color:#101418; }
.kategori-desk { font-size:11px; color:#3A4450; margin-top:2px; max-width:480px; }
.kategori-actions { display:flex; gap:4px; flex-shrink:0; }
.kategori-body { display:none; padding:0 16px 14px; }
.kategori-block.open .kategori-body { display:block; }
.kategori-block .lay-row { background:var(--cloud); border-radius:8px; padding:10px 12px; margin-top:6px; border-bottom:none; }

.btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; border:none; transition:all 0.18s; font-family:inherit; text-decoration:none; }
.btn-primary { background:var(--sky); color:#FFFFFF; }
.btn-primary:hover { background:var(--sky-deep); }
.btn-sm     { padding:5px 10px; font-size:11px; border-radius:7px; }
.btn-edit   { background:#FFFFFF; color:#1B2126; border:1.5px solid #1B2126; }
.btn-edit:hover { background:#1B2126; color:#FFFFFF; }
.btn-danger { background:#FFFFFF; color:#1B2126; border:1.5px solid #1B2126; }
.btn-danger:hover { background:#1B2126; color:#FFFFFF; }

.modal-overlay { display:none; position:fixed; inset:0; background:rgba(22,74,133,0.45); z-index:200; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.active { display:flex; }
.modal { background:var(--cloud); border-radius:16px; width:100%; max-width:480px; padding:24px; position:relative; max-height:90vh; overflow-y:auto; }
.modal-title { font-size:16px; font-weight:700; color:#101418; margin-bottom:18px; }
.modal-close { position:absolute; top:16px; right:16px; background:none; border:none; font-size:20px; cursor:pointer; color:#4B5563; line-height:1; }
.form-group { margin-bottom:14px; }
.form-label { font-size:12px; font-weight:700; color:#3A4450; margin-bottom:5px; display:block; }
.form-control, .form-select { width:100%; padding:10px 13px; border:1.5px solid var(--border); border-radius:9px; font-size:13px; font-family:inherit; outline:none; background:var(--cloud); color:#101418; }
.form-control:focus, .form-select:focus { border-color:var(--sky); }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.modal-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }
.btn-cancel { background:var(--sky-soft); color:var(--sky-deep); }
.btn-cancel:hover { background:var(--border); }

.confirm-overlay { display:none; position:fixed; inset:0; background:rgba(22,74,133,0.45); z-index:300; align-items:center; justify-content:center; }
.confirm-overlay.active { display:flex; }
.confirm-box { background:var(--cloud); border-radius:16px; padding:26px; max-width:360px; width:100%; text-align:center; }
.confirm-icon { font-size:34px; margin-bottom:12px; color:#101418; }
.confirm-title { font-size:16px; font-weight:700; color:#101418; margin-bottom:6px; }
.confirm-msg { font-size:13px; color:#3A4450; margin-bottom:20px; }
.confirm-btns { display:flex; gap:10px; justify-content:center; }


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
      <small>Owner Panel</small>
    </div>
  </div>
  <div class="sidebar-menu">
    <a href="dashboard_owner.php">Dashboard</a>
    <a href="laporan_barang.php">Rekap Frekuensi Cucian</a>
    <a href="kelola_barang.php" class="active">Kelola Kategori &amp; Cucian</a>
    <a href="kelola_karyawan.php">Kelola Karyawan</a>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php">Logout</a>
  </div>
</div>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">
      Kelola Kategori &amp; Cucian
      <small>Atur kategori dan daftar cucian beserta harganya</small>
    </div>
    <div class="topbar-right">
      <div style="text-align:right">
        <div class="user-name"><?= $namaOwner ?></div>
        <div class="user-role">Owner</div>
      </div>
      <div class="avatar"><?= $inisial ?></div>
    </div>
  </div>

  <div class="content">
  <div class="container">

  <?php if ($crud_msg): ?><div class="crud-msg" id="crudMsgBanner"><?= htmlspecialchars($crud_msg) ?></div><?php endif; ?>
  <?php if ($crud_error): ?><div class="crud-err" id="crudMsgBanner"><?= htmlspecialchars($crud_error) ?></div><?php endif; ?>

  <div class="card">
    <div class="card-header">
      <div class="card-title">Kelola Kategori &amp; Cucian</div>
      <button class="btn btn-primary btn-sm" onclick="bukaModalKategori('tambah')">Tambah Kategori</button>
    </div>
    <?php if (empty($semua_kategori)): ?>
      <div style="text-align:center;color:#4B5563;padding:20px;font-size:13px">Belum ada kategori.</div>
    <?php endif; ?>
    <?php foreach ($semua_kategori as $k):
      $itemsKat = $itemPerKategori[$k['id_kategori']] ?? [];
    ?>
    <div class="kategori-block" data-kategori-id="<?= (int)$k['id_kategori'] ?>">
      <div class="kategori-head" onclick="toggleKategori(this)">
        <div class="kategori-head-left">
                    <div>
            <div class="kategori-nama"><?= htmlspecialchars($k['nama_kategori']) ?>
              <?php if (!$k['is_aktif']): ?><span style="font-size:10px;color:#4B5563"> (nonaktif)</span><?php endif; ?>
              <span class="kategori-count">(<?= count($itemsKat) ?> cucian)</span>
            </div>
            <?php if (!empty($k['deskripsi_kategori'])): ?>
            <div class="kategori-desk"><?= htmlspecialchars($k['deskripsi_kategori']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="kategori-actions" onclick="event.stopPropagation()">
          <button class="btn btn-primary btn-sm" onclick='bukaModalItem("tambah", null, <?= (int)$k['id_kategori'] ?>)'>Cucian</button>
          <button class="btn btn-edit btn-sm" onclick='bukaModalKategori("edit",<?= htmlspecialchars(json_encode($k),ENT_QUOTES) ?>)'>Edit</button>
          <button class="btn btn-danger btn-sm" onclick="konfirmasiHapus('kategori',<?= (int)$k['id_kategori'] ?>,'<?= htmlspecialchars($k['nama_kategori'],ENT_QUOTES) ?>')">Hapus</button>
        </div>
      </div>

      <div class="kategori-body">
        <?php if (empty($itemsKat)): ?>
        <div style="color:#4B5563;font-size:12px;padding:8px 0 4px">Belum ada barang di kategori ini.</div>
        <?php else: foreach ($itemsKat as $it): ?>
        <div class="lay-row">
          <div>
            <div class="lay-name"><?= htmlspecialchars($it['nama_item']) ?>
              <?php if (!$it['is_aktif']): ?><span style="font-size:10px;color:#4B5563"> (nonaktif)</span><?php endif; ?>
            </div>
            <div class="lay-meta">per <?= htmlspecialchars($it['satuan']) ?> &middot; estimasi <?= (int)$it['estimasi_hari'] ?> hari &middot; <?= [
              'cuci_kering'  => 'Cuci Kering',
              'cuci_setrika' => 'Cuci + Setrika',
              'setrika_saja' => 'Setrika Saja',
              'item_khusus'  => 'Cucian Khusus',
            ][$it['tipe_proses']] ?? 'Cuci + Setrika' ?>
              <?php $tahapKustomIt = parseCustomTahap($it['custom_tahap'] ?? null); ?>
              <?php if (!empty($tahapKustomIt)):
                if (!in_array('kemas', $tahapKustomIt, true)) { $tahapKustomIt[] = 'kemas'; }
              ?>
                <span class="badge-kustom" title="Urutan proses cucian ini diatur manual oleh owner">
                  Kustom: <?= htmlspecialchars(implode(' → ', array_map(fn($t) => labelTahap($t, 'cuci_setrika', $it['nama_kategori'] ?? null), $tahapKustomIt))) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:12px">
            <div style="text-align:right">
              <div class="lay-harga"><?= 'Rp ' . number_format($it['harga'],0,',','.') ?></div>
              <div class="lay-pemakaian"><?= $it['pemakaian'] ?> × digunakan</div>
            </div>
            <div style="display:flex;gap:4px">
              <button class="btn btn-edit btn-sm" onclick='bukaModalItem("edit",<?= htmlspecialchars(json_encode($it),ENT_QUOTES) ?>)'>Edit</button>
              <button class="btn btn-danger btn-sm" onclick="konfirmasiHapus('item',<?= (int)$it['id_barang'] ?>,'<?= htmlspecialchars($it['nama_item'],ENT_QUOTES) ?>')">Hapus</button>
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div>
  </div>
</div>

<!-- MODAL KATEGORI -->
<div class="modal-overlay" id="modalKategori">
  <div class="modal">
    <button class="modal-close" onclick="tutupModal('modalKategori')">&times;</button>
    <div class="modal-title" id="judulModalKategori">Tambah Kategori</div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action_kategori" id="actionKategori" value="tambah">
      <input type="hidden" name="id" id="kategoriId">
      <div class="form-group"><label class="form-label">Nama Kategori</label>
        <input type="text" class="form-control" name="nama_kategori" id="kategoriNama" required placeholder="Cth: Karpet & Perlengkapan Rumah"></div>
      <div class="form-group"><label class="form-label">Deskripsi</label>
        <textarea class="form-control" name="deskripsi_kategori" id="kategoriDeskripsi" rows="3" placeholder="Deskripsi singkat metode perawatan kategori ini"></textarea></div>
      <div class="form-group" style="display:flex;align-items:center;padding-top:4px">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#101418;cursor:pointer">
          <input type="checkbox" name="is_aktif" id="kategoriAktif" value="1" style="width:16px;height:16px;accent-color:var(--sky)">
          Kategori Aktif</label>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-cancel" onclick="tutupModal('modalKategori')">Batal</button>
        <button type="submit" class="btn btn-primary">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL BARANG -->
<div class="modal-overlay" id="modalItem">
  <div class="modal">
    <button class="modal-close" onclick="tutupModal('modalItem')">&times;</button>
    <div class="modal-title" id="judulModalItem">Tambah Cucian</div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action_item" id="actionItem" value="tambah">
      <input type="hidden" name="id" id="itemId">
      <div class="form-group"><label class="form-label">Kategori</label>
        <select class="form-control" name="kategori_id" id="itemKategoriId" required>
          <?php foreach ($semua_kategori as $k): ?>
          <option value="<?= (int)$k['id_kategori'] ?>"><?= htmlspecialchars($k['nama_kategori']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="form-group"><label class="form-label">Nama Cucian</label>
        <input type="text" class="form-control" name="nama_item" id="itemNama" required placeholder="Cth: Karpet Bulu Tebal / Ambal"></div>
      <div class="form-group"><label class="form-label">Detail Perawatan</label>
        <textarea class="form-control" name="detail_perawatan" id="itemDetail" rows="2" placeholder="Cth: Cuci basah, sikat halus anti-rontok..."></textarea></div>
      <div class="form-group"><label class="form-label">Tipe Proses</label>
        <select class="form-control" name="tipe_proses" id="itemTipeProses" required>
          <option value="cuci_setrika">Cuci + Setrika (cuci, keringkan, setrika)</option>
          <option value="cuci_kering">Cuci Kering, tanpa setrika (cuci, keringkan)</option>
          <option value="setrika_saja">Setrika Saja, tanpa cuci (langsung setrika)</option>
          <option value="item_khusus">Cucian Khusus / karpet, sepatu, tas (cuci, keringkan, finishing)</option>
        </select>
        <small style="font-size:11px;color:#4B5563">Menentukan alur tahapan default yang muncul di panel Bagian Pencucian saat barang ini diproses.</small></div>

      <div class="form-group custom-tahap-box">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#101418;cursor:pointer;margin-bottom:8px">
          <input type="checkbox" name="pakai_custom_tahap" id="itemPakaiCustom" value="1" style="width:16px;height:16px;accent-color:var(--sky)" onchange="toggleCustomTahap()">
          Atur urutan proses sendiri untuk barang ini</label>
        <div id="customTahapFields" style="display:none;background:var(--sky-pale);border:1px solid var(--border);border-radius:10px;padding:12px">
          <small style="font-size:11px;color:#4B5563;display:block;margin-bottom:8px">Khusus untuk barang ini saja — pilihan "Tipe Proses" di atas tidak ikut berubah/tertimpa, cuma dilewati kalau urutan kustom ini diaktifkan. Kosongkan kolom yang tidak dipakai.</small>
          <div class="form-row" style="grid-template-columns:1fr 1fr 1fr 1fr">
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Tahap 1</label>
              <select class="form-select" name="custom_tahap_1" id="customTahap1">
                <option value="">(kosongkan)</option>
                <option value="cuci">Dicuci</option>
                <option value="keringkan">Dikeringkan</option>
                <option value="setrika">Disetrika</option>
                <option value="kemas">Dikemas</option>
              </select>
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Tahap 2</label>
              <select class="form-select" name="custom_tahap_2" id="customTahap2">
                <option value="">(kosongkan)</option>
                <option value="cuci">Dicuci</option>
                <option value="keringkan">Dikeringkan</option>
                <option value="setrika">Disetrika</option>
                <option value="kemas">Dikemas</option>
              </select>
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Tahap 3</label>
              <select class="form-select" name="custom_tahap_3" id="customTahap3">
                <option value="">(kosongkan)</option>
                <option value="cuci">Dicuci</option>
                <option value="keringkan">Dikeringkan</option>
                <option value="setrika">Disetrika</option>
                <option value="kemas">Dikemas</option>
              </select>
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Tahap 4</label>
              <select class="form-select" name="custom_tahap_4" id="customTahap4">
                <option value="">(kosongkan)</option>
                <option value="cuci">Dicuci</option>
                <option value="keringkan">Dikeringkan</option>
                <option value="setrika">Disetrika</option>
                <option value="kemas">Dikemas</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group"><label class="form-label">Harga (Rp)</label>
          <input type="number" class="form-control" name="harga" id="itemHarga" required min="0" placeholder="35000"></div>
        <div class="form-group"><label class="form-label">Estimasi Selesai (hari)</label>
          <input type="number" class="form-control" name="estimasi_hari" id="itemEstimasiHari" required min="1" step="1" value="1" placeholder="2"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Satuan</label>
          <input type="text" class="form-control" name="satuan" id="itemSatuan" required placeholder="kg / pcs / m² / set / pasang">
          <small style="font-size:11px;color:#4B5563">Ketik bebas — isi "kg" untuk barang kiloan, "pcs" untuk satuan, dll.</small></div>
        <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#101418;cursor:pointer">
            <input type="checkbox" name="is_aktif" id="itemAktif" value="1" style="width:16px;height:16px;accent-color:var(--sky)">
            Cucian Aktif</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-cancel" onclick="tutupModal('modalItem')">Batal</button>
        <button type="submit" class="btn btn-primary">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- CONFIRM HAPUS -->
<div class="confirm-overlay" id="confirmHapus">
  <div class="confirm-box">
        <div class="confirm-title">Konfirmasi Hapus</div>
    <div class="confirm-msg" id="confirmMsg">Yakin ingin menghapus data ini?</div>
    <div class="confirm-btns">
      <button class="btn btn-cancel" onclick="tutupConfirm()">Batal</button>
      <form method="POST" id="formHapus" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="hapusId">
        <button type="submit" class="btn btn-danger">Hapus</button>
      </form>
    </div>
  </div>
</div>

<script>
const bukaKategoriId = <?= $buka_kategori !== null ? (int)$buka_kategori : 'null' ?>;

if (document.getElementById('crudMsgBanner')) {
    window.scrollTo({ top: 0, behavior: 'instant' });
}

function tutupModal(id) { document.getElementById(id).classList.remove('active'); }
function tutupConfirm() { document.getElementById('confirmHapus').classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target===el) el.classList.remove('active'); });
});

// ── ACCORDION KATEGORI ──
function toggleKategori(headEl) {
    const block = headEl.closest('.kategori-block');
    block.classList.toggle('open');
}

// Setelah tambah/edit kategori atau tambah/edit barang, otomatis buka
// kembali kategori terkait supaya pengguna langsung lihat hasilnya.
if (bukaKategoriId !== null) {
    const target = document.querySelector('.kategori-block[data-kategori-id="' + bukaKategoriId + '"]');
    if (target) {
        target.classList.add('open');
        target.scrollIntoView({ block: 'center', behavior: 'instant' });
    }
}

function bukaModalKategori(mode, data=null) {
    document.getElementById('judulModalKategori').textContent = mode==='tambah' ? 'Tambah Kategori' : 'Edit Kategori';
    document.getElementById('actionKategori').value = mode;
    if (data) {
        document.getElementById('kategoriId').value         = data.id_kategori;
        document.getElementById('kategoriNama').value       = data.nama_kategori;
        document.getElementById('kategoriDeskripsi').value  = data.deskripsi_kategori || '';
        document.getElementById('kategoriAktif').checked    = data.is_aktif==1;
    } else {
        ['kategoriId','kategoriNama','kategoriDeskripsi'].forEach(id => document.getElementById(id).value='');
        document.getElementById('kategoriAktif').checked = true;
    }
    document.getElementById('modalKategori').classList.add('active');
}

function toggleCustomTahap() {
    const checked = document.getElementById('itemPakaiCustom').checked;
    document.getElementById('customTahapFields').style.display = checked ? 'block' : 'none';
}

function bukaModalItem(mode, data=null, kategoriIdDefault=null) {
    document.getElementById('judulModalItem').textContent = mode==='tambah' ? 'Tambah Cucian' : 'Edit Cucian';
    document.getElementById('actionItem').value = mode;
    const slotCustom = ['customTahap1','customTahap2','customTahap3','customTahap4'];
    if (data) {
        document.getElementById('itemId').value          = data.id_barang;
        document.getElementById('itemKategoriId').value  = data.kategori_id;
        document.getElementById('itemNama').value        = data.nama_item;
        document.getElementById('itemDetail').value      = data.detail_perawatan || '';
        document.getElementById('itemHarga').value       = data.harga;
        document.getElementById('itemEstimasiHari').value = data.estimasi_hari || 1;
        document.getElementById('itemSatuan').value      = data.satuan;
        document.getElementById('itemTipeProses').value  = data.tipe_proses || 'cuci_setrika';
        document.getElementById('itemAktif').checked     = data.is_aktif==1;

        // Urutan proses kustom (kalau ada)
        const kustom = (data.custom_tahap || '').split(',').map(s => s.trim()).filter(Boolean);
        slotCustom.forEach((id, i) => document.getElementById(id).value = kustom[i] || '');
        document.getElementById('itemPakaiCustom').checked = kustom.length > 0;
    } else {
        ['itemId','itemNama','itemDetail','itemHarga','itemSatuan'].forEach(id => document.getElementById(id).value='');
        document.getElementById('itemEstimasiHari').value = 1;
        document.getElementById('itemTipeProses').value = 'cuci_setrika';
        document.getElementById('itemAktif').checked = true;
        slotCustom.forEach(id => document.getElementById(id).value = '');
        document.getElementById('itemPakaiCustom').checked = false;
        if (kategoriIdDefault) document.getElementById('itemKategoriId').value = kategoriIdDefault;
    }
    toggleCustomTahap();
    document.getElementById('modalItem').classList.add('active');
}

function konfirmasiHapus(jenis, id, nama) {
    document.getElementById('hapusId').value = id;
    let pesan = `Yakin ingin menghapus "${nama}"? Tindakan ini tidak bisa dibatalkan.`;
    if (jenis === 'kategori') pesan = `Yakin ingin menghapus kategori "${nama}"? Semua cucian di dalam kategori ini akan ikut terhapus.`;
    document.getElementById('confirmMsg').textContent = pesan;
    const form = document.getElementById('formHapus');
    const old = form.querySelector('input[name^="action"]');
    if (old) old.remove();
    const actionName = { kategori:'action_kategori', item:'action_item' }[jenis] || 'action_item';
    const inp = document.createElement('input');
    inp.type  = 'hidden';
    inp.name  = actionName;
    inp.value = 'hapus';
    form.appendChild(inp);
    document.getElementById('confirmHapus').classList.add('active');
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
</body>
</html>