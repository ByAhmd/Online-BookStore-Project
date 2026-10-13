<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTokenIsGeneratedOnceAndStoredInTheSession(): void
    {
        $token = csrf_token();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame($token, csrf_token(), 'The token is stable within a session');
        self::assertSame($token, $_SESSION['_csrf_token']);
    }

    public function testTokensDifferBetweenSessions(): void
    {
        $first = csrf_token();
        $_SESSION = [];

        self::assertNotSame($first, csrf_token());
    }

    public function testCorruptedSessionTokenIsReplaced(): void
    {
        $_SESSION['_csrf_token'] = ['not', 'a', 'string'];

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', csrf_token());
    }

    public function testFieldContainsTheEscapedToken(): void
    {
        $token = csrf_token();

        self::assertSame('<input type="hidden" name="_token" value="' . $token . '">', csrf_field());
    }

    public function testValidTokenIsAccepted(): void
    {
        self::assertTrue(csrf_is_valid(csrf_token()));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'wrong token' => [str_repeat('a', 64)];
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'array' => [['token']];
        yield 'integer' => [0];
        yield 'true' => [true];
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidTokensAreRejected(mixed $candidate): void
    {
        csrf_token();

        self::assertFalse(csrf_is_valid($candidate));
    }

    public function testNearMissesAreRejected(): void
    {
        $token = csrf_token();

        self::assertFalse(csrf_is_valid(strtoupper($token)), 'Comparison is case-sensitive');
        self::assertFalse(csrf_is_valid(substr($token, 0, -1)), 'Prefix');
        self::assertFalse(csrf_is_valid($token . 'a'), 'Extra character');
        self::assertFalse(csrf_is_valid(' ' . $token), 'Not trimmed');
    }

    public function testNothingValidatesWithoutASessionToken(): void
    {
        self::assertFalse(csrf_is_valid(''));
        self::assertFalse(csrf_is_valid('anything'));

        $_SESSION['_csrf_token'] = '';
        self::assertFalse(csrf_is_valid(''), 'An empty expected token never matches an empty submission');
    }

    public function testRotationInvalidatesTheOldToken(): void
    {
        $old = csrf_token();
        rotate_csrf_token();
        $new = csrf_token();

        self::assertNotSame($old, $new);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $new);
        self::assertFalse(csrf_is_valid($old));
        self::assertTrue(csrf_is_valid($new));
    }
}
