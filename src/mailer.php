<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/** Demo inbox messages are deleted this long after they were stored. */
const DEMO_MAIL_RETENTION_HOURS = 48;
/** The inbox lists at most this many messages (newest first). */
const DEMO_INBOX_LIST_LIMIT = 100;
/** Session key: accounts that sign-up created in this browser session (user id => address). */
const DEMO_INBOX_SESSION_KEY = 'demo_inbox_accounts';
/** At most this many created accounts are remembered per session. */
const DEMO_INBOX_MAX_ACCOUNTS = 20;

/**
 * Sends an email using the configured MAIL_DRIVER:
 *  - "log"      (default) appends the message to mail.log in LOG_DIR (storage/logs); for local development.
 *  - "smtp"     sends through an SMTP server (SMTP_HOST, SMTP_PORT, ...) with PHPMailer.
 *  - "database" stores the message for the demo inbox (inbox.php); only in demo mode (APP_DEMO=true).
 *  - "null"     discards the message.
 *
 * $locale is the language the message is written in (stored with demo inbox
 * messages so they keep their own text direction); null means the active one.
 *
 * Returns true on success. Failures are logged and never expose provider
 * errors to visitors.
 */
function send_mail(string $toEmail, string $toName, string $subject, string $html, string $text, ?string $locale = null): bool
{
    $driver = (string) config('mail.driver');

    try {
        switch ($driver) {
            case 'null':
                return true;

            case 'log':
                $entry = sprintf(
                    "==== %s ====\nTo: %s <%s>\nSubject: %s\n\n%s\n\n",
                    date('Y-m-d H:i:s'),
                    $toName,
                    $toEmail,
                    $subject,
                    $text
                );
                return file_put_contents(log_path('mail.log'), $entry, FILE_APPEND | LOCK_EX) !== false;

            case 'smtp':
                // The demo accounts' made-up addresses (DEMO_EMAIL_DOMAIN) can never receive mail.
                if (str_ends_with(normalize_email($toEmail), '@' . DEMO_EMAIL_DOMAIN)) {
                    return true;
                }
                return send_smtp_mail($toEmail, $toName, $subject, $html, $text);

            case 'database':
                // The inbox page shows these messages to visitors, which only a public demo may do.
                if (!config('app.demo')) {
                    app_log('error', 'MAIL_DRIVER=database is only available in demo mode (APP_DEMO=true)');
                    return false;
                }
                store_demo_mail($toEmail, $subject, $html, $text, $locale ?? current_locale());
                return true;

            default:
                app_log('error', 'Unknown MAIL_DRIVER', ['driver' => $driver]);
                return false;
        }
    } catch (Throwable $e) {
        app_log('error', 'Email delivery failed: ' . $e->getMessage(), ['subject' => $subject]);
        return false;
    }
}

/**
 * Sends one message through the SMTP server configured in SMTP_*. Throws
 * PHPMailer exceptions on delivery errors (send_mail() logs them). The
 * password is only handed to PHPMailer, never logged, and debug output is off.
 */
