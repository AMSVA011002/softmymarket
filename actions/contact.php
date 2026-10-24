<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('/contact.php');
}
Csrf::verifyRequest();
RateLimiter::guard('contact');

$name = trim((string) post('name', ''));
$email = strtolower(trim((string) post('email', '')));
$phone = trim((string) post('phone', ''));
$subject = trim((string) post('subject', ''));
$message = trim((string) post('message', ''));
$topic = trim((string) post('topic', 'general'));

$errors = [];
if (strlen($name) < 2) {
    $errors[] = 'Please tell us your name.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}
if (strlen($message) < 10) {
    $errors[] = 'Please include a little more detail in your message.';
}
if ($errors !== []) {
    remember_input(['name' => $name, 'email' => $email, 'phone' => $phone, 'subject' => $subject, 'message' => $message]);
    flash('danger', implode(' ', $errors));
    redirect('/contact.php?topic=' . rawurlencode($topic));
}

Database::insert('contact_messages', [
    'name' => substr($name, 0, 120),
    'email' => substr($email, 0, 160),
    'phone' => $phone !== '' ? substr($phone, 0, 30) : null,
    'subject' => substr($subject !== '' ? $subject : humanize($topic), 0, 160),
    'message' => substr($message, 0, 4000),
    'topic' => substr($topic, 0, 40),
    'status' => 'new',
    'ip_address' => Security::clientIp(),
    'created_at' => date('Y-m-d H:i:s'),
]);

try {
    Mailer::contactNotification(['name' => $name, 'email' => $email, 'phone' => $phone, 'subject' => $subject, 'message' => $message]);
} catch (Throwable $e) {
    error_log('Contact notification failed: ' . $e->getMessage());
}
Notifier::notifyTeam('New contact message', $name . ': ' . limit_text($message, 100), 'info', '/admin/messages.php');
Audit::log('contact.message', 'contact', null, ['email' => $email, 'topic' => $topic]);
flash('success', 'Thanks for reaching out — our team replies within one business day.');
redirect('/contact.php?sent=1');
