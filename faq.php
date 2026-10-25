<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Frequently asked questions';
$pageDescription = 'Quick answers to the questions we hear most often.';
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
            $faqs = [
                ['Do you deliver outside Colombo?', 'Yes. We deliver to all 25 districts. Rates and timelines are shown at checkout based on your district.'],
                ['Can I pay after receiving the item?', 'Cash on delivery is available island-wide. For larger orders you can also use Mintpay or KOKO to spread payments.'],
                ['Is the warranty local?', 'Yes, products with warranty show the local warranty period on the product page. Keep your invoice.'],
                ['What if the item arrives damaged?', 'Refuse the parcel if the packaging is clearly damaged and contact us with photos within 48 hours. We replace or refund.'],
                ['Do you offer bulk or trade pricing?', 'Yes — contact us with your requirements and we will send a quotation.'],
                ['How do I cancel an order?', 'If it has not shipped, open the order in your account and press Cancel. Otherwise message support and we will try to intercept it.'],
            ];
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
