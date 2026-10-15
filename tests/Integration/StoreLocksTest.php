<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\CheckoutTestCase;
use PDO;
use RuntimeException;

/**
 * The store's named locks (GET_LOCK) belong to the whole database server,
 * which other databases share: another BookWise store, other accounts of a
 * shared host, or parallel test runs. Their names include this store's
 * database (store_lock_name()), so locks held anywhere else never hold up
 * this store's checkouts, account deletions or demo customers, while this
 * store's own requests still wait for each other.
 */
final class StoreLocksTest extends CheckoutTestCase
{
    /** How long the stand-in for a concurrent request of this store holds a lock. */
    private const LOCK_HOLD_SECONDS = 2.0;

    private ?PDO $other = null;

    protected function tearDown(): void
    {
        // Closing the other session releases every lock it holds.
        $this->other = null;
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * A second session on the same server that never selects this store's
     * database, as another store or another account of a shared host would.
     */
    private function otherSession(): PDO
    {
        return $this->other ??= new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', config('db.host'), config('db.port')),
            (string) config('db.user'),
            (string) config('db.pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function holdElsewhere(string ...$locks): void
    {
        foreach ($locks as $lock) {
            $statement = $this->otherSession()->prepare('SELECT GET_LOCK(?, 0)');
            $statement->execute([$lock]);
            self::assertSame(1, (int) $statement->fetchColumn(), "The other session holds $lock");
        }
    }

    /**
     * Runs $action while another PHP process holds $lock on its own
     * connection to this store's database, as a concurrent request of this
     * store would, and releases it after LOCK_HOLD_SECONDS. Returns how long
     * $action took.
     */
    private function timeWhileHeldByAnotherRequest(string $lock, callable $action): float
    {
        $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-lock-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($script, <<<'PHP'
            <?php
            $pdo = new PDO(getenv('LOCK_DSN'), getenv('LOCK_USER'), getenv('LOCK_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $statement = $pdo->prepare('SELECT GET_LOCK(?, 5)');
            $statement->execute([getenv('LOCK_NAME')]);
            echo (int) $statement->fetchColumn() === 1 ? "held\n" : "not held\n";
            fflush(STDOUT);
            usleep((int) (1000000 * (float) getenv('LOCK_SECONDS')));
            PHP);
        try {
            $process = proc_open(
                [PHP_BINARY, $script],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                [
                    'LOCK_DSN' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', config('db.host'), config('db.port'), config('db.name')),
                    'LOCK_USER' => (string) config('db.user'),
                    'LOCK_PASS' => (string) config('db.pass'),
                    'LOCK_NAME' => $lock,
                    'LOCK_SECONDS' => (string) self::LOCK_HOLD_SECONDS,
                    // Windows needs it to open network connections.
                    'SystemRoot' => (string) getenv('SystemRoot'),
                ]
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start the process that holds the lock.');
            }
            fclose($pipes[0]);
            $answer = trim((string) fgets($pipes[1]));
            if ($answer !== 'held') {
                fclose($pipes[1]);
                self::fail("The other process could not take $lock: " . $answer . stream_get_contents($pipes[2]));
            }
            $started = microtime(true);
            $action();
            $took = microtime(true) - $started;
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return $took;
        } finally {
            @unlink($script);
        }
    }

    public function testLockNamesNameThisStoresDatabase(): void
    {
        $database = (string) config('db.name');
        self::assertSame('bookwise-checkout-17:' . $database, checkout_lock_name(17));
        self::assertSame('bookwise-demo-guests:' . $database, store_lock_name(DEMO_GUESTS_LOCK));
        self::assertSame('bookwise-demo-showcase:' . $database, demo_showcase_lock_name());

        // MySQL accepts lock names of at most 64 characters: a long database name is hashed.
        $long = str_repeat('bookwise_', 6) . 'shop_test';
        $this->setEnv('DB_NAME', $long);
        self::assertSame('bookwise-checkout-2147483647:' . md5($long), checkout_lock_name(2147483647));
        self::assertLessThanOrEqual(64, strlen(checkout_lock_name(2147483647)));
        self::assertSame('bookwise-demo-guests:' . md5($long), store_lock_name(DEMO_GUESTS_LOCK));
    }

    public function testCheckoutsAndAccountDeletionIgnoreLocksOfOtherDatabases(): void
    {
        $user = $this->customerWithCart([7 => 1]);
        $userId = (int) $user['id'];
        // The name without a database (as before) and that of another store, for this customer id.
        $this->holdElsewhere('bookwise-checkout-' . $userId, 'bookwise-checkout-' . $userId . ':another_store_test');

        $started = microtime(true);
        [$sessionId] = $this->checkout($user);
        self::assertLessThan(10.0, microtime(true) - $started, 'Not held up by the other session (it would wait 15 seconds)');
        self::assertSame('pending', $this->orderForSession($sessionId)['status']);

        $started = microtime(true);
        self::assertSame('deleted', erase_customer_account($userId)['status']);
        self::assertLessThan(10.0, microtime(true) - $started, 'Not held up by the other session');
        self::assertNull(find_user($userId));
    }

    public function testDemoCustomersIgnoreLocksOfOtherDatabases(): void
    {
        $this->holdElsewhere(DEMO_GUESTS_LOCK, DEMO_GUESTS_LOCK . ':another_store_test');

        $started = microtime(true);
        self::assertSame('created', create_demo_guest('198.51.100.7')['status']);
        self::assertLessThan(8.0, microtime(true) - $started, 'Not held up by the other session (it would wait 10 seconds)');
    }

    public function testThisStoresOwnRequestsStillWaitForEachOther(): void
    {
        $user = $this->customerWithCart([7 => 1]);

        $waited = $this->timeWhileHeldByAnotherRequest(checkout_lock_name((int) $user['id']), function () use ($user): void {
            $this->checkout($user);
        });
        self::assertGreaterThan(self::LOCK_HOLD_SECONDS / 2, $waited, 'The checkout waited for the other request');

        $result = null;
        $waited = $this->timeWhileHeldByAnotherRequest(store_lock_name(DEMO_GUESTS_LOCK), static function () use (&$result): void {
            $result = create_demo_guest('198.51.100.8');
        });
        self::assertSame('created', $result['status'] ?? null);
        self::assertGreaterThan(self::LOCK_HOLD_SECONDS / 2, $waited, 'The demo customer waited for the other request');
    }
}
