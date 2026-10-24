<?php
/**
 * Storefront home page.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$featuredCategories = Catalog::featuredCategories(12);
$deals = Catalog::dealProducts(8);
$featured = Catalog::featuredProducts(8);
$bestSellers = Catalog::bestSellers(4);
$newArrivals = Catalog::newArrivals(8);
$coupons = Coupons::active(3);
$heroSlides = View::heroSlides();

// Recently viewed rail (ids are stored in a first-party cookie by product.php).
$recentIds = array_values(array_filter(array_map('intval', explode(',', (string) ($_COOKIE['smm_recent'] ?? '')))));
$recentlyViewed = $recentIds !== [] ? Catalog::productsByIds(array_slice($recentIds, 0, 8)) : [];

$dealEnds = strtotime('tomorrow 23:59:59');
$pageClass = 'page-home';
$pageDescription = Settings::get('default_meta_description');
require INCLUDES_PATH . '/layout/header.php';
?>

<section class="hero">
  <div id="heroCarousel" class="carousel slide hero-carousel" data-bs-ride="carousel" data-bs-interval="6500">
    <div class="carousel-indicators position-absolute" style="bottom:.9rem">
      <?php foreach ($heroSlides as $index => $slide): ?>
        <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="<?= $index ?>" class="<?= $index === 0 ? 'active' : '' ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>" aria-label="Slide <?= $index + 1 ?>"></button>
      <?php endforeach; ?>
    </div>
    <div class="carousel-inner">
      <?php foreach ($heroSlides as $index => $slide): ?>
        <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
          <div class="hero-slide hero-<?= e($slide['theme']) ?>">
            <div class="container-page">
              <div class="row align-items-center g-4">
                <div class="col-lg-7 <?= $slide['theme'] === 'light' ? 'text-ink-900' : 'text-white' ?>">
                  <span class="eyebrow"><?= e($slide['eyebrow']) ?></span>
                  <h1 class="display-5 fw-bold mt-3 mb-2">
                    <?= e($slide['title']) ?><br>
                    <span class="<?= $slide['theme'] === 'light' ? 'text-brand' : 'text-white' ?>"><?= e($slide['highlight']) ?></span>
                  </h1>
                  <p class="mb-3 <?= $slide['theme'] === 'light' ? 'text-ink-600' : 'text-white-50' ?>" style="max-width:52ch"><?= e($slide['description']) ?></p>
                  <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-lg <?= $slide['theme'] === 'light' ? 'btn-brand' : 'btn-light' ?>" href="<?= e($slide['cta_url']) ?>"><?= e($slide['cta_label']) ?> <i class="bi bi-arrow-right ms-1"></i></a>
                    <a class="btn btn-lg <?= $slide['theme'] === 'light' ? 'btn-outline-brand' : 'btn-outline-light' ?>" href="<?= e($slide['secondary_url']) ?>"><?= e($slide['secondary_label']) ?></a>
                  </div>
                  <dl class="hero-stats d-flex gap-4 mt-4 mb-0">
                    <div><dt>Products</dt><dd><?= e(compact_number(Catalog::productCount('active'))) ?>+</dd></div>
                    <div><dt>Delivery</dt><dd>1–4 days</dd></div>
                    <div><dt>Returns</dt><dd>7 days</dd></div>
                  </dl>
                </div>
                <div class="col-lg-5 d-none d-lg-grid">
                  <div class="hero-visual"><i class="bi <?= e($slide['icon']) ?>"></i></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span><span class="visually-hidden">Previous</span></button>
    <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next"><span class="carousel-control-next-icon"></span><span class="visually-hidden">Next</span></button>
  </div>
</section>

<section class="py-3 bg-white border-bottom">
  <div class="container-page">
    <div class="row g-2 g-lg-3">
      <?php
      $props = [
          ['bi-truck', 'Free delivery', 'On orders over ' . money(Settings::float('free_shipping_threshold', 15000))],
          ['bi-cash-coin', 'Cash on delivery', 'Pay when it arrives'],
          ['bi-shield-check', 'Genuine products', 'Warranty on every item'],
          ['bi-arrow-repeat', '7-day returns', 'No-hassle exchanges'],
      ];
      foreach ($props as [$icon, $title, $text]): ?>
        <div class="col-6 col-lg-3">
          <div class="value-prop">
            <span class="icon"><i class="bi <?= e($icon) ?>"></i></span>
            <div><h3><?= e($title) ?></h3><p><?= e($text) ?></p></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container-page">
    <div class="section-head">
      <div>
        <p class="eyebrow">Shop by department</p>
        <h2 class="fw-bold">Find what you need</h2>
      </div>
      <a class="small fw-semibold text-decoration-none" href="/shop.php">All products <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="row g-2 g-lg-3">
      <?php foreach ($featuredCategories as $category): ?>
        <div class="col-4 col-md-3 col-lg-2">
          <a class="category-tile" href="/category.php?slug=<?= e($category['slug']) ?>">
            <?= View::categoryIcon($category) ?>
            <span class="name"><?= e($category['name']) ?></span>
            <small><?= (int) ($category['product_count'] ?? 0) ?> items</small>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($deals !== []): ?>
  <section class="section pt-0">
    <div class="container-page">
      <div class="deal-banner mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
          <div>
            <span class="eyebrow" style="background:rgba(255,255,255,.18)"><i class="bi bi-lightning-charge-fill"></i> Flash deals</span>
            <h2 class="h3 fw-bold mt-2 mb-1">Price drops that end tonight</h2>
            <p class="mb-0 text-white-50 small">Hand-picked savings while stocks last.</p>
          </div>
          <div class="countdown" data-countdown="<?= e(date('Y-m-d H:i:s', $dealEnds)) ?>">
            <span><b data-cd-days>00</b><small>Days</small></span>
            <span><b data-cd-hours>00</b><small>Hrs</small></span>
            <span><b data-cd-mins>00</b><small>Min</small></span>
            <span><b data-cd-secs>00</b><small>Sec</small></span>
          </div>
        </div>
      </div>
      <div class="grid-products">
        <?php foreach ($deals as $product): ?>
          <?= View::card($product) ?>
        <?php endforeach; ?>
      </div>
      <div class="text-center mt-3">
        <a class="btn btn-outline-brand" href="/deals.php">See all deals <i class="bi bi-arrow-right ms-1"></i></a>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if ($coupons !== []): ?>
  <section class="section pt-0">
    <div class="container-page">
      <div class="coupon-strip">
        <div class="row g-2 align-items-center">
          <div class="col-lg-3">
            <h2 class="h6 fw-bold mb-1"><i class="bi bi-ticket-perforated-fill text-brand me-1"></i> Save more today</h2>
            <p class="small text-ink-500 mb-0">Apply at checkout, one coupon per order.</p>
          </div>
          <div class="col-lg-9">
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($coupons as $coupon): ?>
                <span class="coupon-chip">
                  <i class="bi bi-scissors text-brand"></i>
                  <code><?= e($coupon['code']) ?></code>
                  <span class="small text-ink-600"><?= e($coupon['description'] ?: ($coupon['type'] === 'percent' ? (int) $coupon['value'] . '% off' : 'Save more')) ?></span>
                </span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if ($featured !== []): ?>
  <section class="section pt-0">
    <div class="container-page">
      <div class="section-head">
        <div><p class="eyebrow">Featured</p><h2 class="fw-bold">Staff picks this week</h2></div>
        <a class="small fw-semibold text-decoration-none" href="/shop.php?featured=1">See all <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="grid-products">
        <?php foreach ($featured as $product): ?><?= View::card($product) ?><?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if ($recentlyViewed !== []): ?>
  <section class="section pt-0">
    <div class="container-page">
      <div class="section-head">
        <div><p class="eyebrow">Pick up where you left off</p><h2 class="fw-bold">Recently viewed</h2></div>
      </div>
      <div class="scroll-x" style="display:flex;gap:1rem;overflow-x:auto;padding-bottom:.5rem">
        <?php foreach ($recentlyViewed as $product): ?>
          <div style="flex:0 0 220px;max-width:60vw"><?= View::card($product) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<section class="section pt-0">
  <div class="container-page">
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="row g-3 h-100">
          <div class="col-sm-6">
            <div class="hero-slide hero-light rounded-4 h-100 p-3 d-flex flex-column justify-content-between" style="min-height:180px">
              <div>
                <span class="eyebrow">Pay in 3</span>
                <h2 class="h5 fw-bold mt-2 mb-1">KOKO, Payzy &amp; Mintpay</h2>
                <p class="small text-ink-600 mb-0">Split the cost without interest and get the item today.</p>
              </div>
              <a class="btn btn-sm btn-brand mt-3 align-self-start" href="/help.php#payments">Payment options</a>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="hero-slide hero-blue rounded-4 h-100 p-3 d-flex flex-column justify-content-between" style="min-height:180px">
              <div>
                <span class="eyebrow">Live tracking</span>
                <h2 class="h5 fw-bold text-white mt-2 mb-1">Follow every step</h2>
                <p class="small text-white-50 mb-0">Watch your parcel move from packing to your door.</p>
              </div>
              <a class="btn btn-sm btn-light mt-3 align-self-start" href="/track.php">Track an order</a>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card-soft p-3 h-100">
          <div class="section-head mb-2"><div><h2 class="h6 fw-bold mb-0">Best sellers</h2><p class="small text-ink-500 mb-0">What everyone is buying</p></div></div>
          <?php foreach ($bestSellers as $product): ?>
            <a class="d-flex gap-2 py-2 border-bottom text-decoration-none" href="/product.php?slug=<?= e($product['slug']) ?>">
              <img src="<?= e(img_url($product['image'], (string) $product['title'], 120)) ?>" alt="" width="52" height="52" loading="lazy" style="border-radius:.6rem;object-fit:cover;background:var(--ink-100)">
              <span class="flex-grow-1 min-w-0">
                <span class="d-block small fw-semibold text-ink-800 text-truncate"><?= e($product['title']) ?></span>
                <span class="d-block small text-ink-400"><?= e($product['category_name'] ?? '') ?></span>
                <span class="d-block small fw-bold text-brand"><?= e(money($product['price'])) ?></span>
              </span>
            </a>
          <?php endforeach; ?>
          <a class="btn btn-sm btn-soft w-100 mt-2" href="/shop.php?sort=popular">All best sellers</a>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if ($newArrivals !== []): ?>
  <section class="section pt-0">
    <div class="container-page">
      <div class="section-head">
        <div><p class="eyebrow">Just landed</p><h2 class="fw-bold">New arrivals</h2></div>
        <a class="small fw-semibold text-decoration-none" href="/shop.php?sort=newest">See all <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="grid-products">
        <?php foreach ($newArrivals as $product): ?><?= View::card($product) ?><?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<section class="section pt-0">
  <div class="container-page">
    <div class="card-soft p-3 p-lg-4">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <h2 class="h5 fw-bold mb-1">Need help choosing?</h2>
          <p class="small text-ink-600 mb-0">Message us on WhatsApp or call <?= e(Settings::get('support_phone')) ?> — our team answers in English, Sinhala and Tamil, <?= e(Settings::get('support_hours', '9am – 6pm')) ?>.</p>
        </div>
        <div class="col-lg-4 d-flex flex-wrap gap-2 justify-content-lg-end">
          <a class="btn btn-brand" href="/contact.php"><i class="bi bi-chat-dots me-1"></i> Contact us</a>
          <a class="btn btn-soft" href="/faq.php"><i class="bi bi-question-circle me-1"></i> FAQ</a>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require INCLUDES_PATH . '/layout/footer.php'; ?>
