<?php
/** @var string $heroTitle */
/** @var string|null $heroSubtitle */
/** @var string|null $heroIcon */
/** @var array<string,string|null> $heroCrumbs */
?>
<div class="breadcrumb-wrap"><div class="container-page"><?= View::breadcrumbs($heroCrumbs ?? [$heroTitle => null]) ?></div></div>
<section class="section pb-0">
  <div class="container-page">
    <div class="card-soft p-3 p-md-4" style="background:linear-gradient(120deg,#ecfdf0,#fff 55%,#eff6ff)">
      <div class="d-flex align-items-center gap-3">
        <span class="icon" style="width:52px;height:52px;border-radius:16px;display:grid;place-items:center;background:#fff;color:var(--brand-600);font-size:1.4rem;flex-shrink:0">
          <i class="bi <?= e($heroIcon ?? 'bi-info-circle') ?>"></i>
        </span>
        <div>
          <h1 class="h4 fw-bold mb-1"><?= e($heroTitle) ?></h1>
          <?php if (!empty($heroSubtitle)): ?><p class="small text-ink-600 mb-0"><?= e($heroSubtitle) ?></p><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>
