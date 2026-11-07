<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
if (tk_user()) { tk_redirect('/tickets/'); }
if (isset($_GET['cancel'])) {
    unset($_SESSION['pending_2fa']);
    tk_redirect('/auth/login.php');
}

$error = null;

// A password has been accepted but the account has TOTP enabled: hold a short-lived pending state.
if (!empty($_SESSION['pending_2fa']) && is_array($_SESSION['pending_2fa'])) {
    $pending = $_SESSION['pending_2fa'];
    if (time() - (int)($pending['at'] ?? 0) > 300 || empty($pending['user_id'])) {
        unset($_SESSION['pending_2fa']);
        $pending = null;
    }
} else {
    $pending = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[TK_CSRF_TOKEN_NAME] ?? null;
    if (!tk_verify_csrf(is_string($token) ? $token : null)) {
        tk_security_log('login_csrf_rejected');
        $error = 'Invalid security token. Please try again.';
    } elseif ($pending !== null && isset($_POST['totp_code'])) {
        $fails = (int)($pending['fails'] ?? 0);
        $row = tk_repo_find('users', 'id', (int)$pending['user_id']);
        $code = preg_replace('/\D/', '', (string)($_POST['totp_code'] ?? ''));
        if ($fails >= 5) {
            unset($_SESSION['pending_2fa']);
            $pending = null;
            tk_security_log('login_rate_limited');
            $error = 'Too many incorrect codes. Please log in again.';
        } elseif ($row && !empty($row['totp_secret']) && tk_totp_verify((string)$row['totp_secret'], (string)$code)) {
            unset($_SESSION['pending_2fa']);
            tk_security_log('login_succeeded');
            tk_login((int)$row['id']);
            $_SESSION['flash_success'] = 'Welcome back.';
            tk_redirect('/tickets/');
        } else {
            $_SESSION['pending_2fa']['fails'] = $fails + 1;
            tk_security_log('login_failed');
            $error = 'That code was not accepted. Enter the current code from your authenticator app.';
        }
    } else {
        unset($_SESSION['pending_2fa']);
        $pending = null;
        tk_security_prune_login_attempts();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pass  = tk_substr((string)($_POST['password'] ?? ''), 0, 200);
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        tk_repo_insert('login_attempts', ['email'=>$email,'ip_address'=>$ip,'attempted_at'=>date('c')]);
        $windowStart = time() - TK_LOGIN_WINDOW_SECONDS;
        $attempts = tk_repo_where('login_attempts', function ($a) use ($email, $windowStart) {
            return strtolower($a['email'] ?? '') === $email && strtotime($a['attempted_at'] ?? '') >= $windowStart;
        });
        $ipAttempts = tk_repo_where('login_attempts', fn($a) => ($a['ip_address'] ?? '') === $ip && strtotime($a['attempted_at'] ?? '') >= $windowStart);
        if (count($attempts) > TK_MAX_LOGIN_ATTEMPTS || count($ipAttempts) > TK_MAX_LOGIN_ATTEMPTS * 4) {
            tk_security_log('login_rate_limited');
            $error = 'Too many login attempts. Please wait a few minutes and try again.';
        } else {
            $row = tk_repo_find('users', 'email', $email);
            if ($row && password_verify($pass, $row['password_hash'])) {
                if (!empty($row['totp_secret'])) {
                    session_regenerate_id(true);
                    $_SESSION['pending_2fa'] = ['user_id'=>(int)$row['id'], 'email'=>$row['email'], 'at'=>time(), 'fails'=>0];
                    $pending = $_SESSION['pending_2fa'];
                } else {
                    tk_security_log('login_succeeded');
                    tk_login((int)$row['id']);
                    $_SESSION['flash_success'] = 'Welcome back.';
                    tk_redirect('/tickets/');
                }
            } else {
                tk_security_log('login_failed');
                $error = 'Invalid email or password.';
            }
        }
    }
}

$pageTitle = ($pending !== null ? 'Two-factor code' : 'Log in') . ' | TicketsKenya';
$noindex = true;
require dirname(__DIR__, 2) . '/templates/header.php';
?>
<div class="section-inner">
  <div class="form-card">
    <?php if ($pending !== null): ?>
      <h1>Two-factor code</h1>
      <p class="form-note" style="margin-bottom:12px">Password accepted for <strong><?= tk_e((string)($pending['email'] ?? '')) ?></strong>. Enter the current 6-digit code from your authenticator app.</p>
      <?php if ($error): ?><div class="flash flash-error" role="alert"><?= tk_e($error) ?></div><?php endif; ?>
      <form method="post" action="/auth/login.php" novalidate>
        <?= tk_csrf_field() ?>
        <div class="form-group">
          <label for="totp_code">Authenticator code</label>
          <input type="text" id="totp_code" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" autofocus />
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-lime">Verify and log in</button>
        </div>
      </form>
      <p class="form-note"><a href="/auth/login.php?cancel=1">Start over</a></p>
    <?php else: ?>
      <h1>Log in</h1>
      <p class="form-note" style="margin-bottom:12px">Welcome back. Log in to see your orders.</p>
      <?php if ($error): ?><div class="flash flash-error" role="alert"><?= tk_e($error) ?></div><?php endif; ?>
      <form method="post" action="/auth/login.php" novalidate>
        <?= tk_csrf_field() ?>
        <div class="form-group">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" required value="<?= tk_e($_POST['email'] ?? '') ?>" autocomplete="email" />
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required autocomplete="current-password" />
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-lime">Log in</button>
        </div>
      </form>
      <p class="form-note">
        <a href="/auth/forgot.php">Forgot your password?</a><br>
        No account? <a href="/auth/register.php">Create one</a>
      </p>
    <?php endif; ?>
  </div>
</div>
<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
