<?php
require_once __DIR__ . '/includes/config.php';
requireRole('kasir', 'owner');

// ── SIDEBAR COUNTERS ──
$dalam_antrian   = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'dalam_antrian'")->fetchColumn();
$jml_pesanan_online = $pdo->query("SELECT COUNT(*) FROM pesanan_online WHERE status = 'menunggu'")->fetchColumn();
$sedang_diproses = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'sedang_diproses'")->fetchColumn();
$siap_diambil    = $pdo->query("SELECT COUNT(*) FROM status_cucian WHERE status = 'siap_diambil'")->fetchColumn();

// ── KATALOG LAYANAN (kategori_barang + detail_barang) UNTUK FORM ──
// Harga sekarang FIXED per barang (kolom "harga" tunggal, sudah tidak ada
// harga_min/harga_max lagi). "estimasi_hari" dipakai untuk menghitung
// estimasi selesai otomatis (barang dengan estimasi_hari terbesar jadi acuan).
$kategori_list = $pdo->query("
    SELECT id_kategori, nama_kategori FROM kategori_barang WHERE is_aktif = 1 ORDER BY urutan
")->fetchAll();

$all_items = $pdo->query("
    SELECT il.id_barang, il.kategori_id, il.nama_item, il.harga, il.satuan,
           il.estimasi_hari, kl.nama_kategori
    FROM detail_barang il
    JOIN kategori_barang kl ON kl.id_kategori = il.kategori_id
    WHERE il.is_aktif = 1 AND kl.is_aktif = 1
    ORDER BY kl.urutan, il.urutan
")->fetchAll();
$item_json = json_encode($all_items, JSON_UNESCAPED_UNICODE);

$namaKasir = htmlspecialchars($_SESSION['user']['name']);
$inisial   = strtoupper(substr($_SESSION['user']['name'], 0, 2));

// ── PRE-FILL DARI PESANAN ONLINE (kalkulator estimasi.php) ──
// Kasir dibawa ke sini dari pesanan_online.php dengan ?dari_online=<id>.
// Nama & no HP pelanggan otomatis terisi, TAPI qty/berat tiap barang
// SENGAJA dikosongkan (bukan dipakai dari estimasi pelanggan) -- kasir
// WAJIB timbang ulang & isi qty/berat asli sebelum bisa disimpan, karena
// berat kiloan dari pelanggan cuma perkiraan sendiri, bisa beda dari berat
// aslinya. Lihat validasi JS di bawah (cekBelumDitimbang()).
$pesananOnline = null;
$dariOnlineId  = (int) ($_GET['dari_online'] ?? 0);
if ($dariOnlineId > 0) {
    $stmtPO = $pdo->prepare("SELECT id_pesanan_online, kode, nama_pelanggan, no_hp_pelanggan, status FROM pesanan_online WHERE id_pesanan_online = ?");
    $stmtPO->execute([$dariOnlineId]);
    $po = $stmtPO->fetch();
    if ($po && $po['status'] === 'menunggu') {
        $stmtPOD = $pdo->prepare("SELECT detail_barang_id, nama_item, satuan, qty_estimasi FROM pesanan_online_detail WHERE pesanan_online_id = ?");
        $stmtPOD->execute([$po['id_pesanan_online']]);
        $pesananOnline = [
            'id'      => $po['id_pesanan_online'],
            'kode'    => $po['kode'],
            'nama'    => $po['nama_pelanggan'],
            'no_hp'   => $po['no_hp_pelanggan'],
            'items'   => $stmtPOD->fetchAll(),
        ];
    }
}
$pesanan_online_json = json_encode($pesananOnline, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Transaksi Baru — Azka Laundry</title>
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
.btn-back { display:inline-flex; align-items:center; gap:6px; color:var(--sky-deep); text-decoration:none; font-size:13px; font-weight:600; margin-bottom:14px; }
.btn-back:hover { color:#101418; }

/* FORM — tidak dibungkus kotak putih terpisah, langsung menyatu dengan latar dashboard */
.form-section-title { font-size:13px; font-weight:700; color:#101418; text-transform:uppercase; letter-spacing:0.4px; margin:4px 0 16px; padding-bottom:10px; border-bottom:1px solid var(--border); }

.qty-belum-timbang { border-color:#D9A32B !important; background:#FFF7E6 !important; }
.form-label { font-size:12px; font-weight:700; color:#3A4450; margin-bottom:5px; }
.form-control, .form-select { display:block; width:100%; border:1.5px solid var(--border); border-radius:9px; font-size:13px; padding:10px 14px; font-family:inherit; background:var(--cloud); color:#101418; transition:border-color 0.2s; }
.form-control:focus, .form-select:focus { outline:none; border-color:var(--sky); box-shadow:0 0 0 3px rgba(46,116,192,0.12); }
.bank-chip-row { display:flex; flex-wrap:wrap; gap:6px; }
.bank-chip { padding:6px 12px; border-radius:20px; border:1.5px solid var(--border); background:var(--cloud); color:#3A4450; font-size:11.5px; font-weight:600; cursor:pointer; font-family:inherit; }
.bank-chip:hover { border-color:var(--sky); color:var(--sky-deep); background:var(--sky-pale); }
.bank-chip.active { background:var(--sky); border-color:var(--sky); color:#FFFFFF; }
/* Pengganti utility spacing Bootstrap (mb-3/mt-3) sekarang project sudah gak muat Bootstrap lagi */
.mb-3 { margin-bottom:16px; }
.mt-3 { margin-top:16px; }

.trx-grid { display:grid; grid-template-columns:1fr 360px; gap:28px; align-items:start; }
.trx-col-right { position:sticky; top:22px; }
@media (max-width:900px) {
    .trx-grid { grid-template-columns:1fr; }
    .trx-col-right { position:static; }
}

.cart-panel { background:#EAF2FB; border:1px solid var(--border); border-radius:12px; padding:16px; }
.cart-panel-title { font-size:13px; font-weight:700; color:#101418; display:flex; align-items:center; gap:7px; margin-bottom:12px; }
.cart-panel-title i { color:var(--sky-deep); }
.cart-items-wrap { max-height:340px; overflow-y:auto; padding-right:2px; }
.cart-items-wrap::-webkit-scrollbar { width:6px; }
.cart-items-wrap::-webkit-scrollbar-thumb { background:var(--border); border-radius:6px; }
.cart-empty { text-align:center; color:#4B5563; padding:20px 10px; font-size:12.5px; border:2px dashed var(--border); border-radius:10px; }

.item-picker-row { display:flex; gap:8px; margin-bottom:6px; }
.item-picker-row select { flex:1; }
.item-picker-row .btn-tambah-item { background:var(--sky); color:#FFFFFF; border:none; border-radius:9px; padding:0 16px; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; }
.item-picker-row .btn-tambah-item:hover { background:var(--sky-deep); }
.item-hint { font-size:11px; color:#4B5563; margin-bottom:14px; }
.jumlah-input-wrap { flex:1; display:flex; flex-direction:column; gap:3px; }
.jumlah-label { font-size:11px; font-weight:700; color:#3A4450; }
.harga-input-wrap { display:flex; align-items:center; gap:4px; font-size:11.5px; color:#3A4450; }
.harga-input-wrap input { width:72px; border:1.5px solid var(--border); border-radius:8px; padding:4px 6px; font-size:11.5px; text-align:right; }
.item-row .item-name small.range { display:block; color:#4B5563; font-weight:400; }

.item-row { display:flex; align-items:center; gap:8px; background:var(--cloud); border:1px solid var(--border); border-radius:9px; padding:10px; margin-bottom:8px; font-size:12.5px; }
.item-row .item-name  { flex:1 1 100%; font-weight:600; color:#101418; }
.item-row .item-price { color:var(--sky-deep); font-weight:700; margin-left:auto; }
.qty-ctrl { display:flex; align-items:center; gap:5px; }
.qty-btn { width:24px; height:24px; border-radius:6px; border:1px solid var(--border); background:var(--cloud); font-size:14px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--sky-deep); transition:all 0.15s; }
.qty-btn:hover { background:var(--sky); color:#FFFFFF; border-color:var(--sky); }
.qty-input { width:42px; text-align:center; padding:3px 4px; border:1px solid var(--border); border-radius:6px; font-size:12px; font-weight:600; }
.remove-btn { background:none; border:none; color:#101418; cursor:pointer; font-size:14px; padding:4px; opacity:0.5; }
.remove-btn:hover { opacity:1; }

.total-box { background:var(--sky-deep); border-radius:10px; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; margin-top:12px; }
.total-box .lbl { color:rgba(255,255,255,0.8); font-size:13px; }
.total-box .val { color:#FFFFFF; font-size:19px; font-weight:700; }

.ringkasan-jumlah { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }
.ringkasan-jumlah .chip { background:var(--sky-soft); color:var(--sky-deep); font-size:12px; font-weight:700; padding:5px 12px; border-radius:20px; display:inline-flex; align-items:center; gap:5px; }
.ringkasan-jumlah .chip i { font-size:10px; color:var(--sky-deep); }
.ringkasan-jumlah .chip-total { background:var(--sky); color:#FFFFFF; }
.ringkasan-jumlah .chip-total i { color:#FFFFFF; }

.btn-simpan { width:100%; padding:13px; background:var(--sky); color:#FFFFFF; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; margin-top:12px; font-family:inherit; transition:background 0.2s; }
.btn-simpan:hover { background:var(--sky-deep); }
.btn-simpan:disabled { background:var(--gray-soft); cursor:not-allowed; }
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
    <a href="transaksi_baru.php" class="active">Tambah Transaksi</a>
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
        Transaksi Baru
        <small>Input pesanan cucian pelanggan</small>
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

    <form method="POST" action="simpan_transaksi.php" id="formTrx">
      <?= csrf_field() ?>
      <input type="hidden" name="pesanan_online_id" id="inputPesananOnlineId" value="<?= $pesananOnline ? $pesananOnline['id'] : '' ?>">
      <div class="trx-grid">

            <div class="trx-col-left">
              <div class="form-section-title">Input Transaksi Baru</div>
              <div class="mb-3">
                <label class="form-label">Nama Pelanggan</label>
                <input type="text" name="nama_pelanggan" class="form-control" placeholder="Contoh: Budi Santoso" value="<?= $pesananOnline ? htmlspecialchars($pesananOnline['nama']) : '' ?>" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Nomor HP / WhatsApp</label>
                <input type="text" name="no_hp_pelanggan" class="form-control" placeholder="08xxxxxxxxxx" value="<?= $pesananOnline ? htmlspecialchars($pesananOnline['no_hp']) : '' ?>" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Estimasi Selesai</label>
                <input type="date" name="tanggal_estimasi" id="inputEstimasi" class="form-control"
                       min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 days')) ?>">
                <div class="item-hint" id="estimasiHint" style="margin-bottom:0">Terisi otomatis mengikuti barang dengan waktu pengerjaan terlama. Bisa diubah manual kalau perlu.</div>
              </div>
              <div class="mb-3">
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
                  <select class="form-select" id="pilihItem" onchange="updateJumlahConfig()"></select>
                </div>
                <div class="item-picker-row">
                  <div class="jumlah-input-wrap">
                    <label class="jumlah-label" id="jumlahLabel">Jumlah</label>
                    <input type="number" id="inputJumlah" class="form-control" value="1" min="1" step="1">
                  </div>
                  <button type="button" class="btn-tambah-item" onclick="tambahItemKeranjang()">Tambah</button>
                </div>
                <div class="item-hint">Pilih kategori lalu jenis barangnya, isi jumlah sesuai satuannya (kg untuk kiloan, pasang untuk sepatu, pcs untuk satuan, dll), baru klik Tambah. Harga otomatis terisi dari katalog, dan bisa diubah manual kalau perlu (misal diskon).</div>
              </div>
              <!-- Data katalog layanan (kategori_barang + detail_barang) untuk JS -->
              <script>const itemLayananData = <?= $item_json ?>;</script>
              <div id="hiddenInputs"></div>
            </div>

            <div class="trx-col-right">
              <div class="cart-panel">
                <div class="cart-panel-title">Barang Ditambahkan</div>
                <div class="cart-items-wrap">
                  <div id="itemList"></div>
                </div>
                <div id="ringkasanJumlah" class="ringkasan-jumlah"></div>
                <div class="total-box">
                  <div class="lbl">Total</div>
                  <div class="val" id="totalHarga">Rp 0</div>
                </div>
                <input type="hidden" name="total_harga" id="inputTotal" value="0">
                <div class="mb-3 mt-3">
                  <label class="form-label">Status Pembayaran</label>
                  <select name="status_bayar" id="inputStatusBayar" class="form-select" onchange="toggleMetodeBayar()">
                    <option value="belum_bayar">Belum Bayar</option>
                    <option value="dp">DP (Bayar Sebagian)</option>
                    <option value="sudah_bayar">Sudah Lunas</option>
                  </select>
                </div>
                <div class="mb-3" id="wrapJumlahDp" style="display:none">
                  <label class="form-label">Jumlah DP Dibayar</label>
                  <input type="number" name="jumlah_dp" id="inputJumlahDp" class="form-control" min="1" step="1" placeholder="Contoh: 20000">
                  <div class="item-hint" id="dpHint" style="margin-bottom:0">Sisa tagihan akan dihitung otomatis dan ditagihkan saat pelunasan (barang diambil).</div>
                </div>
                <div class="mb-3" id="wrapMetodeBayar" style="display:none">
                  <label class="form-label">Metode Pembayaran</label>
                  <select name="metode_bayar" id="inputMetodeBayar" class="form-select" onchange="toggleTransferFields()">
                    <option value="tunai">Tunai (Cash)</option>
                    <option value="transfer">Transfer</option>
                  </select>
                </div>
                <div class="mb-3" id="wrapTransferFields" style="display:none">
                  <label class="form-label">Nama Pengirim</label>
                  <input type="text" name="nama_pengirim" id="inputNamaPengirim" class="form-control" placeholder="Nama sesuai rekening/e-wallet pengirim">
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
                  <input type="text" name="no_pengirim" id="inputNoPengirim" class="form-control" style="margin-top:8px" placeholder="Contoh: BCA 1234567890, atau ShopeePay saja">
                </div>
                <button type="submit" class="btn-simpan" id="btnSimpan" disabled>
                  Simpan Transaksi
                </button>
              </div>
            </div>

      </div>
    </form>

  </div>
</div>

<script>
let keranjang = {};
let slotCounter = 0;

// ── PRE-FILL DARI PESANAN ONLINE ──
// pesananOnlineData diisi dari PHP (null kalau bukan datang dari alur
// pesanan online). Tiap barangnya dimasukkan ke keranjang -- TAPI cuma
// barang SATUAN KG yang wajib ditimbang ulang (qty dikosongkan, ditandai
// belumDitimbang=true). Barang hitungan seperti pasang/pcs/set jumlahnya
// sudah pasti sejak awal (mis. "1 pasang sepatu" ya tetap 1 pasang, tidak
// ada bedanya estimasi vs aktual seperti berat kiloan) -- jadi qty dari
// pelanggan langsung dipakai apa adanya, tidak perlu validasi ulang kasir.
const pesananOnlineData = <?= $pesanan_online_json ?>;
if (pesananOnlineData && Array.isArray(pesananOnlineData.items)) {
    pesananOnlineData.items.forEach(function (it) {
        // Ambil harga & estimasi TERKINI dari katalog (bukan snapshot lama),
        // jaga-jaga kalau harga sudah berubah sejak pelanggan pesan.
        const master = itemLayananData.find(x => String(x.id_barang) === String(it.detail_barang_id));
        const satuan = master ? master.satuan : it.satuan;
        const isKg   = satuan === 'kg';
        const slot   = 'slot' + (++slotCounter);
        keranjang[slot] = {
            itemId : it.detail_barang_id,
            nama   : master ? master.nama_item : it.nama_item,
            satuan : satuan,
            harga  : master ? parseFloat(master.harga) : 0,
            qty    : isKg ? 0 : (parseFloat(it.qty_estimasi) || 1),
            estimasiHari: master ? (parseInt(master.estimasi_hari) || 1) : 1,
            catatan: '',
            belumDitimbang: isKg,
            qtyEstimasiPelanggan: parseFloat(it.qty_estimasi) || 0,
        };
    });
}

function formatRp(n) {
    return 'Rp ' + Math.round(n).toLocaleString('id-ID');
}

// ── TAMPIL/SEMBUNYIKAN METODE PEMBAYARAN & JUMLAH DP ──
// Metode pembayaran relevan kalau statusnya "Sudah Lunas" ATAU "DP"
// (baris pembayaran dibuat untuk keduanya). Input jumlah DP cuma
// muncul kalau statusnya "DP".
function toggleMetodeBayar() {
    const status     = document.getElementById('inputStatusBayar').value;
    const wrap       = document.getElementById('wrapMetodeBayar');
    const wrapDp     = document.getElementById('wrapJumlahDp');
    const inputDp    = document.getElementById('inputJumlahDp');
    wrap.style.display   = (status === 'sudah_bayar' || status === 'dp') ? 'block' : 'none';
    wrapDp.style.display = status === 'dp' ? 'block' : 'none';
    inputDp.required     = status === 'dp';
    if (status === 'dp') updateDpHint();
    toggleTransferFields();
}
toggleMetodeBayar();

// Field "Nama Pengirim" / "No. Pengirim" cuma relevan & wajib diisi kalau
// metode bayar-nya Transfer -- supaya kalau ada selisih/komplain transfer,
// kasir gampang menelusuri dari nama/nomor pengirim yang dicatat di sini.
// Ikut disembunyikan/dihapus wajibnya kalau metode bayar section-nya
// sendiri lagi disembunyikan (status bukan DP/Lunas).
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
    // Tandai chip yang lagi aktif (biar kelihatan mana yang barusan dipilih)
    const row = input.closest('.mb-3, .form-group')?.querySelector('.bank-chip-row');
    if (row) {
        row.querySelectorAll('.bank-chip').forEach(c => c.classList.toggle('active', c.textContent.trim() === nama));
    }
}

// Batasi jumlah DP maksimal (harus lebih kecil dari total tagihan — kalau
// mau bayar penuh langsung, pakai status "Sudah Lunas") dan tampilkan
// perkiraan sisa yang harus dilunasi nanti.
function updateDpHint() {
    const total   = parseFloat(document.getElementById('inputTotal').value) || 0;
    const inputDp = document.getElementById('inputJumlahDp');
    const hint    = document.getElementById('dpHint');
    const maxDp   = Math.max(total - 1, 0);
    inputDp.max   = maxDp;
    const dpVal   = parseFloat(inputDp.value) || 0;
    const sisa    = Math.max(total - dpVal, 0);
    hint.textContent = total > 0
        ? 'Total tagihan ' + formatRp(total) + '. Sisa yang harus dilunasi saat barang diambil: ' + formatRp(sisa) + '.'
        : 'Tambahkan barang dulu supaya total tagihan terhitung.';
}
document.getElementById('inputJumlahDp').addEventListener('input', updateDpHint);

// Validasi jumlah DP sebelum form dikirim: harus > 0 dan lebih kecil dari total
// (kalau mau bayar penuh sekaligus, pakai status "Sudah Lunas", bukan DP).
document.getElementById('formTrx').addEventListener('submit', function(e) {
    // Kalau transaksi ini berasal dari Pesanan Online, tolak submit selama
    // masih ada barang yang belum ditimbang ulang (qty asli belum diisi
    // kasir) -- lihat pesananOnlineData & flag belumDitimbang di atas.
    const belumDitimbang = Object.values(keranjang).filter(it => it.belumDitimbang);
    if (belumDitimbang.length > 0) {
        e.preventDefault();
        alert('Masih ada ' + belumDitimbang.length + ' barang yang belum ditimbang ulang: ' +
              belumDitimbang.map(it => it.nama).join(', ') +
              '. Timbang dulu & isi qty/berat aslinya sebelum disimpan.');
        return;
    }

    const status = document.getElementById('inputStatusBayar').value;
    if (status !== 'dp') return;
    const total = parseFloat(document.getElementById('inputTotal').value) || 0;
    const dp    = parseFloat(document.getElementById('inputJumlahDp').value) || 0;
    if (dp <= 0) {
        e.preventDefault();
        alert('Jumlah DP harus diisi dan lebih dari 0.');
        return;
    }
    if (dp >= total) {
        e.preventDefault();
        alert('Jumlah DP tidak boleh sama atau lebih dari total tagihan (' + formatRp(total) + '). Kalau bayar penuh, pilih status "Sudah Lunas".');
    }
});

// ── KONFIGURASI PER SATUAN ──
// Sesuai logika laundry: barang kiloan/luasan (kg, m², liter) boleh angka desimal
// (misal 2.5 kg) untuk PENYESUAIAN (step 0.1), TAPI defaultnya tetap 1 kilogram
// (kiloan) dan minimalnya tetap 1 (khusus kategori luas/m²) — soalnya di laundry
// beneran gak ada order 0.1 kg atau 0.1 m² karpet. Barang satuan (pcs, set,
// pasang, lembar, potong) HARUS bilangan bulat, default & minimalnya 1 juga.
// "min" = batas bawah input, "default" = nilai awal saat barang baru dipilih/ditambahkan.
const satuanConfig = {
    'kg'     : { step: 0.1, min: 1, default: 1, label: 'Berat (kg)' },
    'liter'  : { step: 0.1, min: 1, default: 1, label: 'Jumlah (liter)' },
    'm2'     : { step: 0.1, min: 1, default: 1, label: 'Luas (m²)' },
    'm²'     : { step: 0.1, min: 1, default: 1, label: 'Luas (m²)' },
    'pcs'    : { step: 1,   min: 1, default: 1, label: 'Jumlah (pcs)' },
    'set'    : { step: 1,   min: 1, default: 1, label: 'Jumlah (set)' },
    'pasang' : { step: 1,   min: 1, default: 1, label: 'Jumlah (pasang)' },
    'lembar' : { step: 1,   min: 1, default: 1, label: 'Jumlah (lembar)' },
    'potong' : { step: 1,   min: 1, default: 1, label: 'Jumlah (potong)' },
};
function getSatuanConfig(satuan) {
    return satuanConfig[satuan] || { step: 1, min: 1, default: 1, label: 'Jumlah (' + satuan + ')' };
}

// Dropdown pemilihan barang: HANYA nama barang + satuan, tanpa harga.
// Dropdown pemilihan barang: HANYA nama barang + satuan, tanpa harga.
// Harga sudah otomatis terisi dari katalog saat barang ditambahkan ke keranjang,
// dan tetap bisa disesuaikan manual (misal untuk diskon) di daftar keranjang di bawah.
function renderPilihanItem() {
    const kategoriId = document.getElementById('pilihKategori').value;
    const sel = document.getElementById('pilihItem');
    const filtered = itemLayananData.filter(it => !kategoriId || String(it.kategori_id) === String(kategoriId));

    if (filtered.length === 0) {
        sel.innerHTML = '<option value="">Tidak ada barang di kategori ini</option>';
        updateJumlahConfig();
        return;
    }
    sel.innerHTML = filtered.map(function(it) {
        return '<option value="' + it.id_barang + '">' + it.nama_item + ' (' + it.satuan + ')</option>';
    }).join('');
    updateJumlahConfig();
}

// Sesuaikan label & step input "Jumlah" dengan satuan barang yang lagi dipilih,
// supaya kasir tahu persis mau isi berapa kg / berapa pasang / berapa pcs.
function updateJumlahConfig() {
    const itemId = document.getElementById('pilihItem').value;
    const item = itemLayananData.find(it => String(it.id_barang) === String(itemId));
    const cfg = item ? getSatuanConfig(item.satuan) : { step: 1, min: 1, default: 1, label: 'Jumlah' };
    const inp = document.getElementById('inputJumlah');
    const lbl = document.getElementById('jumlahLabel');
    lbl.textContent = cfg.label;
    inp.step = cfg.step;
    inp.min  = cfg.min;
    inp.value = cfg.default;
}

function tambahItemKeranjang() {
    const itemId = document.getElementById('pilihItem').value;
    if (!itemId) return;
    const item = itemLayananData.find(it => String(it.id_barang) === String(itemId));
    if (!item) return;

    const cfg = getSatuanConfig(item.satuan);
    let qty = parseFloat(document.getElementById('inputJumlah').value);
    if (isNaN(qty) || qty <= 0) {
        alert('Isi dulu jumlah (' + cfg.label + ') untuk "' + item.nama_item + '" sebelum ditambahkan.');
        return;
    }
    // Barang satuan bulat (pcs/set/pasang/dll) wajib bilangan bulat, kg/m²/liter boleh desimal
    qty = cfg.step >= 1 ? Math.round(qty) : Math.round(qty * 100) / 100;
    // Tetap jaga minimal per satuan (kg minimal 1, luas/m² minimal 1, dst)
    if (qty < cfg.min) qty = cfg.min;

    const slot = 'slot' + (++slotCounter);
    keranjang[slot] = {
        itemId : item.id_barang,
        nama   : item.nama_item,
        satuan : item.satuan,
        harga  : parseFloat(item.harga),
        qty    : qty,
        estimasiHari: parseInt(item.estimasi_hari) || 1,
        catatan: ''
    };
    renderItems();

    // reset input jumlah biar siap dipakai untuk barang berikutnya
    document.getElementById('inputJumlah').value = cfg.default;
}

function changeQty(slot, delta) {
    if (!keranjang[slot]) return;
    const cfg = getSatuanConfig(keranjang[slot].satuan);
    let q = Math.round((parseFloat(keranjang[slot].qty) + parseFloat(delta)) * 100) / 100;
    if (q < cfg.min) q = cfg.min;
    keranjang[slot].qty = q;
    keranjang[slot].belumDitimbang = false;
    const inp = document.getElementById('qty-' + slot);
    if (inp) inp.value = q;
    updateSubtotal(slot);
    renderItems();
}

function setQty(slot, val) {
    if (!keranjang[slot]) return;
    const cfg = getSatuanConfig(keranjang[slot].satuan);
    let q = parseFloat(val);
    if (isNaN(q) || q < cfg.min) q = cfg.min;
    q = cfg.step >= 1 ? Math.round(q) : Math.round(q * 100) / 100;
    keranjang[slot].qty = q;
    keranjang[slot].belumDitimbang = false;
    updateSubtotal(slot);
    renderItems();
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

function setCatatan(slot, val) {
    if (!keranjang[slot]) return;
    keranjang[slot].catatan = val;
    const hc = document.getElementById('hcatatan-' + slot);
    if (hc) hc.value = val;
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

// Estimasi selesai = hari ini + estimasi_hari TERBESAR dari semua barang di keranjang.
// Logikanya: kalau ada 1 transaksi isinya karpet (butuh 3 hari) + kaos kiloan (butuh 1 hari),
// semuanya baru bisa diambil sama-sama pas yang paling lama selesai, yaitu 3 hari.
function updateEstimasiSelesai() {
    const slots = Object.keys(keranjang);
    const inp = document.getElementById('inputEstimasi');
    const hint = document.getElementById('estimasiHint');

    let maxHari = 1;
    if (slots.length === 0) {
        hint.textContent = 'Terisi otomatis mengikuti barang dengan waktu pengerjaan terlama. Bisa diubah manual kalau perlu.';
    } else {
        maxHari = Math.max.apply(null, slots.map(function(s) { return keranjang[s].estimasiHari || 1; }));
        hint.textContent = 'Estimasi ' + maxHari + ' hari (mengikuti barang paling lama selesai). Bisa diubah manual kalau perlu.';
    }

    const tgl = new Date();
    tgl.setDate(tgl.getDate() + maxHari);
    const yyyy = tgl.getFullYear();
    const mm   = String(tgl.getMonth() + 1).padStart(2, '0');
    const dd   = String(tgl.getDate()).padStart(2, '0');
    inp.value = yyyy + '-' + mm + '-' + dd;
}

function renderItems() {
    const slots  = Object.keys(keranjang);
    const list   = document.getElementById('itemList');
    const hidden = document.getElementById('hiddenInputs');

    if (slots.length === 0) {
        list.innerHTML = '<div class="cart-empty">Belum ada barang.<br>Pilih barang di kolom kiri.</div>';
        hidden.innerHTML = '';
        document.getElementById('btnSimpan').disabled = true;
        updateTotal();
        updateEstimasiSelesai();
        return;
    }

    let html = '', hiddenHtml = '';
    slots.forEach(function(slot) {
        const item = keranjang[slot];
        const step = getSatuanConfig(item.satuan).step;
        const warn = item.belumDitimbang;
        const warnBadge = warn
            ? '<div style="color:#B8791A;font-size:11px;font-weight:700;margin-top:3px">⚠ Perlu ditimbang ulang (estimasi pelanggan: ' + item.qtyEstimasiPelanggan + ' ' + item.satuan + ')</div>'
            : '';
        html +=
            '<div class="item-row" style="flex-wrap:wrap' + (warn ? ';background:#FFF7E6;border-radius:8px;padding:8px' : '') + '">' +
              '<div class="item-name">' + item.nama +
                '<small class="range">Harga: ' + formatRp(item.harga) + ' / ' + item.satuan + ' &middot; estimasi ' + item.estimasiHari + ' hari</small>' +
                warnBadge +
              '</div>' +
              '<div class="qty-ctrl">' +
                '<button type="button" class="qty-btn" onclick="changeQty(\'' + slot + '\',-' + step + ')">&minus;</button>' +
                '<input id="qty-' + slot + '" class="qty-input' + (warn ? ' qty-belum-timbang' : '') + '" type="number" min="' + getSatuanConfig(item.satuan).min + '" step="' + step + '" value="' + item.qty + '" onchange="setQty(\'' + slot + '\',this.value)">' +
                '<button type="button" class="qty-btn" onclick="changeQty(\'' + slot + '\',' + step + ')">+</button>' +
              '</div>' +
              '<div class="harga-input-wrap">Rp <input id="harga-' + slot + '" type="number" min="0" value="' + item.harga + '" onchange="setHarga(\'' + slot + '\',this.value)"></div>' +
              '<div id="sub-' + slot + '" class="item-price">' + formatRp(item.harga * item.qty) + '</div>' +
              '<button type="button" class="remove-btn" onclick="hapusItem(\'' + slot + '\')">Tutup</button>' +
            '</div>';
        hiddenHtml +=
            '<input type="hidden" name="detail_barang_id[]" value="' + item.itemId + '">' +
            '<input type="hidden" name="qty[]" id="hqty-' + slot + '" value="' + item.qty + '">' +
            '<input type="hidden" name="harga_satuan[]" id="hharga-' + slot + '" value="' + item.harga + '">' +
            '<input type="hidden" name="catatan_item[]" id="hcatatan-' + slot + '" value="">';
    });
    list.innerHTML   = html;
    hidden.innerHTML = hiddenHtml;
    document.getElementById('btnSimpan').disabled = false;
    updateTotal();
    updateEstimasiSelesai();
}

function updateTotal() {
    const slots = Object.keys(keranjang);
    let total = 0;

    // Sesuai logika laundry: jumlah dijumlahkan PER SATUAN, bukan digabung jadi satu angka,
    // karena kg (kiloan) dan pcs (satuan) tidak bisa dijumlahkan begitu saja.
    const jumlahPerSatuan = {};
    let totalJenis = 0;

    slots.forEach(function(slot) {
        const item = keranjang[slot];
        total += item.harga * item.qty;
        jumlahPerSatuan[item.satuan] = (jumlahPerSatuan[item.satuan] || 0) + parseFloat(item.qty);
        totalJenis += 1;
    });

    document.getElementById('totalHarga').textContent = formatRp(total);
    document.getElementById('inputTotal').value = Math.round(total);
    if (document.getElementById('inputStatusBayar').value === 'dp') updateDpHint();

    const ringkasanEl = document.getElementById('ringkasanJumlah');
    if (slots.length === 0) {
        ringkasanEl.innerHTML = '';
        return;
    }

    let chips = '<span class="chip chip-total">' + totalJenis + ' jenis barang</span>';
    Object.keys(jumlahPerSatuan).forEach(function(satuan) {
        let jml = jumlahPerSatuan[satuan];
        jml = Math.round(jml * 100) / 100; // rapikan angka desimal
        chips += '<span class="chip">' + jml + ' ' + satuan + '</span>';
    });
    ringkasanEl.innerHTML = chips;
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
// Tutup sidebar otomatis saat pindah halaman/menu di mobile
document.querySelectorAll('.sidebar-menu a, .sidebar-footer a').forEach(function(a){
    a.addEventListener('click', closeSidebar);
});
</script>
</body>
</html>