    </div><!-- /admin-content -->
  </div><!-- /admin-main -->
</div><!-- /admin-shell -->
<div class="position-fixed bottom-0 end-0 p-3 d-flex flex-column gap-2" data-toast-stack style="z-index:1090"></div>
<script src="<?= e(asset('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
<script src="<?= e(asset('assets/js/admin-images.js')) ?>"></script>
<script src="<?= e(asset('assets/js/admin-hero.js')) ?>"></script>
<script nonce="<?= e(Security::nonce()) ?>">
  (function () {
    var toggle = document.querySelector('[data-admin-toggle]');
    var sidebar = document.querySelector('[data-admin-sidebar]');
    if (toggle && sidebar) {
      toggle.addEventListener('click', function () { sidebar.classList.toggle('open'); });
      document.addEventListener('click', function (event) {
        if (window.innerWidth < 992 && sidebar.classList.contains('open')
            && !sidebar.contains(event.target) && !toggle.contains(event.target)) {
          sidebar.classList.remove('open');
        }
      });
    }
  })();
</script>
<?php if (!empty($adminInlineScript)): ?>
<script nonce="<?= e(Security::nonce()) ?>"><?= $adminInlineScript ?></script>
<?php endif; ?>
</body>
</html>
