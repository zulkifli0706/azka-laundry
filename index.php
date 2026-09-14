<?php
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Azka Laundry — Bersih, Rapih, Wangi</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
<style>
:root {
    /* ── Tema: Biru Langit & Putih ── */
    --sky:        #4E90D9;   /* aksen utama, biru langit */
    --sky-deep:   #2E74C0;   /* hover / kontras */
    --sky-ink:    #164A85;   /* teks di atas biru terang, navy-biru lembut */
    --sky-soft:   #EAF2FB;   /* panel lembut */
    --sky-pale:   #EAF2FB;   /* bg utama, hampir putih dgn rona biru */
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

/* ── HERO ── */
.hero { background:linear-gradient(180deg, #EAF2FB 0%, var(--sky-pale) 55%, var(--sky-pale) 100%); padding:84px 24px 70px; text-align:center; position:relative; overflow:hidden; }
.hero::before {
    content:'';
    position:absolute; inset:0;
    background:
        radial-gradient(circle at 14% 22%, rgba(255,255,255,0.9) 0%, transparent 3%),
        radial-gradient(circle at 88% 16%, rgba(255,255,255,0.8) 0%, transparent 4%),
        radial-gradient(circle at 92% 70%, rgba(255,255,255,0.7) 0%, transparent 3.5%),
        radial-gradient(circle at 6% 75%, rgba(255,255,255,0.6) 0%, transparent 3%),
        radial-gradient(ellipse at 50% -10%, rgba(78,144,217,0.18) 0%, transparent 55%);
    pointer-events:none;
}
.hero-badge { display:inline-flex; align-items:center; gap:6px; background:var(--cloud); border:1px solid var(--border); color:var(--sky-deep); padding:7px 18px; border-radius:30px; font-size:13px; font-weight:700; margin-bottom:26px; position:relative; z-index:1; box-shadow:0 2px 10px rgba(78,144,217,0.12); }
.hero h1 { font-family:'Fraunces', serif; font-weight:600; font-size:54px; line-height:1.12; color:#101418; margin-bottom:18px; position:relative; z-index:1; letter-spacing:-0.5px; }
.hero h1 em { font-style:italic; color:var(--sky); }
.hero p { max-width:520px; margin:0 auto 34px; color:#3A4450; font-size:16px; line-height:1.7; position:relative; z-index:1; }
.hero-btns { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; position:relative; z-index:1; }
.btn { padding:13px 28px; border-radius:30px; font-size:14px; font-weight:700; text-decoration:none; font-family:inherit; cursor:pointer; border:none; transition:all 0.2s; display:inline-flex; align-items:center; gap:8px; }
.btn-primary { background:var(--sky); color:#FFFFFF; box-shadow:0 4px 14px rgba(78,144,217,0.35); }
.btn-primary:hover { background:var(--sky-deep); transform:translateY(-1px); box-shadow:0 6px 18px rgba(78,144,217,0.4); }
.btn-outline { background:var(--cloud); color:#101418; border:1px solid var(--border); }
.btn-outline:hover { background:var(--sky-soft); border-color:var(--sky); }
.hero-stats { display:flex; justify-content:center; gap:48px; margin-top:52px; padding-top:36px; border-top:1px solid var(--border); position:relative; z-index:1; }
.hero-stat .num { font-family:'Fraunces', serif; font-size:26px; font-weight:600; color:#101418; }
.hero-stat .lbl { font-size:12px; color:#4B5563; margin-top:3px; font-weight:500; }

/* ── HERO: layout 2 kolom (teks kiri, ilustrasi kanan di desktop) ── */
.hero-grid { max-width:1180px; margin:0 auto; display:grid; grid-template-columns:1.05fr 0.95fr; gap:36px; align-items:center; text-align:left; }
.hero-content { position:relative; z-index:1; }
.hero-content .hero-badge { margin-left:0; }
.hero-content p { margin-left:0; }
.hero-content .hero-cek-form,
.hero-content .hero-input-row { margin-left:0; }
.hero-content .hero-btns,
.hero-content .hero-stats { justify-content:flex-start; }

/* ── ILUSTRASI HERO (dibuat sendiri sbg SVG, bukan foto — konsisten dgn gaya logo) ── */
.hero-visual { position:relative; z-index:1; display:flex; justify-content:center; }
.hero-illust-wrap { position:relative; width:100%; max-width:400px; animation:heroFloat 10s ease-in-out infinite; }
.hero-illust-wrap svg { width:100%; height:auto; display:block; }
@keyframes heroFloat { 0%, 100% { transform:translateY(0); } 50% { transform:translateY(-10px); } }
.hero-chip {
    position:absolute; display:flex; align-items:center; gap:8px;
    background:var(--cloud); border:1px solid var(--border); border-radius:14px;
    padding:9px 14px 9px 9px; box-shadow:0 12px 28px rgba(22,74,133,0.18);
    font-size:12.5px; font-weight:700; color:#101418; white-space:nowrap;
    animation:heroChipBob 8.5s ease-in-out infinite;
}
.hero-chip .hc-ico {
    width:26px; height:26px; border-radius:50%; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
}
.hero-chip .hc-ico svg { width:14px; height:14px; }
.hero-chip-1 { top:2%; right:-6%; animation-delay:0s; }
.hero-chip-1 .hc-ico { background:var(--sky-soft); color:var(--sky-deep); }
.hero-chip-2 { top:38%; left:-11%; animation-delay:1s; }
.hero-chip-2 .hc-ico { background:#DCF5E8; color:#16A34A; }
.hero-chip-3 { bottom:6%; right:2%; animation-delay:2s; }
.hero-chip-3 .hc-ico { background:#FCE7F3; color:#DB2777; }
@keyframes heroChipBob { 0%, 100% { transform:translateY(0); } 50% { transform:translateY(-8px); } }
@media (prefers-reduced-motion: reduce) {
    .hero-illust-wrap { animation:none; }
    .hero-chip { animation:none; }
}

/* ── HERO CEK FORM ── */
.hero-cek-form { position:relative; z-index:1; margin-top:30px; }
.hero-input-row {
    display:flex; align-items:center;
    background:var(--cloud);
    border:1.5px solid #AFD1F0;
    border-radius:50px; padding:6px 6px 6px 22px;
    max-width:600px; margin:0 auto;
    box-shadow:0 6px 24px rgba(78,144,217,0.15), inset 0 0 0 1px rgba(78,144,217,0.06);
    transition:border-color 0.2s, box-shadow 0.2s;
}
.hero-input-row:focus-within { border-color:var(--sky); box-shadow:0 8px 28px rgba(78,144,217,0.22); }
.hero-input-row input {
    flex:1; background:transparent; border:none; outline:none;
    color:#101418; font-size:15px; font-family:inherit;
    letter-spacing:1px; text-transform:uppercase;
}
.hero-input-row input::placeholder { color:#4B5563; text-transform:none; letter-spacing:0; font-size:14px; }
.hero-input-row button {
    background:var(--sky); color:#FFFFFF; border:none;
    border-radius:40px; padding:11px 24px;
    font-size:14px; font-weight:700; font-family:inherit;
    cursor:pointer; white-space:nowrap; transition:background 0.2s;
    flex-shrink:0;
}
.hero-input-row button:hover { background:var(--sky-deep); }

/* ── SECTION ── */
section { padding:64px 24px; }
.section-inner { max-width:1140px; margin:auto; }
.section-header { text-align:center; margin-bottom:42px; }
.section-header h2 { font-family:'Fraunces', serif; font-weight:600; font-size:32px; color:#101418; margin-bottom:8px; letter-spacing:-0.3px; }
.section-header p { color:#3A4450; font-size:15px; }
.label-chip { display:inline-block; background:var(--sky-soft); color:var(--sky-deep); font-size:12px; font-weight:700; padding:5px 15px; border-radius:30px; margin-bottom:14px; letter-spacing:0.5px; }

/* ── HARGA & ESTIMASI (kartu ajakan ke halaman kalkulator) ── */
.layanan-section { background:var(--sky-pale); position:relative; overflow:visible; }
.estimasi-cta-wrap { position:relative; max-width:560px; margin:0 auto; }
.estimasi-cta {
    text-align:center; position:relative; z-index:1;
    background:var(--cloud); border:1px solid var(--border); border-radius:24px;
    padding:48px 40px; box-shadow:0 8px 32px rgba(78,144,217,0.1);
    transition:box-shadow 0.25s, transform 0.25s;
}
.estimasi-cta:hover { box-shadow:0 16px 44px rgba(78,144,217,0.18); transform:translateY(-3px); }
.estimasi-cta-icon {
    width:64px; height:64px; margin:0 auto 18px; border-radius:18px;
    background:linear-gradient(135deg, var(--sky), var(--sky-deep));
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 8px 20px rgba(46,116,192,0.3);
}
.estimasi-cta-icon svg { width:30px; height:30px; color:#FFFFFF; }
.estimasi-cta h3 { font-family:'Fraunces', serif; font-weight:600; font-size:22px; color:#101418; margin-bottom:10px; }
.estimasi-cta p { font-size:14px; color:#3A4450; line-height:1.7; margin-bottom:24px; }

/* Mini pratinjau alur: pilih -> atur jumlah -> total muncul -- 3 langkah nyata,
   bukan cuma hiasan, jadi wajar dinomori */
.estimasi-mini-steps { display:flex; align-items:center; justify-content:center; gap:6px; margin-bottom:26px; }
.estimasi-mini-steps .ems-step { display:flex; flex-direction:column; align-items:center; gap:6px; width:76px; }
.estimasi-mini-steps .ems-ico {
    width:36px; height:36px; border-radius:10px; background:var(--sky-soft);
    display:flex; align-items:center; justify-content:center; color:var(--sky-deep);
}
.estimasi-mini-steps .ems-ico svg { width:17px; height:17px; }
.estimasi-mini-steps .ems-lbl { font-size:10.5px; color:#4B5563; font-weight:600; text-align:center; line-height:1.3; }
.estimasi-mini-steps .ems-arrow { color:var(--border); font-size:16px; margin-bottom:20px; }

/* Kartu kecil melayang + gelembung di sekitar kartu CTA -- sama seperti di
   halaman kalkulator, ditempel ke .estimasi-cta-wrap supaya selalu nempel &
   kelihatan jelas, bukan hanyut ke ujung layar */
.ecw-float-chip {
    position:absolute; display:flex; align-items:center; gap:7px;
    background:var(--cloud); border:1.5px solid var(--sky); border-radius:30px;
    padding:7px 14px 7px 7px; font-size:12px; font-weight:700; color:#101418;
    box-shadow:0 10px 22px rgba(22,74,133,0.16); white-space:nowrap; z-index:2;
    animation:ecwBob 9.5s ease-in-out infinite;
}
.ecw-float-chip .ecw-ico {
    width:22px; height:22px; border-radius:50%; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    background:var(--sky); color:#FFFFFF;
}
.ecw-float-chip .ecw-ico svg { width:12px; height:12px; }
.ecw-chip-1 { top:6%; left:-6%; animation-delay:0s; }
.ecw-chip-2 { bottom:10%; right:-7%; animation-delay:1.6s; }
.ecw-bubble { position:absolute; border-radius:50%; background:var(--sky); opacity:0.14; pointer-events:none; animation:ecwDrift 12s ease-in-out infinite; }
@keyframes ecwBob { 0%, 100% { transform:translateY(0); } 50% { transform:translateY(-9px); } }
@keyframes ecwDrift { 0%, 100% { transform:translateY(0) scale(1); } 50% { transform:translateY(-12px) scale(1.1); } }
@media (prefers-reduced-motion: reduce) {
    .ecw-float-chip, .ecw-bubble { animation:none; }
}
@media (max-width: 640px) {
    .ecw-float-chip { display:none; }
}

/* ── FITUR ── */
.features-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:20px; position:relative; z-index:1; }
.feature-card { background:var(--cloud); border:1px solid var(--border); border-radius:18px; padding:26px; transition:all 0.25s; }
.feature-card:hover { box-shadow:0 12px 30px rgba(78,144,217,0.16); transform:translateY(-5px); border-color:var(--sky); }
.feature-icon {
    width:48px; height:48px; border-radius:14px; background:var(--sky-soft);
    display:flex; align-items:center; justify-content:center; margin-bottom:16px;
    color:var(--sky-deep); transition:background 0.25s, color 0.25s, transform 0.25s;
    animation:featureBob 7.5s ease-in-out infinite;
}
.feature-icon svg { width:23px; height:23px; }
.feature-card:nth-child(1) .feature-icon { animation-delay:0s; }
.feature-card:nth-child(2) .feature-icon { animation-delay:0.4s; }
.feature-card:nth-child(3) .feature-icon { animation-delay:0.8s; }
.feature-card:nth-child(4) .feature-icon { animation-delay:1.2s; }
.feature-card:hover .feature-icon { background:var(--sky); color:#FFFFFF; transform:scale(1.08); }
.feature-card h4 { font-size:15px; font-weight:700; color:#101418; margin-bottom:8px; }
.feature-card p { font-size:13px; color:#3A4450; line-height:1.65; }
@keyframes featureBob { 0%, 100% { transform:translateY(0); } 50% { transform:translateY(-6px); } }
@media (prefers-reduced-motion: reduce) {
    .feature-icon { animation:none; }
}

/* ── CARA KERJA (3 langkah, urutan beneran jadi nomor relevan) ── */
.steps-row { display:flex; gap:0; }
.step-item { flex:1; text-align:center; position:relative; padding:0 20px; }
.step-item:not(:last-child)::after {
    content:''; position:absolute; top:27px; left:calc(50% + 46px); right:calc(-50% + 46px);
    height:0; border-top:2px dashed var(--border);
}
.step-num {
    width:54px; height:54px; margin:0 auto 18px; border-radius:50%;
    background:var(--sky-ink); color:#FFFFFF; display:flex; align-items:center; justify-content:center;
    font-family:'Fraunces', serif; font-weight:600; font-size:21px; position:relative; z-index:1;
    box-shadow:0 8px 18px rgba(22,74,133,0.25);
}
.step-item h4 { font-size:15px; font-weight:700; color:#101418; margin-bottom:8px; }
.step-item p { font-size:13px; color:#3A4450; line-height:1.65; max-width:230px; margin:0 auto; }

/* ── FOOTER ── */
footer { background:linear-gradient(135deg, var(--sky-ink), var(--sky-deep)); color:rgba(255,255,255,0.8); padding:44px 24px; text-align:center; }
footer .logo-f { font-family:'Fraunces', serif; font-weight:600; font-size:22px; color:#FFFFFF; margin-bottom:8px; }
footer p { font-size:13px; margin-bottom:4px; }
footer .copy { font-size:12px; color:rgba(255,255,255,0.45); margin-top:20px; }

/* ── RESPONSIVE ── */
@media (max-width: 860px) {
    .hero h1 { font-size:38px; }
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
    .nav-menu a.active { background:var(--sky-soft); font-weight:700; }
    .nav-menu .btn-login { width:100%; text-align:center; padding:12px; margin-top:4px; }

    /* Hero: 1 kolom, ilustrasi pindah ke atas & mengecil, teks kembali rata tengah */
    .hero-grid { grid-template-columns:1fr; text-align:center; }
    .hero-content { text-align:center; }
    .hero-content p { margin-left:auto; margin-right:auto; }
    .hero-content .hero-btns,
    .hero-content .hero-stats { justify-content:center; }
    .hero-content .hero-cek-form,
    .hero-content .hero-input-row { margin:0 auto; }
    .hero-visual { order:-1; margin-bottom:6px; }
    .hero-illust-wrap { max-width:260px; }
    .hero-chip { font-size:11px; padding:7px 11px 7px 7px; }
    .hero-chip-1 { top:0; right:-4%; }
    .hero-chip-2 { top:34%; left:-9%; }
    .hero-chip-3 { bottom:4%; right:0; }

    /* Cara kerja: langkah ditumpuk vertikal, garis putus-putus ikut disembunyikan */
    .steps-row { flex-direction:column; gap:28px; }
    .step-item:not(:last-child)::after { display:none; }
}

@media (max-width: 480px) {
    /* Navbar: logo diganti versi ikon bulat saja (tanpa teks) supaya
       tombol menu tetap lega di sisi kanan pada layar sempit */
    .nav-inner { padding:10px 14px; }
    .logo .logo-full { display:none; }
    .logo .logo-compact { display:block; }
    .nav-menu { left:10px; right:10px; }

    .hero { padding:52px 16px 46px; }
    .hero h1 { font-size:29px; letter-spacing:-0.3px; }
    .hero-badge { font-size:11.5px; padding:6px 14px; margin-bottom:20px; }
    .hero p { font-size:13.5px; padding:0 4px; margin-bottom:26px; }

    /* Form cek status: input & tombol ditumpuk vertikal biar tidak kejepit */
    .hero-input-row {
        flex-direction: column;
        align-items: stretch;
        border-radius: 20px;
        padding: 10px;
        gap: 8px;
        max-width: 100%;
    }
    .hero-input-row input {
        width: 100%;
        padding: 10px 8px;
        text-align: center;
        font-size: 13.5px;
    }
    .hero-input-row button {
        width: 100%;
        border-radius: 14px;
        padding: 13px 20px;
    }

    .hero-stats { gap:22px; row-gap:16px; margin-top:36px; padding-top:26px; }
    .hero-stat .num { font-size:21px; }

    .hero-illust-wrap { max-width:200px; }
    .hero-chip { display:none; }
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav>
  <div class="nav-inner">
    <a href="#" class="logo">
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
      <a href="estimasi.php">Estimasi dan Pesan</a>
      <a href="cek_status.php">Cek Cucian</a>
      <a href="#kontak">Kontak</a>
      <a href="login.php" class="btn-login">Login Pegawai</a>
    </div>
    <button type="button" class="nav-toggle" id="navToggle" onclick="document.getElementById('navMenu').classList.toggle('open')" aria-label="Buka menu">
      <span></span>
    </button>
  </div>
</nav>

<!-- HERO -->
<div class="hero">
  <div class="hero-grid">
    <div class="hero-content">
      <div class="hero-badge">Laundry Kiloan & Satuan · Bandung</div>
      <h1>Laundry bersih, rapih,<br>dan bisa dipantau <em>kapan saja</em></h1>
      <p>Cek status cucian secara online menggunakan ID nota yang dikirimkan via WhatsApp. Tanpa login, tanpa ribet.</p>

      <!-- Form cek nota: submit langsung ke halaman cek_status.php -->
      <form method="POST" action="cek_status.php" class="hero-cek-form">
        <div class="hero-input-row">
          <input type="text" name="id_nota"
                 placeholder="Masukkan ID Nota, contoh: AZK250519ABCD"
                 autocomplete="off">
          <button type="submit">Cek Sekarang</button>
        </div>
      </form>

      <div class="hero-btns" style="margin-top:16px">
        <a href="estimasi.php" class="btn btn-outline" style="font-size:13px;padding:10px 22px">Lihat Estimasi dan Pesan Online</a>
      </div>

      <div class="hero-stats">
        <div class="hero-stat"><div class="num">Rp 6rb</div><div class="lbl">per kg kiloan</div></div>
        <div class="hero-stat"><div class="num">Bersih</div><div class="lbl">Rapih & Wangi</div></div>
        <div class="hero-stat"><div class="num">Online</div><div class="lbl">Cek status cucian</div></div>
      </div>
    </div>

    <!-- Ilustrasi: keranjang cucian & baju, digambar sbg SVG asli (bukan foto) supaya konsisten dgn gaya logo -->
    <div class="hero-visual">
      <div class="hero-illust-wrap">
        <svg viewBox="0 0 440 440" xmlns="http://www.w3.org/2000/svg">
          <circle cx="230" cy="215" r="190" fill="#DCEBFA"/>
          <circle cx="140" cy="115" r="55" fill="#FFFFFF" opacity="0.55"/>
          <ellipse cx="228" cy="374" rx="120" ry="14" fill="#164A85" opacity="0.08"/>

          <!-- baju-baju yang menyembul dari keranjang -->
          <g transform="rotate(-14 165 195)">
            <path d="M135 165 L150 150 L165 160 L180 150 L195 165 L188 179 L180 174 L180 252 L150 252 L150 174 L142 179 Z" fill="#FFFFFF" stroke="#D6E7F7" stroke-width="2"/>
          </g>
          <g transform="rotate(5 225 178)">
            <path d="M195 148 L210 133 L225 143 L240 133 L255 148 L248 162 L240 158 L240 236 L210 236 L210 158 L202 162 Z" fill="#EAF2FB" stroke="#D6E7F7" stroke-width="2"/>
          </g>
          <g transform="rotate(17 296 196)">
            <path d="M266 166 L281 151 L296 161 L311 151 L326 166 L319 180 L311 176 L311 252 L281 252 L281 176 L273 180 Z" fill="#4E90D9" stroke="#2E74C0" stroke-width="2"/>
          </g>

          <!-- keranjang -->
          <defs>
            <clipPath id="basketClip">
              <path d="M120 235 L330 235 L305 350 Q303 362 291 362 L159 362 Q147 362 145 350 Z"/>
            </clipPath>
          </defs>
          <g clip-path="url(#basketClip)">
            <rect x="120" y="235" width="210" height="130" fill="#2E74C0"/>
            <line x1="148" y1="235" x2="148" y2="362" stroke="#FFFFFF" stroke-opacity="0.15" stroke-width="5"/>
            <line x1="176" y1="235" x2="176" y2="362" stroke="#FFFFFF" stroke-opacity="0.15" stroke-width="5"/>
            <line x1="204" y1="235" x2="204" y2="362" stroke="#FFFFFF" stroke-opacity="0.15" stroke-width="5"/>
            <line x1="232" y1="235" x2="232" y2="362" stroke="#FFFFFF" stroke-opacity="0.15" stroke-width="5"/>
            <line x1="260" y1="235" x2="260" y2="362" stroke="#FFFFFF" stroke-opacity="0.15" stroke-width="5"/>
            <line x1="288" y1="235" x2="288" y2="362" stroke="#FFFFFF" stroke-opacity="0.15" stroke-width="5"/>
          </g>
          <ellipse cx="118" cy="248" rx="12" ry="22" fill="none" stroke="#2E74C0" stroke-width="7"/>
          <ellipse cx="332" cy="248" rx="12" ry="22" fill="none" stroke="#2E74C0" stroke-width="7"/>
          <ellipse cx="225" cy="235" rx="105" ry="18" fill="#4E90D9"/>
          <ellipse cx="225" cy="231" rx="95" ry="13" fill="#2E74C0"/>

          <!-- gelembung -->
          <circle cx="118" cy="132" r="10" fill="#FFFFFF" opacity="0.85"/>
          <circle cx="150" cy="96" r="6" fill="#FFFFFF" opacity="0.7"/>
          <circle cx="312" cy="112" r="14" fill="#FFFFFF" opacity="0.8"/>
          <circle cx="337" cy="152" r="7" fill="#FFFFFF" opacity="0.6"/>
          <circle cx="88" cy="182" r="8" fill="#EAF2FB" opacity="0.9"/>
          <circle cx="352" cy="202" r="9" fill="#EAF2FB" opacity="0.9"/>

          <!-- kilauan -->
          <path d="M200 60 L202.5 67.5 L210 70 L202.5 72.5 L200 80 L197.5 72.5 L190 70 L197.5 67.5 Z" fill="#FFFFFF"/>
          <path d="M262 56 L263.5 60.5 L268 62 L263.5 63.5 L262 68 L260.5 63.5 L256 62 L260.5 60.5 Z" fill="#EAF2FB"/>
        </svg>

        <div class="hero-chip hero-chip-1">
          <span class="hc-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C12 2 5 10 5 15a7 7 0 0 0 14 0c0-5-7-13-7-13Z"/></svg>
          </span>
          Bersih
        </div>
        <div class="hero-chip hero-chip-2">
          <span class="hc-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12l5 5L20 6"/></svg>
          </span>
          Rapi
        </div>
        <div class="hero-chip hero-chip-3">
          <span class="hc-ico">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l1.8 5.2L19 9l-5.2 1.8L12 16l-1.8-5.2L5 9l5.2-1.8L12 2Z"/></svg>
          </span>
          Wangi
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     HARGA LAYANAN & ESTIMASI BIAYA (ajakan ke halaman kalkulator)
═════════════════════════════════════════ -->
<section id="layanan" class="layanan-section">
  <div class="section-inner">
    <div class="section-header">
      <div class="label-chip">HARGA & ESTIMASI</div>
      <h2>Harga Barang & Estimasi Biaya</h2>
      <p>Pilih kategori & barang, lalu lihat langsung estimasi total biaya cucian Anda secara real-time</p>
    </div>

    <div class="estimasi-cta-wrap">
      <span class="ecw-bubble" style="width:20px;height:20px;top:-4%;left:30%;"></span>
      <span class="ecw-bubble" style="width:13px;height:13px;bottom:8%;left:8%;animation-delay:1.2s"></span>
      <span class="ecw-bubble" style="width:16px;height:16px;top:10%;right:14%;animation-delay:2.1s"></span>

      <div class="ecw-float-chip ecw-chip-1">
        <span class="ecw-ico">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12l5 5L20 6"/></svg>
        </span>
        Tanpa Login
      </div>
      <div class="ecw-float-chip ecw-chip-2">
        <span class="ecw-ico">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>
        </span>
        Hitung Otomatis
      </div>

      <div class="estimasi-cta">
        <div class="estimasi-cta-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12.6 3.5h5.4a1.5 1.5 0 0 1 1.5 1.5v5.4a2 2 0 0 1-.6 1.4l-8.6 8.6a2 2 0 0 1-2.8 0l-5.4-5.4a2 2 0 0 1 0-2.8l8.6-8.6a2 2 0 0 1 1.4-.1Z"/><circle cx="16.5" cy="7.5" r="1.25"/></svg>
        </div>
        <h3>Estimasi dan Pesan Online</h3>
        <p>Pilih kategori, tambahkan barang dari katalog, dan atur jumlahnya — total estimasi biaya langsung tampil otomatis.</p>

        <div class="estimasi-mini-steps">
          <div class="ems-step">
            <div class="ems-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h7"/></svg></div>
            <div class="ems-lbl">Pilih Barang</div>
          </div>
          <div class="ems-arrow">&rarr;</div>
          <div class="ems-step">
            <div class="ems-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg></div>
            <div class="ems-lbl">Atur Jumlah</div>
          </div>
          <div class="ems-arrow">&rarr;</div>
          <div class="ems-step">
            <div class="ems-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12.6 3.5h5.4a1.5 1.5 0 0 1 1.5 1.5v5.4a2 2 0 0 1-.6 1.4l-8.6 8.6a2 2 0 0 1-2.8 0l-5.4-5.4a2 2 0 0 1 0-2.8l8.6-8.6a2 2 0 0 1 1.4-.1Z"/><circle cx="16.5" cy="7.5" r="1.25"/></svg></div>
            <div class="ems-lbl">Total Muncul</div>
          </div>
        </div>

        <a href="estimasi.php" class="btn btn-primary">Buka Estimasi dan Pesan Online &rarr;</a>
      </div>
    </div>
  </div>
</section>

<!-- FITUR -->
<section style="background:var(--cloud); position:relative; overflow:hidden">
  <span class="ecw-bubble" style="width:26px;height:26px;top:6%;left:6%;"></span>
  <span class="ecw-bubble" style="width:16px;height:16px;top:14%;right:9%;animation-delay:1.4s"></span>
  <span class="ecw-bubble" style="width:20px;height:20px;bottom:10%;right:4%;animation-delay:2.5s"></span>
  <div class="section-inner">
    <div class="section-header">
      <div class="label-chip">KEUNGGULAN</div>
      <h2>Kenapa Pilih Azka Laundry?</h2>
    </div>
    <div class="features-grid">
      <div class="feature-card">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12.6 3.5h5.4a1.5 1.5 0 0 1 1.5 1.5v5.4a2 2 0 0 1-.6 1.4l-8.6 8.6a2 2 0 0 1-2.8 0l-5.4-5.4a2 2 0 0 1 0-2.8l8.6-8.6a2 2 0 0 1 1.4-.1Z"/><circle cx="16.5" cy="7.5" r="1.25"/></svg>
        </div>
        <h4>Estimasi Biaya Online</h4>
        <p>Hitung estimasi biaya laundry sebelum datang — pilih barang dan jumlah, total langsung tampil otomatis.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>
        </div>
        <h4>Pantau Status Online</h4>
        <p>Cek status cucian kapan saja menggunakan ID nota tanpa perlu datang langsung atau menghubungi pegawai.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/><path d="M8 12l2.5 2.5L16 9"/></svg>
        </div>
        <h4>Notifikasi via WhatsApp</h4>
        <p>ID nota dikirimkan langsung ke WhatsApp Anda setelah transaksi selesai dicatat oleh kasir.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6l7-3Z"/><path d="M9 12l2 2 4-4"/></svg>
        </div>
        <h4>Harga Transparan</h4>
        <p>Semua harga barang tersedia secara publik. Tidak ada biaya tersembunyi atau kejutan saat pengambilan.</p>
      </div>
    </div>
  </div>
</section>

<!-- CARA KERJA -->
<section style="background:var(--sky-pale)">
  <div class="section-inner">
    <div class="section-header">
      <div class="label-chip">CARA KERJA</div>
      <h2>3 Langkah Mudah</h2>
      <p>Dari antar cucian sampai siap diambil, semua bisa dipantau dari HP Anda</p>
    </div>
    <div class="steps-row">
      <div class="step-item">
        <div class="step-num">1</div>
        <h4>Antar & Catat di Kasir</h4>
        <p>Serahkan cucian ke kasir, dicatat sesuai berat/jenis barang, lalu Anda menerima ID nota via WhatsApp.</p>
      </div>
      <div class="step-item">
        <div class="step-num">2</div>
        <h4>Pantau Status Online</h4>
        <p>Gunakan ID nota di halaman Cek Cucian kapan saja untuk melihat progres — antre, diproses, atau siap diambil.</p>
      </div>
      <div class="step-item">
        <div class="step-num">3</div>
        <h4>Dapat Notifikasi & Ambil</h4>
        <p>Begitu cucian selesai, notifikasi "siap diambil" otomatis dikirim ke WhatsApp Anda.</p>
      </div>
    </div>
  </div>
</section>

<!-- KONTAK -->
<section id="kontak" style="background:var(--sky-pale)">
  <div class="section-inner" style="max-width:600px;text-align:center">
    <div class="label-chip">KONTAK</div>
    <h2 style="font-size:28px;margin-bottom:16px">Hubungi Kami</h2>
    <p style="color:#3A4450;margin-bottom:28px">Butuh informasi lebih lanjut? Hubungi kami via WhatsApp</p>
    <div style="background:var(--cloud);border-radius:18px;padding:30px;border:1px solid var(--border);box-shadow:0 6px 20px rgba(78,144,217,0.1)">
      <p style="font-size:15px;color:#101418;font-weight:600;margin-bottom:8px">Jl. Cisitu Lama 1 No. 9A, Bandung</p>
      <p style="font-size:15px;color:#101418;font-weight:600;margin-bottom:20px">0895-1215-7474</p>
      <a href="https://wa.me/6289512157474" target="_blank" class="btn btn-primary" style="text-decoration:none">
        Chat via WhatsApp
      </a>
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