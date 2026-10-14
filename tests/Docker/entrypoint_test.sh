#!/usr/bin/env bash
#
# Tests for docker/entrypoint.sh that need neither Docker nor Apache.
#
#   tests/Docker/entrypoint_test.sh
#       The configuration logic: PORT, APP_URL, demo payment settings.
#
#   ENTRYPOINT_TEST_DB_NAME=bookwise_deploy_test DB_HOST=127.0.0.1 DB_PORT=3306 \
#   DB_USER=root DB_PASS=secret tests/Docker/entrypoint_test.sh
#       Also runs the whole entrypoint against that database, with a stand-in
#       for apache2-foreground: waiting for the database, the installation
#       (schema, sample catalog, administrator), re-runs, and failures. Needs
#       php with pdo_mysql. EVERY TABLE IN THAT DATABASE IS DROPPED; its name
#       must end in "_test".

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENTRYPOINT="${ROOT}/docker/entrypoint.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

TESTS=0
FAILURES=0

ok() {
    TESTS=$((TESTS + 1))
    printf 'ok %d - %s\n' "$TESTS" "$1"
}

not_ok() {
    TESTS=$((TESTS + 1))
    FAILURES=$((FAILURES + 1))
    printf 'not ok %d - %s\n' "$TESTS" "$1"
    if [[ -n "${2:-}" ]]; then
        printf '    %s\n' "$2"
    fi
}

assert_eq() { # expected actual description
    if [[ "$2" == "$1" ]]; then ok "$3"; else not_ok "$3" "expected '$1', got '$2'"; fi
}

assert_match() { # regex actual description
    if [[ "$2" =~ $1 ]]; then ok "$3"; else not_ok "$3" "'$2' does not match /$1/"; fi
}

assert_contains() { # needle haystack description
    if [[ "$2" == *"$1"* ]]; then ok "$3"; else not_ok "$3" "output does not contain '$1'"; fi
}

assert_not_contains() { # needle haystack description
    if [[ "$2" != *"$1"* ]]; then ok "$3"; else not_ok "$3" "output contains '$1'"; fi
}

# configure VAR=VALUE...
# Runs the entrypoint's configuration steps in a clean environment that holds
# only the given variables. Sets CONFIG_STATUS, CONFIG_LOG (stderr) and CONFIG
# (the exported variables the steps produce, KEY=VALUE per line, plus SIMULATOR).
configure() {
    CONFIG_STATUS=0
    env -i PATH="$PATH" "$@" bash -c '
        source "$1"
        configure_port
        configure_app_url
        configure_payments
        env | grep -E "^(PORT|APP_URL|STRIPE_[A-Z_]+)=" | sort
        echo "SIMULATOR=$SIMULATOR"
    ' configure "$ENTRYPOINT" >"$WORK/config.out" 2>"$WORK/config.err" || CONFIG_STATUS=$?
    CONFIG="$(cat "$WORK/config.out")"
    CONFIG_LOG="$(cat "$WORK/config.err")"
}

# value KEY: the value of KEY in CONFIG ("<unset>" when absent).
value() {
    local line
    line="$(grep -E "^$1=" <<<"$CONFIG" || true)"
    if [[ -z "$line" ]]; then
        printf '<unset>'
    else
        printf '%s' "${line#*=}"
    fi
}

echo "# Configuration"

configure
assert_eq 0 "$CONFIG_STATUS" "defaults: succeeds"
assert_eq 8080 "$(value PORT)" "defaults: PORT is 8080"
assert_eq http://localhost:8080 "$(value APP_URL)" "defaults: APP_URL is http://localhost:PORT"
assert_eq 0 "$(value SIMULATOR)" "defaults: the simulator is not served"
assert_eq '<unset>' "$(value STRIPE_API_BASE)" "defaults: payments are not redirected"

configure PORT=10000 RENDER_EXTERNAL_URL=https://bookwise-demo.onrender.com/
assert_eq https://bookwise-demo.onrender.com "$(value APP_URL)" "APP_URL comes from RENDER_EXTERNAL_URL without the trailing slash"
assert_eq 10000 "$(value PORT)" "PORT from the platform is kept"

configure APP_URL=https://shop.example.com// RENDER_EXTERNAL_URL=https://other.onrender.com
assert_eq https://shop.example.com "$(value APP_URL)" "an explicit APP_URL wins over RENDER_EXTERNAL_URL"

