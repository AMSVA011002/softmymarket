<?php
/** Shared storefront footer: cart drawer, mobile menu, newsletter, scripts. */
declare(strict_types=1);

$brand = Settings::brand();
$footerCart = Cart::contents();
$footerQuote = Pricing::quote($footerCart);
$footerCategories = array_slice(View::navCategories(6), 0, 6);
?>
</main>

<footer class="site-footer">
  <div class="footer-trust">
    <div class="container-page">
      <div class="row g-3">
        <div class="col-6 col-lg-3"><i class="bi bi-truck"></i><div><strong>Island-wide delivery</strong><span>1–4 working days</span></div></div>
        <div class="col-6 col-lg-3"><i class="bi bi-cash-coin"></i><div><strong>Cash on delivery</strong><span>Pay when it arrives</span></div></div>
        <div class="col-6 col-lg-3"><i class="bi bi-shield-check"></i><div><strong>Genuine products</strong><span>Local warranty</span></div></div>
        <div class="col-6 col-lg-3"><i class="bi bi-arrow-repeat"></i><div><strong>7-day returns</strong><span>No-hassle exchanges</span></div></div>
      </div>
    </div>
  </div>

  <div class="container-page">
    <div class="row g-4 py-4 py-lg-5">
      <div class="col-lg-4">
        <a class="logo footer-branding mb-3 d-inline-flex<?= !empty($brand['logo']) ? ' has-brand-logo' : ' no-brand-logo' ?>" href="/">
          <?php if (!empty($brand['logo'])): ?>
            <img src="<?= e(img_url($brand['logo'], $brand['name'], 320)) ?>" alt="<?= e($brand['name']) ?>" width="200" height="48">
          <?php else: ?>
            <span class="footer-brand-name"><?= e($brand['name']) ?></span>
          <?php endif; ?>
        </a>
        <p class="small text-ink-600 mb-3"><?= e(limit_text(Settings::get('store_description'), 190)) ?></p>
        <ul class="list-unstyled small text-ink-600 mb-3">
          <li class="mb-1"><i class="bi bi-geo-alt me-2 text-brand"></i><?= e(Settings::get('address')) ?></li>
          <li class="mb-1"><i class="bi bi-telephone me-2 text-brand"></i><a class="text-decoration-none text-ink-600" href="tel:<?= e(preg_replace('/\s+/', '', Settings::get('support_phone'))) ?>"><?= e(Settings::get('support_phone')) ?></a></li>
          <li class="mb-1"><i class="bi bi-envelope me-2 text-brand"></i><a class="text-decoration-none text-ink-600" href="mailto:<?= e(Settings::get('support_email')) ?>"><?= e(Settings::get('support_email')) ?></a></li>
        </ul>
        <div class="d-flex gap-2">
          <?php foreach (['facebook_url' => 'facebook', 'instagram_url' => 'instagram', 'tiktok_url' => 'tiktok', 'youtube_url' => 'youtube'] as $key => $network):
              $link = trim(Settings::get($key));
              if ($link === '') continue; ?>
            <a class="social-link" href="<?= e($link) ?>" rel="noopener nofollow" target="_blank" aria-label="<?= e(ucfirst($network)) ?>"><i class="bi bi-<?= e($network) ?>"></i></a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-6 col-lg-2">
        <h2 class="footer-title">Shop</h2>
        <ul class="footer-links">
          <li><a href="/shop.php">All products</a></li>
          <li><a href="/deals.php">Deals</a></li>
          <li><a href="/brands.php">Brands</a></li>
          <?php foreach ($footerCategories as $category): ?>
            <li><a href="/category.php?slug=<?= e($category['slug']) ?>"><?= e($category['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="col-6 col-lg-2">
        <h2 class="footer-title">Help</h2>
        <ul class="footer-links">
          <li><a href="/help.php">Help centre</a></li>
          <li><a href="/faq.php">FAQs</a></li>
          <li><a href="/track.php">Track an order</a></li>
          <li><a href="/shipping.php">Shipping &amp; delivery</a></li>
          <li><a href="/returns.php">Returns &amp; refunds</a></li>
          <li><a href="/contact.php">Contact us</a></li>
        </ul>
      </div>

      <div class="col-6 col-lg-2">
        <h2 class="footer-title">Company</h2>
        <ul class="footer-links">
          <li><a href="/about.php">About us</a></li>
          <li><a href="/privacy.php">Privacy policy</a></li>
          <li><a href="/terms.php">Terms of service</a></li>
          <li><a href="/login.php">Sign in</a></li>
          <li><a href="/register.php">Create account</a></li>
          <?php if (Auth::isTeam()): ?><li><a href="/admin/index.php">Dashboard</a></li><?php endif; ?>
        </ul>
      </div>

      <div class="col-lg-2">
        <h2 class="footer-title">Newsletter</h2>
        <p class="small text-ink-600 mb-2">Deals and new arrivals, once a week. No spam.</p>
        <form class="newsletter-form" method="post" action="/actions/newsletter.php">
          <?= Csrf::field() ?>
          <label class="visually-hidden" for="newsletter-email">Email address</label>
          <input class="form-control form-control-sm" id="newsletter-email" type="email" name="email" placeholder="you@email.com" required maxlength="160">
          <button class="btn btn-brand btn-sm mt-2 w-100" type="submit">Subscribe</button>
        </form>
        <div class="d-flex flex-wrap gap-1 mt-3">
          <?php foreach (array_keys(PaymentManager::gateways()) as $code): ?>
            <span class="pay-badge"><i class="bi <?= e(payment_method_icon($code)) ?>"></i> <?= e(payment_method_label($code)) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-3 border-top small text-ink-500">
      <div>© <?= date('Y') ?> <?= e($brand['name']) ?>. <?= e(Settings::get('footer_note')) ?></div>
      <div class="d-flex gap-3">
        <a class="text-decoration-none text-ink-500" href="/privacy.php">Privacy</a>
        <a class="text-decoration-none text-ink-500" href="/terms.php">Terms</a>
        <a class="text-decoration-none text-ink-500" href="/returns.php">Returns</a>
      </div>
    </div>
  </div>
</footer>

<div class="quick-dock" role="region" aria-label="Quick help and page actions">
  <?php
  $smmWhatsapp = preg_replace('/[^0-9]/', '', Settings::get('support_whatsapp')) ?? '';
  $smmPhoneDial = preg_replace('/[^0-9+]/', '', Settings::get('support_phone')) ?? '';
  $smmSupportEmail = trim(Settings::get('support_email'));
  ?>
  <div class="live-chat support-widget" data-live-chat data-support-widget data-dock role="group"
       aria-label="Chat with our team">
    <button class="support-fab support-launcher" type="button" data-dock-toggle
            aria-expanded="false" aria-controls="supportChannels" aria-label="Contact us">
      <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>
    </button>
    <div class="support-channels" id="supportChannels">
      <span class="support-caption">Need help?</span>
      <?php if ($smmWhatsapp !== ''): ?>
        <a class="support-fab support-whatsapp" href="https://wa.me/<?= e(ltrim($smmWhatsapp, '+')) ?>"
           target="_blank" rel="noopener" data-chat-channel="whatsapp" tabindex="-1"
           aria-label="Chat with us on WhatsApp"><i class="bi bi-whatsapp" aria-hidden="true"></i></a>
      <?php endif; ?>
      <?php if ($smmPhoneDial !== ''): ?>
        <a class="support-fab" href="tel:<?= e($smmPhoneDial) ?>" data-chat-channel="phone" tabindex="-1"
           aria-label="Call <?= e(Settings::get('support_phone')) ?>"><i class="bi bi-telephone" aria-hidden="true"></i></a>
      <?php endif; ?>
      <?php if ($smmSupportEmail !== ''): ?>
        <a class="support-fab" href="mailto:<?= e($smmSupportEmail) ?>" data-chat-channel="email" tabindex="-1"
           aria-label="Email <?= e($smmSupportEmail) ?>"><i class="bi bi-envelope" aria-hidden="true"></i></a>
      <?php endif; ?>
    </div>
  </div>
  <button class="back-to-top" type="button" aria-label="Back to top"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
</div>

<!-- Cart drawer -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="cartDrawer" role="dialog" aria-modal="true" aria-labelledby="cartDrawerLabel" style="width:min(420px,92vw)">
  <div class="offcanvas-header border-bottom">
    <h2 class="offcanvas-title h6 fw-bold" id="cartDrawerLabel"><i class="bi bi-bag me-1"></i> Your cart</h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body d-flex flex-column" data-cart-drawer-body>
    <?php if ($footerCart['items'] === [] && $footerCart['unavailable'] === []): ?>
      <div class="text-center py-5">
        <i class="bi bi-bag" style="font-size:2.4rem;color:var(--ink-300)"></i>
        <p class="small text-ink-500 mt-2 mb-3">Your cart is empty.</p>
        <a class="btn btn-brand btn-sm" href="/shop.php">Start shopping</a>
      </div>
    <?php else: ?>
      <div class="flex-grow-1" style="overflow:auto">
        <?php if ($footerCart['unavailable'] !== []): ?>
          <div class="alert alert-danger small py-2" role="alert">
            <strong>Unavailable items block checkout:</strong>
            <ul class="mb-0 mt-1 ps-3">
              <?php foreach ($footerCart['unavailable'] as $unavailable): ?>
                <li><?= e($unavailable['title']) ?> — <?= e($unavailable['reason']) ?> <button class="btn btn-link btn-sm text-danger p-0" type="button" data-cart-remove="<?= (int) $unavailable['product_id'] ?>">Remove</button></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
        <?php foreach ($footerCart['items'] as $line): $product = $line['product']; ?>
          <div class="cart-line">
            <img src="<?= e(product_image($product)) ?>" alt="" loading="lazy">
            <div class="flex-grow-1 min-w-0">
              <a class="title text-truncate-2" href="/product.php?slug=<?= e($product['slug']) ?>"><?= e($product['title']) ?></a>
              <div class="d-flex align-items-center justify-content-between gap-2">
                <div class="small text-ink-500"><?= (int) $line['quantity'] ?> × <?= e(money($product['price'])) ?></div>
                <div class="small fw-bold"><?= e(money($line['line_total'])) ?></div>
              </div>
              <div class="d-flex justify-content-between align-items-center mt-1">
                <div class="qty-stepper">
                  <button type="button" data-qty-step="-1" aria-label="Decrease quantity"><i class="bi bi-dash"></i></button>
                  <input type="number" value="<?= (int) $line['quantity'] ?>" min="1" max="<?= (int) $product['stock'] ?>" data-cart-qty="<?= (int) $product['id'] ?>" aria-label="Quantity for <?= e($product['title']) ?>">
                  <button type="button" data-qty-step="1" aria-label="Increase quantity"><i class="bi bi-plus"></i></button>
                </div>
                <button class="btn btn-link btn-sm text-danger p-0 text-decoration-none" type="button" data-cart-remove="<?= (int) $product['id'] ?>" aria-label="Remove <?= e($product['title']) ?>">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="border-top pt-3 mt-2">
        <div class="summary-row"><span>Subtotal</span><span><?= e(money($footerQuote['subtotal'])) ?></span></div>
        <div class="summary-row"><span>Delivery</span><span><?= $footerQuote['shipping'] > 0 ? e(money($footerQuote['shipping'])) : '<span class="text-brand">Free</span>' ?></span></div>
        <div class="summary-row total"><span>Total</span><span><?= e(money($footerQuote['total'])) ?></span></div>
        <div class="d-grid gap-2 mt-2">
          <?php if ($footerCart['unavailable'] === [] && $footerCart['items'] !== []): ?>
            <a class="btn btn-brand" href="/checkout.php">Checkout <i class="bi bi-arrow-right"></i></a>
          <?php else: ?>
            <button class="btn btn-brand" type="button" disabled>Resolve unavailable items</button>
          <?php endif; ?>
          <a class="btn btn-soft" href="/cart.php">View cart</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Mobile navigation -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="navDrawer" role="dialog" aria-modal="true" aria-labelledby="navDrawerLabel">
  <div class="offcanvas-header border-bottom">
    <h2 class="offcanvas-title h6 fw-bold" id="navDrawerLabel"><?= e($brand['name']) ?></h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <form class="mb-3" action="/shop.php" method="get" role="search">
      <label class="visually-hidden" for="mobile-search">Search</label>
      <div class="input-group">
        <input class="form-control" id="mobile-search" type="search" name="q" placeholder="Search products">
        <button class="btn btn-brand" type="submit" aria-label="Search"><i class="bi bi-search"></i></button>
      </div>
    </form>
    <div class="d-grid gap-2 mb-3">
      <a class="btn btn-soft btn-sm text-start" href="/shop.php"><i class="bi bi-shop me-2"></i>All products</a>
      <a class="btn btn-soft btn-sm text-start" href="/deals.php"><i class="bi bi-lightning-charge me-2"></i>Today's deals</a>
      <a class="btn btn-soft btn-sm text-start" href="/track.php"><i class="bi bi-truck me-2"></i>Track an order</a>
      <a class="btn btn-soft btn-sm text-start" href="/account/wishlist.php"><i class="bi bi-heart me-2"></i>Wishlist<span class="badge badge-feature ms-auto <?= $smmWishCount === 0 ? 'd-none' : '' ?>" data-wishlist-count><?= (int) $smmWishCount ?></span></a>
      <a class="btn btn-soft btn-sm text-start" href="/account/index.php"><i class="bi bi-person me-2"></i><?= Auth::check() ? 'My account' : 'Sign in' ?></a>
    </div>
    <h2 class="footer-title h6">Departments</h2>
    <ul class="footer-links">
      <?php foreach (View::navCategories(20) as $category): ?>
        <li><a href="/category.php?slug=<?= e($category['slug']) ?>"><i class="bi <?= e($category['icon']) ?> me-1"></i><?= e($category['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <h2 class="footer-title h6 mt-3">Support</h2>
    <ul class="footer-links">
      <li><a href="/help.php">Help centre</a></li>
      <li><a href="/contact.php">Contact us</a></li>
      <li><a href="/shipping.php">Shipping</a></li>
      <li><a href="/returns.php">Returns</a></li>
    </ul>
  </div>
</div>

<script src="<?= e(asset('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
<?php if (Settings::get('google_analytics_id') !== ''): ?>
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= e(Settings::get('google_analytics_id')) ?>"></script>
  <script nonce="<?= e(Security::nonce()) ?>">
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', '<?= e(Settings::get('google_analytics_id')) ?>');
  </script>
<?php endif; ?>
</body>
</html>
