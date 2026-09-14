<?php
require_once __DIR__ . '/includes/config.php';

// Kalau sudah login redirect sesuai role
if (isLoggedIn()) {
    switch ($_SESSION['user']['role']) {
        case 'owner':            header('Location: dashboard_owner.php'); break;
        case 'kasir':            header('Location: dashboard_kasir.php'); break;
        case 'bagian_pencucian': header('Location: dashboard_pencucian.php'); break;
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (loginIsLocked()) {
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . loginSecondsRemaining() . ' detik.';
    } elseif (!verify_csrf()) {
        $error = 'Sesi kedaluwarsa, silakan coba lagi.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email && $password) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                resetLoginAttempts();
                session_regenerate_id(true); // cegah session fixation

                $_SESSION['user_id'] = $user['id_user'];
                $_SESSION['user']    = $user;

                switch ($user['role']) {
                    case 'owner':            header('Location: dashboard_owner.php'); break;
                    case 'kasir':            header('Location: dashboard_kasir.php'); break;
                    case 'bagian_pencucian': header('Location: dashboard_pencucian.php'); break;
                    default:                 header('Location: login.php');
                }
                exit;

            } else {
                registerFailedLogin();
                // Pesan disamakan (tidak membedakan "email tidak ada" vs "password salah")
                // supaya tidak membocorkan email mana saja yang terdaftar di sistem.
                $error = 'Email atau password salah.';
            }
        } else {
            $error = 'Harap isi email dan password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — Azka Laundry</title>
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
body {
    font-family:'Plus Jakarta Sans', sans-serif;
    min-height:100vh;
    display:flex; align-items:center; justify-content:center;
    background:linear-gradient(180deg, #EAF2FB 0%, var(--sky-pale) 60%, var(--sky-pale) 100%);
    color:#101418;
    padding:24px;
    position:relative;
    overflow-x:hidden;
}
body::before {
    content:'';
    position:fixed; inset:0;
    background:radial-gradient(ellipse at 50% -10%, rgba(78,144,217,0.18) 0%, transparent 55%);
    pointer-events:none; z-index:0;
}
::selection { background:var(--sky); color:#FFFFFF; }

.card {
    position:relative; z-index:1;
    width:100%; max-width:440px; background:var(--cloud);
    border:1px solid var(--border); border-radius:22px;
    padding:44px 40px;
    box-shadow:0 10px 32px rgba(78,144,217,0.14);
}

@media (max-width:480px) {
    body { padding:16px; }
    .card { padding:32px 24px; border-radius:18px; }
    .logo-icon, .logo-icon svg { width:64px; height:64px; }
    .logo-wrap h1 { font-size:20px; }
    .logo-wrap p { font-size:12px; }
    .form-group input { padding:11px 14px; font-size:13.5px; }
    .btn-submit { padding:12px; font-size:14px; }
}
/* Layar HP yang tingginya pas-pasan (bukan cuma lebarnya) — dikecilin lagi
   biar seluruh card muat di 1 layar tanpa perlu discroll */
@media (max-height:750px) {
    body { align-items:flex-start; padding-top:18px; padding-bottom:18px; }
    .card { padding:22px 22px; border-radius:16px; }
    .logo-icon, .logo-icon svg { width:44px; height:44px; }
    .logo-wrap { margin-bottom:14px; }
    .logo-wrap h1 { font-size:17px; }
    .logo-wrap p { font-size:11px; margin-top:2px; }
    .form-group { margin-bottom:10px; }
    .form-group label { margin-bottom:4px; font-size:11px; }
    .form-group input { padding:9px 12px; font-size:13px; }
    .btn-submit { padding:10px; margin-top:2px; }
    .back-link { margin-top:12px; font-size:12px; }
}
.logo-wrap { text-align:center; margin-bottom:30px; }
.logo-icon {
    width:80px; height:80px;
    display:flex; align-items:center;
    justify-content:center; margin:0 auto 16px;
    filter: drop-shadow(0 4px 14px rgba(78,144,217,0.3));
}
.logo-icon svg { width:80px; height:80px; display:block; }
.logo-wrap h1 { font-family:'Fraunces', serif; font-weight:600; font-size:24px; color:#101418; letter-spacing:-0.3px; }
.logo-wrap p  { font-size:13px; color:#3A4450; margin-top:4px; }

.error-box {
    background:#EAF2FB; border:1px solid #D6E7F7;
    color:#101418; padding:11px 14px; border-radius:12px;
    font-size:13px; margin-bottom:18px; display:flex; align-items:center; gap:8px;
}

.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:12px; font-weight:700; color:#101418; margin-bottom:7px; }
.form-group input {
    width:100%; padding:12px 16px;
    background:var(--sky-pale); border:1.5px solid rgba(78,144,217,0.4);
    border-radius:12px; color:#101418; font-size:14px; font-family:inherit;
    outline:none; transition:border-color 0.2s, box-shadow 0.2s, background 0.2s;
}
.form-group input:focus { border-color:var(--sky); background:var(--cloud); box-shadow:0 4px 14px rgba(78,144,217,0.15); }
.form-group input::placeholder { color:#4B5563; }

.btn-submit {
    width:100%; padding:13px;
    background:var(--sky); color:#FFFFFF;
    border:none; border-radius:30px; font-size:15px;
    font-weight:700; font-family:inherit; cursor:pointer; margin-top:6px;
    transition:background 0.2s, transform 0.2s;
    box-shadow:0 4px 14px rgba(78,144,217,0.35);
}
.btn-submit:hover { background:var(--sky-deep); transform:translateY(-1px); }

.back-link {
    display:block; text-align:center; margin-top:20px;
    font-size:13px; color:#3A4450; text-decoration:none; transition:color 0.2s;
}
.back-link:hover { color:var(--sky-deep); }
</style>
</head>
<body>
<div class="card">
  <div class="logo-wrap">
    <div class="logo-icon">
      <!-- Logo Azka Laundry — embedded SVG -->
      <svg width="120" height="120" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="badgeGrad" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#4E90D9"/>
            <stop offset="100%" stop-color="#2E74C0"/>
          </linearGradient>
        </defs>
        <circle cx="60" cy="60" r="58" fill="url(#badgeGrad)"/>
        <g transform="translate(60,62)">
          <circle cx="0" cy="2" r="26" fill="none" stroke="#FFFFFF" stroke-width="5"/>
          <circle cx="0" cy="5" r="14" fill="none" stroke="#FFFFFF" stroke-width="4.5"/>
          <rect x="-11" y="-31" width="22" height="8" rx="4" fill="#FFFFFF"/>
        </g>
      </svg>
    </div>
    <h1>Azka Laundry</h1>
    <p>Sistem Informasi Manajemen Laundry</p>
  </div>

  <?php if ($error): ?>
  <div class="error-box"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" novalidate>
    <?= csrf_field() ?>
    <div class="form-group">
      <label>Email</label>
      <input type="email" name="email" id="inputEmail"
             placeholder="Masukkan email"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
             autocomplete="email" required>
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" name="password" id="inputPassword"
             placeholder="Masukkan password"
             autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn-submit" <?= loginIsLocked() ? 'disabled style="opacity:.6;cursor:not-allowed"' : '' ?>>Masuk ke Sistem</button>
  </form>

  <a href="index.php" class="back-link">← Kembali ke halaman utama</a>
</div>

</body>
</html>