<?php
/** @var array<string,mixed> $order */
/** @var list<array<string,mixed>> $items */
?>
<div class="table-responsive">
  <table class="table align-middle mb-0">
    <thead>
      <tr class="small text-ink-500">
        <th scope="col">Item</th>
        <th scope="col" class="text-center">Qty</th>
        <th scope="col" class="text-end">Price</th>
        <th scope="col" class="text-end">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <img src="<?= e(img_url((string) $item['image_url'], (string) $item['title'], 120)) ?>" alt="" width="48" height="48" style="object-fit:cover;border-radius:8px;background:var(--ink-100)" loading="lazy">
              <div>
                <a class="small fw-semibold text-decoration-none" href="/product.php?slug=<?= e($item['slug']) ?>"><?= e($item['title']) ?></a>
                <div class="text-ink-400" style="font-size:.7rem">SKU <?= e($item['sku']) ?></div>
                <?php if (!empty($showWarranty)): ?>
                  <div class="order-item-warranty small text-ink-500"><strong>Warranty:</strong> <?= e(trim((string) ($item['warranty'] ?? '')) !== '' ? (string) $item['warranty'] : 'Not specified') ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td class="text-center small"><?= (int) $item['quantity'] ?></td>
          <td class="text-end small"><?= e(money($item['unit_price'])) ?></td>
          <td class="text-end small fw-semibold"><?= e(money($item['total'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="mt-3 ms-auto" style="max-width:320px">
  <div class="summary-row"><span>Subtotal</span><span><?= e(money($order['subtotal'])) ?></span></div>
  <div class="summary-row"><span>Delivery</span><span><?= $order['shipping_fee'] > 0 ? e(money($order['shipping_fee'])) : '<span class="text-brand">Free</span>' ?></span></div>
  <?php if ($order['discount'] > 0): ?><div class="summary-row text-brand"><span>Discount</span><span>−<?= e(money($order['discount'])) ?></span></div><?php endif; ?>
  <?php if ($order['tax'] > 0): ?><div class="summary-row"><span>Tax</span><span><?= e(money($order['tax'])) ?></span></div><?php endif; ?>
  <div class="summary-row total"><span>Total</span><span><?= e(money($order['total'])) ?></span></div>
</div>
