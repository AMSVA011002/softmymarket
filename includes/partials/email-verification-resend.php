<form method="post" action="/actions/email-verification.php" class="text-start mt-3">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="resend">
  <label class="form-label small" for="verification-email">Email address</label>
  <input class="form-control" id="verification-email" type="email" name="email" required maxlength="160" autocomplete="email" value="<?= e($email) ?>">
  <button class="btn btn-soft w-100 mt-2" type="submit">Send a new confirmation link</button>
</form>
