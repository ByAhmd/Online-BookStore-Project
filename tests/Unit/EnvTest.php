<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use BookWise\Tests\Support\EnvironmentOverrides;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    use EnvironmentOverrides;

    private string $prefix;
    private string $file;

    protected function setUp(): void
    {
        $this->prefix = 'BW_ENVTEST_' . strtoupper(bin2hex(random_bytes(4))) . '_';
        $this->file = (string) tempnam(sys_get_temp_dir(), 'bookwise-env-');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        $names = array_merge(array_keys($_ENV), array_keys(getenv()));
        foreach ($names as $name) {
            if (str_starts_with(strtoupper((string) $name), $this->prefix)) {
                putenv((string) $name);
                unset($_ENV[$name]);
            }
        }
    }

    private function loadLines(string ...$lines): void
    {
        file_put_contents($this->file, implode("\n", $lines) . "\n");
        load_env($this->file);
    }

    private function loaded(string $suffix): string|false
    {
        $fromProcess = getenv($this->prefix . $suffix);
        $fromEnv = $_ENV[$this->prefix . $suffix] ?? false;
        self::assertSame($fromProcess, $fromEnv, "getenv() and \$_ENV disagree for $suffix");
        return $fromProcess;
    }

    public function testParsesPlainExportedAndQuotedValues(): void
    {
        $p = $this->prefix;
        $this->loadLines(
            "{$p}PLAIN=value",
            "export {$p}EXPORTED=yes",
            "{$p}DOUBLE=\"double # not a comment\"",
            "{$p}SINGLE='single \$HOME \\n kept'",
            "{$p}SPACED =   padded value   ",
            "{$p}INLINE=value # trailing comment",
            "{$p}HASH=abc#def",
            "{$p}EQUALS=a=b=c",
        );

        self::assertSame('value', $this->loaded('PLAIN'));
        self::assertSame('yes', $this->loaded('EXPORTED'));
        self::assertSame('double # not a comment', $this->loaded('DOUBLE'));
        self::assertSame('single $HOME \\n kept', $this->loaded('SINGLE'), 'Single quotes are literal');
        self::assertSame('padded value', $this->loaded('SPACED'));
        self::assertSame('value', $this->loaded('INLINE'));
        self::assertSame('abc#def', $this->loaded('HASH'), 'A # without a preceding space is part of the value');
        self::assertSame('a=b=c', $this->loaded('EQUALS'));
    }

    public function testDoubleQuotedValuesSupportEscapes(): void
    {
        $this->loadLines($this->prefix . 'ESCAPED="line1\\nline2 \\"quoted\\""');

        self::assertSame("line1\nline2 \"quoted\"", $this->loaded('ESCAPED'));
    }

    public function testSkipsCommentsBlankLinesAndInvalidKeys(): void
    {
        $p = $this->prefix;
        $this->loadLines(
            '# a comment',
            "   # an indented comment with {$p}COMMENTED=1",
            '',
            "{$p}VALID=1",
            "1{$p}DIGIT=x",
            "{$p}BAD-KEY=x",
            "{$p}NO_EQUALS_SIGN",
        );

        self::assertSame('1', $this->loaded('VALID'));
        self::assertFalse($this->loaded('COMMENTED'));
        self::assertFalse(getenv('1' . $p . 'DIGIT'));
        self::assertFalse($this->loaded('BAD-KEY'));
        self::assertFalse($this->loaded('NO_EQUALS_SIGN'));
    }

    public function testHandlesWindowsLineEndings(): void
    {
        file_put_contents($this->file, "{$this->prefix}A=1\r\n{$this->prefix}B=two words\r\n");
        load_env($this->file);

        self::assertSame('1', $this->loaded('A'));
        self::assertSame('two words', $this->loaded('B'));
    }

    public function testDoesNotOverrideExistingEnvironmentVariables(): void
    {
        $p = $this->prefix;
        putenv("{$p}FROM_PROCESS=real");
        $_ENV["{$p}FROM_ENV_ARRAY"] = 'server';

        $this->loadLines("{$p}FROM_PROCESS=file", "{$p}FROM_ENV_ARRAY=file", "{$p}NEW=file");

        self::assertSame('real', getenv("{$p}FROM_PROCESS"));
        self::assertSame('server', $_ENV["{$p}FROM_ENV_ARRAY"]);
        self::assertFalse(getenv("{$p}FROM_ENV_ARRAY"), 'A key already in $_ENV must not be exported from the file');
        self::assertSame('file', $this->loaded('NEW'));
    }

    public function testFirstDefinitionInTheFileWins(): void
    {
        $this->loadLines("{$this->prefix}DUP=first", "{$this->prefix}DUP=second");

        self::assertSame('first', $this->loaded('DUP'));
    }

    public function testMissingFileIsIgnored(): void
    {
        load_env($this->file . '-does-not-exist');

        self::assertFalse(getenv($this->prefix . 'ANYTHING'));
    }

    public function testEmptyValueFallsBackToTheDefault(): void
    {
        $this->loadLines("{$this->prefix}EMPTY=");

        self::assertSame('fallback', env($this->prefix . 'EMPTY', 'fallback'));
        self::assertNull(env($this->prefix . 'EMPTY'));
    }

    /**
     * Regression test for a fixed bug (src/env.php load_env): a double-quoted value followed by
     * an inline comment keeps its quotes, e.g. APP_NAME="Book Wise" # shown in
     * the header  =>  APP_NAME is "\"Book Wise\"". The quote check requires the
     * line to end with the quote, so the value falls through to the unquoted
     * branch, which only strips the comment.
     */
    public function testQuotedValueFollowedByInlineComment(): void
    {
        $this->loadLines($this->prefix . 'NAME="Book Wise" # shown in the header');

        self::assertSame('Book Wise', $this->loaded('NAME'));
    }

    /**
     * Regression test for a fixed bug (src/env.php load_env): escape sequences are replaced one
     * after another with str_replace, so an escaped backslash followed by "n"
     * ("C:\\new") becomes a backslash + newline instead of "C:\new".
     */
    public function testEscapedBackslashIsNotTurnedIntoNewline(): void
    {
        $this->loadLines($this->prefix . 'WINPATH="C:\\\\new\\\\dir"');

        self::assertSame('C:\\new\\dir', $this->loaded('WINPATH'));
    }

    public function testEnvReturnsDefaultWhenUnsetOrEmpty(): void
    {
        $name = $this->prefix . 'READ';
        self::assertSame('default', env($name, 'default'));
        self::assertNull(env($name));

        $this->setEnv($name, '');
        self::assertSame('default', env($name, 'default'));

        $this->setEnv($name, 'value');
        self::assertSame('value', env($name, 'default'));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: bool, 2: bool}>
     */
    public static function boolValues(): iterable
    {
        foreach (['1', 'true', 'TRUE', 'True', 'yes', 'on', 'ON'] as $value) {
            yield "truthy '$value'" => [$value, false, true];
        }
        foreach (['0', 'false', 'no', 'off', 'enabled', ' true', '2'] as $value) {
            yield "falsy '$value'" => [$value, true, false];
        }
        yield 'unset uses default true' => [null, true, true];
        yield 'unset uses default false' => [null, false, false];
        yield 'empty uses default' => ['', true, true];
    }

    #[DataProvider('boolValues')]
    public function testEnvBool(?string $value, bool $default, bool $expected): void
    {
        $name = $this->prefix . 'FLAG';
        $this->setEnv($name, $value);

        self::assertSame($expected, env_bool($name, $default));
    }

    public function testConfigDotAccessAndNormalisation(): void
    {
        $this->setEnv('APP_URL', 'https://shop.example.com/');
        $this->setEnv('STRIPE_CURRENCY', 'USD');
        $this->setEnv('DB_PORT', '3399');
        $this->setEnv('APP_DEBUG', 'off');

        self::assertSame('https://shop.example.com', config('app.url'), 'Trailing slash is trimmed');
        self::assertSame('usd', config('stripe.currency'), 'Currency is lower-cased');
        self::assertSame(3399, config('db.port'), 'Port is an integer');
        self::assertFalse(config('app.debug'));
        self::assertIsArray(config('app'));
        self::assertSame('fallback', config('app.does_not_exist', 'fallback'));
        self::assertSame('fallback', config('nothing.here', 'fallback'));
        self::assertSame('fallback', config('app.url.deeper', 'fallback'), 'Cannot descend into a scalar');
        self::assertNull(config('app.does_not_exist'));
    }

    public function testConfigUsesSecureDefaultsWhenUnset(): void
    {
        $this->setEnv('APP_ENV', null);
        $this->setEnv('APP_DEBUG', null);
        $this->setEnv('MAIL_DRIVER', null);
        $this->setEnv('DB_PASS', null);

        self::assertSame('production', config('app.env'));
        self::assertFalse(config('app.debug'));
        self::assertSame('log', config('mail.driver'));
        self::assertSame('', config('db.pass'));
    }

    public function testAppConfigIsCachedUntilRefreshed(): void
    {
        $this->setEnv('APP_NAME', 'Before');
        self::assertSame('Before', config('app.name'));

        putenv('APP_NAME=After');
        $_ENV['APP_NAME'] = 'After';
        self::assertSame('Before', config('app.name'), 'Cached until app_config(true)');

        app_config(true);
        self::assertSame('After', config('app.name'));
    }
}
