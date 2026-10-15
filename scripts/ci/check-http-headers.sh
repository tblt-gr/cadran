#!/bin/sh
# Assert the HTTP security headers and the session cookie flags of a running stack.
#   check-http-headers.sh [base-url]        default https://localhost:8443 (or CHECK_BASE_URL)
#
# Environment:
#   CADRAN_CA_CERT       Caddy root CA (make tls-certificate); without it TLS is not verified, which
#                        is acceptable here: the script checks headers, not trust.
#   CHECK_RESOLVE        host:port:address passed to curl --resolve, to reach the stack under a
#                        configured server name that is not in DNS (e.g. cadran.test:8443:127.0.0.1).
#   E2E_OWNER_EMAIL /    throwaway owner; when both are set, a login checks the session cookie flags.
#   E2E_OWNER_PASSWORD   The password must be JSON-safe (no quote or backslash).
#
# HSTS must be absent on localhost, 127.0.0.1 and [::1] and present on any other host name.
# Only header names and pass/fail are printed, never a cookie value or the password.
set -u

base_url=${1:-${CHECK_BASE_URL:-https://localhost:8443}}
base_url=${base_url%/}
host_port=${base_url#https://}
host_port=${host_port%%/*}

case $host_port in
    localhost | localhost:* | 127.0.0.1 | 127.0.0.1:* | \[::1\] | \[::1\]:*) expect_hsts=0 ;;
    *) expect_hsts=1 ;;
esac

work_dir=$(mktemp -d)
trap 'rm -rf "$work_dir"' EXIT HUP INT TERM

failures=0
pass() { printf 'PASS %s\n' "$1"; }
fail() {
    printf 'FAIL %s\n' "$1"
    failures=$((failures + 1))
}

# curl_run <output-file> <args...>: shared transport options, response headers to <output-file>.headers.
curl_run() {
    out=$1
    shift
    set -- --silent --show-error --max-time 20 --dump-header "$out.headers" --output "$out.body" "$@"
    if [ -n "${CADRAN_CA_CERT:-}" ]; then
        set -- --cacert "$CADRAN_CA_CERT" "$@"
    else
        set -- --insecure "$@"
    fi
    if [ -n "${CHECK_RESOLVE:-}" ]; then
        set -- --resolve "$CHECK_RESOLVE" "$@"
    fi
    curl "$@"
}

# header_value <headers-file> <name>: last value of a response header, CR stripped, empty if absent.
header_value() {
    grep -i "^$2:" "$1" | tail -n 1 | sed "s/^[^:]*:[[:space:]]*//" | tr -d '\r'
}

# status_of <headers-file>: HTTP status code of the first response in a dumped header file.
status_of() {
    sed -n '1s/^HTTP\/[0-9.]* \([0-9]*\).*/\1/p' "$1" | tr -d '\r'
}

has_header() {
    grep -qi "^$2:" "$1"
}

# expect_exact <headers-file> <label> <name> <value>
expect_exact() {
    if [ "$(header_value "$1" "$3")" = "$4" ]; then
        pass "$2 $3"
    else
        fail "$2 $3 (missing or unexpected value)"
    fi
}

expect_absent() {
    if has_header "$1" "$3"; then
        fail "$2 $3 must not be sent"
    else
        pass "$2 $3 absent"
    fi
}

# check_security_headers <label> <url>
check_security_headers() {
    label=$1
    target="$work_dir/$(printf '%s' "$label" | tr -c 'A-Za-z0-9' '_')"
    if ! curl_run "$target" "$2"; then
        fail "$label unreachable"
        return 1
    fi
    headers="$target.headers"

    csp=$(header_value "$headers" Content-Security-Policy)
    csp_ok=1
    for directive in "default-src 'self'" "script-src 'self'" "object-src 'none'" "frame-ancestors 'none'" "base-uri 'self'"; do
        case $csp in *"$directive"*) ;; *) csp_ok=0 ;; esac
    done
    if [ "$csp_ok" = 1 ]; then pass "$label Content-Security-Policy"; else fail "$label Content-Security-Policy (missing or weakened)"; fi

    if has_header "$headers" Permissions-Policy; then pass "$label Permissions-Policy"; else fail "$label Permissions-Policy missing"; fi
    expect_exact "$headers" "$label" Referrer-Policy no-referrer
    expect_exact "$headers" "$label" X-Content-Type-Options nosniff
    expect_exact "$headers" "$label" X-Frame-Options DENY
    expect_exact "$headers" "$label" Cross-Origin-Opener-Policy same-origin
    expect_exact "$headers" "$label" Cross-Origin-Resource-Policy same-origin
    expect_absent "$headers" "$label" Server
    expect_absent "$headers" "$label" X-Powered-By

    if [ "$expect_hsts" = 1 ]; then
        expect_exact "$headers" "$label" Strict-Transport-Security max-age=31536000
    else
        expect_absent "$headers" "$label" Strict-Transport-Security
    fi
}

check_security_headers "/" "$base_url/"
check_security_headers "/index.html" "$base_url/index.html"

asset_path=$(grep -Eo '/assets/[A-Za-z0-9._-]+\.(js|css)' "$work_dir/_.body" 2>/dev/null | head -n 1)
if [ -n "$asset_path" ]; then
    check_security_headers "/assets/*" "$base_url$asset_path"
else
    fail "/assets/* no hashed asset referenced by /"
fi

check_security_headers "/api/v1/status" "$base_url/api/v1/status"
status_headers="$work_dir/_api_v1_status.headers"
if header_value "$status_headers" Cache-Control | grep -qx 'no-store'; then
    pass "/api/v1/status Cache-Control no-store"
else
    fail "/api/v1/status Cache-Control no-store"
fi

# A Host header that names no configured site is refused and leaks no server banner.
if curl_run "$work_dir/misdirected" --header 'Host: unknown.invalid' "$base_url/"; then
    if [ "$(status_of "$work_dir/misdirected.headers")" = 421 ]; then pass "unknown Host answered 421"; else fail "unknown Host must answer 421"; fi
    expect_absent "$work_dir/misdirected.headers" "unknown Host" Server
else
    fail "unknown Host unreachable"
fi

# Cookie flags. The csrf cookie comes with the status response; the session cookie needs a login.
cookie_line() {
    grep -i "^set-cookie: $2=" "$1" | tail -n 1 | tr -d '\r'
}

csrf_line=$(cookie_line "$status_headers" csrf_token)
if [ -n "$csrf_line" ] && printf '%s' "$csrf_line" | grep -qi '; secure' && printf '%s' "$csrf_line" | grep -qi '; samesite=strict'; then
    pass "csrf_token cookie Secure SameSite=Strict"
else
    fail "csrf_token cookie Secure SameSite=Strict"
fi

jar="$work_dir/jar"
curl_run "$work_dir/probe" --cookie-jar "$jar" "$base_url/api/v1/status" || fail "csrf probe unreachable"
awk '$6 == "csrf_token" { printf "%s", $7 }' "$jar" > "$work_dir/csrf"
printf 'header = "X-CSRF-TOKEN: %s"\n' "$(cat "$work_dir/csrf")" > "$work_dir/csrf.conf"

# The request body is capped at 1 MB by the web server. A valid CSRF pair gets the request past the
# application's first guard, so the refusal observed is the size limit: the body is cut and the
# application rejects it (400), or the server answers 413 itself. Never a 2xx.
head -c 1500000 /dev/zero | tr '\0' 'a' > "$work_dir/oversized"
curl_run "$work_dir/oversized.out" --config "$work_dir/csrf.conf" --cookie "$jar" \
    --header 'Content-Type: application/json' --request POST --data-binary @"$work_dir/oversized" \
    "$base_url/api/v1/session"
case $(status_of "$work_dir/oversized.out.headers") in
    400 | 413) pass "oversized API body refused" ;;
    *) fail "oversized API body must be refused (HTTP 400 or 413)" ;;
