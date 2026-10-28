<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Frequently asked questions';
$pageDescription = 'Quick answers to the questions we hear most often.';
$customFaqs = trim(Settings::get('faq_items'));
$faqs = [];
if ($customFaqs !== '') {
    foreach (preg_split('/\\R/u', $customFaqs) ?: [] as $line) {
        $pair = explode('|', trim($line), 2);
        if (count($pair) === 2 && trim($pair[0]) !== '' && trim($pair[1]) !== '') {
            $faqs[] = [trim($pair[0]), trim($pair[1])];
            if (count($faqs) >= 20) break;
        }
    }
}
if ($faqs === []) {
    $methods = array_map('payment_method_label', array_keys(PaymentManager::available(1)));
    $returnDays = max(1, min(90, Settings::int('return_window_days', 7)));
    $faqs = [
        ['Where do you deliver?', 'Delivery areas, charges and estimates are confirmed at checkout for your address.'],
        ['Which payment methods do you accept?', $methods !== [] ? implode(', ', $methods) . ' are currently available at checkout.' : 'Available payment methods are shown at checkout.'],
        ['How do returns work?', 'Submit a return request within ' . $returnDays . ' days of delivery. See the Returns & refunds page for terms and exclusions.'],
        ['What if an item arrives damaged or incorrect?', 'Contact support within 48 hours with your order details and photos so the team can review the issue.'],
        ['Do products include a warranty?', 'Warranty details are shown on individual product listings where applicable. Keep your invoice as proof of purchase.'],
        ['Do you offer bulk or trade pricing?', 'Contact support with your requirements to ask about availability.'],
        ['How do I cancel an order?', 'Contact support as soon as possible. Cancellation may not be possible once an order has shipped.'],
    ];
}
$heroTitle = 'Frequently asked questions';
$heroSubtitle = 'Short answers — and a real person if you need more.';
$heroIcon = 'bi-question-circle';
require INCLUDES_PATH . '/layout/header.php';
require INCLUDES_PATH . '/partials/page-hero.php';
?>
<section class="section">
  <div class="container-page">
    <div class="row g-4 justify-content-center">
      <div class="col-lg-8">
        <div class="card-soft p-3 p-md-4">
          <dl class="mb-0">
            <?php
            foreach ($faqs as [$q, $a]): ?>
              <dt class="fw-semibold small mt-3"><?= e($q) ?></dt>
              <dd class="text-ink-600 small mb-0"><?= e($a) ?></dd>
            <?php endforeach; ?>
          </dl>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>
