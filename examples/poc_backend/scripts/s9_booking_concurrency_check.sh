#!/usr/bin/env bash
# S9 M08 real concurrency check (CI only, synthetic data): 100 parallel HTTP requests
# mixing the S6 single-booking API and the S9 group-booking API (plus parallel
# idempotent retries) against `php artisan serve` with several PHP workers and MySQL.
# Verifies the one seat ledger: no overbooking, one active place per member, unique
# FIFO sequences, replays return the same group, and promotion follows the queue.
set -euo pipefail
diagnose_failure() {
  local code="$1" line="$2"
  set +e
  echo "::error title=S9 concurrency::line=$line exit_code=$code"
  echo "CI_DIAGNOSTICS: HTTP status, safe API error codes, response validation"
  if [ -d "${OUT:-/nonexistent}" ]; then
    python3 - "$OUT" <<'PY'
import collections, glob, json, os, sys
root = sys.argv[1]
statuses = collections.Counter()
errors = collections.Counter()
invalid = 0
for path in glob.glob(os.path.join(root, "*.code")):
    status = open(path, encoding="utf-8").read().strip()
    statuses[status] += 1
    response = path[:-5] + ".json"
    if not os.path.isfile(response):
        invalid += 1
        continue
    try:
        payload = json.load(open(response, encoding="utf-8"))
        if not isinstance(payload, dict):
            invalid += 1
            continue
        error = payload.get("error")
        if isinstance(error, dict) and isinstance(error.get("code"), str):
            errors[error["code"][:64]] += 1
    except (ValueError, OSError):
        invalid += 1
print("HTTP_STATUS_COUNTS:", dict(statuses))
print("API_ERROR_CODE_COUNTS:", dict(errors))
print("INVALID_OR_MISSING_JSON:", invalid)
for name in ("event",):
    print("TEST_DATA_PRESENT:", name, os.path.isfile(os.path.join(root, name)))
PY
  fi
  echo "PHP_SERVER_DIAGNOSTICS: warning/error type and source location (messages omitted)"
  if [ -f /tmp/s9-concurrency-serve.log ]; then
    grep -E 'PHP (Fatal error|Parse error|Warning|Notice)|\] (ERROR|CRITICAL)' /tmp/s9-concurrency-serve.log | sed -E 's/.*PHP (Fatal error|Parse error|Warning|Notice).*/PHP \1 [message redacted]/; s/.*\] (ERROR|CRITICAL).*/Laravel \1 [message redacted]/' | tail -n 12
  else
    echo "PHP_SERVER_LOG_UNAVAILABLE"
  fi
  echo "DATABASE_VALIDATION:"
  if declare -F check >/dev/null && [ -f "${OUT:-/nonexistent}/event" ]; then
    check 2>/dev/null | tail -n 1 || echo "LEDGER_QUERY_FAILED"
  else
    echo "LEDGER_QUERY_UNAVAILABLE_BEFORE_SETUP"
  fi
}

# Register before fixture setup so early failures are diagnosed as well.
on_exit() {
  local code="$1"
  trap - EXIT
  if [ "$code" -ne 0 ]; then diagnose_failure "$code" "${BASH_LINENO[0]:-unknown}"; fi
  if [ -n "${SERVER:-}" ]; then kill "$SERVER" 2>/dev/null || true; fi
}
trap 'on_exit "$?"' EXIT

if [ "${1:-}" = "--self-test-exit-trap" ]; then
  OUT="$(mktemp -d)"
  printf '409' > "$OUT/test.code"
  printf '{"error":{"code":"BOOKING_FULL"}}' > "$OUT/test.json"
  exit 37
fi

