<?php
/**
 * Product detail page — gallery, price, stock, reviews and live tracking of the
 * recently viewed rail.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$slug = trim((string) query('slug', ''));
$productId = query_int('id');
$product = $slug !== '' ? Catalog::productBySlug($slug) : ($productId > 0 ? Catalog::product($productId) : null);

if ($product === null || !empty($product['deleted_at'])) {
    http_response_code(404);
    $pageTitle = 'Product not found';
    require INCLUDES_PATH . '/layout/header.php';
    echo '<section class="section"><div class="container-page">' . View::emptyState('bi-bag-x', 'We could not find that product', 'It may have sold out or been renamed. Try searching the catalogue.', 'Browse all products', '/shop.php') . '</div></section>';
    require INCLUDES_PATH . '/layout/footer.php';
    exit;
}
if ($product['status'] !== 'active' && !Auth::isTeam()) {
    http_response_code(404);
    $pageTitle = 'Product unavailable';
    require INCLUDES_PATH . '/layout/header.php';
    echo '<section class="section"><div class="container-page">' . View::emptyState('bi-bag-x', 'This product is not available', 'Browse the rest of the catalogue — new stock arrives weekly.', 'Shop all products', '/shop.php') . '</div></section>';
    require INCLUDES_PATH . '/layout/footer.php';
    exit;
}

Catalog::incrementView((int) $product['id']);

// Remember the last 8 products this browser viewed (used by the home rail).
$recent = array_values(array_filter(array_map('intval', explode(',', (string) ($_COOKIE['smm_recent'] ?? ''))), static fn ($id) => $id > 0));
$recent = array_values(array_unique(array_merge([(int) $product['id']], $recent)));
if (count($recent) > 8) {
    $recent = array_slice($recent, 0, 8);
}
setcookie('smm_recent', implode(',', $recent), [
    'expires' => time() + 60 * 60 * 24 * 30,
    'path' => '/',
    'secure' => Security::isHttps(),
    'httponly' => true,
    'samesite' => 'Lax',
]);

$images = $product['images'];
$category = $product['category_id'] > 0 ? Catalog::categoryById((int) $product['category_id']) : null;
$trail = $category !== null ? Catalog::categoryBreadcrumb($category) : [];
$crumbs = [];
foreach ($trail as $node) {
    $crumbs[$node['name']] = '/category.php?slug=' . rawurlencode((string) $node['slug']);
}
$crumbs[limit_text((string) $product['title'], 40)] = null;

$reviewTotal = (int) $product['review_count'];
$reviewSummary = Catalog::reviewSummary((int) $product['id']);
$reviews = Catalog::reviews((int) $product['id'], 6);
$related = Catalog::relatedProducts($product, 4);

$canReview = false;
$ownReview = null;
if (Auth::check()) {
    $ownReview = Database::one('SELECT * FROM reviews WHERE product_id = :pid AND user_id = :uid LIMIT 1', [
        'pid' => (int) $product['id'],
        'uid' => Auth::id(),
    ]);
    $canReview = $ownReview === null && Settings::bool('allow_reviews', true) && Database::exists(
        "SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id
          WHERE oi.product_id = :pid2 AND o.user_id = :uid2 AND o.status IN ('DELIVERED', 'SHIPPED') LIMIT 1",
        ['pid2' => (int) $product['id'], 'uid2' => Auth::id()]
    );
}

$wishlist = Cart::wishlistIds();
$saved = in_array((int) $product['id'], $wishlist, true);
$maxQty = max(1, min((int) Config::get('security.max_order_qty', 10), (int) $product['stock']));

$pageTitle = $product['meta_title'] ?: $product['title'];
$pageDescription = $product['meta_description'] ?: limit_text((string) ($product['short_description'] ?: strip_tags((string) $product['description'])), 155, '');
$pageCanonical = url('/product.php?slug=' . rawurlencode((string) $product['slug']));
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="breadcrumb-wrap"><div class="container-page"><?= View::breadcrumbs($crumbs) ?></div></div>

<section class="section pb-0">
  <div class="container-page">
    <div class="row g-3 g-lg-4">
      <div class="col-lg-6">
        <div class="sticky-lg-top" style="top:84px" data-gallery>
          <div class="gallery-main">
            <img src="<?= e(img_url($images[0] ?? null, (string) $product['title'], 900)) ?>" alt="<?= e($product['title']) ?>"
                 width="900" height="900" data-gallery-main>
          </div>
          <?php if (count($images) > 1): ?>
            <div class="gallery-thumbs">
              <?php foreach (array_slice($images, 0, 5) as $index => $image): ?>
                <button class="gallery-thumb <?= $index === 0 ? 'active' : '' ?>" type="button"
                        data-gallery-thumb="<?= e(img_url($image, (string) $product['title'], 900)) ?>"
                        aria-label="View image <?= $index + 1 ?>">
                  <img src="<?= e(img_url($image, (string) $product['title'], 160)) ?>" alt="" width="120" height="120" loading="lazy">
                </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <p class="small text-ink-400 mt-2 mb-0"><i class="bi bi-shield-check me-1"></i> Images are illustrative; exact packaging may vary.</p>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
          <?php if (!empty($product['brand_name'])): ?>
            <a class="badge text-bg-light text-decoration-none" href="/shop.php?brand[]=<?= rawurlencode((string) $product['brand_slug']) ?>"><?= e($product['brand_name']) ?></a>
          <?php endif; ?>
          <?php if (!empty($product['is_flash_deal'])): ?><span class="badge text-bg-danger">Flash deal</span><?php endif; ?>
          <?php if (!empty($product['is_featured'])): ?><span class="badge text-bg-success">Featured</span><?php endif; ?>
          <?php if (($product['condition'] ?? 'new') !== 'new'): ?><span class="badge text-bg-secondary"><?= e(ucfirst((string) $product['condition'])) ?></span><?php endif; ?>
          <span class="small text-ink-400 ms-auto">SKU <?= e($product['sku']) ?></span>
        </div>
        <h1 class="h3 fw-bold mb-2" style="letter-spacing:-.02em"><?= e($product['title']) ?></h1>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
          <?= View::stars((float) $product['rating']) ?>
          <a class="small text-decoration-none" href="#tab-reviews">
            <?= $reviewSummary['count'] > 0 ? e(number_format($reviewSummary['average'], 1)) . ' · ' . $reviewSummary['count'] . ' review' . ($reviewSummary['count'] === 1 ? '' : 's') : 'Be the first to review' ?>
          </a>
          <span class="text-ink-300">|</span>
          <span class="small text-ink-500"><?= (int) $product['sold_count'] ?> sold</span>
        </div>

        <div class="price-block d-flex flex-wrap align-items-baseline gap-2 mb-3">
          <span class="now"><?= e(money($product['price'])) ?></span>
          <?php if (!empty($product['compare_at_price']) && (float) $product['compare_at_price'] > (float) $product['price']): ?>
            <span class="was"><?= e(money($product['compare_at_price'])) ?></span>
            <span class="save">You save <?= (int) $product['discount_percent'] ?>%</span>
          <?php endif; ?>
        </div>
        <p class="small text-ink-500 mb-3">Inclusive of all taxes. <?= e(Settings::get('default_shipping_fee') !== '' ? '' : '') ?>
          <?= $product['free_shipping'] || (float) $product['price'] >= Settings::float('free_shipping_threshold', 15000)
              ? 'Free delivery on this item.'
              : 'Delivery from ' . money(Settings::float('default_shipping_fee', 450)) . '.' ?>
        </p>

        <p class="text-ink-700 mb-3"><?= nl2br(e((string) ($product['short_description'] ?: limit_text(strip_tags((string) $product['description']), 260)))) ?></p>

        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
          <?= View::stockNote($product) ?>
          <?php if (!empty($product['warranty'])): ?><span class="small text-ink-500"><i class="bi bi-patch-check me-1"></i><?= e($product['warranty']) ?></span><?php endif; ?>
          <?php if (!empty($product['weight_grams'])): ?><span class="small text-ink-500"><i class="bi bi-box me-1"></i><?= (int) $product['weight_grams'] ?> g</span><?php endif; ?>
        </div>

        <?php if ((int) $product['stock'] > 0): ?>
          <div class="d-flex flex-wrap gap-2 mb-3 align-items-stretch" data-product-actions>
            <div class="qty-stepper">
              <button type="button" data-qty-step="-1" aria-label="Decrease quantity"><i class="bi bi-dash"></i></button>
              <input type="number" value="1" min="1" max="<?= $maxQty ?>" data-qty-input inputmode="numeric" aria-label="Quantity">
              <button type="button" data-qty-step="1" aria-label="Increase quantity"><i class="bi bi-plus"></i></button>
            </div>
            <button class="btn btn-brand btn-lg flex-grow-1" type="button" data-add-to-cart="<?= (int) $product['id'] ?>">
              <i class="bi bi-bag-plus me-1"></i> Add to cart
            </button>
            <button class="btn btn-soft btn-lg <?= $saved ? 'saved' : '' ?>" type="button" data-wishlist="<?= (int) $product['id'] ?>"
                    aria-label="<?= $saved ? 'Remove from wishlist' : 'Save to wishlist' ?>">
              <i class="bi <?= $saved ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
            </button>
          </div>
          <form method="post" action="/checkout.php" class="mb-3" data-disable-on-submit>
            <?= Csrf::field() ?>
            <input type="hidden" name="intent" value="buy">
            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
            <div class="d-flex gap-2">
              <div class="qty-stepper">
                <button type="button" data-qty-step="-1" aria-label="Decrease quantity"><i class="bi bi-dash"></i></button>
                <input type="number" name="quantity" value="1" min="1" max="<?= $maxQty ?>" data-qty-input inputmode="numeric" aria-label="Quantity for buy now">
                <button type="button" data-qty-step="1" aria-label="Increase quantity"><i class="bi bi-plus"></i></button>
              </div>
              <button class="btn btn-ocean btn-lg flex-grow-1" type="submit">
                <i class="bi bi-lightning-charge-fill me-1"></i> Buy now
              </button>
            </div>
          </form>
        <?php else: ?>
          <div class="alert alert-warning small">This product is out of stock. <a href="/shop.php?on_sale=1" class="alert-link">Browse similar items</a>.</div>
          <button class="btn btn-soft btn-lg w-100 mb-3" type="button" data-wishlist="<?= (int) $product['id'] ?>">
            <i class="bi bi-heart me-1"></i> Notify me / save to wishlist
          </button>
        <?php endif; ?>

        <div class="card-soft p-3 mb-3">
          <div class="row g-2 small">
            <div class="col-6 d-flex gap-2"><i class="bi bi-truck text-brand"></i><span>Dispatched in 24h from Colombo</span></div>
            <div class="col-6 d-flex gap-2"><i class="bi bi-cash-coin text-brand"></i><span>Cash on delivery available</span></div>
            <div class="col-6 d-flex gap-2"><i class="bi bi-arrow-repeat text-brand"></i><span>7-day easy returns</span></div>
            <div class="col-6 d-flex gap-2"><i class="bi bi-shield-check text-brand"></i><span>Genuine stock, local warranty</span></div>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-3 small text-ink-500">
          <span><i class="bi bi-geo-alt me-1"></i>Ships to all 25 districts</span>
          <a class="text-decoration-none" href="/shipping.php">Delivery details</a>
          <a class="text-decoration-none" href="/returns.php">Returns policy</a>
        </div>

        <?php if (!empty($product['tags_array'])): ?>
          <div class="mt-3 d-flex flex-wrap gap-1">
            <?php foreach ($product['tags_array'] as $tag): ?>
              <a class="filter-chip" href="/shop.php?q=<?= rawurlencode((string) $tag) ?>"><?= e($tag) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container-page">
    <ul class="nav nav-tabs mb-3" role="tablist">
      <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-description" type="button" role="tab">Description</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-specs" type="button" role="tab">Specifications</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-reviews" type="button" role="tab">Reviews (<?= $reviewSummary['count'] ?>)</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-delivery" type="button" role="tab">Delivery &amp; returns</button></li>
    </ul>
    <div class="tab-content card-soft p-3 p-md-4">
      <div class="tab-pane fade show active product-description" id="tab-description" role="tabpanel">
        <?php if (trim((string) $product['description']) === ''): ?>
          <p class="text-ink-500 small mb-0">No description has been added for this product yet.</p>
        <?php else: ?>
          <p><?= nl2br(e((string) $product['description'])) ?></p>
        <?php endif; ?>
      </div>
      <div class="tab-pane fade" id="tab-specs" role="tabpanel">
        <?php if ($product['specs'] === []): ?>
          <p class="text-ink-500 small mb-0">No specifications listed.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm spec-table mb-0">
              <tbody>
                <?php foreach ($product['specs'] as $name => $value): ?>
                  <tr><th scope="row"><?= e((string) $name) ?></th><td><?= e(is_array($value) ? implode(', ', $value) : (string) $value) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <div class="tab-pane fade" id="tab-reviews" role="tabpanel">
        <div class="row g-3">
          <div class="col-lg-4">
            <div class="text-center text-lg-start">
              <div class="rating-big"><?= e(number_format($reviewSummary['average'], 1)) ?></div>
              <div><?= View::stars((float) $reviewSummary['average']) ?></div>
              <p class="small text-ink-500 mb-1"><?= $reviewSummary['count'] ?> verified review<?= $reviewSummary['count'] === 1 ? '' : 's' ?></p>
              <?php if ($reviewSummary['verified'] > 0): ?><p class="small text-brand mb-0"><i class="bi bi-patch-check-fill"></i> <?= $reviewSummary['verified'] ?> verified buyer<?= $reviewSummary['verified'] === 1 ? '' : 's' ?></p><?php endif; ?>
            </div>
            <?php if ($reviewSummary['count'] > 0): ?>
              <div class="mt-3">
                <?php foreach ([5, 4, 3, 2, 1] as $star): $count = (int) ($reviewSummary['stars'][$star] ?? 0); ?>
                  <div class="rating-bar mb-1">
                    <span style="width:2rem"><?= $star ?>★</span>
                    <span class="bar"><div style="width:<?= (int) round($count / max(1, $reviewSummary['count']) * 100) ?>%"></div></span>
                    <span class="text-ink-400" style="width:1.6rem;text-align:right"><?= $count ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="col-lg-8">
            <?php if ($reviews === []): ?>
              <p class="small text-ink-500">No reviews yet — if you bought this item, share your experience.</p>
            <?php else: ?>
              <?php foreach ($reviews as $review): ?>
                <div class="border-bottom py-3">
                  <div class="d-flex justify-content-between gap-2">
                    <div>
                      <strong class="small"><?= e($review['author_name']) ?></strong>
                      <?= View::stars((float) $review['rating']) ?>
                    </div>
                    <time class="small text-ink-400" datetime="<?= e((string) $review['created_at']) ?>"><?= e(fmt_date((string) $review['created_at'])) ?></time>
                  </div>
                  <?php if (!empty($review['title'])): ?><p class="small fw-semibold mb-1 mt-1"><?= e($review['title']) ?></p><?php endif; ?>
                  <p class="small text-ink-600 mb-0"><?= nl2br(e((string) $review['body'])) ?></p>
                  <?php if (!empty($review['admin_reply'])): ?>
                    <div class="mt-2 p-2 rounded" style="background:var(--ink-50)">
                      <strong class="small"><i class="bi bi-shop me-1"></i>Store reply</strong>
                      <p class="small mb-0 text-ink-600"><?= nl2br(e((string) $review['admin_reply'])) ?></p>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($ownReview !== null): ?>
              <div class="alert alert-info small mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i> You reviewed this product
                <?php if (($ownReview['status'] ?? '') === 'pending'): ?>(awaiting moderation)<?php endif; ?>.
              </div>
            <?php elseif ($canReview): ?>
              <form method="post" action="/actions/review.php" class="mt-3" data-disable-on-submit>
                <?= Csrf::field() ?>
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                <h3 class="h6 fw-bold">Write a review</h3>
                <div class="row g-2">
                  <div class="col-sm-4">
                    <label class="form-label small" for="review-rating">Rating</label>
                    <select class="form-select form-select-sm" id="review-rating" name="rating" required>
                      <?php foreach ([5 => '5 stars — excellent', 4 => '4 stars — good', 3 => '3 stars — ok', 2 => '2 stars — poor', 1 => '1 star — bad'] as $value => $label): ?>
                        <option value="<?= $value ?>"><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-sm-8">
                    <label class="form-label small" for="review-title">Title (optional)</label>
                    <input class="form-control form-control-sm" id="review-title" name="title" maxlength="120">
                  </div>
                  <div class="col-12">
                    <label class="form-label small" for="review-body">Your review</label>
                    <textarea class="form-control form-control-sm" id="review-body" name="body" rows="3" minlength="10" maxlength="2000" required placeholder="What did you like or dislike?"></textarea>
                  </div>
                  <div class="col-12"><button class="btn btn-brand btn-sm" type="submit">Submit review</button></div>
                </div>
              </form>
            <?php elseif (!Auth::check()): ?>
              <p class="small mt-3 mb-0"><a href="/login.php?next=<?= rawurlencode('/product.php?slug=' . (string) $product['slug']) ?>">Sign in</a> to review this product after your order arrives.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="tab-pane fade product-description" id="tab-delivery" role="tabpanel">
        <p><?= nl2br(e(Settings::get('shipping_text') ?: 'Orders placed before 2pm on weekdays are dispatched the same day from our Colombo warehouse. Delivery takes 1–2 working days in Colombo and 2–4 working days elsewhere. Free delivery applies to orders over ' . money(Settings::float('free_shipping_threshold', 15000)) . '.')) ?></p>
        <p class="mb-0"><?= nl2br(e(Settings::get('returns_text') ?: 'Changed your mind? Return unused items in their original packaging within 7 days for a refund or exchange. Faulty items are covered by the manufacturer warranty.')) ?></p>
      </div>
    </div>
  </div>
</section>

<?php if ($related !== []): ?>
  <section class="section pt-0">
    <div class="container-page">
      <div class="section-head">
        <div><p class="eyebrow">Keep looking</p><h2 class="fw-bold">You may also like</h2></div>
        <a class="small fw-semibold text-decoration-none" href="/shop.php?category=<?= rawurlencode((string) ($category['slug'] ?? '')) ?>">More in this category</a>
      </div>
      <div class="grid-products">
        <?php foreach ($related as $item): ?><?= View::card($item) ?><?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<script type="application/ld+json" nonce="<?= e(Security::nonce()) ?>">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $product['title'],
    'image' => array_map(static fn ($image) => img_url((string) $image, (string) $product['title'], 900), array_slice($images, 0, 3)),
    'description' => limit_text(strip_tags((string) $product['description']), 320, ''),
    'sku' => $product['sku'],
    'brand' => ['@type' => 'Brand', 'name' => (string) ($product['brand_name'] ?? Settings::get('store_name'))],
    'offers' => [
        '@type' => 'Offer',
        'url' => url('/product.php?slug=' . rawurlencode((string) $product['slug'])),
        'priceCurrency' => Settings::get('currency', 'LKR'),
        'price' => number_format((float) $product['price'], 2, '.', ''),
        'availability' => (int) $product['stock'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
    ],
    'aggregateRating' => $reviewSummary['count'] > 0 ? [
        '@type' => 'AggregateRating',
        'ratingValue' => $reviewSummary['average'],
        'reviewCount' => $reviewSummary['count'],
    ] : null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>