configure APP_URL=ftp://shop.example.com
assert_eq 1 "$CONFIG_STATUS" "a non-http APP_URL is refused"

configure PORT=abc
assert_eq 1 "$CONFIG_STATUS" "a non-numeric PORT is refused"
configure PORT=70000
assert_eq 1 "$CONFIG_STATUS" "an out-of-range PORT is refused"
configure PORT=08080
assert_eq 8080 "$(value PORT)" "PORT with a leading zero is read as decimal"

echo "# Demo payments"

configure APP_DEMO=true PORT=10000 RENDER_EXTERNAL_URL=https://bookwise-demo.onrender.com
key="$(value STRIPE_SECRET_KEY)"
assert_eq 0 "$CONFIG_STATUS" "demo: succeeds without Stripe settings"
assert_match '^sk_test_[0-9a-f]{48}$' "$key" "demo: a random sk_test_ key is generated"
assert_eq "$key" "$(value STRIPE_SIM_KEY)" "demo: the simulator requires exactly the store's key"
assert_match '^whsec_[0-9a-f]{48}$' "$(value STRIPE_WEBHOOK_SECRET)" "demo: a webhook secret is generated"
assert_eq http://127.0.0.1:10000/stripe-sim "$(value STRIPE_API_BASE)" "demo: the app calls the simulator over loopback on PORT"
assert_eq https://bookwise-demo.onrender.com/stripe-sim "$(value STRIPE_SIM_PUBLIC_URL)" "demo: browsers are sent to APP_URL/stripe-sim"
assert_eq /stripe-sim "$(value STRIPE_SIM_BASE_PATH)" "demo: the simulator is mounted under /stripe-sim"
assert_eq /tmp/bookwise-stripe-sim.json "$(value STRIPE_MOCK_STORE)" "demo: the simulator state lives in /tmp"
assert_eq 1 "$(value SIMULATOR)" "demo: Apache serves the simulator"
assert_not_contains "$key" "$CONFIG_LOG" "demo: the generated key is not printed"

configure APP_DEMO=true
assert_eq '<unset>' "$(value STRIPE_SIM_TEST_API)" "demo: the simulator's test controls stay off"
other_key="$(value STRIPE_SECRET_KEY)"
if [[ "$other_key" != "$key" ]]; then ok "demo: every start generates a different key"; else not_ok "demo: every start generates a different key"; fi

configure APP_DEMO=true STRIPE_SIM_TEST_API=1
assert_eq '<unset>' "$(value STRIPE_SIM_TEST_API)" "demo: STRIPE_SIM_TEST_API=1 is removed"
assert_contains "ignoring STRIPE_SIM_TEST_API" "$CONFIG_LOG" "demo: removing STRIPE_SIM_TEST_API is reported"

configure APP_DEMO=yes 'STRIPE_SECRET_KEY=q1W+e/R5t9yU=' STRIPE_WEBHOOK_SECRET=render-generated
assert_eq sk_test_q1WeR5t9yU "$(value STRIPE_SECRET_KEY)" "demo: a platform-generated base64 value becomes a stable sk_test_ key"
assert_eq render-generated "$(value STRIPE_WEBHOOK_SECRET)" "demo: a provided webhook secret is kept"
assert_eq 1 "$(value SIMULATOR)" "demo: APP_DEMO=yes counts as true"

configure APP_DEMO=TRUE STRIPE_SECRET_KEY=sk_test_provided123
assert_eq sk_test_provided123 "$(value STRIPE_SECRET_KEY)" "demo: a provided test key is kept"

configure APP_DEMO=true STRIPE_SECRET_KEY=sk_live_doNotUse987
assert_eq 1 "$CONFIG_STATUS" "demo: a live key is refused"
assert_not_contains sk_live_doNotUse987 "$CONFIG_LOG" "demo: the refused live key is not printed"

configure APP_DEMO=false STRIPE_SECRET_KEY=sk_live_realStore456 STRIPE_WEBHOOK_SECRET=whsec_real
assert_eq 0 "$CONFIG_STATUS" "real mode: succeeds"
assert_eq sk_live_realStore456 "$(value STRIPE_SECRET_KEY)" "real mode: the Stripe key is left alone"
assert_eq '<unset>' "$(value STRIPE_API_BASE)" "real mode: payments go to Stripe"
assert_eq '<unset>' "$(value STRIPE_SIM_KEY)" "real mode: no simulator settings"
assert_eq 0 "$(value SIMULATOR)" "real mode: the simulator is not served"
assert_not_contains sk_live_realStore456 "$CONFIG_LOG" "real mode: the key is not printed"

