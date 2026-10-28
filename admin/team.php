<?php
/**
 * Team accounts: store staff and administrators.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_require('users.manage');

$team = Users::team();
$editing = query_int('edit') > 0 ? Users::find(query_int('edit')) : null;
$returnUrl = admin_url('team.php');

$adminTitle = 'Team';
$activeTeam = count(array_filter($team, static fn ($member) => $member['status'] === 'active'));
$suspendedTeam = count($team) - $activeTeam;
$adminSubtitle = count($team) . ' staff and administrator account' . (count($team) === 1 ? '' : 's')
    . ($suspendedTeam > 0 ? ' · ' . $suspendedTeam . ' suspended' : '');
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
                  <a class="btn btn-sm btn-soft" href="<?= e(admin_url('team.php', ['edit' => (int) $member['id']])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                  <?php if ((int) $member['id'] === (int) Auth::id()): ?>
                    <span class="badge text-bg-light ms-1">current session</span>
                  <?php elseif ($member['status'] === 'suspended'): ?>
                    <form method="post" action="/admin/actions.php" class="d-inline">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="team_reactivate">
                      <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                      <button class="btn btn-sm btn-success" type="submit" title="Reactivate — they can sign in again"><i class="bi bi-arrow-counterclockwise"></i> Reactivate</button>
                    </form>
                    <form method="post" action="/admin/actions.php" class="d-inline" data-confirm="Delete <?= e($member['name']) ?> permanently? This cannot be undone; their orders and audit trail are kept.">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="team_delete">
                      <input type="hidden" name="confirm_delete" value="1">
                      <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                      <button class="btn btn-sm btn-soft text-danger" type="submit" title="Delete permanently"><i class="bi bi-trash3"></i></button>
                    </form>
                  <?php else: ?>
                    <form method="post" action="/admin/actions.php" class="d-inline" data-confirm="Suspend <?= e($member['name']) ?>? They will not be able to sign in until you reactivate the account.">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="team_suspend">
                      <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                      <button class="btn btn-sm btn-soft" type="submit" title="Suspend"><i class="bi bi-slash-circle"></i></button>
                    </form>
                    <form method="post" action="/admin/actions.php" class="d-inline" data-confirm="Delete <?= e($member['name']) ?> permanently? This cannot be undone; their orders and audit trail are kept.">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="team_delete">
                      <input type="hidden" name="confirm_delete" value="1">
                      <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                      <button class="btn btn-sm btn-soft text-danger" type="submit" title="Delete permanently"><i class="bi bi-trash3"></i></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <p class="small text-ink-500 mt-2 mb-0"><i class="bi bi-info-circle me-1"></i> <strong>Suspend</strong> blocks sign-in but keeps the account and its history — <strong>Reactivate</strong> brings it back. <strong>Delete</strong> removes the account permanently; their orders and audit entries stay intact.</p>
    <p class="small text-ink-500 mb-0">Store staff can manage products, stock, orders and customers. Only administrators can change settings, coupons and team accounts.</p>
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
      <?php if ($editing !== null && $editing['status'] === 'suspended'): ?>
        <div class="alert alert-warning small py-2 px-3 d-flex flex-wrap align-items-center gap-2">
          <i class="bi bi-slash-circle"></i>
          <span class="flex-grow-1">This account is <strong>suspended</strong> — saving keeps it blocked.</span>
          <form method="post" action="/admin/actions.php" class="m-0">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="team_reactivate">
            <input type="hidden" name="user_id" value="<?= (int) $editing['id'] ?>">
            <input type="hidden" name="return" value="<?= e($returnUrl . '&edit=' . (int) $editing['id']) ?>">
            <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i>Reactivate</button>
          </form>
        </div>
      <?php endif; ?>
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
      <?php if ($editing === null): ?>
        <div class="alert alert-info small py-2 px-3 mb-3"><i class="bi bi-envelope me-1"></i> We’ll email the selected role and a temporary password to this address. The team member must set a new password at first sign-in.</div>
      <?php else: ?>
        <div class="form-text mb-3">Role and contact details can be updated here. Password changes are made by the account holder after signing in.</div>
      <?php endif; ?>
      <button class="btn btn-brand btn-sm w-100" type="submit"><?= $editing === null ? 'Send invitation' : 'Save changes' ?></button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
