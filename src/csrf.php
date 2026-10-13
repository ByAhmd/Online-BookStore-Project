<?php

declare(strict_types=1);

/**
 * Synchronizer-token CSRF protection. Every state-changing form must include
 * csrf_field() and its handler must call verify_csrf().
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_is_valid(mixed $token): bool
{
    $expected = $_SESSION['_csrf_token'] ?? null;
    return is_string($token) && is_string($expected) && $expected !== '' && hash_equals($expected, $token);
}

/**
 * Aborts with 419 unless the request carries the session's CSRF token
 * (form field `_token` or `X-CSRF-Token` header).
 */
function verify_csrf(): void
{
    $token = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_is_valid($token)) {
        abort(419, 'Your session expired or the form could not be verified. Please go back, refresh the page and try again.');
    }
}

/**
 * Issues a fresh token, e.g. after login, logout or a privilege change.
 */
function rotate_csrf_token(): void
{
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
