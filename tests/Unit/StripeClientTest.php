<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use BookWise\Tests\Support\IsolatedPhp;
use PHPUnit\Framework\TestCase;

/**
 * stripe_client() caches its client for the whole process, so each case runs
 * in a fresh process (which also keeps its log lines out of storage/logs).
 * No request is sent to Stripe: constructing the client is offline.
 */
final class StripeClientTest extends TestCase
{
    public function testLiveKeyIsRefusedOutsideProduction(): void
    {
        $run = IsolatedPhp::run('stripe_client', [], ['APP_ENV' => 'local', 'STRIPE_SECRET_KEY' => 'sk_live_not_a_real_key']);

        self::assertSame([
            'ok' => false,
            'exception' => 'CheckoutException',
            'message' => 'Online payments are not available in this environment.',
        ], $run['result']);
        self::assertStringContainsString('Refusing to use a non-test Stripe key outside APP_ENV=production', $run['files']['storage/logs/app.log'] ?? '');
        self::assertStringNotContainsString('sk_live_not_a_real_key', $run['files']['storage/logs/app.log'] ?? '', 'Keys are never logged');
    }

    public function testRestrictedLiveKeyIsAlsoRefusedOutsideProduction(): void
    {
        $run = IsolatedPhp::run('stripe_client', [], ['APP_ENV' => 'local', 'STRIPE_SECRET_KEY' => 'rk_live_not_a_real_key']);

        self::assertSame('CheckoutException', $run['result']['exception'] ?? null);
    }

    public function testCurrencyWithoutTwoDecimalsIsRefused(): void
    {
        foreach (['jpy', 'kwd', 'sa'] as $currency) {
            $run = IsolatedPhp::run('stripe_client', [], ['APP_ENV' => 'local', 'STRIPE_SECRET_KEY' => 'sk_test_x', 'STRIPE_CURRENCY' => $currency]);
            self::assertSame('CheckoutException', $run['result']['exception'] ?? null, "STRIPE_CURRENCY=$currency");
        }
    }

    public function testLiveKeyIsRefusedForEveryNonProductionEnvironment(): void
    {
        foreach (['testing', 'staging', 'Production'] as $appEnv) {
            $run = IsolatedPhp::run('stripe_client', [], ['APP_ENV' => $appEnv, 'STRIPE_SECRET_KEY' => 'sk_live_not_a_real_key']);
            self::assertSame('CheckoutException', $run['result']['exception'] ?? null, "APP_ENV=$appEnv");
        }
    }

    public function testUnsetAppEnvMeansProduction(): void
    {
        $run = IsolatedPhp::run('stripe_client', [], ['APP_ENV' => null, 'STRIPE_SECRET_KEY' => 'sk_live_not_a_real_key', 'STRIPE_API_BASE' => null]);

        self::assertSame(['ok' => true, 'api_base' => 'https://api.stripe.com'], $run['result'], 'APP_ENV defaults to production');
    }

    public function testLiveKeyIsAllowedInProduction(): void
    {
        $run = IsolatedPhp::run('stripe_client', [], ['APP_ENV' => 'production', 'STRIPE_SECRET_KEY' => 'sk_live_not_a_real_key', 'STRIPE_API_BASE' => null]);

        self::assertSame(['ok' => true, 'api_base' => 'https://api.stripe.com'], $run['result']);
    }

    public function testMissingKeyMeansPaymentsAreNotConfigured(): void
    {
        $run = IsolatedPhp::run('stripe_client', [], ['STRIPE_SECRET_KEY' => null]);

        self::assertSame([
            'ok' => false,
            'exception' => 'CheckoutException',
            'message' => 'Online payments are not configured yet. Please try again later.',
        ], $run['result']);
    }

    public function testApiBaseOverrideIsUsedWithoutTrailingSlash(): void
    {
        $run = IsolatedPhp::run('stripe_client', [], ['STRIPE_SECRET_KEY' => 'sk_test_phpunit', 'STRIPE_API_BASE' => 'http://127.0.0.1:12345/']);

        self::assertSame(['ok' => true, 'api_base' => 'http://127.0.0.1:12345'], $run['result']);
    }
}
