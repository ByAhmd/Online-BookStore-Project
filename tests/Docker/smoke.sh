#!/usr/bin/env bash
#
# End-to-end smoke test of a running BookWise container in demo mode, e.g.
#
#     docker compose up -d --build
#     tests/Docker/smoke.sh                       # BASE_URL=http://localhost:8080
#
# It uses only curl, grep, sed and od, so it can also check a deployed demo:
#     BASE_URL=https://<account>.alwaysdata.net tests/Docker/smoke.sh
# (that registers one throwaway customer and buys one book with the simulator).
# BASE_URL must be the site's APP_URL: the payment page returns there.
#
# Checks: /health.php, the home page with the sample catalog, sign-up, add to
# cart, checkout on the payment simulator's page (public/pay.php) to the paid
# confirmation, that the payment page needs its CSRF token and refuses unknown
# sessions, that private files are not served, and the security headers.

set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
while [[ "$BASE_URL" == */ ]]; do
    BASE_URL="${BASE_URL%/}"
done
EXPECTED_BOOKS="${EXPECTED_BOOKS:-55}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
JAR="$WORK/cookies.txt"
BODY="$WORK/body.html"
HEADERS="$WORK/headers.txt"
: >"$JAR"

STATUS=''
LOCATION=''
CHECKS=0

pass() {
    CHECKS=$((CHECKS + 1))
    printf 'ok %d - %s\n' "$CHECKS" "$*"
}

fail() {
    printf 'FAILED: %s\n' "$*" >&2
    if [[ -s "$HEADERS" ]]; then
        printf -- '--- last response headers ---\n' >&2
        cat "$HEADERS" >&2
    fi
    if [[ -s "$BODY" ]]; then
        printf -- '--- last response body (first 60 lines) ---\n' >&2
        head -n 60 "$BODY" >&2
    fi
    exit 1
}

