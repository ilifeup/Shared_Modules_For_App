#!/usr/bin/env bash
# Real concurrency check for SMS OTP issuance (CI only, synthetic data, fake SMS):
# parallel HTTP requests against `php artisan serve` with several PHP workers and
# MySQL. Verifies the database lock: cooldown and global limit hold, no 5xx/deadlock.
set -euo pipefail
trap 'echo "::error title=otp concurrency::failed at line $LINENO: $BASH_COMMAND"' ERR

BASE="http://127.0.0.1:8002"
OUT="$(mktemp -d)"

php artisan cache:clear > /dev/null
php artisan tinker --execute='
App\Models\PhoneOtp::query()->delete();
foreach (["+886911000001", "+886911000002", "+886911000003", "+886911000004"] as $p) {
    App\Models\User::query()->firstOrCreate(["phone_e164" => $p], ["name" => "Concurrency", "password" => "synthetic-pass-1", "is_active" => true]);
}
app(App\Support\Settings::class)->put(["sms_global_hourly_limit" => 3, "sms_phone_hourly_limit" => 3, "sms_resend_cooldown_minutes" => 3, "sms_test_mode" => true]);
' > /dev/null

PHP_CLI_SERVER_WORKERS=6 nohup php artisan serve --host=127.0.0.1 --port=8002 > /tmp/s1-concurrency-serve.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -fsS "$BASE/api/v1/health" > /dev/null 2>&1 && break; sleep 1; done

send() { # name phone
  curl -sS --max-time 30 -o "$OUT/$1.json" -w '%{http_code}' -X POST "$BASE/api/v1/auth/phone/send-otp" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -d "{\"phone\":\"$2\"}" > "$OUT/$1.code"
}

count_codes() { # code prefix...
  local n=0
  for f in "$@"; do [ "$(cat "$OUT/$f.code")" = "$CODE" ] && n=$((n + 1)); done
  echo "$n"
}

otp_count() { # phone (or "all")
  php artisan tinker --execute="\$q = App\Models\PhoneOtp::query()->whereIn('status', App\Models\PhoneOtp::COUNTED_STATUSES); if ('$1' !== 'all') { \$q->where('phone_e164', '$1'); } echo \$q->count();" | tail -n 1
}

# A: two simultaneous requests for the same phone -> exactly one sent, one cooldown.
send a1 0911000001 & p1=$!
send a2 0911000001 & p2=$!
wait "$p1" "$p2"
CODE=200; ok="$(count_codes a1 a2)"
CODE=429; limited="$(count_codes a1 a2)"
grep -h '"code"' "$OUT"/a*.json | sed 's/^/  /' || true
[ "$ok" = 1 ] && [ "$limited" = 1 ] || { echo "::error title=otp concurrency::same phone: expected 1x200 + 1x429, got ok=$ok limited=$limited ($(cat "$OUT"/a*.code | tr '\n' ' '))"; exit 1; }
grep -q 'SMS_COOLDOWN' "$OUT/a1.json" "$OUT/a2.json"
[ "$(otp_count +886911000001)" = 1 ] || { echo "::error title=otp concurrency::same phone created more than one code"; exit 1; }
echo "PASS: parallel requests for one phone -> 1 sent, 1 SMS_COOLDOWN"

# B: three simultaneous requests for different phones with 2 slots left in the global limit.
send b2 0911000002 & p1=$!
send b3 0911000003 & p2=$!
send b4 0911000004 & p3=$!
wait "$p1" "$p2" "$p3"
CODE=200; ok="$(count_codes b2 b3 b4)"
CODE=429; limited="$(count_codes b2 b3 b4)"
[ "$ok" = 2 ] && [ "$limited" = 1 ] || { echo "::error title=otp concurrency::global limit: expected 2x200 + 1x429, got ok=$ok limited=$limited ($(cat "$OUT"/b*.code | tr '\n' ' '))"; exit 1; }
grep -q 'SMS_GLOBAL_LIMIT' "$OUT/b2.json" "$OUT/b3.json" "$OUT/b4.json"
[ "$(otp_count all)" = 3 ] || { echo "::error title=otp concurrency::global count is not 3"; exit 1; }
echo "PASS: parallel requests across phones respect the global hourly limit"

if grep -qiE 'deadlock|SQLSTATE\[40001\]' storage/logs/laravel.log; then
  echo "::error title=otp concurrency::deadlock found in laravel.log"; exit 1
fi
echo "PASS: no deadlock / 5xx"
echo "OTP concurrency check: all checks passed"
