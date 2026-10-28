<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$topic = (string) query('topic', 'general');
$pageTitle = 'Contact us';
$pageDescription = 'Talk to the ' . Settings::get('store_name') . ' team about orders, deliveries, returns or trade enquiries.';
$heroTitle = 'Contact us';
$heroSubtitle = 'We reply within one business day — usually much faster.';
$heroIcon = 'bi-chat-dots';
require INCLUDES_PATH . '/layout/header.php';
require INCLUDES_PATH . '/partials/page-hero.php';
?>
<section class="section">
  <div class="container-page">
    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card-soft p-3 p-md-4">
          <?php if (query('sent') !== null): ?>
            <div class="alert alert-success small"><i class="bi bi-check-circle me-1"></i>Your message is with our team. We will reply by email shortly.</div>
          <?php endif; ?>
          <h2 class="h6 fw-bold mb-3">Send us a message</h2>
          <form method="post" action="/actions/contact.php" class="row g-2" data-disable-on-submit>
            <?= Csrf::field() ?>
            <input type="hidden" name="topic" value="<?= e($topic) ?>">
            <div class="col-md-6">
              <label class="form-label" for="name">Your name</label>
              <input class="form-control" id="name" name="name" required maxlength="120" value="<?= e(old('name', Auth::user()['name'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="email">Email</label>
              <input class="form-control" id="email" type="email" name="email" required maxlength="160" value="<?= e(old('email', Auth::user()['email'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="phone">Mobile <span class="text-ink-400">(optional)</span></label>
              <input class="form-control" id="phone" name="phone" maxlength="20" value="<?= e(old('phone', Auth::user()['phone'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="subject">Subject</label>
              <input class="form-control" id="subject" name="subject" maxlength="160" value="<?= e(old('subject', '')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="message">How can we help?</label>
              <textarea class="form-control" id="message" name="message" rows="5" required maxlength="4000"><?= e(old('message', '')) ?></textarea>
            </div>
            <div class="col-12 d-flex align-items-center justify-content-between gap-2">
              <p class="small text-ink-400 mb-0">We never share your details.</p>
              <button class="btn btn-brand" type="submit"><i class="bi bi-send me-1"></i> Send message</button>
            </div>
          </form>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card-soft p-3 p-md-4 mb-3">
          <h2 class="h6 fw-bold mb-2">Reach us directly</h2>
          <ul class="list-unstyled small mb-0">
            <li class="d-flex gap-2 mb-2"><i class="bi bi-telephone text-brand"></i><div><strong>Phone</strong><br><a class="text-decoration-none" href="tel:<?= e(preg_replace('/\s+/', '', Settings::get('support_phone'))) ?>"><?= e(Settings::get('support_phone')) ?></a></div></li>
            <li class="d-flex gap-2 mb-2"><i class="bi bi-whatsapp text-brand"></i><div><strong>WhatsApp</strong><br><?= e(Settings::get('support_whatsapp')) ?></div></li>
            <li class="d-flex gap-2 mb-2"><i class="bi bi-envelope text-brand"></i><div><strong>Email</strong><br><a class="text-decoration-none" href="mailto:<?= e(Settings::get('support_email')) ?>"><?= e(Settings::get('support_email')) ?></a></div></li>
            <li class="d-flex gap-2"><i class="bi bi-geo-alt text-brand"></i><div><strong>Showroom</strong><br><?= e(Settings::get('address')) ?></div></li>
          </ul>
        </div>
        <div class="card-soft p-3 p-md-4 mb-3">
          <h2 class="h6 fw-bold mb-2">Opening hours</h2>
          <div class="summary-row"><span>Monday – Friday</span><span>9:00 – 18:00</span></div>
          <div class="summary-row"><span>Saturday</span><span>9:00 – 14:00</span></div>
          <div class="summary-row"><span>Sunday &amp; Poya</span><span>Closed</span></div>
        </div>
        <div class="card-soft p-3 p-md-4">
          <h2 class="h6 fw-bold mb-2">Faster answers</h2>
          <div class="d-grid gap-2">
            <a class="btn btn-soft btn-sm text-start" href="/track.php"><i class="bi bi-truck me-2"></i>Track an order</a>
            <a class="btn btn-soft btn-sm text-start" href="/returns.php"><i class="bi bi-arrow-counterclockwise me-2"></i>Start a return</a>
            <a class="btn btn-soft btn-sm text-start" href="/faq.php"><i class="bi bi-question-circle me-2"></i>Read the FAQs</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>
