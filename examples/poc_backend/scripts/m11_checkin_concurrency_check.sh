#!/usr/bin/env bash
# M11 #36: dedicated MySQL + multi-worker parallel HTTP probe.
# Run only against disposable CI DB. Requires PHP, curl and migrated backend.
set -Eeuo pipefail
FAILED_LINE=unknown
trap 'FAILED_LINE=$LINENO' ERR
OUT="$(mktemp -d)"
PORT=8011
BASE="http://127.0.0.1:$PORT"
trap 'rc=$?; if [ "$rc" -ne 0 ]; then echo "::error title=M11 probe::failed at line $FAILED_LINE (exit $rc)"; for f in setup.log booking.status booking.json fixture.log server.log storage/logs/laravel.log 1.code 1.json 2.code 2.json check.log; do if [ -f "$OUT/$f" ]; then echo "=== $f ==="; tail -c 1600 "$OUT/$f"; echo; fi; done; fi; if [ -n "${SERVER:-}" ]; then kill "$SERVER" 2>/dev/null || true; fi; rm -rf "$OUT"' EXIT
export OUT
php artisan tinker --execute='
app(App\Support\Settings::class)->put(["verification_policy" => "email_required"]);
$categoryOptions = ["allow_proxy_booking" => true];
$category = App\Models\EventCategory::query()->firstOrCreate(["name" => "M11 CI"], $categoryOptions);
$event = App\Models\CalendarEvent::query()->create([
"title" => "M11 concurrency CI", "starts_at" => now()->addDays(3), "ends_at" => now()->addDays(3)->addHours(2),
"status" => "published", "audience" => "members", "booking_enabled" => true,
"booking_capacity" => 5, "booking_max_seats" => 3, "waitlist_enabled" => true,
"checkin_enabled" => true, "checkout_enabled" => true, "checkin_gps_required" => false,
"event_category_id" => $category->id,
]);
$user = App\Models\User::factory()->create(["verified_status" => 2, "is_active" => true]);
$companion = App\Models\User::factory()->create(["FamilyUser" => true, "verified_status" => 2]);
// Integrated #43 schema is mandatory; fail rather than silently bypass delegation.
\Illuminate\Support\Facades\DB::table("family_memberships")->insert([
    "manager_user_id" => $user->id, "member_user_id" => $companion->id,
    "relationship" => "family", "status" => "active", "started_at" => now(),
    "created_by" => $user->id, "created_at" => now(), "updated_at" => now(),
]);
file_put_contents(getenv("OUT")."/companion", $companion->id);
file_put_contents(getenv("OUT")."/event", $event->id);
file_put_contents(getenv("OUT")."/bearer", $user->createToken("m11-ci")->plainTextToken);
' >"$OUT/setup.log" 2>&1 || { echo 'M11 fixture setup failed'; tail -n 40 "$OUT/setup.log"; exit 1; }
EVENT="$(cat "$OUT/event")"
BEARER="$(cat "$OUT/bearer")"
COMPANION="$(cat "$OUT/companion")"
PHP_CLI_SERVER_WORKERS=8 php artisan serve --no-reload --host=127.0.0.1 --port="$PORT" >"$OUT/server.log" 2>&1 &
SERVER=$!
ready=0
for i in $(seq 1 40); do
  if curl -fsS "$BASE/api/v1/health" >/dev/null 2>&1; then ready=1; break; fi
  sleep 1
