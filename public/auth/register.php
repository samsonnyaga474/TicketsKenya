<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (tk_user()) {
    tk_redirect('/tickets/');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[TK_CSRF_TOKEN_NAME] ?? null;
    if (!tk_verify_csrf(is_string($token) ? $token : null)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $name  = trim((string)($_POST['full_name'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password_confirm'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (tk_strlen($name) < 2 || tk_strlen($name) > 120) {
            $error = 'Please enter your full name (2–120 characters).';
        } elseif (strlen($pass) < 10) {
            $error = 'Password must be at least 10 characters.';
        } elseif (strlen($pass) > 200) {
            $error = 'Password must be 200 characters or fewer.';
        } elseif ($pass !== $pass2) {
            $error = 'Passwords do not match.';
        } elseif (tk_repo_find('users', 'email', $email)) {
            $error = 'An account with that email already exists.';
        } else {
            $hash = password_hash($pass, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
            $id = tk_repo_insert('users', [
                'email' => $email,
                'password_hash' => $hash,
                'full_name' => $name,
                'role' => 'customer',
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ]);
            tk_login($id);
            $_SESSION['flash_success'] = 'Account created. Welcome!';
            tk_redirect('/tickets/');
        }
    }
}

$pageTitle = 'Create account | TicketsKenya';
$noindex = true;
require dirname(__DIR__, 2) . '/templates/header.php';
?>

<div class="section-inner">
  <div class="form-card">
    <h1>Create account</h1>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= tk_e($error) ?></div><?php endif; ?>
    <form method="post" action="/auth/register.php" novalidate>
      <?= tk_csrf_field() ?>
      <div class="form-group">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" required maxlength="120" value="<?= tk_e($_POST['full_name'] ?? '') ?>" autocomplete="name" />
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required value="<?= tk_e($_POST['email'] ?? '') ?>" autocomplete="email" />
      </div>
      <div class="form-group">
        <label for="password">Password (min 10 characters)</label>
        <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password" />
      </div>
      <div class="form-group">
        <label for="password_confirm">Confirm password</label>
        <input type="password" id="password_confirm" name="password_confirm" required minlength="10" autocomplete="new-password" />
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-lime">Create account</button>
      </div>
    </form>
    <p class="form-note">Already have an account? <a href="/auth/login.php">Log in</a></p>
  </div>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
