<?php
/**
 * Store settings: general content, commerce rules, payment gateways, hero slides.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_require('settings.manage');

$tab = (string) query('tab', 'general');
$groups = admin_setting_groups();
$gatewayFields = admin_gateway_fields();
$gatewayConfig = admin_gateway_config();
$slides = View::heroSlides();
$returnUrl = admin_url('settings.php', ['tab' => $tab]);
$storeUrlParts = parse_url(url('/')) ?: [];
$storeOrigin = (string) ($storeUrlParts['scheme'] ?? 'https') . '://' . (string) ($storeUrlParts['host'] ?? '');
if (isset($storeUrlParts['port'])) {
    $storeOrigin .= ':' . (int) $storeUrlParts['port'];
}

/** Render one settings input. */
$renderField = static function (string $key, array $meta): string {
    $value = Settings::get($key, (string) $meta['default']);
    $id = 'set-' . preg_replace('/[^a-z0-9_-]/i', '-', $key);
    $label = '<label class="form-label small mb-1" for="' . e($id) . '">' . e($meta['label']) . '</label>';
    $help = !empty($meta['help']) ? '<div class="form-text">' . e($meta['help']) . '</div>' : '';
    $name = 'settings[' . $key . ']';
    // Image fields get a live preview and the same Drive/URL rules as product
    // images (assets/js/media-url.js does the browser side, this is the fallback
    // markup when JS is off — the value is normalised again on save).
    if ($meta['type'] === 'image') {
        $normalised = normalize_image_url((string) $value);
        $preview = $normalised !== null
            ? '<img src="' . e(img_url($normalised, $meta['label'], 160)) . '" alt="">'
            : '<i class="bi bi-image"></i>';
        $statusText = $normalised !== null
            ? (is_drive_url($normalised) ? 'Google Drive — proxied and cached.' : 'Image link — proxied and cached.')
            : 'No image selected.';
        $control = '<div class="d-flex gap-2 align-items-start" data-media-row data-media-width="160">'
            . '<span class="image-slot-preview is-wide' . ($normalised !== null ? ' has-image' : '') . '" data-media-preview aria-hidden="true">'
            . $preview . '</span>'
            . '<div class="flex-grow-1 min-w-0">'
            . '<input class="form-control form-control-sm" id="' . e($id) . '" name="' . e($name) . '" value="' . e((string) $value) . '"'
            . ' placeholder="https://drive.google.com/file/d/FILE_ID/view" data-media-input autocomplete="off">'
            . '<div class="form-text" data-media-status>' . e($statusText) . '</div>'
            . '</div></div>';
        return $label . $control . $help;
    }

    $control = match ($meta['type']) {
        'textarea' => '<textarea class="form-control form-control-sm" id="' . e($id) . '" name="' . e($name) . '" rows="3">' . e($value) . '</textarea>',
        'bool' => '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"' . ((int) $value === 1 ? ' checked' : '') . '><label class="form-check-label small" for="' . e($id) . '">Enabled</label></div>',
        'number' => '<input class="form-control form-control-sm" id="' . e($id) . '" name="' . e($name) . '" type="number" step="0.01" value="' . e($value) . '">',
        'color' => '<input class="form-control form-control-sm form-control-color" id="' . e($id) . '" name="' . e($name) . '" type="color" value="' . e($value) . '">',
        'email' => '<input class="form-control form-control-sm" id="' . e($id) . '" name="' . e($name) . '" type="email" value="' . e($value) . '">',
        default => '<input class="form-control form-control-sm" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '">',
    };
    return $label . $control . $help;
};