if [ "${1:-}" = "--self-test-diagnostics" ]; then
  OUT="$(mktemp -d)"
  trap 'rm -rf "$OUT"' EXIT
  printf '409' > "$OUT/l1.code"
  printf '{"error":{"code":"BOOKING_FULL"}}' > "$OUT/l1.json"
  printf '500' > "$OUT/g1-1.code"
  printf '{invalid-json' > "$OUT/g1-1.json"
  diagnose_failure 37 123 > "$OUT/diagnostics.txt"
  grep -Fq 'line=123 exit_code=37' "$OUT/diagnostics.txt"
  grep -Fq '409' "$OUT/diagnostics.txt"
  grep -Fq '500' "$OUT/diagnostics.txt"
  grep -Fq 'BOOKING_FULL' "$OUT/diagnostics.txt"
  grep -Fq 'INVALID_OR_MISSING_JSON: 1' "$OUT/diagnostics.txt"
  grep -Fq 'LEDGER_QUERY_UNAVAILABLE_BEFORE_SETUP' "$OUT/diagnostics.txt"
  set +e
  bash "$0" --self-test-exit-trap > "$OUT/exit-trap.txt" 2>&1
  actual_code=$?
  set -e
  [ "$actual_code" -eq 37 ]
  grep -Fq 'exit_code=37' "$OUT/exit-trap.txt"
  grep -Fq 'BOOKING_FULL' "$OUT/exit-trap.txt"
  echo 'PASS: synthetic failure diagnostics and real nonzero EXIT trap'
  exit 0
fi

BASE="http://127.0.0.1:8004"
OUT="$(mktemp -d)"
LEGACY=50     # S6 POST /events/{id}/bookings (1 seat each)
GROUP_COUNT=40     # S9 POST /events/{id}/booking-groups (self + 1 named attendee = 2 seats)
RETRIES=10    # the first RETRIES groups send the same client_request_id twice in parallel
CAPACITY=20
export OUT LEGACY GROUP_COUNT CAPACITY

php artisan cache:clear > /dev/null
php artisan tinker --execute='
app(App\Support\Settings::class)->put(["verification_policy" => "email_required"]);
$category = App\Models\EventCategory::query()->firstOrCreate(["name" => "CI 併發"], ["allow_proxy_booking" => true, "allow_guest_booking" => false]);
$event = App\Models\CalendarEvent::query()->create([
    "title" => "S9 concurrency", "starts_at" => now()->addDays(3), "ends_at" => now()->addDays(3)->addHour(),
    "status" => "published", "audience" => "members", "booking_enabled" => true, "booking_capacity" => (int) getenv("CAPACITY"),
    "booking_max_seats" => 5, "waitlist_enabled" => true, "event_category_id" => $category->id,
]);
file_put_contents(getenv("OUT")."/event", $event->id);
$make = fn () => App\Models\User::factory()->create()->createToken("ci")->plainTextToken;
file_put_contents(getenv("OUT")."/legacy", implode("\n", array_map(fn () => $make(), range(1, (int) getenv("LEGACY")))));
file_put_contents(getenv("OUT")."/groups", implode("\n", array_map(fn () => $make(), range(1, (int) getenv("GROUP_COUNT")))));
'
EVENT="$(cat "$OUT/event")"

PHP_CLI_SERVER_WORKERS=8 nohup php artisan serve --host=127.0.0.1 --port=8004 > /tmp/s9-concurrency-serve.log 2>&1 &
SERVER=$!
# EXIT diagnostics and server cleanup registered at script start
for _ in $(seq 1 30); do curl -fsS "$BASE/api/v1/health" > /dev/null 2>&1 && break; sleep 1; done

pids=()
n=0
while IFS= read -r token || [ -n "$token" ]; do
  n=$((n + 1))
  curl -sS --max-time 60 -o "$OUT/l$n.json" -w '%{http_code}' -X POST "$BASE/api/v1/events/$EVENT/bookings" \
    -H 'Accept: application/json' -H "Authorization: Bearer $token" > "$OUT/l$n.code" &
  pids+=("$!")
