<?php
/**
 * Storefront home page.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$featuredCategories = Catalog::featuredCategories(12);
$deals = Catalog::dealProducts(8);
$featured = Catalog::featuredProducts(8);
$bestSellers = Catalog::bestSellers(4);
$newArrivals = Catalog::newArrivals(8);
$coupons = Coupons::active(3);
$heroSlides = View::heroSlides();
$homeReviews = Catalog::recentReviews(3);
$homeReviewStats = Catalog::storeReviewSummary();
$returnWindowDays = max(1, min(90, Settings::int('return_window_days', 7)));
$deliveryPromise = Settings::get('delivery_promise', '1–4 working days');
$homeStats = [
    'products' => Catalog::productCount('active'),
    'brands'   => Database::int('SELECT COUNT(*) FROM brands'),
];

// Brand strip: only brands that actually have something in stock, busiest first,
// so the marquee never shows an empty shop front.
$brandStrip = array_values(array_filter(
    Catalog::brands(true),
    static fn (array $brand): bool => (int) ($brand['product_count'] ?? 0) > 0
));
usort($brandStrip, static function (array $a, array $b): int {
    return [(int) $b['product_count'], (string) $a['name']] <=> [(int) $a['product_count'], (string) $b['name']];
});
$brandStrip = array_slice($brandStrip, 0, 24);

// Recently viewed rail (ids are stored in a first-party cookie by product.php).
$recentIds = array_values(array_filter(array_map('intval', explode(',', (string) ($_COOKIE['smm_recent'] ?? '')))));
$recentlyViewed = $recentIds !== [] ? Catalog::productsByIds(array_slice($recentIds, 0, 8)) : [];

$dealEnds = strtotime('tomorrow 23:59:59');
$pageClass = 'page-home';
$pageDescription = Settings::get('default_meta_description');
require INCLUDES_PATH . '/layout/header.php';
?>

<section class="hero" aria-label="Featured offers">
  <div id="heroCarousel" class="carousel slide hero-carousel" data-bs-ride="carousel"
       data-bs-interval="6500" data-bs-touch="true" aria-roledescription="carousel">
    <div class="carousel-inner">
      <?php foreach ($heroSlides as $index => $slide): ?>
        <?php
        $heroImage = trim((string) ($slide['image'] ?? ''));
        $heroSrc = $heroImage !== '' ? img_url($heroImage, $slide['title'], 900) : '';
        // Proxied images accept a target width; ask for a crisp hero size.
        if (str_starts_with($heroSrc, '/image.php?')) {
            $heroSrc .= '&w=900';
        }
        $heroIsLight = $slide['theme'] === 'light';
        ?>
        <div class="carousel-item<?= $index === 0 ? ' active' : '' ?>"
             role="group" aria-roledescription="slide"
             aria-label="Slide <?= $index + 1 ?> of <?= count($heroSlides) ?>">
          <div class="hero-slide hero-<?= e($slide['theme']) ?>">
            <div class="container-page">
              <div class="row align-items-center g-3 g-lg-4">
                <div class="col-lg-<?= $heroImage !== '' ? '7' : '12' ?> hero-body <?= $heroIsLight ? 'text-ink-900' : 'text-white' ?>">
                  <span class="eyebrow"><?= e($slide['eyebrow']) ?></span>
                  <?php
                  // One <h1> per page: the first slide owns it, later slides
                  // keep the same visual weight with an <h2>.
                  $slideTag = $index === 0 ? 'h1' : 'h2';
                  ?>
                  <<?= $slideTag ?> class="hero-title">
                    <?= e($slide['title']) ?><br>
                    <span class="<?= $heroIsLight ? 'text-brand' : 'text-white' ?>"><?= e($slide['highlight']) ?></span>
                  </<?= $slideTag ?>>
                  <p class="hero-copy <?= $heroIsLight ? 'text-ink-600' : 'text-white-50' ?>"><?= e($slide['description']) ?></p>
                  <div class="hero-cta">
                    <a class="btn btn-lg <?= $heroIsLight ? 'btn-brand' : 'btn-light' ?>" href="<?= e($slide['cta_url']) ?>">
                      <?= e($slide['cta_label']) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                    <a class="btn btn-lg <?= $heroIsLight ? 'btn-outline-brand' : 'btn-outline-light' ?>" href="<?= e($slide['secondary_url']) ?>"><?= e($slide['secondary_label']) ?></a>
                  </div>
                  <dl class="hero-stats">
                    <div><dt>Products</dt><dd><?= e(compact_number(Catalog::productCount('active'))) ?>+</dd></div>
                    <div><dt>Delivery</dt><dd><?= e($deliveryPromise) ?></dd></div>
                    <div><dt>Returns</dt><dd><?= (int) $returnWindowDays ?> days</dd></div>
                  </dl>
                </div>
                <?php if ($heroImage !== ''): ?>
                  <div class="col-lg-5 hero-media">
                    <div class="hero-visual">
                      <img class="hero-visual-img" src="<?= e($heroSrc) ?>"
                           alt="<?= e(trim($slide['title'] . ' ' . $slide['highlight'])) ?>"
                           width="900" height="675"
                           loading="<?= $index === 0 ? 'eager' : 'lazy' ?>"<?= $index === 0 ? ' fetchpriority="high"' : '' ?>
                           decoding="async">
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (count($heroSlides) > 1): ?>
    <div class="hero-controls">
      <div class="container-page hero-controls-inner">
        <div class="carousel-indicators">
          <?php foreach ($heroSlides as $index => $slide): ?>
            <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="<?= $index ?>"
                    class="<?= $index === 0 ? 'active' : '' ?>"
                    aria-current="<?= $index === 0 ? 'true' : 'false' ?>"
                    aria-label="Go to slide <?= $index + 1 ?>"></button>
          <?php endforeach; ?>
        </div>
        <div class="hero-arrows">
          <button class="hero-arrow" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev"
                  aria-label="Previous slide"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
          <button class="hero-arrow" type="button" data-bs-target="#heroCarousel" data-bs-slide="next"
                  aria-label="Next slide"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="py-3 bg-white border-bottom">
  <div class="container-page">
    <div class="row g-2 g-lg-3">
      <?php
      $props = [
          ['bi-truck', Settings::get('trust_delivery_title'), Settings::get('trust_delivery_text')],
          ['bi-cash-coin', Settings::get('trust_payment_title'), Settings::get('trust_payment_text')],
          ['bi-shield-check', Settings::get('trust_quality_title'), Settings::get('trust_quality_text')],
          ['bi-chat-dots', Settings::get('trust_returns_title'), Settings::get('trust_returns_text')],
      ];
      foreach ($props as [$icon, $title, $text]): if ($title === '' && $text === '') continue; ?>
        <div class="col-6 col-lg-3">
          <div class="value-prop">
            <span class="icon"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></span>
            <div><p class="value-prop-title"><?= e($title) ?></p><p><?= e($text) ?></p></div>
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
              <img src="<?= e(product_image_src($product['image'] ?? null, (string) $product['title'], 120)) ?>" alt="" width="52" height="52" loading="lazy" style="border-radius:.6rem;object-fit:cover;background:var(--ink-100)">
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

<?php if ($brandStrip !== []): ?>
  <section class="section pt-0" aria-labelledby="brandStripTitle">
    <div class="container-page">
      <div class="section-head mb-2">
        <div>
          <p class="eyebrow">Brands we stock</p>
          <h2 class="fw-bold" id="brandStripTitle">Shop by brand</h2>
        </div>
        <a class="small fw-semibold text-decoration-none" href="/brands.php">All brands <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>
    <div class="brand-marquee" data-brand-marquee>
      <div class="brand-marquee-track" data-brand-track>
        <?php
        // A short brand list would make a rail narrower than the screen and the
        // loop would show a gap, so repeat it inside each copy until the track
        // is comfortably wider than any viewport.
        $brandRepeat = max(1, min(10, (int) ceil(20 / max(1, count($brandStrip)))));
        ?>
        <?php for ($pass = 1; $pass <= 2; $pass++): ?>
          <?php // The second copy only exists to make the loop seamless: hide it from assistive tech and the tab order. ?>
          <div class="brand-marquee-group"<?= $pass === 2 ? ' aria-hidden="true"' : '' ?>>
            <?php for ($copy = 1; $copy <= $brandRepeat; $copy++): ?>
              <?php foreach ($brandStrip as $brandItem): ?>
                <?php
                $brandLogo = trim((string) ($brandItem['logo_url'] ?? ''));
                $brandHref = '/shop.php?brand%5B%5D=' . rawurlencode((string) $brandItem['slug']);
                // Only the very first pass is real content; the rest is decoration.
                $brandPrimary = $pass === 1 && $copy === 1;
                ?>
                <a class="brand-chip" href="<?= e($brandHref) ?>" title="<?= e($brandItem['name']) ?>"
                   <?= $brandPrimary ? '' : 'aria-hidden="true" tabindex="-1"' ?>>
                  <?php if ($brandLogo !== ''): ?>
                    <img src="<?= e(img_url($brandLogo, (string) $brandItem['name'], 160)) ?>"
                         alt="" width="104" height="26" loading="lazy" decoding="async">
                  <?php else: ?>
                    <span class="brand-chip-mark" aria-hidden="true"><?= e(initials((string) $brandItem['name'], 2)) ?></span>
                  <?php endif; ?>
                  <span class="brand-chip-name"><?= e($brandItem['name']) ?></span>
                </a>
              <?php endforeach; ?>
            <?php endfor; ?>
          </div>
        <?php endfor; ?>
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

<section class="section pt-0" id="trust">
  <div class="container-page">
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card-soft p-3 p-lg-4 h-100">
          <p class="eyebrow">Social proof</p>
          <h2 class="fw-bold mb-1">Shoppers choose <?= e(Settings::get('store_name')) ?></h2>
          <p class="small text-ink-600 mb-3">Real customers, real orders, real reviews.</p>

          <div class="trust-strip mb-3">
            <?php if ($homeReviewStats['total'] > 0): ?>
              <div class="trust-stat">
                <strong><?= e(number_format((float) $homeReviewStats['average'], 1)) ?>/5</strong>
                <span>Average rating</span>
              </div>
              <div class="trust-stat">
                <strong><?= (int) $homeReviewStats['total'] ?></strong>
                <span>Verified reviews</span>
              </div>
            <?php else: ?>
              <div class="trust-stat">
                <strong><?= (int) $returnWindowDays ?> days</strong>
                <span>Return request window</span>
              </div>
              <div class="trust-stat">
                <strong>COD</strong>
                <span>Pay on delivery</span>
              </div>
            <?php endif; ?>
            <div class="trust-stat">
              <strong><?= e(compact_number($homeStats['products'])) ?>+</strong>
              <span>Products in stock</span>
            </div>
            <div class="trust-stat">
              <strong><?= (int) $homeStats['brands'] ?></strong>
              <span>Brands stocked</span>
            </div>
          </div>

          <?php if ($homeReviews !== []): ?>
            <div class="row g-2">
              <?php foreach ($homeReviews as $review): ?>
                <div class="col-md-4">
                  <figure class="review-card mb-0">
                    <div class="review-stars"><?= View::stars((float) $review['rating']) ?></div>
                    <blockquote class="review-quote"><?= e(limit_text((string) $review['body'], 140)) ?></blockquote>
                    <figcaption class="review-author">
                      <strong><?= e((string) $review['author_name']) ?></strong>
                      <a href="/product.php?slug=<?= e((string) $review['product_slug']) ?>"><?= e(limit_text((string) $review['product_title'], 42)) ?></a>
                    </figcaption>
                  </figure>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="review-card review-card-empty">
              <i class="bi bi-patch-check-fill text-brand"></i>
              <div>
                <strong>Every order is covered</strong>
                <p class="small text-ink-600 mb-0"><?= e(Settings::get('store_description')) ?></p>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-5">
        <form class="card-soft p-3 p-lg-4 h-100 contact-form" id="ask" method="post" action="/actions/contact.php" data-contact-form>
          <?= Csrf::field() ?>
          <input type="hidden" name="topic" value="home">
          <input type="hidden" name="subject" value="Home page enquiry">
          <p class="eyebrow">Talk to a human</p>
          <h2 class="h4 fw-bold mb-1">Ask us anything</h2>
          <p class="small text-ink-600 mb-3">Our team typically replies <?= e(Settings::get('support_response_time')) ?>.</p>
          <div class="mb-2">
            <label class="form-label small" for="ask-name">Your name</label>
            <input class="form-control" id="ask-name" type="text" name="name" maxlength="120" autocomplete="name" required>
          </div>
          <div class="mb-2">
            <label class="form-label small" for="ask-email">Email address</label>
            <input class="form-control" id="ask-email" type="email" name="email" maxlength="160" autocomplete="email" required>
          </div>
          <div class="mb-3">
            <label class="form-label small" for="ask-message">How can we help?</label>
            <textarea class="form-control" id="ask-message" name="message" rows="3" maxlength="4000" required></textarea>
          </div>
          <button class="btn btn-brand w-100" type="submit"><i class="bi bi-send me-1"></i> Send message</button>
          <p class="small text-ink-500 mt-2 mb-0">Prefer to talk? Call <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', Settings::get('support_phone'))) ?>"><?= e(Settings::get('support_phone')) ?></a>.</p>
        </form>
      </div>
    </div>
  </div>
</section>

<?php require INCLUDES_PATH . '/layout/footer.php'; ?>
