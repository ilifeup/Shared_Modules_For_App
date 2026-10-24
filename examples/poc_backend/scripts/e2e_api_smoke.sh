#!/usr/bin/env bash
# HTTP end-to-end smoke for CI: real `php artisan serve`, MySQL and the fake SMS
# transport (test mode). Synthetic data only; never point this at production.
set -euo pipefail
trap 'echo "::error title=e2e smoke::failed at line $LINENO: $BASH_COMMAND"' ERR

BASE="http://127.0.0.1:8001"
LOG="storage/logs/laravel.log"
PHONE="0987654321"
PASS="e2e-synthetic-password"

APP_ENV=local LOG_CHANNEL=single nohup php artisan serve --host=127.0.0.1 --port=8001 > /tmp/s1-serve.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT

for _ in $(seq 1 30); do
  curl -fsS "$BASE/api/v1/health" > /dev/null 2>&1 && break
  sleep 1
done

call() { # method path [json] [token]
  local args=(-sS -o /tmp/s1-body.json -w '%{http_code}' -X "$1" "$BASE$2" -H 'Accept: application/json' -H 'Content-Type: application/json')
  [ -n "${3:-}" ] && args+=(-d "$3")
  [ -n "${4:-}" ] && args+=(-H "Authorization: Bearer $4")
  curl "${args[@]}"
}

expect() { # expected actual label
  if [ "$1" != "$2" ]; then
    echo "::error title=e2e smoke::$3 (expected HTTP $1, got $2): $(head -c 600 /tmp/s1-body.json)"; exit 1
  fi
  echo "PASS: $3"
}

EMAIL="e2e-member@example.com"
otp_from_log() { grep -oP '驗證碼 \K[0-9]{6}' "$LOG" 2>/dev/null | tail -n 1 || true; }
json() { php -r '$d = json_decode(file_get_contents("/tmp/s1-body.json"), true); $v = $d; foreach (explode(".", $argv[1]) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; } echo is_bool($v) ? ($v ? "true" : "false") : (is_array($v) ? json_encode($v) : (string) $v);' "$1"; }
check() { # path expected label
  [ "$(json "$1")" = "$2" ] || { echo "::error title=e2e smoke::$3 ($1 = '$(json "$1")', expected '$2')"; exit 1; }
  echo "PASS: $3"
}
age_otps() { # skip the resend cooldown for the next step (synthetic CI data only)
  php artisan tinker --execute='App\Models\PhoneOtp::query()->update(["created_at" => now()->subMinutes(10)]);' > /dev/null
}

expect 200 "$(call GET /api/v1/auth/policy)" "read verification policy"
check data.verification_policy email_required "initial policy is email_required"

expect 422 "$(call POST /api/v1/auth/register "{\"name\":\"E2E\",\"phone\":\"$PHONE\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\"}")" "register without email rejected"
expect 201 "$(call POST /api/v1/auth/register "{\"name\":\"E2E Member\",\"email\":\"$EMAIL\",\"phone\":\"$PHONE\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\"}")" "register with name, email, phone, password"
check data.verification.email sent "verification email sent"

expect 200 "$(call POST /api/v1/auth/login "{\"identifier\":\"$PHONE\",\"password\":\"$PASS\"}")" "unverified member can log in"
check data.user.verification.satisfied false "login reports verification not satisfied"
TOKEN="$(json data.token)"
expect 403 "$(call GET /api/v1/notices '' "$TOKEN")" "protected endpoint closed before verification"
check error.code VERIFICATION_REQUIRED "VERIFICATION_REQUIRED error code"

# Switch to the store-release policy (admin action in Filament; tinker here).
php artisan tinker --execute='app(App\Support\Settings::class)->put(["verification_policy" => "phone_required"]);' > /dev/null
expect 200 "$(call GET /api/v1/me '' "$TOKEN")" "existing token still reads /me"
check data.verification.missing phone "policy switch applies to the existing token"

expect 200 "$(call POST /api/v1/auth/phone/send-otp "{\"phone\":\"$PHONE\"}")" "request phone OTP"
CODE="$(otp_from_log)"
[ -n "$CODE" ] || { echo "::error title=e2e smoke::OTP not found in fake SMS log: $(tail -n 5 "$LOG" | cut -c1-200 | tr '\n' ' ')"; exit 1; }

