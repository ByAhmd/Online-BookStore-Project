<?php

declare(strict_types=1);

// The demo inbox (inbox.php): the emails a public demo stores instead of sending them.
return [
    'inbox' => [
        'title' => 'Demo inbox',
        'intro' => [
            'one' => 'This demo store sends no real email. The emails it would send you, such as confirmation and password reset links, appear here instead and are deleted after :count hour, or earlier when the demo is reset.',
            'other' => 'This demo store sends no real email. The emails it would send you, such as confirmation and password reset links, appear here instead and are deleted after :count hours, or earlier when the demo is reset.',
        ],
        'rule' => "You can read the emails sent to the account you are logged in to, and to the accounts you created in this browser session. Once the session ends, for example when you log out, an account's emails can only be read by logging in to it.",
        'rule_admin' => 'As an administrator, you can read the emails sent to your own address.',
        'addresses' => 'Emails sent to',
        'guest' => 'Sign up, or log in, to read the emails this store sends you.',
        'empty' => 'No emails yet.',
        'list_title' => 'Emails',
        'count' => [
            'one' => ':count email',
            'other' => ':count emails',
        ],
        'to' => 'To :email',
        'recipient' => 'To',
        'received' => 'Received',
        'back' => 'Back to the inbox',
        'links_note' => 'Only links to this store can be opened from here.',
        'not_found' => 'This email does not exist, has expired, or was not sent to you.',
    ],
];