function send_smtp_mail(string $toEmail, string $toName, string $subject, string $html, string $text): bool
{
    $host = trim((string) config('mail.smtp.host'));
    if ($host === '') {
        app_log('error', 'MAIL_DRIVER=smtp but SMTP_HOST is not set');
        return false;
    }
    $encryption = (string) config('mail.smtp.encryption');
    $secure = match ($encryption) {
        'tls' => PHPMailer::ENCRYPTION_STARTTLS,
        'ssl' => PHPMailer::ENCRYPTION_SMTPS,
        'none' => '',
        default => null,
    };
    if ($secure === null) {
        app_log('error', 'Unknown SMTP_ENCRYPTION (use tls, ssl or none)', ['encryption' => $encryption]);
        return false;
    }
    $username = (string) config('mail.smtp.username');
    // Without encryption the sign-in (the password, merely base64-encoded)
    // would cross the network readable: only a server on this machine is trusted with it.
    if ($secure === '' && $username !== '' && !smtp_host_is_loopback($host)) {
        app_log('error', 'SMTP_ENCRYPTION=none signs in only to a server on this machine (localhost); use tls or ssl');
        return false;
    }
    $timeout = max(1, (int) config('mail.smtp.timeout'));

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->SMTPDebug = SMTP::DEBUG_OFF;
    $mail->Host = $host;
    $mail->Port = (int) config('mail.smtp.port');
    // Exactly the configured encryption: with "tls" PHPMailer stops (before
    // authenticating) when the server offers no STARTTLS, and "none" never
    // upgrades on its own.
    $mail->SMTPSecure = $secure;
    $mail->SMTPAutoTLS = false;
    // PHPMailer waits up to 300 seconds by default, for the connection and for
    // every reply; a page that sends an email must not hang that long.
    $mail->Timeout = $timeout;
    $mail->getSMTPInstance()->Timelimit = $timeout;

    $mail->SMTPAuth = $username !== '';
    if ($mail->SMTPAuth) {
        $mail->Username = $username;
        $mail->Password = (string) config('mail.smtp.password');
    }

    // UTF-8 with quoted-printable bodies: Arabic text survives every server,
    // and headers such as the subject are MIME-encoded automatically.
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
    $mail->XMailer = ' '; // no X-Mailer header naming the library and its version
    $mail->setFrom((string) config('mail.from_address'), (string) config('mail.from_name'));
    $mail->addAddress($toEmail, $toName);
    $mail->Subject = $subject;
    if ($html !== '') {
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $text;
    } else {
        $mail->Body = $text;
    }

    return $mail->send();
}

/**
 * True when every server in SMTP_HOST is this machine: localhost or a
 * loopback address. SMTP_HOST is read the way PHPMailer reads it: servers
 * separated by ";", each "[ssl:// or tls://]host[:port]", IPv6 in brackets.
 * Anything else counts as remote.
 */
function smtp_host_is_loopback(string $hosts): bool
{
    foreach (explode(';', $hosts) as $entry) {
        if (!preg_match('~^(?:(?:ssl|tls)://)?(\[[0-9a-f:.]+\]|[^:\[\]]+)(?::\d+)?$~iD', trim($entry), $match)) {
            return false;
        }
        $host = strtolower(trim($match[1], '[]'));
        $address = filter_var($host, FILTER_VALIDATE_IP) !== false ? inet_pton($host) : false;
        $loopback = $host === 'localhost'
            || ($address !== false && (strlen($address) === 4 ? $address[0] === "\x7F" : $address === inet_pton('::1')));
        if (!$loopback) {
            return false;
        }
    }
    return true;
}

/**
 * Composes an email in the recipient's language and sends it. $compose runs
 * while $locale is active (null keeps the visitor's language) and returns
 * [subject, html, text]; the visitor's language is restored afterwards.
 *
 * @param callable(): array{0: string, 1: string, 2: string} $compose
 */
function send_localized_mail(string $toEmail, string $toName, ?string $locale, callable $compose): bool
{
    use_locale($locale);
    try {
        [$subject, $html, $text] = $compose();
        $mailLocale = current_locale();
    } finally {
        use_locale(null);
    }
    return send_mail($toEmail, $toName, $subject, $html, $text, $mailLocale);
}

/**
 * Wraps an email's HTML body with the active language and text direction.
 */
function mail_html_layout(string $bodyHtml): string
{
    return '<div lang="' . e(current_locale()) . '" dir="' . e(text_direction()) . '">' . $bodyHtml . '</div>';
}

function mail_html_signature(): string
{
    return '<p>' . e(t('auth.mail.regards')) . '<br>' . e(t('auth.mail.team')) . '</p>';
}

function mail_text_signature(): string
{
    return t('auth.mail.regards') . "\n" . t('auth.mail.team');
}

/* ---------------------------------------------------------------------
 * Demo inbox (APP_DEMO=true and MAIL_DRIVER=database)
 *
 * A public demo has no email provider, so its emails are stored in the
 * demo_mail table and shown on inbox.php. A browser may only read the mail
 * of addresses it has proved it controls: the signed-in customer's own
 * address, and the addresses of accounts that sign-up created in this
 * browser session. Typing someone else's address into a form (sign-up,
 * password reset, resend) never makes their mail readable.
 * ------------------------------------------------------------------- */