expect 422 "$(call POST /api/v1/auth/phone/verify "{\"phone\":\"$PHONE\",\"code\":\"000000\"}")" "wrong OTP rejected"
expect 200 "$(call POST /api/v1/auth/phone/verify "{\"phone\":\"$PHONE\",\"code\":\"$CODE\"}")" "OTP verified"
expect 422 "$(call POST /api/v1/auth/phone/verify "{\"phone\":\"$PHONE\",\"code\":\"$CODE\"}")" "OTP single use"
expect 200 "$(call GET /api/v1/notices '' "$TOKEN")" "protected endpoint open after phone verification"

expect 200 "$(call POST /api/v1/auth/login "{\"identifier\":\"$PHONE\",\"password\":\"$PASS\"}")" "login with phone"
TOKEN="$(json data.token)"

expect 200 "$(call GET /api/v1/me/profile '' "$TOKEN")" "read own profile"
expect 200 "$(call PATCH /api/v1/me/profile '{"display_name":"E2E 改名"}' "$TOKEN")" "update display name"
[ "$(json data.display_name)" = "E2E 改名" ] || { echo "::error title=e2e smoke::display name not persisted"; exit 1; }
expect 200 "$(call GET /api/v1/me/profile '' "$TOKEN")" "re-read profile"
[ "$(json data.display_name)" = "E2E 改名" ] || { echo "::error title=e2e smoke::display name not persisted after re-read"; exit 1; }
echo "PASS: display name persisted"

expect 200 "$(call GET /api/v1/me/membership '' "$TOKEN")" "read own membership"
check data.level.code general "new member is at the general level"
expect 200 "$(call GET /api/v1/membership-levels)" "list membership levels"

php artisan tinker --execute='App\Models\Notice::query()->create(["title" => "E2E smoke notice", "body" => "x", "status" => "published"]);' > /dev/null
expect 200 "$(call GET /api/v1/notices '' "$TOKEN")" "list notices"
grep -q 'E2E smoke notice' /tmp/s1-body.json || { echo "::error title=e2e smoke::published notice missing"; exit 1; }

expect 200 "$(call POST /api/v1/auth/logout '' "$TOKEN")" "logout"
expect 401 "$(call GET /api/v1/me/profile '' "$TOKEN")" "token revoked after logout"

# Password reset by phone: purpose-bound code, all tokens revoked.
expect 200 "$(call POST /api/v1/auth/login "{\"identifier\":\"$EMAIL\",\"password\":\"$PASS\"}")" "login with email"
TOKEN="$(json data.token)"
expect 200 "$(call POST /api/v1/auth/password/forgot "{\"email\":\"$EMAIL\"}")" "forgot password (email)"
KNOWN="$(json data.message)"
expect 200 "$(call POST /api/v1/auth/password/forgot '{"email":"nobody@example.com"}')" "forgot password (unknown email)"
[ "$(json data.message)" = "$KNOWN" ] || { echo "::error title=e2e smoke::forgot-password answer differs for unknown email"; exit 1; }
echo "PASS: forgot-password does not reveal registration"

age_otps
expect 200 "$(call POST /api/v1/auth/password/phone/send-otp "{\"phone\":\"$PHONE\"}")" "request reset code"
RESET_CODE="$(otp_from_log)"
[ -n "$RESET_CODE" ] && [ "$RESET_CODE" != "$CODE" ] || { echo "::error title=e2e smoke::reset code not found"; exit 1; }
NEWPASS="e2e-synthetic-password-2"
expect 200 "$(call POST /api/v1/auth/password/phone/reset "{\"phone\":\"$PHONE\",\"code\":\"$RESET_CODE\",\"password\":\"$NEWPASS\",\"password_confirmation\":\"$NEWPASS\"}")" "reset password by phone"
expect 401 "$(call GET /api/v1/me '' "$TOKEN")" "old token revoked after reset"
expect 401 "$(call POST /api/v1/auth/login "{\"identifier\":\"$PHONE\",\"password\":\"$PASS\"}")" "old password rejected"
expect 200 "$(call POST /api/v1/auth/login "{\"identifier\":\"$PHONE\",\"password\":\"$NEWPASS\"}")" "login with new password"
expect 422 "$(call POST /api/v1/auth/password/phone/reset "{\"phone\":\"$PHONE\",\"code\":\"$RESET_CODE\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\"}")" "reset code single use"

grep -qi "X-Request-Id" <(curl -sSI "$BASE/api/v1/health") && echo "PASS: X-Request-Id header" || { echo "FAIL: X-Request-Id"; exit 1; }
echo "HTTP E2E smoke: all checks passed"
