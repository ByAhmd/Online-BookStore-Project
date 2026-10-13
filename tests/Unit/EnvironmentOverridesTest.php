<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use BookWise\Tests\Support\EnvironmentOverrides;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * The suite relies on EnvironmentOverrides restoring the environment after
 * every test; a leak would silently change later tests' configuration.
 */
final class EnvironmentOverridesTest extends TestCase
{
    use EnvironmentOverrides;

    /** @var array{getenv: string|false, inEnv: bool, config: mixed}|null */
    private static ?array $before = null;

    public function testOverridesApplyToEnvAndConfig(): void
    {
        self::$before = [
            'getenv' => getenv('APP_NAME'),
            'inEnv' => array_key_exists('APP_NAME', $_ENV),
            'config' => config('app.name'),
        ];

        $this->setEnv('APP_NAME', 'Overridden For One Test');

        self::assertSame('Overridden For One Test', getenv('APP_NAME'));
        self::assertSame('Overridden For One Test', $_ENV['APP_NAME']);
        self::assertSame('Overridden For One Test', config('app.name'));
    }

    #[Depends('testOverridesApplyToEnvAndConfig')]
    public function testOverridesAreRestoredAfterTheTest(): void
    {
        self::assertNotNull(self::$before);
        self::assertSame(self::$before['getenv'], getenv('APP_NAME'));
        self::assertSame(self::$before['inEnv'], array_key_exists('APP_NAME', $_ENV));
        self::assertSame(self::$before['config'], config('app.name'));
    }
}
