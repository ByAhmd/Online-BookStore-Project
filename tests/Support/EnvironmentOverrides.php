<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use PHPUnit\Framework\Attributes\After;

/**
 * Temporarily changes environment variables (process env and $_ENV, which
 * env() reads first) and rebuilds app_config(). Everything is restored after
 * each test.
 */
trait EnvironmentOverrides
{
    /** @var array<string, array{getenv: string|false, inEnv: bool, env: mixed}> */
    private array $environmentBackup = [];

    protected function setEnv(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->environmentBackup)) {
            $this->environmentBackup[$name] = [
                'getenv' => getenv($name),
                'inEnv' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
            ];
        }

        if ($value === null) {
            putenv($name);
            unset($_ENV[$name]);
        } else {
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
        app_config(true);
    }

    #[After]
    protected function restoreEnvironment(): void
    {
        if ($this->environmentBackup === []) {
            return;
        }
        foreach ($this->environmentBackup as $name => $original) {
            if ($original['getenv'] === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $original['getenv']);
            }
            if ($original['inEnv']) {
                $_ENV[$name] = $original['env'];
            } else {
                unset($_ENV[$name]);
            }
        }
        $this->environmentBackup = [];
        app_config(true);
    }
}