/**
 * True when emails are stored for the demo inbox instead of being sent.
 */
function demo_inbox_enabled(): bool
{
    return (bool) config('app.demo') && config('mail.driver') === 'database';
}

/**
 * Stores a message for the demo inbox, after deleting expired messages.
 * Mail to an address without a customer or administrator account, such as
 * the "account deleted" notice, is not stored: no browser could ever read
 * it (see demo_inbox_addresses()), and it would only keep personal data.
 */
function store_demo_mail(string $toEmail, string $subject, string $html, string $text, string $locale): void
{
    purge_expired_demo_mail();
    $recipient = normalize_email($toEmail);
    if (!user_email_exists($recipient)) {
        return;
    }
    db_run(
        'INSERT INTO demo_mail (recipient, subject, html, text, locale) VALUES (?, ?, ?, ?, ?)',
        [
            $recipient,
            mb_substr($subject, 0, 255),
            $html,
            $text,
            is_supported_locale($locale) ? $locale : default_locale(),
        ]
    );
}

/**
 * Deletes demo inbox messages older than DEMO_MAIL_RETENTION_HOURS. Runs
 * whenever a message is stored and whenever the inbox is opened.
 */
function purge_expired_demo_mail(): void
{
    db_run('DELETE FROM demo_mail WHERE created_at < (NOW() - INTERVAL ? HOUR)', [DEMO_MAIL_RETENTION_HOURS]);
}

/**
 * Called by sign-up before an account is created. Mail kept for an address
 * that has no account belonged to an account that was deleted (for example
 * its "account deleted" notice); it is removed so that whoever registers the
 * address next cannot read it.
 */
function demo_inbox_forget_unowned_mail(string $email): void
{
    if (!demo_inbox_enabled() || user_email_exists($email)) {
        return;
    }
    db_run('DELETE FROM demo_mail WHERE recipient = ?', [$email]);
}

/**
 * Lets this browser session read the demo inbox of the customer account
 * that sign-up has just created for $email. Call it only when the account
 * was really created, never when the address was already registered.
 * The session keeps the account's id: if that account is deleted and the
 * address registered again by someone else, this session loses access.
 *
 * Like signing in, this gives the session a new privilege, so it gets a new
 * session ID and CSRF token: a session ID planted in the browser beforehand
 * cannot be used to read the new account's confirmation or reset links.
 */
function demo_inbox_remember_account(string $email): void
{
    if (!demo_inbox_enabled()) {
        return;
    }
    $userId = db_value('SELECT id FROM users WHERE Eaddress = ?', [$email]);
    if ($userId === null) {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        rotate_csrf_token();
    }
    $accounts = $_SESSION[DEMO_INBOX_SESSION_KEY] ?? [];
    if (!is_array($accounts)) {
        $accounts = [];
    }
    unset($accounts[(int) $userId]);
    $accounts[(int) $userId] = normalize_email($email);
    $_SESSION[DEMO_INBOX_SESSION_KEY] = array_slice($accounts, -DEMO_INBOX_MAX_ACCOUNTS, null, true);
}

/**
 * Addresses whose demo inbox this browser session may read:
 *  - a signed-in administrator: only the administrator's own address;
 *  - everyone else: the signed-in customer's own address, plus the accounts
 *    sign-up created in this session that still exist with the same address.
 *
 * @return list<string>
 */
function demo_inbox_addresses(): array
{
    $user = current_user();
    if ($user === null) {
        $admin = current_admin();
        if ($admin !== null) {
            return [normalize_email((string) $admin['Eaddress'])];
        }
    }

    $addresses = $user !== null ? [normalize_email((string) $user['Eaddress'])] : [];
    $created = $_SESSION[DEMO_INBOX_SESSION_KEY] ?? [];
    if (is_array($created) && $created !== []) {
        $ids = array_map('intval', array_keys($created));
        $rows = db_all(
            'SELECT id, Eaddress FROM users WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        foreach ($rows as $row) {
            $remembered = $created[(int) $row['id']] ?? null;
            if (is_string($remembered) && normalize_email((string) $row['Eaddress']) === $remembered) {
                $addresses[] = $remembered;
            }
        }
    }
    return array_values(array_unique($addresses));
}

