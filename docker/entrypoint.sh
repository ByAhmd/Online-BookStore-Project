#!/usr/bin/env bash
#
# BookWise container entrypoint (see docs/DEPLOYMENT.md).
#
# For the default command (apache2-foreground) it:
#   1. derives APP_URL from RENDER_EXTERNAL_URL when APP_URL is not set;
#   2. in demo mode (APP_DEMO=true), configures the built-in payment simulator
#      that Apache serves under /stripe-sim, generating STRIPE_SECRET_KEY
#      (sk_test_...) and STRIPE_WEBHOOK_SECRET when they are missing;
#   3. waits for the database (up to DB_WAIT_SECONDS, default 60);
#   4. runs "php bin/install.php": the schema for an empty database (plus the
#      sample catalog in demo mode or with BOOKWISE_SEED=true), pending
#      migrations for an existing one, and the administrator from
#      BOOKWISE_ADMIN_EMAIL / BOOKWISE_ADMIN_PASSWORD;
#   5. mirrors app.log and php-error.log to the container output and starts
#      Apache in the foreground.
# Any other command (e.g. "bash" or "php bin/create-admin.php ...") runs as is.
#
# Passwords, keys and other secrets are never printed.

set -euo pipefail

BOOKWISE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SIMULATOR_PATH=/stripe-sim
SIMULATOR_STORE=/tmp/bookwise-stripe-sim.json
# 1 when Apache must serve the payment simulator (set by configure_payments).
SIMULATOR=0

log() {
    printf '[bookwise] %s\n' "$*" >&2
}

die() {
    log "ERROR: $*"
    exit 1
}

# Same truthy values as env_bool() in src/env.php.
is_true() {
    local value="${1:-}"
    case "${value,,}" in
        1 | true | yes | on) return 0 ;;
        *) return 1 ;;
    esac
}

# Prints BYTES random bytes as lowercase hex.
random_hex() {
    od -An -N "$1" -tx1 /dev/urandom | tr -d ' \n'
}

configure_port() {
    PORT="${PORT:-8080}"
    if [[ ! "$PORT" =~ ^[0-9]{1,5}$ ]] || ((10#$PORT < 1 || 10#$PORT > 65535)); then
        die "PORT must be a TCP port number (got '${PORT}')."
    fi
    PORT="$((10#$PORT))"
    export PORT
}

configure_app_url() {
    local url="${APP_URL:-}"
    if [[ -z "$url" ]]; then
        if [[ -n "${RENDER_EXTERNAL_URL:-}" ]]; then
            url="$RENDER_EXTERNAL_URL"
        else
            url="http://localhost:${PORT}"
        fi
    fi
    while [[ "$url" == */ ]]; do
        url="${url%/}"
    done
    if [[ ! "$url" =~ ^https?://[^/[:space:]]+(/[^[:space:]]*)?$ ]]; then
        die "APP_URL must be the public http(s) URL of the site (got '${url}')."
    fi
    APP_URL="$url"
    export APP_URL
}

# Demo mode: payments go to the simulator inside this container. Otherwise the
# Stripe settings are left exactly as configured and /stripe-sim is not served.
configure_payments() {
    SIMULATOR=0
    if ! is_true "${APP_DEMO:-false}"; then
        if [[ -n "${STRIPE_API_BASE:-}" ]]; then
            log "WARNING: STRIPE_API_BASE is set, so payments go to ${STRIPE_API_BASE} instead of Stripe."
        fi
        log "Payments: Stripe Checkout (APP_DEMO is off; the payment simulator is not served)."
        return 0
    fi

    local key="${STRIPE_SECRET_KEY:-}"
    case "$key" in
        sk_live_* | rk_live_*)
            die "APP_DEMO=true must never be combined with a live Stripe key. Remove STRIPE_SECRET_KEY (a test key is generated) or set APP_DEMO=false."
            ;;
        sk_test_* | rk_test_*) ;;
        '')
            key="sk_test_$(random_hex 24)"
            ;;
        *)
            # e.g. a random value from the hosting platform (Render's
            # generateValue is base64): keep it, in the shape of a test key.
            key="${key//[^A-Za-z0-9]/}"
            key="sk_test_${key:-$(random_hex 24)}"
            ;;
    esac

    local webhook_secret="${STRIPE_WEBHOOK_SECRET:-}"
    if [[ -z "$webhook_secret" ]]; then
        webhook_secret="whsec_$(random_hex 24)"
    fi

    if [[ -n "${STRIPE_SIM_TEST_API:-}" && "${STRIPE_SIM_TEST_API}" != 0 ]]; then
        log "WARNING: ignoring STRIPE_SIM_TEST_API: the simulator's test controls stay disabled in demo mode."
    fi
    unset STRIPE_SIM_TEST_API

    STRIPE_SECRET_KEY="$key"
    STRIPE_WEBHOOK_SECRET="$webhook_secret"
    # The application calls the simulator over loopback; browsers use the public URL.
    STRIPE_API_BASE="http://127.0.0.1:${PORT}${SIMULATOR_PATH}"
    STRIPE_SIM_KEY="$key"
    STRIPE_SIM_BASE_PATH="$SIMULATOR_PATH"
    STRIPE_SIM_PUBLIC_URL="${APP_URL}${SIMULATOR_PATH}"
    STRIPE_MOCK_STORE="$SIMULATOR_STORE"
    export STRIPE_SECRET_KEY STRIPE_WEBHOOK_SECRET STRIPE_API_BASE \
        STRIPE_SIM_KEY STRIPE_SIM_BASE_PATH STRIPE_SIM_PUBLIC_URL STRIPE_MOCK_STORE
    SIMULATOR=1
    log "Payments: demo mode, simulated at ${STRIPE_SIM_PUBLIC_URL} (no real payments are taken)."
}

