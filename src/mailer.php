<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Sends an email using the configured MAIL_DRIVER:
 *  - "log"      (default) appends the message to mail.log in LOG_DIR (storage/logs); for local development.
 *  - "smtp"     sends through an SMTP server (SMTP_HOST, SMTP_PORT, ...) with PHPMailer.
 *  - "null"     discards the message.
 *
 * Returns true on success. Failures are logged and never expose provider
 * errors to visitors.
 */
function send_mail(string $toEmail, string $toName, string $subject, string $html, string $text): bool
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
                return send_smtp_mail($toEmail, $toName, $subject, $html, $text);

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

    $username = (string) config('mail.smtp.username');
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
    } finally {
        use_locale(null);
    }
    return send_mail($toEmail, $toName, $subject, $html, $text);
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