esac

if [ -n "${E2E_OWNER_EMAIL:-}" ] && [ -n "${E2E_OWNER_PASSWORD:-}" ]; then
    # The body comes from stdin and the CSRF header from a config file so no secret appears in an argument list.
    printf '{"email":"%s","password":"%s"}' "$E2E_OWNER_EMAIL" "$E2E_OWNER_PASSWORD" |
        curl_run "$work_dir/login" --config "$work_dir/csrf.conf" --cookie "$jar" \
            --header 'Content-Type: application/json' --request POST --data-binary @- \
            "$base_url/api/v1/session"
    login_status=$(status_of "$work_dir/login.headers")

    if [ "$login_status" != 204 ]; then
        fail "login (HTTP $login_status instead of 204)"
    else
        pass "login"
        session_line=$(cookie_line "$work_dir/login.headers" cadran_session)
        if [ -z "$session_line" ]; then
            fail "cadran_session cookie not set"
        else
            for flag in '; secure' '; httponly' '; samesite=lax'; do
                if printf '%s' "$session_line" | grep -qi "$flag"; then pass "cadran_session cookie ${flag#; }"; else fail "cadran_session cookie ${flag#; }"; fi
            done
            if printf '%s' "$session_line" | grep -Eqi '; (expires|max-age)='; then
                fail "cadran_session cookie must be a browser-session cookie (no Expires or Max-Age)"
            else
                pass "cadran_session cookie has no Expires or Max-Age"
            fi
        fi
    fi
else
    printf 'SKIP session cookie flags (E2E_OWNER_EMAIL and E2E_OWNER_PASSWORD not set)\n'
fi

if [ "$failures" -ne 0 ]; then
    printf '%s check(s) failed.\n' "$failures" >&2
    exit 1
fi
printf 'All HTTP security header checks passed.\n'
