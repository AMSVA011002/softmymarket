<?php
declare(strict_types=1);

/** One-time email ownership verification for newly registered customers. */
final class EmailVerification
{
    public const TOKEN_LIFETIME = 86400;

    /** Issue a fresh token and send the verification message to a pending user. */
    public static function sendToPendingUser(int $userId): bool
    {
        $token = Security::randomToken(32);
        try {
            $user = Database::tx(static function () use ($userId, $token): ?array {
                $user = Database::one('SELECT id, name, email, role, status, email_verified_at FROM users WHERE id = :id FOR UPDATE', ['id' => $userId]);
                if ($user === null || $user['role'] !== 'customer' || $user['status'] !== 'pending' || $user['email_verified_at'] !== null) {
                    return null;
                }
                $now = date('Y-m-d H:i:s');
                // Replacing the pending link revokes every older token; keep
                // used tokens only after successful verification so scanner
                // re-opens remain safe without reviving superseded links.
                Database::run('DELETE FROM email_verifications WHERE user_id = :uid', ['uid' => $userId]);
                Database::run('DELETE FROM email_verifications WHERE expires_at < :expired', ['expired' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
                Database::insert('email_verifications', [
                    'user_id' => $userId,
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => date('Y-m-d H:i:s', time() + self::TOKEN_LIFETIME),
                    'used_at' => null,
                    'ip_address' => Security::clientIp(),
                    'created_at' => $now,
                ]);
                return $user;
            });
        } catch (Throwable $e) {
            error_log('Email verification token creation failed: ' . $e->getMessage());
            return false;
        }
        if ($user === null) {
            return false;
        }

        $link = url('/verify-email.php', ['token' => $token, 'email' => (string) $user['email']]);
        $sent = Mailer::emailVerification($user, $link);
        if (!$sent) {
            error_log('Email verification message could not be delivered for user id ' . $userId . '.');
            Audit::log('auth.email_verification_delivery_failed', 'user', $userId);
        }
        return $sent;
    }

    /** @return array{ok:bool,user_id?:int,error?:string} */
    public static function confirm(string $email, string $token): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $token === '' || strlen($token) > 200) {
            return ['ok' => false, 'error' => 'That confirmation link is invalid or has expired.'];
        }
        try {
            $result = Database::tx(static function () use ($email, $token): array {
                $row = Database::one(
                    'SELECT ev.id AS verification_id, ev.used_at, ev.expires_at,
                            u.id AS user_id, u.name, u.email, u.role, u.status, u.email_verified_at
                       FROM email_verifications ev
                       JOIN users u ON u.id = ev.user_id
                      WHERE ev.token_hash = :hash AND u.email = :email AND ev.expires_at > :now
                      LIMIT 1 FOR UPDATE',
                    ['hash' => hash('sha256', $token), 'email' => $email, 'now' => date('Y-m-d H:i:s')]
                );
                if ($row === null || $row['role'] !== 'customer') {
                    return ['ok' => false, 'error' => 'That confirmation link is invalid or has expired.'];
                }
                if ($row['status'] === 'pending' && $row['email_verified_at'] === null) {
                    $now = date('Y-m-d H:i:s');
                    Users::update((int) $row['user_id'], ['status' => 'active', 'email_verified_at' => $now]);
                    Database::run('UPDATE email_verifications SET used_at = :now WHERE user_id = :uid AND used_at IS NULL', ['now' => $now, 'uid' => (int) $row['user_id']]);
                    return ['ok' => true, 'user_id' => (int) $row['user_id'], 'newly_verified' => true];
                }
                // Email security scanners sometimes open links before the
                // recipient. A valid, unexpired token for an already verified
                // account may still display the completion screen.
                if ($row['status'] === 'active' && $row['email_verified_at'] !== null) {
                    return ['ok' => true, 'user_id' => (int) $row['user_id'], 'newly_verified' => false];
                }
                return ['ok' => false, 'error' => 'This account cannot be verified. Contact store support.'];
            });
        } catch (Throwable $e) {
            error_log('Email verification failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'We could not verify this email right now. Please try again shortly.'];
        }

        if (!$result['ok']) {
            return $result;
        }
        if (!empty($result['newly_verified'])) {
            $userId = (int) $result['user_id'];
            try {
                Audit::log('auth.email_verified', 'user', $userId);
            } catch (Throwable $e) {
                error_log('Email verification audit failed: ' . $e->getMessage());
            }
            try {
                Notifier::notify($userId, 'Email address confirmed', 'Your account is ready. Continue to the store to sign in.', 'success', '/shop.php');
            } catch (Throwable $e) {
                error_log('Email verification notification failed: ' . $e->getMessage());
            }
            $user = Users::find($userId);
            if ($user !== null) {
                try {
                    Mailer::welcome($user);
                } catch (Throwable $e) {
                    error_log('Verified-account welcome email failed: ' . $e->getMessage());
                }
            }
        }
        return ['ok' => true, 'user_id' => (int) $result['user_id']];
    }
}
