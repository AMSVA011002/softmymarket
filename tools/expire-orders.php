<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$cancelled = OrderService::expireOverdueBankTransfers();
$expiredGatewayAttempts = OrderService::expireStaleGatewayAttempts();

printf("Cancelled %d overdue bank-transfer order(s); released %d stale online checkout(s).\n", $cancelled, $expiredGatewayAttempts);
