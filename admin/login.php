<?php
require_once '../config.php';

function loginRedirectTargetByRole(string $role): string
{
    return rtrim(BASE_URL, '/') . '/admin/' . ($role === 'admin' ? 'index.php' : 'operator_dashboard.php');
}

if (isset($_SESSION['user_id'])) {
    header('Location: ' . loginRedirectTargetByRole((string) ($_SESSION['role'] ?? 'operator')));
    exit;
}

$error = '';
$success = '';
$siteName = 'E-Recepsionis';
$settingsRes = @$koneksi->query("SELECT setting_value FROM settings WHERE setting_key = 'site_name' LIMIT 1");
if ($settingsRes && ($row = $settingsRes->fetch_assoc()) && trim((string) ($row['setting_value'] ?? '')) !== '') {
    $siteName = trim((string) $row['setting_value']);
}

if (isset($_GET['logged_out'])) {
    $success = 'Anda telah berhasil logout.';
}
if (isset($_GET['timeout'])) {
    $error = 'Sesi Anda telah berakhir. Silakan login kembali.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = esc($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $result = $koneksi->query("SELECT * FROM users WHERE username='$username' AND status_aktif=1");

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nama_lengkap'] = $user['nama_lengkap'] ?? $user['username'] ?? 'Administrator';
            $_SESSION['role'] = $user['role'];
            $_SESSION['last_activity'] = time();
            $koneksi->query('UPDATE users SET last_login=NOW() WHERE id=' . (int) $user['id']);
            header('Location: ' . loginRedirectTargetByRole((string) $user['role']));
            exit;
        }
        $error = 'Password salah.';
    } else {
        $error = 'Username tidak ditemukan atau akun tidak aktif.';
    }
}

$postedUsername = isset($_POST['username']) ? (string) $_POST['username'] : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= htmlspecialchars($siteName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --ink: #15202b;
            --muted: #5f6f82;
            --line: #dde3ec;
            --surface: #ffffff;
            --accent: #0f6e56;
            --accent-deep: #0b5542;
            --accent-soft: #e6f4ef;
            --danger-bg: #fef2f2;
            --danger-ink: #991b1b;
            --ok-bg: #ecfdf5;
            --ok-ink: #065f46;
            --radius: 16px;
            --font: 'Plus Jakarta Sans', 'Segoe UI', system-ui, sans-serif;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: var(--font);
            color: var(--ink);
            background:
                radial-gradient(1200px 600px at 10% -10%, rgba(15, 110, 86, 0.18), transparent 55%),
                radial-gradient(900px 500px at 100% 0%, rgba(21, 94, 117, 0.16), transparent 50%),
                linear-gradient(165deg, #eef1f6 0%, #e7ece8 45%, #e3ebe8 100%);
            display: grid;
            place-items: center;
            padding: 1.5rem;
            -webkit-font-smoothing: antialiased;
        }

        .login-shell {
            width: 100%;
            max-width: 420px;
            animation: rise .45s ease-out;
        }

        @keyframes rise {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-card {
            background: var(--surface);
            border: 1px solid rgba(200, 210, 223, 0.9);
            border-radius: var(--radius);
            padding: 2rem 1.75rem 1.75rem;
            box-shadow:
                0 1px 2px rgba(21, 32, 43, 0.04),
                0 18px 40px rgba(21, 32, 43, 0.08);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: .85rem;
            margin-bottom: 1.75rem;
        }

        .brand-mark {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--accent);
            color: #fff;
            display: grid;
            place-items: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }

        .brand-text h1 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            line-height: 1.25;
        }

        .brand-text p {
            margin: .15rem 0 0;
            color: var(--muted);
            font-size: .85rem;
            font-weight: 500;
        }

        .alert {
            display: flex;
            align-items: flex-start;
            gap: .55rem;
            border-radius: 10px;
            padding: .75rem .9rem;
            margin: 0 0 1.15rem;
            font-size: .875rem;
            font-weight: 500;
            line-height: 1.4;
        }

        .alert i { margin-top: .1rem; flex-shrink: 0; }
        .alert-danger { background: var(--danger-bg); color: var(--danger-ink); }
        .alert-success { background: var(--ok-bg); color: var(--ok-ink); }

        .field { margin-bottom: 1rem; }

        .field label {
            display: block;
            font-size: .78rem;
            font-weight: 650;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: .4rem;
        }

        .field-control {
            position: relative;
        }

        .field input {
            width: 100%;
            height: 48px;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 0 2.75rem 0 0.95rem;
            font: inherit;
            font-size: .95rem;
            color: var(--ink);
            background: #fff;
            transition: border-color .2s ease, box-shadow .2s ease;
        }

        .field input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(15, 110, 86, 0.14);
        }

        .field input::placeholder { color: #94a3b8; }

        .toggle-pass {
            position: absolute;
            right: .55rem;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: var(--muted);
            width: 36px;
            height: 36px;
            border-radius: 8px;
            cursor: pointer;
            display: grid;
            place-items: center;
        }

        .toggle-pass:hover { color: var(--accent); background: var(--accent-soft); }

        .btn-login {
            width: 100%;
            height: 48px;
            margin-top: .35rem;
            border: 0;
            border-radius: 10px;
            background: var(--accent);
            color: #fff;
            font: inherit;
            font-size: .95rem;
            font-weight: 650;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            transition: background .2s ease, transform .15s ease;
        }

        .btn-login:hover:not(:disabled) {
            background: var(--accent-deep);
        }

        .btn-login:active:not(:disabled) {
            transform: translateY(1px);
        }

        .btn-login:disabled {
            opacity: .75;
            cursor: wait;
        }

        .login-foot {
            margin-top: 1.35rem;
            padding-top: 1.1rem;
            border-top: 1px solid var(--line);
            text-align: center;
            color: var(--muted);
            font-size: .8rem;
        }

        @media (max-width: 480px) {
            body { padding: 1rem; align-items: flex-start; padding-top: 2rem; }
            .login-card { padding: 1.5rem 1.25rem; }
        }
    </style>
</head>
<body>
    <div class="login-shell">
        <div class="login-card">
            <div class="brand">
                <div class="brand-mark" aria-hidden="true"><i class="bi bi-building"></i></div>
                <div class="brand-text">
                    <h1><?= htmlspecialchars($siteName) ?></h1>
                    <p>Masuk ke panel administrasi</p>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success" role="status">
                    <i class="bi bi-check-circle"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm" autocomplete="on">
                <div class="field">
                    <label for="username">Username</label>
                    <div class="field-control">
                        <input
                            type="text"
                            id="username"
                            name="username"
                            value="<?= htmlspecialchars($postedUsername) ?>"
                            placeholder="Nama pengguna"
                            required
                            autofocus
                            autocomplete="username"
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="field-control">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Kata sandi"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="toggle-pass" id="togglePassword" aria-label="Tampilkan password">
                            <i class="bi bi-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    Masuk
                </button>
            </form>

            <div class="login-foot">
                Akses terbatas untuk admin &amp; operator
            </div>
        </div>
    </div>

    <script>
        (function () {
            var pass = document.getElementById('password');
            var toggle = document.getElementById('togglePassword');
            var icon = document.getElementById('togglePasswordIcon');
            var form = document.getElementById('loginForm');
            var btn = document.getElementById('loginBtn');

            toggle.addEventListener('click', function () {
                var show = pass.type === 'password';
                pass.type = show ? 'text' : 'password';
                icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
                toggle.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
            });

            form.addEventListener('submit', function () {
                btn.disabled = true;
                btn.innerHTML = 'Memproses…';
            });
        })();
    </script>
</body>
</html>
