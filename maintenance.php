<?php
/**
 * Maintenance holding page.
 *
 * Normally rendered automatically by includes/bootstrap.php when
 * Settings → Commerce → Maintenance mode is on. It is also a real URL so the
 * dashboard can link to a preview ("see what customers see") and so a
 * bookmarked link keeps working after the store is back up.
 */
declare(strict_types=1);

// Tell the bootstrap this request is the holding page itself, so the gate does
// not recurse into it.
define('SMM_MAINTENANCE_PAGE', true);

require_once __DIR__ . '/includes/bootstrap.php';
// The gate skips itself for this page, so load the renderer explicitly.
require_once INCLUDES_PATH . '/maintenance.php';

if (!Settings::bool('maintenance_mode')) {
    // Store is open: send people where they wanted to go instead of showing a
    // stale holding page.
    redirect('/');
}

smm_maintenance_page();
