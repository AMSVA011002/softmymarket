<?php
/**
 * Team accounts: store staff and administrators.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
admin_require('users.manage');

$team = Users::team();
$editing = query_int('edit') > 0 ? Users::find(query_int('edit')) : null;
$returnUrl = admin_url('team.php');

$adminTitle = 'Team';
$adminSubtitle = count($team) . ' staff and administrator account' . (count($team) === 1 ? '' : 's');
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-soft p-0">
      <div class="px-3 pt-3 pb-2 d-flex flex-wrap gap-2 align-items-center">
        <h2 class="h6 fw-bold mb-0">People</h2>
        <input class="form-control form-control-sm ms-auto" style="max-width:240px" type="search"
               placeholder="Filter by name or email" data-live-client="#team-table"
               data-live-empty="#team-empty" data-live-count="#team-count"
               aria-label="Filter team" autocomplete="off">
        <span class="live-search-note" id="team-count"></span>
      </div>
      <div class="table-responsive" id="team-table">
        <table class="table table-admin mb-0">
          <thead><tr><th>Member</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($team as $member): ?>
              <tr data-filter-row>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <span class="logo-mark" style="width:32px;height:32px;font-size:.65rem"><?= e(initials((string) $member['name'], 2)) ?></span>
                    <div>
                      <div class="fw-semibold"><?= e($member['name']) ?><?= (int) $member['id'] === (int) Auth::id() ? ' <span class="badge text-bg-light">you</span>' : '' ?></div>
                      <div class="text-ink-400" style="font-size:.68rem"><?= e($member['email']) ?></div>
                    </div>
                  </div>
                </td>
                <td><span class="badge text-bg-<?= $member['role'] === 'admin' ? 'success' : 'info' ?>"><?= e(role_label((string) $member['role'])) ?></span></td>
                <td><span class="badge text-bg-<?= $member['status'] === 'active' ? 'light' : 'secondary' ?>"><?= e(humanize((string) $member['status'])) ?></span></td>
                <td class="small text-ink-500"><?= !empty($member['last_login_at']) ? e(time_ago((string) $member['last_login_at'])) : 'Never' ?></td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-sm btn-soft" href="<?= e(admin_url('team.php', ['edit' => (int) $member['id']])) ?>"><i class="bi bi-pencil"></i></a>
                  <?php if ((int) $member['id'] !== (int) Auth::id()): ?>
                    <form method="post" action="/admin/actions.php" class="d-inline" data-confirm="Suspend this team account?">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="team_delete">
                      <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                      <button class="btn btn-sm btn-soft" type="submit" title="Suspend"><i class="bi bi-slash-circle"></i></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <p class="small text-ink-500 mt-2 mb-0"><i class="bi bi-info-circle me-1"></i> Store staff can manage products, stock, orders and customers. Only administrators can change settings, coupons and team accounts.</p>
  </div>

  <div class="col-lg-5">
    <form class="card-soft p-3" method="post" action="/admin/actions.php" autocomplete="off">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="team_save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 fw-bold mb-0"><?= $editing === null ? 'Invite a team member' : 'Edit ' . e((string) $editing['name']) ?></h2>
        <?php if ($editing !== null): ?><a class="small" href="<?= e($returnUrl) ?>">Cancel</a><?php endif; ?>
      </div>
      <label class="form-label small" for="t-name">Full name *</label>
      <input class="form-control form-control-sm mb-2" id="t-name" name="name" value="<?= e($editing['name'] ?? '') ?>" required maxlength="120">
      <label class="form-label small" for="t-email">Email *</label>
      <input class="form-control form-control-sm mb-2" id="t-email" type="email" name="email" value="<?= e($editing['email'] ?? '') ?>" required maxlength="160">
      <label class="form-label small" for="t-role">Role</label>
      <select class="form-select form-select-sm mb-2" id="t-role" name="role">
        <?php foreach (['staff' => 'Store staff', 'admin' => 'Administrator'] as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= ($editing['role'] ?? 'staff') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="d-flex justify-content-between align-items-center">
        <label class="form-label small" for="t-password"><?= $editing === null ? 'Temporary password *' : 'New password' ?></label>
        <button class="btn btn-link btn-sm p-0 small" type="button" data-toggle-password="t-password">Show</button>
      </div>
      <input class="form-control form-control-sm mb-1" id="t-password" type="password" name="password" autocomplete="new-password" <?= $editing === null ? 'required' : '' ?> minlength="8">
      <div class="form-text mb-3">At least 8 characters with letters and numbers. <?= $editing !== null ? 'Leave blank to keep the current password.' : 'Share it securely; they can change it after signing in.' ?></div>
      <button class="btn btn-brand btn-sm w-100" type="submit"><?= $editing === null ? 'Create team account' : 'Save changes' ?></button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
