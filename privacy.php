<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Privacy policy';
$pageDescription = 'What personal data SoftMyMarket collects, why, and how we protect it.';
$heroTitle = 'Privacy policy';
$heroSubtitle = 'What we collect, why we collect it, and how to exercise your rights.';
$heroIcon = 'bi-shield-lock';
require INCLUDES_PATH . '/layout/header.php';
require INCLUDES_PATH . '/partials/page-hero.php';
?>
<section class="section">
  <div class="container-page">
    <div class="row g-4 justify-content-center">
      <div class="col-lg-9">
        <div class="card-soft p-3 p-md-4 policy-copy small text-ink-700">
          <h2 class="h6 fw-bold">What we collect</h2>
          <p>Your name, email, mobile number, delivery addresses, order history, payment references and basic technical data (IP address, browser) used for security and fraud prevention. We never store full card numbers or CVV codes.</p>

          <h2 class="h6 fw-bold mt-3">Why we collect it</h2>
          <ul>
            <li>To process, pack and deliver your orders</li>
            <li>To send order and delivery updates</li>
            <li>To verify payments and prevent fraud</li>
            <li>To answer support requests and handle returns</li>
            <li>To improve the store, only in aggregated form</li>
            <li>To send marketing emails — only if you opted in, and you can unsubscribe at any time</li>
          </ul>

          <h2 class="h6 fw-bold mt-3">Who we share it with</h2>
          <p>Couriers (name, address, phone for delivery), payment providers (amount and reference for settlement), and our email/SMS delivery provider. We do not sell personal data.</p>

          <h2 class="h6 fw-bold mt-3">Cookies</h2>
          <p>We use a session cookie to keep you signed in and remember your cart, plus a long-lived cookie when you tick "remember me". These are strictly necessary for the store to work; there is no third-party advertising tracker on this site.</p>

          <h2 class="h6 fw-bold mt-3">How we protect it</h2>
          <ul>
            <li>Passwords are hashed with bcrypt and never logged</li>
            <li>All database access uses prepared statements</li>
            <li>Sensitive forms are protected with CSRF tokens and rate limiting</li>
            <li>Uploaded files are stored outside the public web root and served through an access-checked endpoint</li>
            <li>Payment pages are served over HTTPS by the gateway itself</li>
          </ul>

          <h2 class="h6 fw-bold mt-3">How long we keep it</h2>
          <p>Order records are kept for seven years to satisfy accounting requirements. Marketing preferences are kept until you unsubscribe. You can ask us to delete your account details that are not needed for tax or legal reasons.</p>

          <h2 class="h6 fw-bold mt-3">Your rights</h2>
          <p>You can request a copy of your data, correct it, or ask us to delete it. Email <a href="mailto:<?= e(Settings::get('support_email')) ?>"><?= e(Settings::get('support_email')) ?></a> and we will respond within 30 days.</p>

          <p class="text-ink-500 mt-3 mb-0">Controller: <?= e(Settings::get('store_name')) ?>, <?= e(Settings::get('address')) ?>.</p>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>
