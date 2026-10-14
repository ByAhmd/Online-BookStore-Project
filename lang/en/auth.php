<?php

declare(strict_types=1);

// Sign up, log in, log out, password reset, email confirmation and their emails.
return [
    'page_heading' => 'Sign up or log in to BookWise',
    'choose_form' => 'Choose a form',

    'panels' => [
        'signup' => 'Sign Up',
        'login' => 'Login',
        'reset' => 'Reset Password',
        'verify' => 'Confirm Your Email',
    ],

    'fields' => [
        'email' => 'Email Address',
        'name' => 'Name',
        'password' => 'Password',
        'password_confirmation' => 'Confirm Password',
        'new_password' => 'New Password',
        'new_password_confirmation' => 'Confirm New Password',
    ],

    'password_hint' => [
        'one' => 'At least :count character.',
        'other' => 'At least :count characters.',
    ],
    'show_password' => 'Show password',

    // Messages used by public/js/index.js (rendered into data-* attributes).
    'js' => [
        'password_mismatch' => 'Passwords do not match.',
        'password_too_long' => 'Password must be at most :max bytes long.',
    ],

    'signup' => [
        'submit' => 'Sign Up',
        'have_account' => 'Already have an account?',
        'login_link' => 'Log in',
        'verification_hint' => 'We will email you a link to confirm your address before you can log in.',
        'email_taken' => 'An account with this email already exists.',
        'welcome' => 'Welcome to BookWise, :name! Your account is ready.',
        'check_inbox' => 'Check your inbox to confirm your email. We have sent a message to the address you entered.',
    ],

    'login' => [
        'submit' => 'Login',
        'forgot' => 'Forgot your password?',
        'new_here' => 'New to BookWise?',
        'create_account' => 'Create an account',
        'failed' => 'Incorrect email or password.',
        'throttled' => [
            'one' => 'Too many failed sign-in attempts. Please wait :count minute and try again.',
            'other' => 'Too many failed sign-in attempts. Please wait :count minutes and try again.',
        ],
        'welcome_back_name' => 'Welcome back, :name!',
        'welcome_back' => 'Welcome back!',
        'failed_or_unconfirmed' => 'Incorrect email or password, or the email address is not confirmed yet. If you have just signed up, open the link we emailed you.',
    ],

    'logout' => [
        'done' => 'You have been logged out.',
    ],

    'reset' => [
        'intro' => 'Enter the email address of your account and we will send you a link to choose a new password.',
        'hint' => [
            'one' => 'The link expires in :count minute.',
            'other' => 'The link expires in :count minutes.',
        ],
        'submit' => 'Send Reset Link',
        'remembered' => 'Remembered it?',
        'back_to_login' => 'Back to login',
        'demo_notice' => 'This demo store does not send email, so password reset and email confirmation links cannot be delivered.',
        // Demo inbox (MAIL_DRIVER=database): :inbox is a link labelled with demo_inbox_link.
        'demo_inbox_notice' => 'This demo store sends no real email: the password reset and confirmation emails for your account appear in the :inbox.',
        'demo_inbox_link' => 'demo inbox',
        'sent' => [
            'one' => "If an account exists for that email, we've sent a password reset link. It expires in :count minute.",
            'other' => "If an account exists for that email, we've sent a password reset link. It expires in :count minutes.",
        ],
        'invalid_title' => 'Reset link expired',
        'invalid_heading' => 'This reset link is invalid or has expired',
        'invalid_intro' => [
            'one' => 'Reset links work once and expire after :count minute. Request a new link to choose a new password.',
            'other' => 'Reset links work once and expire after :count minutes. Request a new link to choose a new password.',
        ],
        'request_new' => 'Request a new link',
        'form_title' => 'Choose a new password',
        'form_intro' => 'Set a new password for :email.',
        'update_submit' => 'Update Password',
        'invalid_link' => 'This reset link is invalid or has expired. Please request a new one.',
        'updated' => 'Your password has been updated. You can now log in.',
        'link_only' => 'This page can only be opened from the link in your email.',
    ],

    'verify' => [
        'heading' => 'Confirm your email address',
        'intro' => [
            'one' => 'Open the confirmation link we emailed you to activate your account. The link expires in :count hour.',
            'other' => 'Open the confirmation link we emailed you to activate your account. The link expires in :count hours.',
        ],
        'resend_intro' => "Didn't get the email? Check your spam folder, or enter your email address to receive a new link.",
        'resend_submit' => 'Send a New Link',
        'resent' => [
            'one' => "If an account with that email is waiting for confirmation, we've sent a new link. It expires in :count hour.",
            'other' => "If an account with that email is waiting for confirmation, we've sent a new link. It expires in :count hours.",
        ],
        'already_confirmed' => 'Already confirmed?',
        'confirm_intro' => 'To confirm :email, enter the password you chose when you signed up. You will then be logged in.',
        'confirm_submit' => 'Confirm and Log In',
        'confirm_failed' => 'Incorrect password. Enter the password you chose when you signed up.',
        'not_you' => 'Did someone else sign up with your address, or did you forget the password? Choose a new password instead; that confirms your address too.',
        'success' => 'Thank you, your email address is confirmed. Welcome to BookWise, :name!',
        'invalid_title' => 'Confirmation link expired',
        'invalid_heading' => 'This confirmation link is invalid or has expired',
        'invalid_intro' => [
            'one' => 'Confirmation links work once and expire after :count hour. If you have already confirmed your address, you can log in. Otherwise, request a new link below.',
            'other' => 'Confirmation links work once and expire after :count hours. If you have already confirmed your address, you can log in. Otherwise, request a new link below.',
        ],
        'link_only' => 'This page can only be opened from the link in your email.',
    ],

    'guard' => [
        'login_required' => 'Please log in to continue.',
        'admin_shop' => 'Administrator accounts manage the catalog. Sign in with a customer account to shop.',
        'admin_login_required' => 'Please log in with an administrator account.',
        'admin_forbidden' => 'You do not have permission to access the admin area.',
    ],

    'errors' => [
        'unknown_action' => 'Unknown form action.',
    ],

    'validation' => [
        'email_required' => 'Please enter your email address.',
        'email_invalid' => 'Please enter a valid email address.',
        'name_required' => 'Please enter your name.',
        'name_too_long' => [
            'one' => 'Name must be :count character or fewer.',
            'other' => 'Name must be :count characters or fewer.',
        ],
        'name_invalid' => 'Name contains characters that are not allowed.',
        'password_required' => 'Please enter your password.',
        'password_too_short' => [
            'one' => 'Password must be at least :count character long.',
            'other' => 'Password must be at least :count characters long.',
        ],
        'password_too_long' => [
            'one' => 'Password must be at most :count byte long.',
            'other' => 'Password must be at most :count bytes long.',
        ],
        'password_mismatch' => 'Passwords do not match.',
    ],

    'mail' => [
        'greeting' => 'Hello,',
        'greeting_name' => 'Hello :name,',
        'regards' => 'Best regards,',
        'team' => 'BookWise Team',

        'welcome' => [
            'subject' => 'Welcome to BookWise!',
            'body' => 'Welcome to BookWise! Your account for this email address is ready.',
            'cta' => 'Start exploring our collection of books',
            'ignore' => 'If you did not create this account, you can ignore this email.',
        ],

        'verify' => [
            'subject' => 'Confirm your BookWise email address',
            'body' => 'Thank you for signing up for BookWise. Please confirm that this is your email address to activate your account.',
            'cta' => 'Confirm my email address',
            'expiry' => [
                'one' => 'This link expires in :count hour and can be used once.',
                'other' => 'This link expires in :count hours and can be used once.',
            ],
            'ignore' => 'If you did not create this account, you can ignore this email: the account will not be activated.',
        ],

        'signup_notice' => [
            'subject' => 'Someone tried to sign up with your email address',
            'body' => 'Someone tried to create a new BookWise account with this email address, which already has an account.',
            'if_you' => 'If it was you, log in to your account, or reset your password if you have forgotten it.',
            'login' => 'Log in',
            'reset' => 'Reset your password',
            'ignore' => 'If it was not you, you can ignore this email. Your account has not been changed.',
        ],

        'reset' => [
            'subject' => 'Reset your BookWise password',
            'body' => 'We received a request to reset your BookWise password.',
            'cta' => 'Choose a new password',
            'expiry' => [
                'one' => 'This link expires in :count minute and can be used once.',
                'other' => 'This link expires in :count minutes and can be used once.',
            ],
            'ignore' => 'If you did not request this, you can ignore this email.',
        ],
    ],
];