echo "# Other commands"

other_output="$(env -i PATH="$PATH" DB_HOST=192.0.2.1 bash "$ENTRYPOINT" echo hello-from-command 2>&1)"
assert_eq hello-from-command "$other_output" "a command other than apache2-foreground runs directly, without setup"

if [[ -z "${ENTRYPOINT_TEST_DB_NAME:-}" ]]; then
    echo "# Skipping the database tests (set ENTRYPOINT_TEST_DB_NAME to a database whose name ends in _test)"
else
    if [[ "$ENTRYPOINT_TEST_DB_NAME" != *_test ]]; then
        echo "ENTRYPOINT_TEST_DB_NAME must end in _test (its tables are dropped)." >&2
        exit 2
    fi
    echo "# Full entrypoint against ${ENTRYPOINT_TEST_DB_NAME}"

    db_env=(
        "DB_HOST=${DB_HOST:-127.0.0.1}"
        "DB_PORT=${DB_PORT:-3306}"
        "DB_NAME=${ENTRYPOINT_TEST_DB_NAME}"
        "DB_USER=${DB_USER:-root}"
        "DB_PASS=${DB_PASS:-}"
    )

    # db_query SQL: first column of the first row (via the application's own code).
    db_query() {
        env "${db_env[@]}" php -r '
            define("BOOKWISE_NO_SESSION", true);
            require $argv[1] . "/src/bootstrap.php";
            $value = db_value($argv[2]);
            echo $value === null ? "" : (string) $value;
        ' "$ROOT" "$1"
    }

    # drop_tables: empties the test database.
    drop_tables() {
        env "${db_env[@]}" php -r '
            require $argv[1] . "/src/bootstrap.php";
            db()->exec("SET FOREIGN_KEY_CHECKS = 0");
            foreach (db()->query("SHOW FULL TABLES WHERE Table_type = \"BASE TABLE\"")->fetchAll(PDO::FETCH_NUM) as $row) {
                db()->exec("DROP TABLE `" . str_replace("`", "``", $row[0]) . "`");
            }
            db()->exec("SET FOREIGN_KEY_CHECKS = 1");
        ' "$ROOT"
    }
    drop_tables

    # Stand-in for Apache: records its arguments and the payment settings it was given.
    mkdir -p "$WORK/bin"
    cat >"$WORK/bin/apache2-foreground" <<'STUB'
