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

    public function testALiveKeyIsRefusedInDemoModeEvenInProduction(): void
    {
        // A public demo must never take real money, also when the simulator is switched off.
        $run = IsolatedPhp::run('stripe_client', [], [
            'APP_ENV' => 'production', 'APP_DEMO' => 'true', 'PAYMENT_SIMULATOR' => 'false', 'STRIPE_SECRET_KEY' => 'sk_live_not_a_real_key',
        ]);

        self::assertSame('CheckoutException', $run['result']['exception'] ?? null);
        self::assertStringContainsString('Refusing to use a non-test Stripe key in demo mode', $run['files']['storage/logs/app.log'] ?? '');
        self::assertStringNotContainsString('sk_live_not_a_real_key', $run['files']['storage/logs/app.log'] ?? '');

        $test = IsolatedPhp::run('stripe_client', [], [
            'APP_ENV' => 'production', 'APP_DEMO' => 'true', 'PAYMENT_SIMULATOR' => 'false', 'STRIPE_SECRET_KEY' => 'sk_test_phpunit', 'STRIPE_API_BASE' => null,
        ]);
        self::assertSame(['ok' => true, 'api_base' => 'https://api.stripe.com'], $test['result'], 'A demo may use Stripe test mode');
    }

    public function testTheSimulatorNeedsNoStripeKeyAndAnswersInProcess(): void
    {
        $run = IsolatedPhp::run('simulated_payment', [], [
            'PAYMENT_SIMULATOR' => 'true', 'STRIPE_SECRET_KEY' => null, 'STRIPE_API_BASE' => 'http://127.0.0.1:9', 'APP_URL' => 'https://demo.example.com',
        ]);

        $result = $run['result'];
        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(PAYMENT_SIMULATOR_API_BASE, $result['api_base'], 'STRIPE_API_BASE is ignored: nothing goes over the network');
        self::assertSame(\PaymentSimulatorHttpClient::class, $result['http_client']);
        self::assertMatchesRegularExpression('#^https://demo\.example\.com/pay\.php\?session=cs_test_[a-f0-9]{24}$#', $result['url']);
        self::assertSame(2500, $result['amount_total']);
        self::assertTrue($result['replayed'], 'A repeated idempotency key returns the first session');
        self::assertMatchesRegularExpression('#^https://demo\.example\.com/success_pay\.php\?session_id=cs_test_[a-f0-9]{24}$#', $result['redirect']);
        self::assertSame('paid', $result['payment_status']);
        self::assertSame(['succeeded', 2500], $result['refund']);

        // The state lives in PAYMENT_SIMULATOR_STORE (relative to the project root) and holds no secrets.
        $state = json_decode($run['files']['storage/payment-simulator.json'] ?? '', true);
        self::assertIsArray($state);
        self::assertCount(1, $state['sessions']);
        self::assertCount(1, $state['refunds']);
        self::assertArrayNotHasKey('storage/logs/app.log', $run['files'], 'Nothing is logged');
    }

    public function testDemoModeTurnsTheSimulatorOnUnlessItIsSwitchedOff(): void
    {
        $demo = IsolatedPhp::run('stripe_client', [], ['APP_DEMO' => 'true', 'PAYMENT_SIMULATOR' => null, 'STRIPE_SECRET_KEY' => null]);
        self::assertSame(['ok' => true, 'api_base' => PAYMENT_SIMULATOR_API_BASE], $demo['result']);

        $off = IsolatedPhp::run('stripe_client', [], ['APP_DEMO' => 'true', 'PAYMENT_SIMULATOR' => 'false', 'STRIPE_SECRET_KEY' => null]);
        self::assertSame('Online payments are not configured yet. Please try again later.', $off['result']['message'] ?? null);

        $local = IsolatedPhp::run('stripe_client', [], ['APP_DEMO' => 'false', 'PAYMENT_SIMULATOR' => 'true', 'STRIPE_SECRET_KEY' => null]);
        self::assertSame(['ok' => true, 'api_base' => PAYMENT_SIMULATOR_API_BASE], $local['result'], 'Also without demo mode, e.g. for local development');
    }
}