/**
 * The newest messages to the given addresses that have not expired yet.
 *
 * @param list<string> $addresses
 * @return list<array{id: int, recipient: string, subject: string, locale: string, created_at: string}>
 */
function demo_inbox_messages(array $addresses): array
{
    if ($addresses === []) {
        return [];
    }
    $rows = db_all(
        'SELECT id, recipient, subject, locale, created_at FROM demo_mail
         WHERE recipient IN (' . implode(', ', array_fill(0, count($addresses), '?')) . ')
           AND created_at >= (NOW() - INTERVAL ? HOUR)
         ORDER BY created_at DESC, id DESC
         LIMIT ' . DEMO_INBOX_LIST_LIMIT,
        [...$addresses, DEMO_MAIL_RETENTION_HOURS]
    );
    return array_map('demo_mail_row', $rows);
}

/**
 * One message, or null when it does not exist, has expired or was not sent
 * to one of the given addresses (the caller cannot tell these apart).
 *
 * @param list<string> $addresses
 * @return array{id: int, recipient: string, subject: string, locale: string, created_at: string, text: string}|null
 */
function demo_inbox_message(int $id, array $addresses): ?array
{
    if ($addresses === []) {
        return null;
    }
    $row = db_one(
        'SELECT id, recipient, subject, locale, created_at, text FROM demo_mail
         WHERE id = ?
           AND recipient IN (' . implode(', ', array_fill(0, count($addresses), '?')) . ')
           AND created_at >= (NOW() - INTERVAL ? HOUR)',
        [$id, ...$addresses, DEMO_MAIL_RETENTION_HOURS]
    );
    if ($row === null) {
        return null;
    }
    return demo_mail_row($row) + ['text' => (string) $row['text']];
}

/**
 * @param array<string, mixed> $row
 * @return array{id: int, recipient: string, subject: string, locale: string, created_at: string}
 */
function demo_mail_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'recipient' => (string) $row['recipient'],
        'subject' => (string) $row['subject'],
        'locale' => is_supported_locale($row['locale']) ? (string) $row['locale'] : default_locale(),
        'created_at' => (string) $row['created_at'],
    ];
}

/**
 * The plain-text part of a message as escaped HTML in which links to this
 * store (APP_URL) are clickable. Every other URL, including any scheme but
 * http(s), stays plain text, so a stored message can never link elsewhere.
 * Show it with white-space: pre-wrap to keep the line breaks.
 */
function demo_mail_text_html(string $text): string
{
    $parts = preg_split('~(https?://[^\s<>"\']+)~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return e($text); // not valid UTF-8
    }
    $storeUrl = (string) config('app.url') . '/';
    $html = '';
    foreach ($parts as $index => $part) {
        if ($index % 2 === 0) {
            $html .= e($part);
            continue;
        }
        // Punctuation that ends a sentence is not part of the link.
        $url = rtrim($part, '.,;:!?)');
        $html .= str_starts_with($url, $storeUrl) ? '<a href="' . e($url) . '">' . e($url) . '</a>' : e($url);
        $html .= e(substr($part, strlen($url)));
    }
    return $html;
}

/**
 * The demo-mode note on the sign-in pages, as HTML: it points to the demo
 * inbox when that is active, and otherwise explains that no email is sent.
 */
function demo_mail_notice_html(): string
{
    if (!demo_inbox_enabled()) {
        return e(t('auth.reset.demo_notice'));
    }
    return t_html('auth.reset.demo_inbox_notice', [
        'inbox' => '<a class="auth-link" href="inbox.php">' . e(t('auth.reset.demo_inbox_link')) . '</a>',
    ]);
}
