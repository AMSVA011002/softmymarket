<?php
declare(strict_types=1);

/**
 * Transactional email.
 *
 * Transports:
 *   mail — the host's sendmail binary (common on shared PHP hosting)
 *   smtp — direct SMTP client (SSL on 465 or STARTTLS on 587)
 *   log  — write the message to storage/logs/mail.log only (staging/demo)
 *
 * Every message is also appended to the mail log when mail.log is enabled, so
 * support can prove what the store sent.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $html, ?string $replyTo = null, ?string $text = null): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $text = $text ?? trim(strip_tags(str_replace(['<br>', '</p>', '</h1>', '</h2>', '</li>'], "\n", $html)));
        $base = (array) Config::get('mail', []);
        if (Config::get('mail.log')) {
            self::log($to, $subject, $html);
        }

        try {
            $options = self::options($base);
            $fromEmail = $options['from_email'];
            $fromName = $options['from_name'];
            $replyTo = $replyTo ?? $options['reply_to'];
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('A valid sender and reply-to email address must be configured in Admin → Settings → Email.');
            }
            if ($options['transport'] === 'log') {
                // A log-only transport is useful for staging but never counts
                // as delivery (especially for security-critical verification).
                return false;
            }
            if ($options['transport'] === 'smtp') {
                return self::smtpSend($to, $subject, $html, $text, $fromEmail, $fromName, $replyTo, $options['smtp']);
            }
            if ($options['transport'] !== 'mail') {
                throw new RuntimeException('Unsupported mail transport.');
            }
            return self::mailSend($to, $subject, $html, $text, $fromEmail, $fromName, $replyTo);
        } catch (Throwable $e) {
            error_log('Mail delivery failed: ' . $e->getMessage());
            return false;
        }
    }

    /** @param array<string,mixed> $base @return array{transport:string,from_email:string,from_name:string,reply_to:string,smtp:array<string,mixed>} */
    private static function options(array $base): array
    {
        $smtp = (array) ($base['smtp'] ?? []);
        $smtp['host'] = Settings::get('mail_smtp_host', (string) ($smtp['host'] ?? ''));
        $smtp['port'] = Settings::int('mail_smtp_port', (int) ($smtp['port'] ?? 465));
        $smtp['encryption'] = Settings::get('mail_smtp_encryption', (string) ($smtp['encryption'] ?? 'ssl'));
        $smtp['username'] = Settings::get('mail_smtp_username', (string) ($smtp['username'] ?? ''));
        $smtp['timeout'] = Settings::int('mail_smtp_timeout', (int) ($smtp['timeout'] ?? 15));
        $encryptedPassword = Settings::get('mail_smtp_password_enc', '');
        $defaultFromName = trim((string) ($base['from_name'] ?? ''));
        if ($defaultFromName === '') {
            $defaultFromName = Settings::get('store_name');
        }
        if ($encryptedPassword !== '') {
            $password = Security::decrypt($encryptedPassword);
            if ($password === null) {
                throw new RuntimeException('The saved SMTP password could not be decrypted. Re-enter it in Admin → Settings → Email.');
            }
            $smtp['password'] = $password;
        } else {
            // SMTP secrets belong in the encrypted dashboard setting, never in config.php.
            $smtp['password'] = '';
        }

        return [
            'transport' => Settings::get('mail_transport', (string) ($base['transport'] ?? 'mail')),
            'from_email' => Settings::get('mail_from_email', (string) ($base['from_email'] ?? '')),
            'from_name' => Settings::get('mail_from_name', $defaultFromName),
            'reply_to' => Settings::get('mail_reply_to', (string) ($base['reply_to'] ?? '')),
            'smtp' => $smtp,
        ];
    }

    private static function mailSend(string $to, string $subject, string $html, string $text, string $from, string $fromName, string $replyTo): bool
    {
        $boundary = 'b_' . bin2hex(random_bytes(10));
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . self::encodeName($fromName) . ' <' . $from . '>',
            'Reply-To: ' . $replyTo,
            'X-Mailer: YourMarket',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$text}\r\n\r\n"
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n\r\n"
            . "--{$boundary}--";
        $subjectHeader = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        return @mail($to, $subjectHeader, $body, implode("\r\n", $headers), '-f' . $from);
    }

    private static function smtpSend(string $to, string $subject, string $html, string $text, string $from, string $fromName, string $replyTo, array $cfg): bool
    {
        $host = (string) ($cfg['host'] ?? '');
        $port = (int) ($cfg['port'] ?? 465);
        $encryption = (string) ($cfg['encryption'] ?? 'ssl');
        $timeout = max(5, min(60, (int) ($cfg['timeout'] ?? 15)));
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('SMTP port must be between 1 and 65535.');
        }
        if ($host === '') {
            throw new RuntimeException('SMTP host is not configured. Set it in Admin → Settings → Email.');
        }
        $username = (string) ($cfg['username'] ?? '');
        $password = (string) ($cfg['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new RuntimeException('SMTP username and password are required. Configure them in Admin → Settings → Email.');
        }
        if (!in_array($encryption, ['ssl', 'tls'], true)) {
            throw new RuntimeException('SMTP must use SSL/TLS or STARTTLS.');
        }

        $scheme = $encryption === 'ssl' ? 'ssl://' : '';
        $socket = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, $timeout);
        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP connect failed: ' . $errstr);
        }
        stream_set_timeout($socket, $timeout);

        self::expect($socket, 220);
        $urlHost = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);
        $hostname = is_string($urlHost) && $urlHost !== '' ? $urlHost : 'localhost';
        $hostname = preg_replace('/[^a-zA-Z0-9.-]/', '', $hostname) ?: 'localhost';
        self::command($socket, 'EHLO ' . $hostname, 250);

        if ($encryption === 'tls') {
            self::command($socket, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS failed.');
            }
            self::command($socket, 'EHLO ' . $hostname, 250);
        }

        self::command($socket, 'AUTH LOGIN', 334);
        self::command($socket, base64_encode($username), 334);
        self::command($socket, base64_encode($password), 235);

        self::command($socket, 'MAIL FROM:<' . $from . '>', 250);
        self::command($socket, 'RCPT TO:<' . $to . '>', 250);
        self::command($socket, 'DATA', 354);

        $boundary = 'b_' . bin2hex(random_bytes(10));
        $headers = [
            'From: ' . self::encodeName($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Reply-To: ' . $replyTo,
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $hostname . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $text . "\r\n\r\n"
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $html . "\r\n\r\n"
            . "--{$boundary}--";
        $body = preg_replace('/^\./m', '..', $body) ?? $body;

        fwrite($socket, $body . "\r\n.\r\n");
        self::expect($socket, 250);
        self::command($socket, 'QUIT', 221);
        fclose($socket);
        return true;
    }

    /** @param resource $socket */
    private static function command($socket, string $command, int $expect): void
    {
        fwrite($socket, $command . "\r\n");
        self::expect($socket, $expect);
    }

    /** @param resource $socket */
    private static function expect($socket, int $code): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if ((int) substr($response, 0, 3) !== $code) {
            throw new RuntimeException('SMTP unexpected response: ' . trim($response));
        }
    }

    private static function encodeName(string $name): string
    {
        return '=?UTF-8?B?' . base64_encode($name) . '?=';
    }

    private static function log(string $to, string $subject, string $html): void
    {
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $entry = sprintf("[%s] to=%s subject=%s bytes=%d\n", date('c'), $to, $subject, strlen($html));
        @file_put_contents($dir . '/mail.log', $entry, FILE_APPEND);
    }

    /* ---------------------------------------------------------------- layout */

    public static function wrap(string $heading, string $bodyHtml, ?string $ctaLabel = null, ?string $ctaUrl = null): string
    {
        $brand = Settings::brand();
        $color = $brand['color'] ?: '#0c800f';
        $cta = '';
        if ($ctaLabel !== null && $ctaUrl !== null) {
            $cta = '<p style="margin:26px 0 6px"><a href="' . e($ctaUrl) . '" style="display:inline-block;background:' . e($color) . ';color:#fff;text-decoration:none;padding:13px 26px;border-radius:10px;font-weight:600">' . e($ctaLabel) . '</a></p>';
        }
        return '<!doctype html><html><body style="margin:0;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:16px;overflow:hidden">'
            . '<tr><td style="background:' . e($color) . ';padding:20px 26px;color:#fff;font-size:20px;font-weight:700">' . e($brand['name']) . '</td></tr>'
            . '<tr><td style="padding:26px">'
            . '<h1 style="margin:0 0 14px;font-size:21px">' . e($heading) . '</h1>'
            . '<div style="font-size:15px;line-height:1.6;color:#334155">' . $bodyHtml . '</div>'
            . $cta
            . '</td></tr>'
            . '<tr><td style="padding:18px 26px;background:#f8fafc;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px">'
            . e($brand['name']) . ' · ' . e($brand['address']) . '<br>'
            . 'Need help? Reply to this email or call ' . e($brand['phone']) . '.'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /* ------------------------------------------------------------- templates */

    public static function orderConfirmation(array $order, array $items): bool
    {
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #e2e8f0">' . e($item['title'])
                . '<div style="color:#64748b;font-size:12px">Qty ' . (int) $item['quantity'] . ' × ' . e(money($item['unit_price'])) . '</div></td>'
                . '<td align="right" style="padding:8px 0;border-bottom:1px solid #e2e8f0">' . e(money($item['total'])) . '</td></tr>';
        }
        $body = '<p>Hi ' . e($order['customer_name']) . ',</p>'
            . '<p>Thanks for shopping with us. We have received order <strong>' . e($order['order_number']) . '</strong> and our team is preparing it.</p>'
            . '<table role="presentation" width="100%" style="margin:16px 0;font-size:14px">' . $rows
            . '<tr><td style="padding-top:10px">Subtotal</td><td align="right" style="padding-top:10px">' . e(money($order['subtotal'])) . '</td></tr>'
            . '<tr><td>Delivery</td><td align="right">' . e($order['shipping_fee'] > 0 ? money($order['shipping_fee']) : 'Free') . '</td></tr>'
            . ($order['discount'] > 0 ? '<tr><td>Discount</td><td align="right">−' . e(money($order['discount'])) . '</td></tr>' : '')
            . '<tr><td style="font-weight:700;padding-top:8px">Total</td><td align="right" style="font-weight:700;padding-top:8px">' . e(money($order['total'])) . '</td></tr>'
            . '</table>'
            . '<p>Payment: ' . e(payment_method_label($order['payment_method'])) . '</p>'
            . '<p>You can follow the order live from your account at any time.</p>';

        return self::send(
            (string) $order['email'],
            'Order ' . $order['order_number'] . ' confirmed',
            self::wrap('We are on it!', $body, 'Track your order', url('account/order.php?id=' . (int) $order['id']))
        );
    }

    public static function orderStatus(array $order, string $status, string $message): bool
    {
        $body = '<p>Hi ' . e($order['customer_name']) . ',</p>'
            . '<p>Your order <strong>' . e($order['order_number']) . '</strong> is now <strong>' . e(order_status_label($status)) . '</strong>.</p>'
            . '<p>' . e($message) . '</p>';
        return self::send(
            (string) $order['email'],
            'Order ' . $order['order_number'] . ' — ' . order_status_label($status),
            self::wrap('Order update', $body, 'View order', url('account/order.php?id=' . (int) $order['id']))
        );
    }

    public static function emailVerification(array $user, string $link): bool
    {
        $body = '<p>Hi ' . e($user['name']) . ',</p>'
            . '<p>Thank you for creating an account with ' . e(Settings::get('store_name')) . '. Confirm that this email address belongs to you to activate your customer account.</p>'
            . '<p>This secure link expires in 24 hours. If you did not create the account, you can ignore this message.</p>'
            . '<p style="color:#64748b;font-size:13px;word-break:break-all">' . e($link) . '</p>';
        return self::send((string) $user['email'], 'Confirm your email address', self::wrap('Confirm your email', $body, 'Verify email address', $link));
    }

    public static function teamInvitation(array $user, string $temporaryPassword): bool
    {
        $role = (string) ($user['role'] ?? 'staff');
        $roleLabel = $role === 'admin' ? 'Administrator' : 'Store staff';
        $loginUrl = url('/login.php', ['next' => '/admin/index.php']);
        $body = '<p>Hi ' . e($user['name']) . ',</p>'
            . '<p>You have been invited to join ' . e(Settings::get('store_name')) . ' as <strong>' . e($roleLabel) . '</strong>.</p>'
            . '<p>Sign in using this email address and temporary password:</p>'
            . '<p><strong>Email:</strong> ' . e($user['email']) . '<br><strong>Temporary password:</strong> <code>' . e($temporaryPassword) . '</code></p>'
            . '<p>You will be required to choose a new password immediately after signing in. Do not forward this message; contact the store administrator if you did not expect this invitation.</p>';
        return self::send((string) $user['email'], 'Your ' . Settings::get('store_name') . ' team account', self::wrap('You are invited to the team', $body, 'Sign in securely', $loginUrl));
    }

    public static function welcome(array $user): bool
    {
        $body = '<p>Hi ' . e($user['name']) . ',</p>'
            . '<p>Welcome to ' . e(Settings::get('store_name')) . '! Your account is ready.</p>'
            . '<p>' . e(Settings::get('store_description')) . '</p>';
        return self::send((string) $user['email'], 'Welcome to ' . Settings::get('store_name'), self::wrap('Welcome aboard', $body, 'Start shopping', url('/')));
    }

    public static function passwordReset(array $user, string $link): bool
    {
        $body = '<p>Hi ' . e($user['name']) . ',</p>'
            . '<p>We received a request to reset your password. This link is valid for 60 minutes and can only be used once.</p>'
            . '<p style="color:#64748b;font-size:13px;word-break:break-all">' . e($link) . '</p>'
            . '<p>If you did not ask for this, you can safely ignore this email — your current password keeps working.</p>';
        return self::send((string) $user['email'], 'Reset your password', self::wrap('Password reset', $body, 'Choose a new password', $link));
    }

    public static function bankTransferReceived(array $order): bool
    {
        $body = '<p>Hi ' . e($order['customer_name']) . ',</p>'
            . '<p>We received your bank transfer notification for order <strong>' . e($order['order_number']) . '</strong>. Our finance team will verify it and start preparing your order.</p>';
        return self::send(
            (string) $order['email'],
            'Payment received — ' . $order['order_number'],
            self::wrap('We are verifying your payment', $body, 'View order', url('account/order.php?id=' . (int) $order['id']))
        );
    }

    public static function contactNotification(array $message): bool
    {
        $email = Settings::get('support_email', (string) Config::get('app.support_email', 'hello@yourmarket.example'));
        $body = '<p><strong>From:</strong> ' . e($message['name']) . ' &lt;' . e($message['email']) . '&gt;</p>'
            . '<p><strong>Phone:</strong> ' . e($message['phone'] ?? '—') . '</p>'
            . '<p><strong>Subject:</strong> ' . e($message['subject'] ?? '—') . '</p>'
            . '<p style="white-space:pre-line">' . e($message['message']) . '</p>';
        return self::send($email, 'New contact message from ' . $message['name'], self::wrap('New store message', $body));
    }

    public static function lowStockAlert(array $products): bool
    {
        $email = Settings::get('orders_email', (string) Config::get('app.support_email', 'orders@yourmarket.example'));
        $rows = '';
        foreach ($products as $product) {
            $rows .= '<li>' . e($product['title']) . ' — ' . (int) $product['stock'] . ' left (SKU ' . e($product['sku']) . ')</li>';
        }
        $body = '<p>The following products need restocking:</p><ul>' . $rows . '</ul>';
        return self::send($email, 'Low stock alert', self::wrap('Stock running low', $body, 'Open inventory', url('admin/inventory.php')));
    }
}