# request METHOD PATH-OR-URL [curl arguments...]
# Sends one request with the cookie jar and never follows redirects. Sets
# STATUS and LOCATION; the body and headers are left in $BODY and $HEADERS.
request() {
    local method="$1" url="$2"
    shift 2
    if [[ "$url" != http://* && "$url" != https://* ]]; then
        url="${BASE_URL}/${url#/}"
    fi
    : >"$BODY"
    : >"$HEADERS"
    local method_args=()
    if [[ "$method" != POST ]]; then
        method_args=(-X "$method")
    fi
    STATUS="$(curl -sS --max-time 30 -o "$BODY" -D "$HEADERS" -w '%{http_code}' \
        "${method_args[@]}" -b "$JAR" -c "$JAR" "$@" "$url")" || fail "$method $url: curl failed"
    LOCATION="$(header_value Location)"
}

# header_value NAME: value of a header of the last response (case-insensitive name).
header_value() {
    grep -i "^$1:" "$HEADERS" | tail -n 1 | cut -d: -f2- | sed 's/^ *//' | tr -d '\r' || true
}

expect_status() {
    local expected
    for expected in "$@"; do
        if [[ "$STATUS" == "$expected" ]]; then
            return 0
        fi
    done
    fail "expected HTTP $* but got ${STATUS}"
}

# CSRF token of the last page: a hidden _token field, else the meta tag.
csrf_token() {
    local token
    token="$(sed -n 's/.*name="_token" value="\([A-Za-z0-9]*\)".*/\1/p' "$BODY" | head -n 1)"
    if [[ -z "$token" ]]; then
        token="$(sed -n 's/.*name="csrf-token" content="\([A-Za-z0-9]*\)".*/\1/p' "$BODY" | head -n 1)"
    fi
    [[ -n "$token" ]] || fail "no CSRF token on the page"
    printf '%s' "$token"
}

page_text() {
    sed 's/<[^>]*>/ /g' "$BODY" | tr -s ' \t\r\n' ' '
}

echo "Smoke test against ${BASE_URL}"

# --- Health ---------------------------------------------------------------
request GET health.php
expect_status 200
grep -q '"status":"ok"' "$BODY" || fail 'health.php did not report {"status":"ok"}'
[[ "$(header_value Cache-Control)" == *no-store* ]] || fail "health.php must send Cache-Control: no-store"
[[ -z "$(header_value Set-Cookie)" ]] || fail "health.php must not start a session"
pass "health.php reports ok (200, no-store, no session)"

request POST health.php --data ''
expect_status 405
pass "health.php refuses POST (405)"

# --- Home page and headers -------------------------------------------------
request GET Home_Page.php
expect_status 200
page_text | grep -Eiq "(^|[^0-9.,])${EXPECTED_BOOKS} +([[:alpha:]]+ +){0,3}books?" \
    || fail "the home page does not mention ${EXPECTED_BOOKS} books"
pass "home page loads and shows the ${EXPECTED_BOOKS}-book catalog"

[[ "$(header_value Content-Security-Policy)" == *"script-src 'self'"* ]] || fail "missing Content-Security-Policy"
[[ "$(header_value X-Frame-Options)" == DENY ]] || fail "missing X-Frame-Options: DENY"
[[ "$(header_value X-Content-Type-Options)" == nosniff ]] || fail "missing X-Content-Type-Options: nosniff"
[[ -z "$(header_value X-Powered-By)" ]] || fail "X-Powered-By reveals the PHP version"
server="$(header_value Server)"
if [[ "$server" =~ [0-9]|Debian|Ubuntu|PHP ]]; then
    fail "the Server header reveals versions: ${server}"
fi
pass "security headers are set and no versions are revealed"

# --- Sign up ----------------------------------------------------------------
EMAIL="smoke-$(date +%s)-${RANDOM}@example.com"
PASSWORD="Smoke-$(od -An -N 12 -tx1 /dev/urandom | tr -d ' \n')"

request GET 'index.php?form=signup'
expect_status 200
token="$(csrf_token)"
request POST Register.php \
    --data-urlencode "_token=${token}" \
    --data-urlencode 'action=register' \
    --data-urlencode "email=${EMAIL}" \
    --data-urlencode 'name=Smoke Tester' \
    --data-urlencode "password=${PASSWORD}" \
    --data-urlencode "password_confirmation=${PASSWORD}"
expect_status 302 303
[[ "$LOCATION" != *form=signup* ]] || fail "sign-up was rejected (redirected back to the form)"

request GET cart.php
if [[ "$STATUS" != 200 ]]; then
    # The sign-up flow may ask new customers to log in themselves.
    request GET 'index.php?form=login'
    expect_status 200
    token="$(csrf_token)"
    request POST Register.php \
        --data-urlencode "_token=${token}" \
        --data-urlencode 'action=login' \
        --data-urlencode "email=${EMAIL}" \
        --data-urlencode "password=${PASSWORD}"
    expect_status 302 303
    request GET cart.php
fi
expect_status 200
pass "a new customer can sign up and is signed in"

# --- Cart ------------------------------------------------------------------
request GET Home_Page.php
expect_status 200
book_id="$(grep -o 'Selected\.php?id=[0-9][0-9]*' "$BODY" | head -n 1 | cut -d= -f2 || true)"
[[ -n "$book_id" ]] || fail "no book link on the home page"

request GET "Selected.php?id=${book_id}"
expect_status 200
token="$(csrf_token)"
request POST "Selected.php?id=${book_id}" \
    --data-urlencode "_token=${token}" \
    --data-urlencode 'action=add_to_cart' \
    --data-urlencode 'quantity=1'
expect_status 302 303
[[ "$LOCATION" == *cart.php* ]] || fail "adding to the cart did not lead to the cart (Location: ${LOCATION})"

request GET cart.php
expect_status 200
grep -q 'action="checkout.php"' "$BODY" || fail "the cart has no checkout form"
pass "book ${book_id} was added to the cart"

# --- Checkout with the payment simulator -------------------------------------
token="$(csrf_token)"
request POST checkout.php --data-urlencode "_token=${token}"
expect_status 303
if [[ ! "$LOCATION" =~ /pay\.php\?session=(cs_test_[A-Za-z0-9]+)$ ]]; then
    fail "checkout did not redirect to the simulated payment page (Location: ${LOCATION})"
fi
session_id="${BASH_REMATCH[1]}"
pay_url="$LOCATION"
[[ "$pay_url" == "${BASE_URL}/pay.php?session=${session_id}" ]] \
    || fail "the payment page ${pay_url} is not on ${BASE_URL}: set BASE_URL to the site's APP_URL"
pass "checkout redirects to the simulated payment page"

request GET "$pay_url"
expect_status 200
for action in pay decline slow cancel; do
    grep -q "value=\"${action}\"" "$BODY" || fail "the simulated payment page has no ${action} button"
done
token="$(csrf_token)"
pass "the simulated payment page offers Pay, Decline, Slow payment and Cancel"

request POST "$pay_url" --data-urlencode 'action=pay'
expect_status 419
pass "the payment page refuses a form without its CSRF token (419)"

request POST "$pay_url" --data-urlencode "_token=${token}" --data-urlencode 'action=pay'
expect_status 303
[[ "$LOCATION" == "${BASE_URL}/success_pay.php?session_id=${session_id}" ]] \
    || fail "paying did not return to success_pay.php (Location: ${LOCATION})"
pass "the simulated payment returns to the store"

request GET "$LOCATION"
expect_status 200
grep -Eq 'result-paid|payment was received' "$BODY" || fail "success_pay.php does not confirm the payment"
pass "the order is confirmed as paid"

request GET 'pay.php?session=cs_test_unknown'
expect_status 404
pass "the payment page answers 404 for unknown sessions"

# --- Private files -------------------------------------------------------------
for path in .env .htaccess composer.json composer.lock vendor/autoload.php storage/logs/app.log \
    storage/payment-simulator.json src/bootstrap.php src/payment_simulator.php database/schema.sql \
    bin/install.php bin/setup.php docker/entrypoint.sh tests/Support/stripe_mock_router.php uploads/.htaccess; do
    request GET "$path"
    expect_status 403 404
done
pass "private files are not served (403/404)"

echo "All ${CHECKS} smoke checks passed."
