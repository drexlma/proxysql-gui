<?php
// Anmeldung

if (!auth_enabled() || logged_in()) {
    redirect('?page=dashboard');
}

$error = '';
if (is_post()) {
    csrf_check();
    if (try_login(post('user'), post_raw('password'))) {
        redirect('?page=dashboard');
    }
    $error = 'Benutzername oder Passwort falsch.';
}

layout_start('Anmelden', 'login', ['nav' => false]);
?>
<div class="login-box">
  <?php panel_start('Anmelden') ?>
    <?php if ($error): ?><div class="msg msg-error"><?= icon('error') ?><span><?= h($error) ?></span></div><?php endif ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <div class="field"><label for="user">Benutzer</label><input id="user" name="user" type="text" autocomplete="username" required autofocus value="<?= h(post('user')) ?>"></div>
      <div class="field"><label for="password">Passwort</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
      <button type="submit" class="btn btn-primary">Anmelden</button>
    </form>
  <?php panel_end() ?>
</div>
<?php
layout_end();
