<?php

declare(strict_types=1);

/**
 * Shared PDO connection. All queries use prepared statements with native
 * (non-emulated) parameter binding and throw PDOException on failure.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        config('db.host'),
        config('db.port'),
        config('db.name')
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ];
    $caFile = db_ssl_ca_file();
    if ($caFile !== null) {
        // Encrypted connection that verifies the server certificate (required by most hosted MySQL services).
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caFile;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    $pdo = new PDO($dsn, (string) config('db.user'), (string) config('db.pass'), $options);

    // Keep TIMESTAMP values and NOW() in the application's timezone. Named zones
    // handle daylight saving but need the server's time zone tables; fall back
    // to the current UTC offset when they are not loaded.
    try {
        $pdo->prepare('SET time_zone = ?')->execute([date_default_timezone_get()]);
    } catch (PDOException) {
        $pdo->exec("SET time_zone = '" . (new DateTimeImmutable('now'))->format('P') . "'");
    }

    return $pdo;
}

/**
 * Path of the CA certificate for TLS database connections, or null for an
 * unencrypted local connection. DB_SSL_CA_PEM (certificate text) is written
 * to a private temporary file once per process.
 */
function db_ssl_ca_file(): ?string
{
    $path = config('db.ssl_ca');
    if (is_string($path) && $path !== '') {
        if (!is_file($path)) {
            throw new RuntimeException('DB_SSL_CA does not point to a readable file');
        }
        return $path;
    }

    $pem = config('db.ssl_ca_pem');
    if (!is_string($pem) || trim($pem) === '') {
        return null;
    }
    $pem = str_replace('\n', "\n", trim($pem)) . "\n";
    if (!str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
        throw new RuntimeException('DB_SSL_CA_PEM does not contain a PEM certificate');
    }
    $file = sys_get_temp_dir() . '/bookwise-db-ca-' . hash('sha256', $pem) . '.pem';
    if (!is_file($file)) {
        file_put_contents($file, $pem, LOCK_EX);
        @chmod($file, 0600);
    }
    return $file;
}

/**
 * Runs a prepared statement and returns it.
 *
 * @param array<int|string, mixed> $params
 */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * @param array<int|string, mixed> $params
 * @return array<string, mixed>|null
 */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/**
 * @param array<int|string, mixed> $params
 * @return list<array<string, mixed>>
 */
function db_all(string $sql, array $params = []): array
{
    return array_values(db_run($sql, $params)->fetchAll());
}

/**
 * @param array<int|string, mixed> $params
 */
function db_value(string $sql, array $params = []): mixed
{
    $value = db_run($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * Executes $callback inside a transaction, committing on success and rolling
 * back (then rethrowing) on any exception.
 *
 * @template T
 * @param callable(PDO): T $callback
 * @return T
 */
function db_transaction(callable $callback): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $callback($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * The name of one of this store's named locks (GET_LOCK()): $name and the
 * database's name, e.g. "bookwise-checkout-17:bookwise". Named locks belong
 * to the whole database server, which other databases share (another
 * BookWise store, or other accounts on a shared host). Without the database
 * in the name, any of them could hold this store's locks, by chance or on
 * purpose, and hold up its checkouts or demo customers. MySQL accepts names
 * of at most 64 characters: a longer one uses the hash of the database's
 * name instead.
 */
function store_lock_name(string $name): string
{
    $database = (string) config('db.name');
    $lock = $name . ':' . $database;
    return strlen($lock) <= 64 ? $lock : $name . ':' . md5($database);
}

/**
 * True when a PDOException is a duplicate-key violation (SQLSTATE 23000 / 1062).
 */
function is_duplicate_key_error(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? null) === 1062;
}