done
[ "$ready" = 1 ] || { echo "M11 HTTP server not ready"; tail -n 30 "$OUT/server.log"; exit 1; }
curl -sS --max-time 30 -w "%{http_code}" -o "$OUT/booking.json" -X POST "$BASE/api/v1/events/$EVENT/booking-groups" \
  -H "Authorization: Bearer $BEARER" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{\"client_request_id\":\"$(cat /proc/sys/kernel/random/uuid)\"}" >"$OUT/booking.status"
[ "$(cat "$OUT/booking.status")" = 200 ] || [ "$(cat "$OUT/booking.status")" = 201 ] || { echo "M11 booking HTTP $(cat "$OUT/booking.status")"; cat "$OUT/booking.json"; exit 1; }
# A delegated family member has a distinct users.id and requires a separate booking.
curl -sS --max-time 30 -w "%{http_code}" -o "$OUT/companion-booking.json" -X POST "$BASE/api/v1/events/$EVENT/booking-groups" \
  -H "Authorization: Bearer $BEARER" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{\"member_user_id\":$COMPANION,\"client_request_id\":\"$(cat /proc/sys/kernel/random/uuid)\"}" >"$OUT/companion-booking.status"
[ "$(cat "$OUT/companion-booking.status")" = 200 ] || [ "$(cat "$OUT/companion-booking.status")" = 201 ] || { echo "M11 companion booking HTTP $(cat "$OUT/companion-booking.status")"; cat "$OUT/companion-booking.json"; exit 1; }
php artisan tinker --execute='
$id = (int) file_get_contents(getenv("OUT")."/event");
$ids = App\Models\EventBookingAttendee::query()->where("calendar_event_id", $id)->orderBy("id")->pluck("id")->all();
if (count($ids) !== 2) { throw new RuntimeException("Expected two attendees"); }
file_put_contents(getenv("OUT")."/ids", implode(" ", $ids).PHP_EOL);
file_put_contents(getenv("OUT")."/qr", app(App\Services\Checkin\CheckinTokens::class)->event($id));
' >"$OUT/fixture.log" 2>&1 || { echo 'M11 fixture tinker failed'; cat "$OUT/fixture.log"; exit 1; }
# Booking requires a future event; move it into the active check-in window only
# after booking and attendee fixtures have been created.
php artisan tinker --execute='
$id = (int) file_get_contents(getenv("OUT")."/event");
App\Models\CalendarEvent::query()->whereKey($id)->update([
    "starts_at" => now()->subMinutes(5),
    "ends_at" => now()->addHours(2),
]);
' >"$OUT/window.log" 2>&1 || { echo 'M11 check-in window setup failed'; cat "$OUT/window.log"; exit 1; }
read -r A B <"$OUT/ids"
QR="$(cat "$OUT/qr")"
pids=()
for n in 1 2; do
  if [ "$n" = 1 ]; then FIRST="$A"; SECOND="$B"; else FIRST="$B"; SECOND="$A"; fi
  curl -sS --max-time 40 -o "$OUT/$n.json" -w '%{http_code}' \
    -X POST "$BASE/api/v1/events/$EVENT/checkin" \
    -H "Authorization: Bearer $BEARER" -H "Accept: application/json" -H "Content-Type: application/json" \
    -d "{\"attendee_ids\":[$FIRST,$SECOND],\"event_token\":\"$QR\"}" >"$OUT/$n.code" &
  pids+=("$!")
done
wait "${pids[@]}"
for n in 1 2; do
  code="$(cat "$OUT/$n.code")"
  echo "M11 parallel request $n: HTTP $code $(head -c 250 "$OUT/$n.json")"
  if [ "$code" != 200 ]; then
    echo "::error title=M11 concurrency::authorized family manager batch $n returned HTTP $code; both delegated member scans must succeed"
    exit 1
  fi
done
php artisan tinker --execute='
$id = (int) file_get_contents(getenv("OUT")."/event");
$rows = App\Models\EventAttendance::query()->where("calendar_event_id", $id)->count();
$audits = App\Models\AuditEvent::query()->where("event", "checkin.checked_in")->where("details->event_id", $id)->count();
echo "M11_ATTENDANCE_COUNT=".$rows." AUDITS=".$audits.PHP_EOL;
if ($audits !== 2 || $rows !== 2) {
    throw new RuntimeException("M11 authorized overlapping family scans must create exactly two attendance records and two audits");
}
' 2>&1 | tee "$OUT/check.log"
# This probe verifies authorized family-manager concurrency, idempotent scans and audit counts.
# Unauthorized cross-family isolation and fault-injected rollback are covered by separate tests.
echo "M11 authorized family parallel collision probe completed"
