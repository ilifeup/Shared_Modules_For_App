#!/usr/bin/env bash
# Real concurrency check for S6 bookings (CI only, synthetic data): parallel HTTP
# requests from different members for the last seat, against `php artisan serve`
# with several PHP workers and MySQL. Verifies the row lock: no overbooking.
set -Eeuo pipefail
FAILED_LINE=unknown
trap 'FAILED_LINE=$LINENO' ERR

BASE="http://127.0.0.1:8003"
OUT="$(mktemp -d)"
MEMBERS=6
export OUT MEMBERS

php artisan cache:clear > /dev/null
# One event with capacity 2; one seat already taken, so 1 seat is left for $MEMBERS parallel requests.
php artisan tinker --execute='
// Earlier CI steps may have switched the policy; factory members have a verified email.
app(App\Support\Settings::class)->put(["verification_policy" => "email_required"]);
$event = App\Models\CalendarEvent::query()->create([
    "title" => "Concurrency booking", "starts_at" => now()->addDays(3), "ends_at" => now()->addDays(3)->addHour(),
    "status" => "published", "audience" => "members", "booking_enabled" => true, "booking_capacity" => 2,
]);
$first = App\Models\User::factory()->create();
app(App\Services\BookingService::class)->book($event, $first);
file_put_contents(getenv("OUT")."/event", $event->id);
$tokens = [];
foreach (range(1, (int) getenv("MEMBERS")) as $i) {
    $tokens[] = App\Models\User::factory()->create()->createToken("ci")->plainTextToken;
}
file_put_contents(getenv("OUT")."/tokens", implode("\n", $tokens));
' > /dev/null
EVENT="$(cat "$OUT/event")"

PHP_CLI_SERVER_WORKERS=6 nohup php artisan serve --no-reload --host=127.0.0.1 --port=8003 > /tmp/s6-concurrency-serve.log 2>&1 &
SERVER=$!
trap 'rc=$?; if [ "$rc" -ne 0 ]; then echo "::error title=concurrency probe::failed at line $FAILED_LINE (exit $rc)"; echo "=== PHP server log ==="; tail -n 70 /tmp/s6-concurrency-serve.log 2>/dev/null || true; echo "=== API HTTP codes and response excerpts ==="; for f in "$OUT"/*.code; do [ -f "$f" ] || continue; echo "$(basename "$f") HTTP $(cat "$f")"; head -c 350 "${f%.code}.json" 2>/dev/null || true; echo; done; fi; kill "$SERVER" 2>/dev/null || true; rm -rf "$OUT"' EXIT
for _ in $(seq 1 30); do curl -fsS "$BASE/api/v1/health" > /dev/null 2>&1 && break; sleep 1; done

pids=()
i=0
while IFS= read -r token || [ -n "$token" ]; do # last line has no newline
  i=$((i + 1))
  curl -sS --max-time 30 -o "$OUT/r$i.json" -w '%{http_code}' -X POST "$BASE/api/v1/events/$EVENT/bookings" \
    -H 'Accept: application/json' -H "Authorization: Bearer $token" > "$OUT/r$i.code" &
  pids+=("$!")
done < "$OUT/tokens"
wait "${pids[@]}"

created=0; full=0; other=0
for n in $(seq 1 "$i"); do
  code="$(cat "$OUT/r$n.code")"
  if [ "$code" = 201 ]; then created=$((created + 1))
  elif [ "$code" = 409 ] && grep -q 'BOOKING_FULL' "$OUT/r$n.json"; then full=$((full + 1))
  else other=$((other + 1)); echo "  unexpected: $code $(head -c 300 "$OUT/r$n.json")"; fi
done
echo "created=$created full=$full other=$other"
[ "$created" = 1 ] && [ "$full" = $((MEMBERS - 1)) ] && [ "$other" = 0 ] || {
  echo "::error title=booking concurrency::expected 1x201 and $((MEMBERS - 1))x409 BOOKING_FULL, got created=$created full=$full other=$other"; exit 1; }

confirmed="$(php artisan tinker --execute="echo App\Models\EventBooking::query()->where('calendar_event_id', $EVENT)->where('status', 'confirmed')->count();" | tail -n 1)"
[ "$confirmed" = 2 ] || { echo "::error title=booking concurrency::confirmed bookings = $confirmed (capacity 2)"; exit 1; }

if grep -qiE 'deadlock|SQLSTATE\[40001\]' storage/logs/laravel.log 2>/dev/null; then
  echo "::error title=booking concurrency::deadlock found in laravel.log"; exit 1
fi
echo "PASS: parallel requests for the last seat -> 1 booking, no overbooking"
