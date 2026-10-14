#!/usr/bin/env bash
#
# BookWise container entrypoint (see docs/DEPLOYMENT.md).
#
# For the default command (apache2-foreground) it:
#   1. checks PORT and APP_URL (default http://localhost:$PORT), and refuses
#      the local-only administrator password of docker-compose.yml on any
#      address other than this computer;
#   2. reports how payments are taken. In demo mode (APP_DEMO=true) the
#      application's built-in payment simulator answers them in-process
#      (PAYMENT_SIMULATOR), so nothing needs to be set up here. A live Stripe
#      key is refused in demo mode, and the simulator on a real store
#      (APP_ENV=production without APP_DEMO);
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
# The administrator password that docker-compose.yml publishes for local use.
LOCAL_ADMIN_PASSWORD=local-admin-password

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

configure_port() {
    PORT="${PORT:-8080}"
    if [[ ! "$PORT" =~ ^[0-9]{1,5}$ ]] || ((10#$PORT < 1 || 10#$PORT > 65535)); then
        die "PORT must be a TCP port number (got '${PORT}')."
    fi
    PORT="$((10#$PORT))"
    export PORT
}

configure_app_url() {
    local url="${APP_URL:-http://localhost:${PORT}}"
    while [[ "$url" == */ ]]; do
        url="${url%/}"
    done
    if [[ ! "$url" =~ ^https?://[^/[:space:]]+(/[^[:space:]]*)?$ ]]; then
        die "APP_URL must be the public http(s) URL of the site (got '${url}')."
    fi
    APP_URL="$url"
    export APP_URL
}

# docker-compose.yml publishes its administrator password so that the local
# store works out of the box. Once the store can be reached from elsewhere
# (for example through Tailscale Funnel) anyone could sign in with it, so it
# is refused unless APP_URL is an address of this computer.
check_admin_password() {
    if [[ "${BOOKWISE_ADMIN_PASSWORD:-}" != "$LOCAL_ADMIN_PASSWORD" ]]; then
        return 0
    fi
    if [[ "$APP_URL" =~ ^https?://(localhost|127\.0\.0\.1|\[::1\])(:[0-9]+)?(/|$) ]]; then
        return 0
    fi
    die "BOOKWISE_ADMIN_PASSWORD is the local-only password from docker-compose.yml, but APP_URL (${APP_URL}) is not a local address. Set BOOKWISE_ADMIN_PASSWORD to a password of your own (see docs/DEPLOYMENT.md)."
}

# Payments are the application's business: with the payment simulator on
# (PAYMENT_SIMULATOR, which defaults to APP_DEMO) it answers them in-process
# and the Stripe settings are not used. This reports which one is used, and
# refuses the two combinations the application refuses too: a live Stripe key
# in demo mode, which must never take real money, and the simulator on a real
# store (APP_ENV=production without APP_DEMO), where anyone could "pay" with it.
report_payments() {
    local simulator="${PAYMENT_SIMULATOR:-${APP_DEMO:-false}}"
    if is_true "$simulator" && ! is_true "${APP_DEMO:-false}" && [[ "${APP_ENV:-production}" == production ]]; then
        die "PAYMENT_SIMULATOR is on, but this is a real store (APP_ENV=production, APP_DEMO=false), where anyone could \"pay\" without paying. Set PAYMENT_SIMULATOR=false to take payments with Stripe, or APP_DEMO=true for a demo."
    fi
    if is_true "${APP_DEMO:-false}"; then
        case "${STRIPE_SECRET_KEY:-}" in
            sk_live_* | rk_live_*)
                die "APP_DEMO=true must never be combined with a live Stripe key. Remove STRIPE_SECRET_KEY, or set APP_DEMO=false for a real store."
                ;;
        esac
    fi
    if is_true "$simulator"; then
        log "Payments: built-in payment simulator at ${APP_URL}/pay.php (no real payments are taken)."
        return 0
    fi
    if [[ -n "${STRIPE_API_BASE:-}" ]]; then
        log "WARNING: STRIPE_API_BASE is set, so payments go to ${STRIPE_API_BASE} instead of Stripe."
    fi
    log "Payments: Stripe Checkout (the payment simulator is off)."
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
    # storage/ also holds the payment simulator's state (PAYMENT_SIMULATOR_STORE).
    for dir in "$logs" "${BOOKWISE_ROOT}/storage" "${BOOKWISE_ROOT}/public/uploads/avatars" "${BOOKWISE_ROOT}/public/uploads/covers"; do
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
    check_admin_password
    report_payments

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
    log "Starting Apache on port ${PORT}."
    exec "$@"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
