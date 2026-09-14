<?php
require_once __DIR__ . '/includes/config.php';
requireRole('owner');

// Helper: terjemahkan pesan error PDO umum jadi pesan yang mudah dipahami pengguna
function pesanErrorRamah(PDOException $e, string $konteks): string {
    $kode = $e->errorInfo[1] ?? 0;
    if ($kode == 1062) { // Duplicate entry
        return "Gagal $konteks: data (misalnya email) sudah digunakan.";
    }
    if ($kode == 1451 || $kode == 1452) { // Foreign key constraint
        return "Gagal $konteks: data ini masih terhubung dengan data lain, sehingga tidak bisa dihapus.";
    }
    error_log("CRUD error ($konteks): " . $e->getMessage());
    return "Gagal $konteks. Silakan coba lagi.";
}

// ── VERIFIKASI CSRF UNTUK SEMUA AKSI POST DI HALAMAN INI ──
$crud_msg   = '';
$crud_error = '';

// FIX: pola Post-Redirect-Get. Sebelumnya setelah edit/hapus/tambah, halaman
// cuma di-render ulang di URL POST yang sama — browser jadi tidak melakukan
// navigasi baru, sehingga posisi scroll tetap di tempat form/tombol tadi
// (biasanya di bawah), bukan otomatis kembali ke atas. Dengan redirect ke
// URL GET baru setelah setiap aksi berhasil/gagal, browser memuat halaman
// dari atas seperti navigasi biasa. Ini juga mencegah data terkirim dobel
// kalau halaman di-refresh setelah submit.
function redirectCrud(?string $ok = null, ?string $err = null): void {
    $params = [];
    if ($ok !== null)  $params['crud_ok']    = $ok;
    if ($err !== null) $params['crud_error'] = $err;
    $qs = http_build_query($params);
    header('Location: kelola_karyawan.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf()) {
    redirectCrud(null, 'Sesi kedaluwarsa, silakan muat ulang halaman dan coba lagi.');
}

// Pesan hasil aksi (dikirim lewat query string oleh redirectCrud di atas)
if (isset($_GET['crud_ok']))    $crud_msg   = $_GET['crud_ok'];
if (isset($_GET['crud_error'])) $crud_error = $_GET['crud_error'];

// ── CRUD KARYAWAN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_karyawan']) && !$crud_error) {
    $action = $_POST['action_karyawan'];
    try {
        if ($action === 'tambah') {
            $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)")
                ->execute([trim($_POST['name']), trim($_POST['email']), $hash, $_POST['role']]);
            redirectCrud('Karyawan berhasil ditambahkan.');
        } elseif ($action === 'edit') {
            $id = (int)$_POST['id'];
            if (!empty($_POST['password'])) {
                $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE users SET name=?,email=?,password=?,role=?,updated_at=NOW() WHERE id_user=?")
                    ->execute([trim($_POST['name']), trim($_POST['email']), $hash, $_POST['role'], $id]);
            } else {
                $pdo->prepare("UPDATE users SET name=?,email=?,role=?,updated_at=NOW() WHERE id_user=?")
                    ->execute([trim($_POST['name']), trim($_POST['email']), $_POST['role'], $id]);
            }
            redirectCrud('Data karyawan diperbarui.');
        } elseif ($action === 'hapus') {
            $idHapus = (int)$_POST['id'];
            if ($idHapus === (int)$_SESSION['user']['id_user']) {
                redirectCrud(null, 'Tidak bisa menghapus akun yang sedang kamu gunakan sendiri.');
            } else {
                $pdo->prepare("DELETE FROM users WHERE id_user=?")->execute([$idHapus]);
                redirectCrud('Karyawan dihapus.');
            }
        }
    } catch (PDOException $e) {
        redirectCrud(null, pesanErrorRamah($e, 'menyimpan data karyawan'));
    }
}

// ── PEGAWAI ──
$pegawai = $pdo->query("SELECT id_user,name,email,role,created_at FROM users ORDER BY id_user")->fetchAll();

// Kelompokkan pegawai per role supaya tampil sebagai seksi dashboard, bukan satu daftar rata
$grupPegawai = ['owner' => [], 'kasir' => [], 'bagian_pencucian' => []];
foreach ($pegawai as $p) {
    $grupPegawai[$p['role']][] = $p;
}