done < "$OUT/legacy"
g=0
while IFS= read -r token || [ -n "$token" ]; do
  g=$((g + 1))
  uuid="$(cat /proc/sys/kernel/random/uuid)"
  body="{\"include_self\":true,\"attendees\":[{\"name\":\"同行$g\"}],\"client_request_id\":\"$uuid\"}"
  copies=1; [ "$g" -le "$RETRIES" ] && copies=2
  for c in $(seq 1 "$copies"); do
    curl -sS --max-time 60 -o "$OUT/g$g-$c.json" -w '%{http_code}' -X POST "$BASE/api/v1/events/$EVENT/booking-groups" \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $token" -d "$body" > "$OUT/g$g-$c.code" &
    pids+=("$!")
  done
done < "$OUT/groups"
echo "requests sent: $(( ${#pids[@]} ))"
wait "${pids[@]}"

legacy_ok=0; legacy_full=0; group_ok=0; other=0
for i in $(seq 1 "$n"); do
  code="$(cat "$OUT/l$i.code")"
  if [ "$code" = 201 ]; then legacy_ok=$((legacy_ok + 1))
  elif [ "$code" = 409 ] && grep -q 'BOOKING_FULL' "$OUT/l$i.json"; then legacy_full=$((legacy_full + 1))
  else other=$((other + 1)); echo "  unexpected legacy HTTP status: $code (body redacted)"; fi
done
for i in $(seq 1 "$g"); do
  for f in "$OUT"/g"$i"-*.code; do
    code="$(cat "$f")"
    if [ "$code" = 201 ] || [ "$code" = 200 ]; then group_ok=$((group_ok + 1))
    else other=$((other + 1)); echo "  unexpected group HTTP status: $code (body redacted)"; fi
  done
  if [ -f "$OUT/g$i-2.json" ]; then
    a="$(grep -o '"id":"[0-9]*"' "$OUT/g$i-1.json" | head -n 1)"; b="$(grep -o '"id":"[0-9]*"' "$OUT/g$i-2.json" | head -n 1)"
    [ -n "$a" ] && [ "$a" = "$b" ] || { echo "::error title=s9 booking concurrency::retry $i returned different groups ($a vs $b)"; exit 1; }
  fi
done
echo "legacy_ok=$legacy_ok legacy_full=$legacy_full group_ok=$group_ok other=$other"
[ "$other" = 0 ] || { echo "::error title=s9 booking concurrency::unexpected responses: $other"; exit 1; }

