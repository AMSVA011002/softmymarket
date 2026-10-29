<?php
/** @var array<string,mixed> $product */
/** @var array<string,list<int>> $wishlist */
$saved = in_array((int) $product['id'], $wishlist ?? [], true);
$discount = (int) ($product['discount_percent'] ?? 0);
$isChoice = !empty($product['is_choice']);
$isFeatured = !empty($product['is_featured']);
$stock = (int) $product['stock'];
$url = '/product.php?slug=' . rawurlencode((string) $product['slug']);
?>
<article class="product-card">
  <a class="product-media" href="<?= e($url) ?>" aria-label="<?= e($product['title']) ?>">
    <img src="<?= e(product_image($product)) ?>" alt="<?= e($product['title']) ?>"
         loading="<?= !empty($options['priority']) ? 'eager' : 'lazy' ?>" width="400" height="300">
    <span class="product-badges">
      <?php if ($discount > 0): ?><span class="badge-deal">−<?= $discount ?>%</span><?php endif; ?>
      <?php if ($isChoice): ?><span class="badge-choice">Choice</span><?php endif; ?>
      <?php if ($isFeatured): ?><span class="badge-feature">Featured</span><?php endif; ?>
      <?php if ($stock <= 0): ?><span class="badge-out">Sold out</span><?php endif; ?>
    </span>
  </a>
  <button type="button" class="product-wish <?= $saved ? 'saved' : '' ?>" data-wishlist="<?= (int) $product['id'] ?>"
          aria-label="<?= $saved ? 'Remove from wishlist' : 'Save to wishlist' ?>">
    <i class="bi <?= $saved ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
  </button>
  <div class="product-body">
    <span class="product-cat"><?= e($product['category_name'] ?? '') ?></span>
    <a class="product-title" href="<?= e($url) ?>"><?= e($product['title']) ?></a>
    <div class="product-rating">
      <?= View::stars((float) ($product['rating'] ?? 0)) ?>
      <span><?= (int) ($product['review_count'] ?? 0) > 0 ? e(number_format((float) $product['rating'], 1)) . ' (' . (int) $product['review_count'] . ')' : 'No reviews yet' ?></span>
    </div>
    <?= View::stockNote($product) ?>
    <div class="product-price">
      <span class="now"><?= e(money($product['price'])) ?></span>
      <?php if (!empty($product['compare_at_price']) && (float) $product['compare_at_price'] > (float) $product['price']): ?>
        <span class="was"><?= e(money($product['compare_at_price'])) ?></span>
      <?php endif; ?>
    </div>
    <?php if (!empty($options['showQuickAdd'])): ?>
      <div class="product-foot">
        <button class="btn btn-brand" type="button" data-add-to-cart="<?= (int) $product['id'] ?>" <?= $stock <= 0 ? 'disabled' : '' ?>>
          <i class="bi bi-bag-plus"></i> <span class="d-none d-sm-inline">Add</span>
        </button>
        <a class="btn btn-outline-brand" href="<?= e($url) ?>" aria-label="View <?= e($product['title']) ?>">
          <i class="bi bi-eye"></i><span class="d-none d-lg-inline"> View</span>
        </a>
      </div>
    <?php endif; ?>
  </div>
</article>
