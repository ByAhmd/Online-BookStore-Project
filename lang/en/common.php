<?php

declare(strict_types=1);

// Shared interface strings: layout, navigation, footer, errors and statuses.
return [
    'skip_link' => 'Skip to main content',
    'main_navigation' => 'Main',
    'admin_tag' => 'Admin',
    'session_expired' => 'Your session expired. Please log in again.',
    'demo_banner' => 'Demo store: payments are simulated, no emails are sent, and data may be reset at any time.',
    'demo_banner_inbox' => 'Demo store: payments are simulated, emails go to the demo inbox instead of being sent, and data may be reset at any time.',

    'nav' => [
        'home' => 'Home',
        'explore' => 'Explore',
        'about' => 'About',
        'dashboard' => 'Dashboard',
        'orders' => 'Orders',
        'storefront' => 'Storefront',
        'sign_up' => 'Sign Up',
        'login' => 'Login',
        'logout' => 'Log out',
        'my_profile' => 'My profile',
        // Demo inbox link; the accessible label must contain the visible text.
        'inbox' => 'Inbox',
        'inbox_label' => 'Demo inbox',
        'cart' => [
            'one' => 'Cart, :count item',
            'other' => 'Cart, :count items',
        ],
    ],

    'language' => [
        'label' => 'Language',
        'switch_to' => 'Switch language to :language',
    ],

    'footer' => [
        'tagline' => 'Your personalized reading companion since 2024!',
        'copyright' => ':app © 2024',
        'explore' => 'Explore',
        'about_us' => 'About Us',
        'dashboard' => 'Dashboard',
    ],

    'flash' => [
        'dismiss' => 'Dismiss message',
    ],

    'error' => [
        'titles' => [
            '400' => 'Bad request',
            '403' => 'Access denied',
            '404' => 'Page not found',
            '405' => 'Method not allowed',
            '419' => 'Page expired',
            '429' => 'Too many requests',
            '500' => 'Server error',
            '503' => 'Service unavailable',
            'default' => 'Error',
        ],
        'default_message' => 'The request could not be completed.',
        'server_error' => 'Something went wrong on our side. Please try again in a moment.',
        'csrf' => 'Your session expired or the form could not be verified. Please go back, refresh the page and try again.',
        'post_only' => 'This action only accepts form submissions.',
        'back_home' => 'Back to home',
        'explore_books' => 'Explore books',
        'back_dashboard' => 'Back to dashboard',
        'view_storefront' => 'View storefront',
    ],

    'status' => [
        'pending' => 'Pending',
        'paid' => 'Paid',
        'canceled' => 'Canceled',
        'expired' => 'Expired',
        'failed' => 'Failed',
        'refunded' => 'Refunded',
    ],
];
