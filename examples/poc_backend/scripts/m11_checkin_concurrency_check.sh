#!/usr/bin/env bash
# M11 #36: dedicated MySQL + multi-worker parallel HTTP probe.
# Run only against disposable CI DB. Requires PHP, curl and migrated backend.
set -Eeuo pipefail
FAILED_LINE=unknown
trap 'FAILED_LINE=$LINENO' ERR
OUT="$(mktemp -d)"
PORT=8011
BASE="http://127.0.0.1:$PORT"
trap 'rc=$?; if [ "$rc" -ne 0 ]; then echo "::error title=M11 probe::failed at line $FAILED_LINE (exit $rc)"; for f in booking.status booking.json fixture.log server.log 1.code 1.json 2.code 2.json check.log; do if [ -f "$OUT/$f" ]; then echo "=== $f ==="; tail -c 1600 "$OUT/$f"; echo; fi; done; fi; if [ -n "${SERVER:-}" ]; then kill "$SERVER" 2>/dev/null || true; fi; rm -rf "$OUT"' EXIT
export OUT
php artisan tinker --execute='
app(App\Support\Settings::class)->put(["verification_policy" => "email_required"]);
 $categoryOptions = ["allow_proxy_booking" => true];
if (\Illuminate\Support\Facades\Schema::hasColumn("event_categories", "allow_guest_booking")) {
    $categoryOptions["allow_guest_booking"] = false;
}
$category = App\Models\EventCategory::query()->firstOrCreate(["name" => "M11 CI"], $categoryOptions);
$event = App\Models\CalendarEvent::query()->create([
"title" => "M11 concurrency CI", "starts_at" => now()->addDays(3), "ends_at" => now()->addDays(3)->addHours(2),
"status" => "published", "audience" => "members", "booking_enabled" => true,
"booking_capacity" => 5, "booking_max_seats" => 3, "waitlist_enabled" => true,
"checkin_enabled" => true, "checkout_enabled" => true, "checkin_gps_required" => false,
"event_category_id" => $category->id,
]);
$user = App\Models\User::factory()->create();
$companion = App\Models\User::factory()->create();
\Illuminate\Support\Facades\DB::table("family_memberships")->insert([
    "manager_user_id" => $user->id, "member_user_id" => $companion->id,
    "relationship" => "family", "status" => "active", "started_at" => now(),
    "created_by" => $user->id, "created_at" => now(), "updated_at" => now(),
]);
file_put_contents(getenv("OUT")."/companion", $companion->id);
file_put_contents(getenv("OUT")."/event", $event->id);
file_put_contents(getenv("OUT")."/bearer", $user->createToken("m11-ci")->plainTextToken);
' >/dev/null
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
  -d "{\"include_self\":true,\"attendees\":[{\"member_user_id\":$COMPANION}],\"client_request_id\":\"$(cat /proc/sys/kernel/random/uuid)\"}" >"$OUT/booking.status"
[ "$(cat "$OUT/booking.status")" = 200 ] || [ "$(cat "$OUT/booking.status")" = 201 ] || { echo "M11 booking HTTP $(cat "$OUT/booking.status")"; cat "$OUT/booking.json"; exit 1; }
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
    echo "::warning title=M11 concurrency::parallel request $n returned HTTP $code; review deadlock/unique-key behavior"
  fi
done
php artisan tinker --execute='
$id = (int) file_get_contents(getenv("OUT")."/event");
$rows = App\Models\EventAttendance::query()->where("calendar_event_id", $id)->get();
$audits = App\Models\AuditEvent::query()->where("event", "checkin.checked_in")->where("details->event_id", $id)->count();
echo "M11_ATTENDANCE_COUNT=".$rows->count()." AUDITS=".$audits.PHP_EOL;
if ($audits !== 2) { throw new RuntimeException("M11 expected exactly two check-in audits, got ".$audits); }
if ($rows->count() !== 2 || $rows->where("status", App\Models\EventAttendance::CHECKED_IN)->count() !== 2) {
    throw new RuntimeException("M11 incomplete attendance after parallel requests");
}
' 2>&1 | tee "$OUT/check.log"
# This probe reports collision responses and final state; do not treat it as
# proof of complete atomicity until per-request rollback/audit assertions exist.
echo "M11 parallel collision probe completed (not full concurrency acceptance)"