check() {
  php artisan tinker --execute='
$id = (int) file_get_contents(getenv("OUT")."/event");
$seats = App\Models\EventBookingAttendee::query()->where("calendar_event_id", $id)->where("status", "confirmed")->count();
$dupMembers = App\Models\EventBookingAttendee::query()->where("calendar_event_id", $id)->where("active", 1)->whereNotNull("member_user_id")
    ->selectRaw("member_user_id, count(*) c")->groupBy("member_user_id")->havingRaw("count(*) > 1")->count();
$groups = App\Models\EventBookingGroup::query()->where("calendar_event_id", $id)->count();
$seq = App\Models\EventWaitlistEntry::query()->where("calendar_event_id", $id)->pluck("sequence");
$legacyConfirmed = App\Models\EventBooking::query()->where("calendar_event_id", $id)->where("status", "confirmed")->count();
$legacyAttendees = App\Models\EventBookingAttendee::query()->where("calendar_event_id", $id)->where("status", "confirmed")->whereHas("group", fn ($q) => $q->whereNotNull("legacy_booking_id"))->count();
$head = App\Models\EventWaitlistEntry::query()->where("calendar_event_id", $id)->where("status", "waiting")->orderBy("sequence")->with("group")->first();
echo json_encode(["seats" => $seats, "dup_members" => $dupMembers, "groups" => $groups, "seq_unique" => $seq->unique()->count() === $seq->count(), "waiting" => $seq->count(), "legacy_confirmed" => $legacyConfirmed, "legacy_attendees" => $legacyAttendees, "head_seats" => $head?->group?->seat_count]), PHP_EOL;
' | tail -n 1
}
state="$(check)"
initial_seats="$(php -r 'echo json_decode($argv[1], true)["seats"];' "$state")"
echo "ledger: $state"
php -r '$s = json_decode($argv[1], true); $cap = (int) $argv[2]; $groups = (int) $argv[3]; $legacyOk = (int) $argv[4];
  $errors = [];
  if ($s["seats"] > $cap || $s["seats"] < 0) $errors[] = "invalid confirmed seats {$s["seats"]} for capacity $cap";
  // Strict FIFO may legitimately leave one seat unused when the head group needs two.
  if ($s["seats"] < $cap && ($s["head_seats"] === null || $s["head_seats"] <= $cap - $s["seats"])) $errors[] = "head of waitlist should have been promoted";
  if ($s["dup_members"] !== 0) $errors[] = "members with two active places: {$s["dup_members"]}";
  if (! $s["seq_unique"]) $errors[] = "duplicate waitlist sequence";
  if ($s["legacy_confirmed"] !== $legacyOk || $s["legacy_attendees"] !== $legacyOk) $errors[] = "S6 bookings {$s["legacy_confirmed"]} / ledger {$s["legacy_attendees"]} != 201 responses $legacyOk";
  if ($s["groups"] !== $groups + $legacyOk) $errors[] = "groups {$s["groups"]} != expected ".($groups + $legacyOk)." (replays must not create groups)";
  foreach ($errors as $e) echo "::error title=s9 booking concurrency::$e\n";
  exit($errors === [] ? 0 : 1);' "$state" "$CAPACITY" "$g" "$legacy_ok"

# Strict FIFO promotion: raise the capacity by 6 seats (three 2-seat groups); the waitlist
# head must be promoted first and no later group may jump ahead.
php artisan tinker --execute='
$id = (int) file_get_contents(getenv("OUT")."/event");
$engine = app(App\Services\Booking\BookingEngine::class);
$admin = App\Models\User::factory()->create();
$admin->assignRole(Spatie\Permission\Models\Role::findOrCreate(App\Models\User::ADMIN_ROLE, "web"));
$before = App\Models\EventWaitlistEntry::query()->where("calendar_event_id", $id)->where("status", "waiting")->orderBy("sequence")->pluck("event_booking_group_id")->all();
$engine->changeCapacity(App\Models\CalendarEvent::query()->findOrFail($id), (int) getenv("CAPACITY") + 6, [], $admin, "CI FIFO");
$promoted = App\Models\EventBookingGroup::query()->whereIn("id", $before)->where("status", "confirmed")->pluck("id")->all();
$expected = array_slice($before, 0, count($promoted)); sort($expected);
sort($promoted);
$ok = count($promoted) === 3 && array_map("intval", $promoted) == array_map("intval", $expected);
$seats = App\Models\EventBookingAttendee::query()->where("calendar_event_id", $id)->where("status", "confirmed")->count();
echo json_encode(["waiting_before" => count($before), "promoted" => count($promoted), "fifo" => $ok, "seats" => $seats]), PHP_EOL;
' | tail -n 1 > "$OUT/fifo.json"
echo "fifo: $(cat "$OUT/fifo.json")"
grep -q '"fifo":true' "$OUT/fifo.json" || { echo "::error title=s9 booking concurrency::promotion did not follow the waitlist order"; exit 1; }
grep -q "\"seats\":$((initial_seats + 6))" "$OUT/fifo.json" || { echo "::error title=s9 booking concurrency::promoted seats differ from six newly available seats"; exit 1; }

if grep -qiE 'deadlock|SQLSTATE\[40001\]' storage/logs/laravel.log 2>/dev/null; then
  echo "::error title=s9 booking concurrency::deadlock found in laravel.log"; exit 1
fi
echo "PASS: 100 parallel mixed S6/S9 requests -> no overbooking, unique FIFO, idempotent replays, ordered promotion"
