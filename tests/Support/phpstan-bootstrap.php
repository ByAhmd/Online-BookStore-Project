<?php

declare(strict_types=1);

// Constants that src/bootstrap.php defines at runtime, declared for PHPStan.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