$roleMeta = [
    'owner'            => ['label' => 'Owner',            'icon' => 'fa-user-tie',       'desc' => 'Akses penuh ke seluruh sistem'],
    'kasir'            => ['label' => 'Kasir',             'icon' => 'fa-cash-register',  'desc' => 'Menerima & mencatat transaksi pelanggan'],
    'bagian_pencucian' => ['label' => 'Bagian Pencucian',  'icon' => 'fa-shirt',          'desc' => 'Memproses status cucian dari antrian sampai selesai'],
];

$totalKaryawan = count($pegawai);

$namaOwner = htmlspecialchars($_SESSION['user']['name']);
$inisial   = strtoupper(substr($_SESSION['user']['name'], 0, 2));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Kelola Karyawan — Azka Laundry</title>
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
.topbar { background:var(--cloud); border-bottom:1px solid var(--border); padding:14px 26px; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:99; }
.topbar-title { font-size:17px; font-weight:700; color:#101418; }
.topbar-title small { display:block; font-size:12px; color:#3A4450; font-weight:400; margin-top:2px; }
.topbar-right { display:flex; align-items:center; gap:12px; }
.avatar { width:36px; height:36px; border-radius:50%; background:var(--sky); display:flex; align-items:center; justify-content:center; color:#FFFFFF; font-weight:700; font-size:12px; }
.user-name { font-size:13px; font-weight:600; color:#101418; }
.user-role { font-size:11px; color:#4B5563; }
.content { padding:22px 26px; }

.container { max-width:1100px; margin:auto; }
.card { background:var(--cloud); border-radius:14px; padding:20px; border:1px solid var(--border); }
.card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.card-title { font-size:15px; font-weight:700; color:#101418; display:flex; align-items:center; gap:8px; }

.crud-msg { background:#EAF2FB; border:1px solid #AECBEF; border-radius:12px; padding:12px 16px; margin-bottom:20px; font-size:13px; color:#1B2126; font-weight:600; }
.crud-err { background:#EAF2FB; border:1px solid #D6E7F7; border-radius:12px; padding:12px 16px; margin-bottom:20px; font-size:13px; color:#101418; font-weight:600; }

/* RINGKASAN — statistik jumlah karyawan per role */
.stat-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px; }
.stat-card { background:var(--cloud); border-radius:14px; padding:18px 20px; border:1px solid var(--border); position:relative; overflow:hidden; }
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--sky); }
.stat-icon { font-size:19px; margin-bottom:8px; color:var(--sky-deep); }
.stat-label { font-size:12px; color:#3A4450; font-weight:600; margin-bottom:4px; }
.stat-num { font-size:26px; font-weight:800; color:#101418; line-height:1; }
@media (max-width:900px) { .stat-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width:480px) {
    .stat-grid { grid-template-columns:repeat(4,1fr); gap:6px; }
    .stat-card { padding:8px 6px; }
    .stat-card::before { display:none; }
    .stat-icon { display:none; }
    .stat-label { font-size:8px; margin-bottom:1px; line-height:1.2; }
    .stat-num { font-size:15px; }
}

/* SEKSI PER ROLE — menggantikan daftar rata/tabel */
.role-section { background:var(--cloud); border-radius:14px; border:1px solid var(--border); margin-bottom:16px; overflow:hidden; }
.role-section:last-child { margin-bottom:0; }
.role-section-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:16px 20px; border-bottom:1px solid var(--border); background:var(--sky-pale); }
.role-section-title { font-size:14px; font-weight:700; color:#101418; display:flex; align-items:center; gap:9px; }
.role-section-title i { width:18px; text-align:center; color:var(--sky-deep); }
.role-count { font-size:11px; font-weight:700; color:var(--sky-deep); background:var(--sky-soft); padding:2px 9px; border-radius:20px; }
.role-section-desc { font-size:11.5px; color:#4B5563; margin-top:3px; }
.role-section-body { padding:14px 20px; display:flex; flex-direction:column; gap:10px; }

.emp-card { display:flex; align-items:center; gap:14px; padding:12px 14px; border:1px solid var(--border); border-radius:12px; background:var(--cloud); transition:box-shadow 0.15s, border-color 0.15s; }
.emp-card:hover { box-shadow:0 6px 16px rgba(22,74,133,0.07); border-color:#D6E7F7; }
.emp-ava { width:42px; height:42px; border-radius:11px; background:var(--sky-soft); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; color:var(--sky-deep); flex-shrink:0; }
.emp-info { flex:1; min-width:0; }
.emp-name  { font-size:13.5px; font-weight:700; color:#101418; }
.emp-meta-row { display:flex; flex-wrap:wrap; gap:4px 14px; margin-top:3px; }
.emp-meta  { font-size:11.5px; color:#4B5563; display:flex; align-items:center; gap:5px; }
.emp-meta i { font-size:10.5px; }
.emp-actions { display:flex; gap:6px; flex-shrink:0; }

.empty-role { text-align:center; color:#4B5563; font-size:12.5px; padding:14px 0; }

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
.modal { background:var(--cloud); border-radius:16px; width:100%; max-width:440px; padding:24px; position:relative; max-height:90vh; overflow-y:auto; }
.modal-title { font-size:16px; font-weight:700; color:#101418; margin-bottom:18px; }
.modal-close { position:absolute; top:16px; right:16px; background:none; border:none; font-size:20px; cursor:pointer; color:#4B5563; line-height:1; }
.form-group { margin-bottom:14px; }
.form-label { font-size:12px; font-weight:700; color:#3A4450; margin-bottom:5px; display:block; }
.form-control, .form-select { width:100%; padding:10px 13px; border:1.5px solid var(--border); border-radius:9px; font-size:13px; font-family:inherit; outline:none; background:var(--cloud); color:#101418; }
.form-control:focus, .form-select:focus { border-color:var(--sky); }
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
    <a href="kelola_barang.php">Kelola Kategori &amp; Cucian</a>
    <a href="kelola_karyawan.php" class="active">Kelola Karyawan</a>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php">Logout</a>
  </div>
</div>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">
      Kelola Karyawan
      <small>Atur akun kasir, bagian pencucian, dan owner</small>
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

  <!-- RINGKASAN -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-icon">Karyawan</div>
      <div class="stat-label">Total Karyawan</div>
      <div class="stat-num"><?= $totalKaryawan ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"></div>
      <div class="stat-label">Owner</div>
      <div class="stat-num"><?= count($grupPegawai['owner']) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"></div>
      <div class="stat-label">Kasir</div>
      <div class="stat-num"><?= count($grupPegawai['kasir']) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"></div>
      <div class="stat-label">Bagian Pencucian</div>
      <div class="stat-num"><?= count($grupPegawai['bagian_pencucian']) ?></div>
    </div>
  </div>

  <div class="card-header" style="margin-bottom:14px">
    <div class="card-title">Kelola Karyawan</div>
    <button class="btn btn-primary btn-sm" onclick="bukaModalKaryawan('tambah')">Tambah Karyawan</button>
  </div>

  <!-- SEKSI PER ROLE -->
  <?php foreach ($roleMeta as $roleKey => $meta): $anggota = $grupPegawai[$roleKey]; ?>
  <div class="role-section">
    <div class="role-section-head">
      <div>
        <div class="role-section-title"><?= $meta['label'] ?> <span class="role-count"><?= count($anggota) ?> orang</span></div>
        <div class="role-section-desc"><?= $meta['desc'] ?></div>
      </div>
      <button class="btn btn-edit btn-sm" onclick="bukaModalKaryawan('tambah','<?= $roleKey ?>')">Tambah <?= $meta['label'] ?></button>
    </div>
    <div class="role-section-body">
      <?php if (empty($anggota)): ?>
      <div class="empty-role">Belum ada <?= strtolower($meta['label']) ?>.</div>
      <?php else: foreach ($anggota as $p):
        $inisialP = strtoupper(substr($p['name'],0,2));
      ?>
      <div class="emp-card">
        <div class="emp-ava"><?= $inisialP ?></div>
        <div class="emp-info">
          <div class="emp-name"><?= htmlspecialchars($p['name']) ?></div>
          <div class="emp-meta-row">
            <span class="emp-meta"><?= htmlspecialchars($p['email']) ?></span>
            <span class="emp-meta">Bergabung <?= date('d M Y', strtotime($p['created_at'])) ?></span>
          </div>
        </div>
        <div class="emp-actions">
          <button class="btn btn-edit btn-sm" onclick='bukaModalKaryawan("edit",<?= htmlspecialchars(json_encode($p),ENT_QUOTES) ?>)'>Edit</button>
          <button class="btn btn-danger btn-sm" onclick="konfirmasiHapus(<?= $p['id_user'] ?>,'<?= htmlspecialchars($p['name'],ENT_QUOTES) ?>')">Hapus</button>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

</div>
  </div>
</div>

<!-- MODAL KARYAWAN -->
<div class="modal-overlay" id="modalKaryawan">
  <div class="modal">
    <button class="modal-close" onclick="tutupModal('modalKaryawan')">&times;</button>
    <div class="modal-title" id="judulModalKaryawan">Tambah Karyawan</div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action_karyawan" id="actionKaryawan" value="tambah">
      <input type="hidden" name="id" id="karyawanId">
      <div class="form-group"><label class="form-label">Nama Lengkap</label>
        <input type="text" class="form-control" name="name" id="karyawanName" required placeholder="Nama karyawan"></div>
      <div class="form-group"><label class="form-label">Email</label>
        <input type="email" class="form-control" name="email" id="karyawanEmail" required placeholder="email@domain.com"></div>
      <div class="form-group"><label class="form-label">Role</label>
        <select class="form-control" name="role" id="karyawanRole">
          <option value="kasir">Kasir</option>
          <option value="bagian_pencucian">Bagian Pencucian</option>
          <option value="owner">Owner</option>
        </select></div>
      <div class="form-group"><label class="form-label">Password</label>
        <input type="password" class="form-control" name="password" id="karyawanPassword" placeholder="Kosongkan jika tidak diubah">
        <small id="hintPass" style="font-size:11px;color:#4B5563;display:none">Kosongkan jika tidak ingin mengubah password</small></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-cancel" onclick="tutupModal('modalKaryawan')">Batal</button>
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
        <input type="hidden" name="action_karyawan" value="hapus">
        <input type="hidden" name="id" id="hapusId">
        <button type="submit" class="btn btn-danger">Hapus</button>
      </form>
    </div>
  </div>
</div>

<script>
if (document.getElementById('crudMsgBanner')) {
    window.scrollTo({ top: 0, behavior: 'instant' });
}

function tutupModal(id) { document.getElementById(id).classList.remove('active'); }
function tutupConfirm() { document.getElementById('confirmHapus').classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target===el) el.classList.remove('active'); });
});

function bukaModalKaryawan(mode, dataAtauRole=null) {
    document.getElementById('judulModalKaryawan').textContent = mode==='tambah' ? 'Tambah Karyawan' : 'Edit Karyawan';
    document.getElementById('actionKaryawan').value = mode;
    document.getElementById('hintPass').style.display = mode==='edit' ? 'block' : 'none';
    document.getElementById('karyawanPassword').required = mode==='tambah';
    if (mode === 'edit' && dataAtauRole) {
        const data = dataAtauRole;
        document.getElementById('karyawanId').value    = data.id_user;
        document.getElementById('karyawanName').value  = data.name;
        document.getElementById('karyawanEmail').value = data.email;
        document.getElementById('karyawanRole').value  = data.role;
        document.getElementById('karyawanPassword').value = '';
    } else {
        ['karyawanId','karyawanName','karyawanEmail','karyawanPassword'].forEach(id => document.getElementById(id).value='');
        // Kalau dipicu dari tombol "+ Tambah" di seksi tertentu, dataAtauRole berisi nama role-nya
        document.getElementById('karyawanRole').value = (typeof dataAtauRole === 'string') ? dataAtauRole : 'kasir';
    }
    document.getElementById('modalKaryawan').classList.add('active');
}

function konfirmasiHapus(id, nama) {
    document.getElementById('hapusId').value = id;
    document.getElementById('confirmMsg').textContent = `Yakin ingin menghapus "${nama}"? Tindakan ini tidak bisa dibatalkan.`;
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