#!/usr/bin/env bash
{
    printf 'ARGS=%s\n' "$*"
    env | grep -E '^(APP_URL|PORT|STRIPE_[A-Z_]+)=' | sort
} >"$APACHE_STUB_OUTPUT"
STUB
    chmod +x "$WORK/bin/apache2-foreground"

    admin_password="Admin-pass-$RANDOM-$RANDOM"
    run_entrypoint() { # VAR=VALUE...; sets RUN_STATUS, RUN_LOG, APACHE
        RUN_STATUS=0
        rm -f "$WORK/apache.out"
        env PATH="$WORK/bin:$PATH" APACHE_STUB_OUTPUT="$WORK/apache.out" PORT=18080 DB_WAIT_SECONDS=10 \
            "${db_env[@]}" "$@" bash "$ENTRYPOINT" >"$WORK/run.log" 2>&1 || RUN_STATUS=$?
        RUN_LOG="$(cat "$WORK/run.log")"
        APACHE="$(cat "$WORK/apache.out" 2>/dev/null || true)"
    }

    run_entrypoint APP_DEMO=true BOOKWISE_ADMIN_EMAIL=admin@example.test "BOOKWISE_ADMIN_PASSWORD=${admin_password}"
    assert_eq 0 "$RUN_STATUS" "first start: succeeds"
    assert_contains "Database is reachable." "$RUN_LOG" "first start: waits for the database"
    assert_contains "Created schema" "$RUN_LOG" "first start: creates the schema"
    assert_contains "Loaded the sample catalog (55 books)." "$RUN_LOG" "first start: loads the sample catalog"
    assert_contains "Created administrator admin@example.test." "$RUN_LOG" "first start: creates the administrator"
    assert_eq 55 "$(db_query 'SELECT COUNT(*) FROM books')" "first start: 55 books in the database"
    assert_eq 1 "$(db_query "SELECT COUNT(*) FROM admins WHERE Eaddress = 'admin@example.test'")" "first start: the administrator exists"
    assert_contains "ARGS=-D BOOKWISE_STRIPE_SIM" "$APACHE" "first start: Apache is started with the simulator in demo mode"
    assert_contains "STRIPE_API_BASE=http://127.0.0.1:18080/stripe-sim" "$APACHE" "first start: Apache gets the demo payment settings"
    assert_not_contains "$admin_password" "$RUN_LOG" "first start: the admin password is not printed"
    generated_key="$(grep -E '^STRIPE_SECRET_KEY=' <<<"$APACHE" | cut -d= -f2- || true)"
    assert_match '^sk_test_' "$generated_key" "first start: Apache gets the generated key"
    assert_not_contains "$generated_key" "$RUN_LOG" "first start: the generated key is not printed"

    run_entrypoint APP_DEMO=true BOOKWISE_ADMIN_EMAIL=admin@example.test "BOOKWISE_ADMIN_PASSWORD=${admin_password}"
    assert_eq 0 "$RUN_STATUS" "restart: succeeds"
    assert_contains "Database schema is up to date." "$RUN_LOG" "restart: the existing schema is kept"
    assert_not_contains "Loaded the sample catalog" "$RUN_LOG" "restart: the catalog is not loaded twice"
    assert_not_contains "administrator" "$RUN_LOG" "restart: the unchanged administrator is left alone"
    assert_eq 55 "$(db_query 'SELECT COUNT(*) FROM books')" "restart: still 55 books"

    # Demo mode puts back the copies visitors bought.
    env "${db_env[@]}" php -r 'require $argv[1] . "/src/bootstrap.php"; db()->exec("UPDATE books SET stock = 0");' "$ROOT"
    run_entrypoint APP_DEMO=true
    assert_contains "Demo: restocked 55 book(s)." "$RUN_LOG" "demo restart: restocks the catalog"
    assert_eq 0 "$(db_query 'SELECT COUNT(*) FROM books WHERE stock < 3')" "demo restart: every book has copies again"

    run_entrypoint APP_DEMO=false STRIPE_SECRET_KEY=sk_test_realmode
    assert_eq 0 "$RUN_STATUS" "real mode: succeeds"
    assert_eq "ARGS=" "$(grep -E '^ARGS=' <<<"$APACHE")" "real mode: Apache is started without the simulator"
    assert_not_contains "Demo: restocked" "$RUN_LOG" "real mode: stock is left alone"

    # A real store starts with an empty catalog, unless BOOKWISE_SEED asks for the sample.
    drop_tables
    run_entrypoint APP_DEMO=false STRIPE_SECRET_KEY=sk_test_realmode
    assert_eq 0 "$RUN_STATUS" "real mode, empty database: succeeds"
    assert_contains "Created schema" "$RUN_LOG" "real mode, empty database: creates the schema"
    assert_eq 0 "$(db_query 'SELECT COUNT(*) FROM books')" "real mode, empty database: no sample books"
    drop_tables
    run_entrypoint APP_DEMO=false STRIPE_SECRET_KEY=sk_test_realmode BOOKWISE_SEED=true
    assert_eq 55 "$(db_query 'SELECT COUNT(*) FROM books')" "real mode with BOOKWISE_SEED=true: the sample catalog"

    run_entrypoint APP_DEMO=true BOOKWISE_ADMIN_EMAIL=not-an-email "BOOKWISE_ADMIN_PASSWORD=${admin_password}"
    assert_eq 1 "$RUN_STATUS" "an invalid administrator email stops the container"
    assert_eq "" "$APACHE" "an invalid administrator email: Apache is not started"

    wrong_password="Wrong-db-pass-$RANDOM"
    run_entrypoint APP_DEMO=true DB_WAIT_SECONDS=2 "DB_PASS=${wrong_password}"
    assert_eq 1 "$RUN_STATUS" "a database that refuses the login stops the container"
    assert_contains "Giving up." "$RUN_LOG" "a database that refuses the login is reported"
    assert_not_contains "$wrong_password" "$RUN_LOG" "the database password is not printed"
    assert_eq "" "$APACHE" "an unreachable database: Apache is not started"
fi

echo "1..${TESTS}"
if ((FAILURES > 0)); then
    echo "${FAILURES} of ${TESTS} entrypoint tests failed." >&2
    exit 1
fi
echo "All ${TESTS} entrypoint tests passed."