$adminTitle = 'Settings';
$adminSubtitle = 'Branding, commerce rules, payment providers and page content';
$adminActions = '<a class="btn btn-sm btn-soft" href="/" target="_blank" rel="noopener"><i class="bi bi-shop me-1"></i> View store</a>';
require __DIR__ . '/includes/layout-top.php';
?>
<ul class="nav nav-tabs mb-3 flex-wrap">
  <?php
  $tabLabels = ['general' => 'Store', 'commerce' => 'Commerce', 'content' => 'Content', 'payments' => 'Payments', 'hero' => 'Hero slides'];
  foreach ($tabLabels as $value => $label): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $value ? 'active' : '' ?>" href="<?= e(admin_url('settings.php', ['tab' => $value])) ?>"><?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'payments'): ?>
  <form method="post" action="/admin/actions.php" data-payhere-settings>
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="gateway_save">
    <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
    <?php foreach ($gatewayFields as $gatewayKey => $gatewayMeta): $current = (array) ($gatewayConfig[$gatewayKey] ?? []); ?>
      <div class="card-soft p-3 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <h2 class="h6 fw-bold mb-0"><i class="bi <?= e($gatewayMeta['icon']) ?> me-1"></i> <?= e($gatewayMeta['label']) ?></h2>
          <span class="badge text-bg-<?= !empty($current['enabled']) ? 'success' : 'secondary' ?>"><?= !empty($current['enabled']) ? 'Enabled' : 'Disabled' ?></span>
        </div>
        <?php if ($gatewayKey === 'payhere'): ?>
          <div class="alert alert-warning small py-2 px-3 mb-3">
            In the PayHere Merchant Portal, keep one Integration record for this store origin: <code><?= e($storeOrigin) ?></code>. Use the Merchant Secret issued for that same domain. Do not add duplicate domain records. The site sends a Referer origin on the cross-domain checkout request; do not override it with a no-referrer policy.
          </div>
        <?php endif; ?>
        <div class="row g-2">
          <?php foreach ($gatewayMeta['fields'] as $fieldKey => $fieldMeta): $fieldId = 'gw-' . $gatewayKey . '-' . $fieldKey; $value = $current[$fieldKey] ?? $fieldMeta['default']; ?>
            <div class="<?= in_array($fieldMeta['type'], ['textarea', 'bool'], true) ? 'col-12' : 'col-md-6 col-lg-4' ?>">
              <?php if ($fieldMeta['type'] === 'bool'): ?>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="<?= e($fieldId) ?>" name="gateway[<?= e($gatewayKey) ?>][<?= e($fieldKey) ?>]" value="1" <?= !empty($value) ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="<?= e($fieldId) ?>"><?= e($fieldMeta['label']) ?></label>
                </div>
              <?php else: ?>
                <label class="form-label small mb-1" for="<?= e($fieldId) ?>"><?= e($fieldMeta['label']) ?></label>
                <?php if ($fieldMeta['type'] === 'select'): ?>
                  <select class="form-select form-select-sm" id="<?= e($fieldId) ?>" name="gateway[<?= e($gatewayKey) ?>][<?= e($fieldKey) ?>]" <?= $gatewayKey === 'payhere' && $fieldKey === 'mode' ? 'data-payhere-mode' : '' ?>>
                    <?php foreach ((array) ($fieldMeta['options'] ?? []) as $optionValue => $optionLabel): ?>
                      <option value="<?= e((string) $optionValue) ?>" <?= (string) $value === (string) $optionValue ? 'selected' : '' ?>><?= e((string) $optionLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php if ($gatewayKey === 'payhere' && $fieldKey === 'mode'): ?>
                    <div class="alert <?= $value === 'live' ? 'alert-danger' : 'alert-info' ?> small py-2 px-3 mt-2 mb-0" data-payhere-mode-notice role="status">
                      <?= $value === 'live'
                          ? 'LIVE mode: real customer charges may occur. The checkout URL is fixed to https://www.payhere.lk/pay/checkout. Use the live Merchant ID and the secret for this exact store domain only after sandbox testing.'
                          : 'SANDBOX mode: checkout uses https://sandbox.payhere.lk/pay/checkout. Use PayHere sandbox credentials for development; no real charge is made.' ?>
                    </div>
                  <?php endif; ?>
                <?php elseif ($fieldMeta['type'] === 'textarea'): ?>
                  <textarea class="form-control form-control-sm" id="<?= e($fieldId) ?>" name="gateway[<?= e($gatewayKey) ?>][<?= e($fieldKey) ?>]" rows="2"><?= e((string) $value) ?></textarea>
                <?php elseif ($fieldMeta['type'] === 'password'): ?>
                  <input class="form-control form-control-sm" id="<?= e($fieldId) ?>" type="password" autocomplete="new-password" name="gateway[<?= e($gatewayKey) ?>][<?= e($fieldKey) ?>]" value="" placeholder="<?= (string) $value !== '' ? '•••••• saved — leave blank to keep' : 'Not set' ?>">
                <?php elseif ($fieldMeta['type'] === 'number'): ?>
                  <input class="form-control form-control-sm" id="<?= e($fieldId) ?>" type="number" step="0.01" name="gateway[<?= e($gatewayKey) ?>][<?= e($fieldKey) ?>]" value="<?= e((string) $value) ?>">
                <?php else: ?>
                  <input class="form-control form-control-sm" id="<?= e($fieldId) ?>" name="gateway[<?= e($gatewayKey) ?>][<?= e($fieldKey) ?>]" value="<?= e((string) $value) ?>" <?= $fieldKey === 'secret' || $fieldKey === 'api_key' ? 'placeholder="' . ((string) $value !== '' ? 'Saved — leave blank to keep' : 'Not set') . '"' : '' ?>>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($gatewayKey === 'bank_transfer'): $accounts = (array) ($current['accounts'] ?? []); ?>
          <hr>
          <h3 class="h6 fw-bold mb-2">Bank accounts shown to customers</h3>
          <div data-bank-rows>
            <?php foreach (array_merge($accounts, [['bank' => '', 'branch' => '', 'name' => '', 'number' => '']]) as $account): ?>
              <div class="row g-2 mb-2" data-bank-row>
                <div class="col-6 col-lg-3"><input class="form-control form-control-sm" name="bank[bank][]" value="<?= e((string) ($account['bank'] ?? '')) ?>" placeholder="Bank"></div>
                <div class="col-6 col-lg-2"><input class="form-control form-control-sm" name="bank[branch][]" value="<?= e((string) ($account['branch'] ?? '')) ?>" placeholder="Branch"></div>
                <div class="col-6 col-lg-3"><input class="form-control form-control-sm" name="bank[name][]" value="<?= e((string) ($account['name'] ?? '')) ?>" placeholder="Account name"></div>
                <div class="col-6 col-lg-3"><input class="form-control form-control-sm" name="bank[number][]" value="<?= e((string) ($account['number'] ?? '')) ?>" placeholder="Account number"></div>
                <div class="col-12 col-lg-1"><button class="btn btn-sm btn-soft w-100" type="button" data-bank-remove><i class="bi bi-x"></i></button></div>
              </div>
            <?php endforeach; ?>
          </div>
          <button class="btn btn-sm btn-soft" type="button" data-bank-add><i class="bi bi-plus-lg"></i> Add account</button>
        <?php endif; ?>

        <p class="small text-ink-400 mt-2 mb-0">
          Callback URL: <code><?= e(url('/payment/notify.php?gateway=' . $gatewayKey)) ?></code> ·
          <?php if ($gatewayKey === 'payhere'): ?>Return URL is generated securely per order during checkout.<?php else: ?>Return URL: <code><?= e(url('/payment/return.php?gateway=' . $gatewayKey)) ?></code><?php endif; ?>
        </p>
      </div>
    <?php endforeach; ?>
    <div class="d-flex gap-2">
      <button class="btn btn-brand" type="submit"><i class="bi bi-check2 me-1"></i> Save payment settings</button>
      <a class="btn btn-soft" href="<?= e(admin_url('settings.php', ['tab' => 'payments'])) ?>">Discard changes</a>
    </div>
  </form>

<?php elseif ($tab === 'hero'): ?>
  <?php
  /*
   * Homepage hero carousel editor.
   *
   * Every slide shows an image (a Google Drive share link or any https image
   * URL — the same rules as product images) instead of the old decorative icon.
   * Slides can be added (JS clones the template below; without JS the
   * "Add slide" link re-renders this tab with one more empty card) and removed
   * (tick the checkbox — the slide is skipped when the form is saved).
   */
  $heroLimit = View::HERO_SLIDE_LIMIT;
  $requestedCards = max(0, min($heroLimit, (int) (query('slides', '0') ?? '0')));
  $heroCards = array_slice($slides, 0, $heroLimit);
  while (count($heroCards) < $requestedCards) {
      $heroCards[] = [
          'eyebrow' => '', 'title' => '', 'highlight' => '', 'description' => '',
          'cta_label' => '', 'cta_url' => '', 'secondary_label' => '', 'secondary_url' => '',
          'theme' => 'green', 'image' => '',
      ];
  }
  $canAdd = count($heroCards) < $heroLimit;
  $addUrl = admin_url('settings.php', ['tab' => 'hero', 'slides' => count($heroCards) + 1]);
  ?>

  <div class="card-soft p-3 mb-3">
    <div class="d-flex flex-wrap gap-2 align-items-start justify-content-between">
      <div>
        <h2 class="h6 fw-bold mb-1"><i class="bi bi-images me-1"></i> Hero carousel slides</h2>
        <p class="small text-ink-500 mb-0">
          Each slide takes an <strong>image</strong> — paste a Google Drive share link
          (<code>drive.google.com/file/d/…/view</code>) or any <code>https</code> image URL.
          Leave the image empty for a text-only slide. Up to <?= (int) $heroLimit ?> slides.
        </p>
      </div>
      <span class="badge text-bg-light" data-hero-count><?= count($heroCards) ?> / <?= (int) $heroLimit ?> slides</span>
    </div>
  </div>

  <form method="post" action="/admin/actions.php" data-hero-slides>
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="hero_slides_save">
    <input type="hidden" name="return" value="<?= e($returnUrl) ?>">

    <div data-hero-slide-list>
      <?php foreach ($heroCards as $index => $slide): $n = $index + 1; ?>
        <div class="card-soft p-3 mb-3 hero-slide-card" data-hero-slide>
          <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
            <h3 class="h6 fw-bold mb-0">Slide <span data-hero-number><?= $n ?></span></h3>
            <div class="form-check m-0">
              <input class="form-check-input" type="checkbox" name="slide_delete[]" value="<?= $index ?>"
                     id="s<?= $n ?>-delete" data-hero-delete>
              <label class="form-check-label small text-danger" for="s<?= $n ?>-delete">
                <i class="bi bi-trash3 me-1"></i>Remove this slide
              </label>
            </div>
          </div>
          <div class="row g-2">
            <div class="col-12">
              <label class="form-label small" for="s<?= $n ?>-image">Slide image</label>
              <div class="d-flex gap-2 align-items-start" data-image-row>
                <span class="image-slot-preview is-wide" data-image-preview aria-hidden="true"><i class="bi bi-image"></i></span>
                <div class="flex-grow-1 min-w-0">
                  <input class="form-control form-control-sm" id="s<?= $n ?>-image" name="slide_image[]"
                         value="<?= e((string) ($slide['image'] ?? '')) ?>" data-image-input
                         placeholder="https://drive.google.com/file/d/FILE_ID/view">
                  <div class="form-text" data-image-status>Google Drive link or any https image URL.</div>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label small" for="s<?= $n ?>-eyebrow">Eyebrow</label>
              <input class="form-control form-control-sm" id="s<?= $n ?>-eyebrow" name="slide_eyebrow[]" value="<?= e((string) ($slide['eyebrow'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small" for="s<?= $n ?>-title">Headline <span class="text-danger">*</span></label>
              <input class="form-control form-control-sm" id="s<?= $n ?>-title" name="slide_title[]" value="<?= e((string) ($slide['title'] ?? '')) ?>" data-hero-title>
            </div>
            <div class="col-md-4">
              <label class="form-label small" for="s<?= $n ?>-highlight">Highlighted line</label>
              <input class="form-control form-control-sm" id="s<?= $n ?>-highlight" name="slide_highlight[]" value="<?= e((string) ($slide['highlight'] ?? '')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label small" for="s<?= $n ?>-description">Description</label>
              <textarea class="form-control form-control-sm" id="s<?= $n ?>-description" name="slide_description[]" rows="2"><?= e((string) ($slide['description'] ?? '')) ?></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label small" for="s<?= $n ?>-ctalabel">Button label</label>
              <input class="form-control form-control-sm" id="s<?= $n ?>-ctalabel" name="slide_cta_label[]" value="<?= e((string) ($slide['cta_label'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small" for="s<?= $n ?>-ctaurl">Button link</label>
              <input class="form-control form-control-sm" id="s<?= $n ?>-ctaurl" name="slide_cta_url[]" value="<?= e((string) ($slide['cta_url'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small" for="s<?= $n ?>-theme">Theme</label>
              <select class="form-select form-select-sm" id="s<?= $n ?>-theme" name="slide_theme[]">
                <?php foreach (['green' => 'Brand green', 'blue' => 'Ocean blue', 'light' => 'Light'] as $value => $label): ?>
                  <option value="<?= e($value) ?>" <?= ($slide['theme'] ?? 'green') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small" for="s<?= $n ?>-secondarylabel">Second button label</label>
              <input class="form-control form-control-sm" id="s<?= $n ?>-secondarylabel" name="slide_secondary_label[]" value="<?= e((string) ($slide['secondary_label'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small" for="s<?= $n ?>-secondaryurl">Second button link</label>
              <input class="form-control form-control-sm" id="s<?= $n ?>-secondaryurl" name="slide_secondary_url[]" value="<?= e((string) ($slide['secondary_url'] ?? '')) ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <span class="form-text mb-1">Image shows on the right of the slide.</span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <template id="hero-slide-template">
      <div class="card-soft p-3 mb-3 hero-slide-card" data-hero-slide>
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
          <h3 class="h6 fw-bold mb-0">Slide <span data-hero-number>__NUMBER__</span></h3>
          <div class="form-check m-0">
            <input class="form-check-input" type="checkbox" name="slide_delete[]" value="__INDEX__"
                   id="s__INDEX__-delete" data-hero-delete>
            <label class="form-check-label small text-danger" for="s__INDEX__-delete">
              <i class="bi bi-trash3 me-1"></i>Remove this slide
            </label>
          </div>
        </div>
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label small" for="s__INDEX__-image">Slide image</label>
            <div class="d-flex gap-2 align-items-start" data-image-row>
              <span class="image-slot-preview is-wide" data-image-preview aria-hidden="true"><i class="bi bi-image"></i></span>
              <div class="flex-grow-1 min-w-0">
                <input class="form-control form-control-sm" id="s__INDEX__-image" name="slide_image[]" value=""
                       data-image-input placeholder="https://drive.google.com/file/d/FILE_ID/view">
                <div class="form-text" data-image-status>Google Drive link or any https image URL.</div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label small" for="s__INDEX__-eyebrow">Eyebrow</label>
            <input class="form-control form-control-sm" id="s__INDEX__-eyebrow" name="slide_eyebrow[]" value="">
          </div>
          <div class="col-md-4">
            <label class="form-label small" for="s__INDEX__-title">Headline <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" id="s__INDEX__-title" name="slide_title[]" value="" data-hero-title>
          </div>
          <div class="col-md-4">
            <label class="form-label small" for="s__INDEX__-highlight">Highlighted line</label>
            <input class="form-control form-control-sm" id="s__INDEX__-highlight" name="slide_highlight[]" value="">
          </div>
          <div class="col-12">
            <label class="form-label small" for="s__INDEX__-description">Description</label>
            <textarea class="form-control form-control-sm" id="s__INDEX__-description" name="slide_description[]" rows="2"></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label small" for="s__INDEX__-ctalabel">Button label</label>
            <input class="form-control form-control-sm" id="s__INDEX__-ctalabel" name="slide_cta_label[]" value="">
          </div>
          <div class="col-md-4">
            <label class="form-label small" for="s__INDEX__-ctaurl">Button link</label>
            <input class="form-control form-control-sm" id="s__INDEX__-ctaurl" name="slide_cta_url[]" value="">
          </div>
          <div class="col-md-4">
            <label class="form-label small" for="s__INDEX__-theme">Theme</label>
            <select class="form-select form-select-sm" id="s__INDEX__-theme" name="slide_theme[]">
              <option value="green">Brand green</option>
              <option value="blue">Ocean blue</option>
              <option value="light">Light</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label small" for="s__INDEX__-secondarylabel">Second button label</label>
            <input class="form-control form-control-sm" id="s__INDEX__-secondarylabel" name="slide_secondary_label[]" value="">
          </div>
          <div class="col-md-4">
            <label class="form-label small" for="s__INDEX__-secondaryurl">Second button link</label>
            <input class="form-control form-control-sm" id="s__INDEX__-secondaryurl" name="slide_secondary_url[]" value="">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <span class="form-text mb-1">Image shows on the right of the slide.</span>
          </div>
        </div>
      </div>
    </template>

    <div class="d-flex flex-wrap gap-2 align-items-center">
      <button class="btn btn-brand" type="submit"><i class="bi bi-check2 me-1"></i> Save hero slides</button>
      <?php if ($canAdd): ?>
        <a class="btn btn-soft" href="<?= e($addUrl) ?>" data-hero-add data-hero-limit="<?= (int) $heroLimit ?>">
          <i class="bi bi-plus-lg me-1"></i> Add slide
        </a>
      <?php else: ?>
        <span class="small text-ink-500">Slide limit reached (<?= (int) $heroLimit ?>). Remove one to add another.</span>
      <?php endif; ?>
      <a class="btn btn-soft" href="<?= e(admin_url('settings.php', ['tab' => 'hero'])) ?>">Reset</a>
      <span class="small text-ink-400 ms-auto">Ticked slides are deleted when you save.</span>
    </div>
  </form>

<?php else: ?>
  <?php $group = $groups[$tab] ?? $groups['general']; ?>
  <form method="post" action="/admin/actions.php">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="settings_save">
    <input type="hidden" name="group" value="<?= e($tab) ?>">
    <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
    <?php if ($tab === 'commerce' && Settings::bool('maintenance_mode')): ?>
      <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="status">
        <i class="bi bi-cone-striped"></i>
        <span class="flex-grow-1"><strong>Maintenance mode is ON.</strong> Visitors see a holding page (503) instead of the shop. You stay signed in, so this dashboard works normally.</span>
        <a class="btn btn-sm btn-soft" href="/maintenance.php" target="_blank" rel="noopener">
          <i class="bi bi-eye me-1"></i> Preview the holding page
        </a>
      </div>
    <?php endif; ?>
    <div class="card-soft p-3">
      <div class="row g-3">
        <?php foreach ($group as $key => $meta): ?>
          <div class="<?= in_array($meta['type'], ['textarea', 'image'], true) ? 'col-12' : 'col-md-6' ?>">
            <?= $renderField($key, $meta) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <button class="btn btn-brand mt-3" type="submit"><i class="bi bi-check2 me-1"></i> Save settings</button>
  </form>
<?php endif; ?>

<?php
$adminInlineScript = <<<'JS'
(function () {
  var rows = document.querySelector('[data-bank-rows]');
  var add = document.querySelector('[data-bank-add]');
  var payHereForm = document.querySelector('[data-payhere-settings]');
  var payHereMode = document.querySelector('[data-payhere-mode]');
  var payHereNotice = document.querySelector('[data-payhere-mode-notice]');
  if (payHereForm && payHereMode && payHereNotice) {
    var syncPayHereMode = function () {
      var live = payHereMode.value === 'live';
      payHereNotice.classList.toggle('alert-danger', live);
      payHereNotice.classList.toggle('alert-info', !live);
      payHereNotice.textContent = live
        ? 'LIVE mode: real customer charges may occur. The checkout URL is fixed to https://www.payhere.lk/pay/checkout. Use the live Merchant ID and the secret for this exact store domain only after sandbox testing.'
        : 'SANDBOX mode: checkout uses https://sandbox.payhere.lk/pay/checkout. Use PayHere sandbox credentials for development; no real charge is made.';
    };
    payHereMode.addEventListener('change', function () {
      if (payHereMode.value === 'live' && !window.confirm('Switch PayHere to LIVE mode? Customers can make real payments and be charged.')) {
        payHereMode.value = 'sandbox';
      }
      syncPayHereMode();
    });
    payHereForm.addEventListener('submit', function (event) {
      if (payHereMode.value === 'live' && !window.confirm('Save PayHere in LIVE mode? This will send customer payments to PayHere live checkout.')) {
        event.preventDefault();
      }
    });
  }
  if (add && rows) {
    add.addEventListener('click', function () {
      var row = rows.querySelector('[data-bank-row]').cloneNode(true);
      row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
      rows.appendChild(row);
    });
  }
  document.addEventListener('click', function (event) {
    var remove = event.target.closest('[data-bank-remove]');
    if (!remove) return;
    var row = remove.closest('[data-bank-row]');
    var list = document.querySelector('[data-bank-rows]');
    if (row && list && list.querySelectorAll('[data-bank-row]').length > 1) row.remove();
  });
})();
JS;
require __DIR__ . '/includes/layout-bottom.php';
?>
