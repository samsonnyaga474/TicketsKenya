<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
if (tk_user()) { tk_redirect('/tickets/'); }
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[TK_CSRF_TOKEN_NAME] ?? null;
    if (!tk_verify_csrf(is_string($token) ? $token : null)) {
        tk_security_log('login_csrf_rejected');
        $error = 'Invalid security token. Please try again.';
    } else {
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
                tk_security_log('login_succeeded');
                tk_login((int)$row['id']);
                $_SESSION['flash_success'] = 'Welcome back.';
                tk_redirect('/tickets/');
            } else {
                tk_security_log('login_failed');
                $error = 'Invalid email or password.';
            }
        }
    }
}
$pageTitle = 'Log in | TicketsKenya';
$noindex = true;
require dirname(__DIR__, 2) . '/templates/header.php';
?>
<div class="section-inner">
  <div class="form-card">
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
  </div>
</div>
<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