log_directory() {
    local dir="${LOG_DIR:-storage/logs}"
    if [[ "$dir" != /* ]]; then
        dir="${BOOKWISE_ROOT}/${dir}"
    fi
    printf '%s' "${dir%/}"
}

prepare_directories() {
    local logs dir
    logs="$(log_directory)"
    mkdir -p "$logs" "${BOOKWISE_ROOT}/public/uploads/avatars" "${BOOKWISE_ROOT}/public/uploads/covers" 2>/dev/null || true
    for dir in "$logs" "${BOOKWISE_ROOT}/public/uploads/avatars" "${BOOKWISE_ROOT}/public/uploads/covers"; do
        if [[ ! -w "$dir" ]]; then
            log "WARNING: ${dir} is not writable by $(id -un 2>/dev/null || echo 'this user')."
        fi
    done
    touch "${logs}/app.log" "${logs}/php-error.log" 2>/dev/null || true
}

wait_for_database() {
    local timeout="${DB_WAIT_SECONDS:-60}" attempt=0 reason start="$SECONDS"
    if [[ ! "$timeout" =~ ^[0-9]+$ ]]; then
        die "DB_WAIT_SECONDS must be a number of seconds (got '${timeout}')."
    fi
    log "Waiting for the database '${DB_NAME:-bookwise}' at ${DB_HOST:-127.0.0.1}:${DB_PORT:-3306} (up to ${timeout}s)..."
    while true; do
        attempt=$((attempt + 1))
        if reason="$(php "${BOOKWISE_ROOT}/docker/db-ready.php" 2>&1)"; then
            log "Database is reachable."
            return 0
        fi
        if ((SECONDS - start >= timeout)); then
            log "Database still unreachable after ${timeout}s: ${reason}"
            return 1
        fi
        log "Database not ready yet (attempt ${attempt}): ${reason}"
        sleep 3
    done
}

# Copies new lines of app.log and php-error.log to the container output (the
# only log a hosting dashboard shows). mail.log is left out on purpose: with
# MAIL_DRIVER=log it contains password-reset links. Stops with this process.
mirror_logs() {
    local logs
    logs="$(log_directory)"
    tail --pid="$$" -q -n 0 -F "${logs}/app.log" "${logs}/php-error.log" >&2 2>/dev/null &
}

main() {
    if (($# == 0)); then
        set -- apache2-foreground
    fi
    if [[ "$1" != apache2-foreground ]]; then
        exec "$@"
    fi

    cd "$BOOKWISE_ROOT"
    configure_port
    configure_app_url
    configure_payments

    local tls=off
    if [[ -n "${DB_SSL_CA:-}${DB_SSL_CA_PEM:-}" ]]; then
        tls=on
    fi
    log "APP_URL=${APP_URL} APP_ENV=${APP_ENV:-production} APP_DEMO=$(is_true "${APP_DEMO:-false}" && echo true || echo false) MAIL_DRIVER=${MAIL_DRIVER:-log} database TLS=${tls}"

    prepare_directories
    wait_for_database || die "Giving up. Check DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS (and DB_SSL_CA_PEM for a hosted MySQL that requires TLS)."

    # The sample catalog is for demos: a real store starts with an empty catalog.
    local install_args=()
    if is_true "${APP_DEMO:-false}" || is_true "${BOOKWISE_SEED:-false}"; then
        install_args=(--seed)
    fi
    log "Preparing the database (php bin/install.php ${install_args[*]:-})..."
    php "${BOOKWISE_ROOT}/bin/install.php" "${install_args[@]}" || die "Database installation failed (see the messages above)."

    mirror_logs
    if ((SIMULATOR)); then
        set -- "$@" -D BOOKWISE_STRIPE_SIM
    fi
    log "Starting Apache on port ${PORT}."
    exec "$@"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
