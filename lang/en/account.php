<?php

declare(strict_types=1);

// Customer account settings (profilePage.php) and account emails.
return [
    'title' => 'Account Settings',
    'nav_label' => 'Account settings',
    'back_home' => 'Back to home',
    'sign_out' => 'Sign out',

    'sections' => [
        'account' => 'Account Info',
        'payment' => 'Payment Preference',
        'orders' => 'My Orders',
        'security' => 'Security',
        'delete' => 'Delete Account',
    ],

    'profile' => [
        'avatar_alt' => 'Your current profile picture',
        'picture' => 'Profile picture',
        'picture_hint' => 'JPEG, PNG, GIF or WebP, up to 2 MB',
        'name' => 'Full name',
        'email' => 'Email',
        'email_hint' => 'Your email is your sign-in address and cannot be changed here.',
        'favorite_genre' => 'Favorite genre',
        'favorite_genre_hint' => 'Choose a genre from the list or type your own.',
        'date_of_birth' => 'Date of birth',
        'optional' => '(optional)',
        'submit' => 'Update Profile',
        'updated' => 'Your profile has been updated.',
        'invalid' => 'Please correct the highlighted fields. Your profile was not updated.',
        // Messages used by public/js/profile.js (rendered into data-* attributes).
        'js' => [
            'choose' => 'Choose a new profile picture',
            'type_error' => 'Please choose a JPEG, PNG, GIF or WebP image.',
            'size_error' => 'That picture is larger than 2 MB. Please choose a smaller one.',
            'preview_alt' => 'Preview of your new profile picture',
            'selected' => 'New picture selected. Select "Update Profile" to save it.',
        ],
    ],

    'language' => [
        'heading' => 'Language',
        'label' => 'Language for the website and emails',
        'hint' => 'Pages on this device and the emails we send you use this language.',
        'submit' => 'Save Language',
        'saved' => 'Your language preference has been saved.',
        'not_saved' => 'Your language preference was not saved.',
        'invalid' => 'Please choose one of the listed languages.',
    ],

    'payment' => [
        'intro' => "Card details are entered securely on Stripe's checkout page at payment time. :app never stores card numbers.",
        'legacy_notice' => 'Your previous entry was cleared for your security — please choose a preference.',
        'label' => 'Preferred payment method',
        'none' => 'No preference',
        'options' => [
            'Credit / Debit Card' => 'Credit / Debit Card',
            'Apple Pay' => 'Apple Pay',
            'Google Pay' => 'Google Pay',
            'Mada' => 'Mada',
        ],
        'submit' => 'Save Preference',
        'saved' => 'Your payment preference has been saved.',
        'not_saved' => 'Your payment preference was not saved.',
        'invalid' => 'Please choose one of the listed payment preferences.',
    ],

    'orders' => [
        'empty' => "You haven't placed any orders yet.",
        'explore' => 'Explore books',
        'number' => 'Order #:id',
        'placed' => 'Placed :date',
        'date_format' => 'j M Y, H:i',
        'quantity' => '× :count',
        'total' => 'Total',
        // Shown capitalized by the .status-badge style.
        'status' => [
            'pending' => 'pending',
            'paid' => 'paid',
            'canceled' => 'canceled',
            'expired' => 'expired',
            'failed' => 'failed',
            'refunded' => 'refunded',
        ],
    ],

    'security' => [
        'intro' => 'Change the password you use to log in. Changing it signs you out on every other device.',
        'current_password' => 'Current password',
        'new_password' => 'New password',
        'new_password_confirmation' => 'Confirm new password',
        'submit' => 'Change Password',
        'current_required' => 'Please enter your current password.',
        'current_wrong' => 'Your current password is not correct.',
        'not_changed' => 'Your password was not changed.',
        'throttled' => [
            'one' => 'Too many incorrect password attempts. Please wait :count minute and try again.',
            'other' => 'Too many incorrect password attempts. Please wait :count minutes and try again.',
        ],
        'changed' => 'Your password has been changed. Your other devices have been signed out.',
    ],

    'delete' => [
        'intro' => 'Deleting your account is permanent and cannot be undone.',
        'removed_heading' => 'What is deleted',
        'removed' => [
            'profile' => 'Your profile: name, email address, password, profile picture, favorite genre, date of birth and preferences.',
            'shopping' => 'Your cart and your favorites.',
            'reviews' => 'Your book reviews and the likes and dislikes you gave.',
            'purchases' => 'Your purchase list, which unlocks reviews (best-seller counts are lowered accordingly).',
            'security' => 'Unused email links and records of failed sign-in attempts.',
        ],
        'kept_heading' => 'What is kept',
        'kept' => 'Your order records (books, amounts, dates and payment references) are kept for accounting, anonymised: they are no longer linked to your account, name or email address.',
        'password' => 'Current password',
        'confirm' => 'I understand that my account and the data listed above will be permanently deleted.',
        'submit' => 'Delete My Account',
        'confirm_prompt' => 'Delete your account permanently? This cannot be undone.',
        'password_required' => 'Please enter your current password.',
        'password_wrong' => 'Your current password is not correct.',
        'confirm_required' => 'Please confirm that you want to delete your account.',
        'not_deleted' => 'Your account was not deleted.',
        'unresolved' => 'We could not confirm the status of a checkout you started, so your account was not deleted. You have not been charged twice. Please try again in a few minutes.',
        'throttled' => [
            'one' => 'Too many incorrect password attempts. Please wait :count minute and try again.',
            'other' => 'Too many incorrect password attempts. Please wait :count minutes and try again.',
        ],
        'done' => 'Your account has been deleted. Thank you for reading with us.',
    ],

    'errors' => [
        'unknown_action' => 'Unknown profile action.',
    ],

    'validation' => [
        'favorite_genre_required' => 'Favorite genre is required.',
        'favorite_genre_too_long' => [
            'one' => 'Favorite genre must be :count character or fewer.',
            'other' => 'Favorite genre must be :count characters or fewer.',
        ],
        'favorite_genre_invalid' => 'Favorite genre contains characters that are not allowed.',
        'date_of_birth_invalid' => 'Please enter a valid date of birth.',
        'date_of_birth_range' => 'Date of birth must be a past date after 1900.',
    ],

    'upload' => [
        'too_large' => 'Profile pictures must be 2 MB or smaller.',
        'failed' => 'The picture could not be uploaded. Please try again.',
        'empty' => 'The selected file is empty. Please choose an image.',
        'type' => 'Please upload a JPEG, PNG, GIF or WebP image.',
        'invalid_image' => 'Please upload a valid JPEG, PNG, GIF or WebP image.',
        'dimensions' => 'Images must be at most 4000×4000 pixels.',
    ],

    'mail' => [
        'password_changed' => [
            'subject' => 'Your BookWise password was changed',
            'body' => 'The password of your BookWise account was just changed, and your other devices were signed out.',
            'not_you' => 'If you did not change it, reset your password now to secure your account.',
            'reset' => 'Reset your password',
        ],
        'deleted' => [
            'subject' => 'Your BookWise account was deleted',
            'body' => 'Your BookWise account and its personal data have been deleted, as you requested.',
            'orders' => 'Records of past orders are kept for accounting without any link to you.',
            'not_you' => 'If you did not delete your account, please let the BookWise team know as soon as possible.',
        ],
    ],
];
