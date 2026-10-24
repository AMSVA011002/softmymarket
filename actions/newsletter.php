<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('/');
}
Csrf::verifyRequest();
RateLimiter::guard('newsletter');

$email = strtolower(trim((string) post('email', '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('warning', 'Please enter a valid email address.');
    back();
}

try {
    Database::run(
        'INSERT INTO newsletter_subscribers (email, source, created_at) VALUES (:email, :source, :now)
         ON DUPLICATE KEY UPDATE source = :source2',
        ['email' => $email, 'source' => 'footer', 'now' => date('Y-m-d H:i:s'), 'source2' => 'footer']
    );
    flash('success', 'You are subscribed — watch your inbox for the next drop.');
} catch (Throwable $e) {
    error_log('Newsletter signup failed: ' . $e->getMessage());
    flash('danger', 'We could not save your subscription. Please try again.');
}
back();
