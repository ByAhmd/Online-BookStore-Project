<?php

declare(strict_types=1);

/**
 * Sends an email using the configured MAIL_DRIVER:
 *  - "log"      (default) appends the message to mail.log in LOG_DIR (storage/logs); for local development.
 *  - "sendgrid" sends through the SendGrid v3 API using SENDGRID_API_KEY.
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

            case 'sendgrid':
                $apiKey = config('mail.sendgrid_api_key');
                if (!is_string($apiKey) || $apiKey === '') {
                    app_log('error', 'MAIL_DRIVER=sendgrid but SENDGRID_API_KEY is not set');
                    return false;
                }
                $mail = new \SendGrid\Mail\Mail();
                $mail->setFrom((string) config('mail.from_address'), (string) config('mail.from_name'));
                $mail->setSubject($subject);
                $mail->addTo($toEmail, $toName);
                $mail->addContent('text/plain', $text);
                $mail->addContent('text/html', $html);

                $response = (new \SendGrid($apiKey))->send($mail);
                if ($response->statusCode() >= 300) {
                    app_log('error', 'SendGrid rejected an email', ['status' => $response->statusCode(), 'subject' => $subject]);
                    return false;
                }
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
