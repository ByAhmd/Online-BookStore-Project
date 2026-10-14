<?php

declare(strict_types=1);

/**
 * A tiny SMTP server for the mailer tests, started by
 * BookWise\Tests\Support\FakeSmtpServer:
 *
 *     php tests/Support/fake_smtp_server.php <transcript file>
 *
 * It listens on 127.0.0.1 at a port chosen by the system, writes that port to
 * "<transcript file>.port", and appends every line it receives ("C: ...")
 * and sends ("S: ...") to the transcript file. A reply is logged before it is
 * sent, so the transcript is complete as soon as the client has its answer.
 * It accepts any sender, recipient and AUTH LOGIN or PLAIN sign-in, and never
 * offers STARTTLS. It serves one connection at a time and exits after a
 * minute without connections. TEST TOOL ONLY.
 */

$transcript = $argv[1] ?? '';
if ($transcript === '') {
    fwrite(STDERR, "Usage: php fake_smtp_server.php <transcript file>\n");
    exit(2);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Cannot listen: $errstr\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
file_put_contents($transcript, '');
// Written under another name first, so the test never reads half a number.
file_put_contents($transcript . '.port.tmp', substr($name, strrpos($name, ':') + 1));
rename($transcript . '.port.tmp', $transcript . '.port');

$log = static function (string $line) use ($transcript): void {
    file_put_contents($transcript, $line . "\n", FILE_APPEND | LOCK_EX);
};

while (($client = @stream_socket_accept($server, 60)) !== false) {
    stream_set_timeout($client, 30);
    $reply = static function (string ...$lines) use ($client, $log): void {
        foreach ($lines as $index => $line) {
            $line = $index < count($lines) - 1 ? substr_replace($line, '-', 3, 1) : $line;
            $log('S: ' . $line);
            fwrite($client, $line . "\r\n");
        }
    };
    $read = static function () use ($client, $log): ?string {
        $line = fgets($client);
        if ($line === false) {
            return null;
        }
        $line = rtrim($line, "\r\n");
        $log('C: ' . $line);
        return $line;
    };

    $reply('220 fake-smtp.test ESMTP ready');
    while (($line = $read()) !== null) {
        $verb = strtoupper((string) strtok($line, ' '));
        if ($verb === 'EHLO') {
            $reply('250 fake-smtp.test', '250 AUTH PLAIN LOGIN', '250 8BITMIME', '250 SIZE 10485760');
        } elseif ($verb === 'HELO' || $verb === 'MAIL' || $verb === 'RCPT' || $verb === 'RSET' || $verb === 'NOOP') {
            $reply('250 OK');
        } elseif ($verb === 'AUTH') {
            $mechanism = strtoupper(trim(substr($line, 5)));
            if ($mechanism === 'LOGIN') {
                $reply('334 VXNlcm5hbWU6'); // "Username:"
                $read();
                $reply('334 UGFzc3dvcmQ6'); // "Password:"
                $read();
            } elseif ($mechanism === 'PLAIN') {
                $reply('334 ');
                $read();
            }
            $reply('235 Authentication succeeded');
        } elseif ($verb === 'DATA') {
            $reply('354 End data with <CR><LF>.<CR><LF>');
            while (($data = $read()) !== null && $data !== '.') {
                // The message itself is only recorded in the transcript.
            }
            $reply('250 Queued');
        } elseif ($verb === 'QUIT') {
            $reply('221 Bye');
            break;
        } else {
            $reply('502 Command not implemented');
        }
    }
    fclose($client);
}
fclose($server);
