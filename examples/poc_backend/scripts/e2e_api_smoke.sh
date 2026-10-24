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

expect 201 "$(call POST /api/v1/auth/register "{\"name\":\"E2E Phone\",\"phone\":\"$PHONE\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\"}")" "register with phone"
expect 403 "$(call POST /api/v1/auth/login "{\"identifier\":\"$PHONE\",\"password\":\"$PASS\"}")" "login blocked before OTP"

CODE="$(grep -oP '驗證碼 \K[0-9]{6}' "$LOG" 2>/dev/null | tail -n 1 || true)"
[ -n "$CODE" ] || { echo "::error title=e2e smoke::OTP not found in fake SMS log: $(tail -n 5 "$LOG" | cut -c1-200 | tr '\n' ' ')"; exit 1; }

expect 422 "$(call POST /api/v1/auth/phone/verify "{\"phone\":\"$PHONE\",\"code\":\"000000\"}")" "wrong OTP rejected"
expect 200 "$(call POST /api/v1/auth/phone/verify "{\"phone\":\"$PHONE\",\"code\":\"$CODE\"}")" "OTP verified"
expect 422 "$(call POST /api/v1/auth/phone/verify "{\"phone\":\"$PHONE\",\"code\":\"$CODE\"}")" "OTP single use"

expect 200 "$(call POST /api/v1/auth/login "{\"identifier\":\"$PHONE\",\"password\":\"$PASS\"}")" "login with phone"
TOKEN="$(php -r 'echo json_decode(file_get_contents("/tmp/s1-body.json"), true)["data"]["token"];')"

expect 200 "$(call GET /api/v1/me/profile '' "$TOKEN")" "read own profile"
expect 200 "$(call PATCH /api/v1/me/profile '{"display_name":"E2E 改名"}' "$TOKEN")" "update display name"
json_get() { php -r '$d = json_decode(file_get_contents("/tmp/s1-body.json"), true); echo $d["data"]["display_name"] ?? "";'; }
[ "$(json_get)" = "E2E 改名" ] || { echo "::error title=e2e smoke::display name not persisted"; exit 1; }
expect 200 "$(call GET /api/v1/me/profile '' "$TOKEN")" "re-read profile"
[ "$(json_get)" = "E2E 改名" ] || { echo "::error title=e2e smoke::display name not persisted after re-read"; exit 1; }
echo "PASS: display name persisted"

php artisan tinker --execute='App\Models\Notice::query()->create(["title" => "E2E smoke notice", "body" => "x", "status" => "published"]);' > /dev/null
expect 200 "$(call GET /api/v1/notices '' "$TOKEN")" "list notices"
grep -q 'E2E smoke notice' /tmp/s1-body.json || { echo "::error title=e2e smoke::published notice missing"; exit 1; }

expect 200 "$(call POST /api/v1/auth/logout '' "$TOKEN")" "logout"
expect 401 "$(call GET /api/v1/me/profile '' "$TOKEN")" "token revoked after logout"

grep -qi "X-Request-Id" <(curl -sSI "$BASE/api/v1/health") && echo "PASS: X-Request-Id header" || { echo "FAIL: X-Request-Id"; exit 1; }
echo "HTTP E2E smoke: all checks passed"
