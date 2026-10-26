#!/usr/bin/env bash
# M11 #36: dedicated MySQL + multi-worker parallel HTTP probe.
# Run only against disposable CI DB. Requires PHP, curl and migrated backend.
set -euo pipefail
OUT="$(mktemp -d)"
PORT=8011
BASE="http://127.0.0.1:$PORT"
trap 'kill "${SERVER:-0}" 2>/dev/null || true; rm -rf "$OUT"' EXIT
export OUT
php artisan tinker --execute='
$category = App\Models\EventCategory::query()->firstOrCreate(["name" => "M11 CI"], ["allow_proxy_booking" => true, "allow_guest_booking" => false]);
$event = App\Models\CalendarEvent::query()->create([
"title" => "M11 concurrency CI", "starts_at" => now()->addMinutes(30), "ends_at" => now()->addHours(2),
"status" => "published", "audience" => "members", "booking_enabled" => true,
"booking_capacity" => 5, "booking_max_seats" => 3, "waitlist_enabled" => true,
"checkin_enabled" => true, "checkout_enabled" => true, "checkin_gps_required" => false,
"event_category_id" => $category->id,
]);
$user = App\Models\User::factory()->create();
file_put_contents(getenv("OUT")."/event", $event->id);
file_put_contents(getenv("OUT")."/bearer", $user->createToken("m11-ci")->plainTextToken);
' >/dev/null
EVENT="$(cat "$OUT/event")"
BEARER="$(cat "$OUT/bearer")"
PHP_CLI_SERVER_WORKERS=8 php artisan serve --host=127.0.0.1 --port="$PORT" >"$OUT/server.log" 2>&1 &
SERVER=$!
for i in $(seq 1 40); do
  curl -fsS "$BASE/api/v1/health" >/dev/null 2>&1 && break
  sleep 1
done
curl -fsS --max-time 30 -X POST "$BASE/api/v1/events/$EVENT/booking-groups" \
  -H "Authorization: Bearer $BEARER" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"include_self":true,"attendees":[{"name":"M11 parallel guest"}]}' >"$OUT/booking.json"
php artisan tinker --execute='
$id = (int) file_get_contents(getenv("OUT")."/event");
$ids = App\Models\EventBookingAttendee::query()->where("calendar_event_id", $id)->orderBy("id")->pluck("id")->all();
if (count($ids) !== 2) { throw new RuntimeException("Expected two attendees"); }
file_put_contents(getenv("OUT")."/ids", implode(" ", $ids));
file_put_contents(getenv("OUT")."/qr", app(App\Services\Checkin\CheckinTokens::class)->event($id));
' >/dev/null
read -r A B <"$OUT/ids"
QR="$(cat "$OUT/qr")"
pids=()
for n in 1 2; do
  if [ "$n" = 1 ]; then FIRST="$A"; SECOND="$B"; else FIRST="$B"; SECOND="$A"; fi
  curl -sS --max-time 40 -o "$OUT/$n.json" -w '%{http_code}' \
    -X POST "$BASE/api/v1/events/$EVENT/checkin" \
    -H "Authorization: Bearer $BEARER" -H "Accept: application/json" -H "Content-Type: application/json" \
    -d "{\"attendee_ids\":[$FIRST,$SECOND],\"event_token\":\"$QR\"}" >"$OUT/$n.code" &
done
wait
for n in 1 2; do
  echo "M11 parallel request $n: HTTP $(cat "$OUT/$n.code") $(head -c 250 "$OUT/$n.json")"
done
php artisan tinker --execute='
$id = (int) file_get_contents(getenv("OUT")."/event");
$rows = App\Models\EventAttendance::query()->where("calendar_event_id", $id)->get();
$audits = App\Models\AuditEvent::query()->where("event", "checkin.checked_in")->where("metadata->event_id", $id)->count();
echo "M11_ATTENDANCE_COUNT=".$rows->count().PHP_EOL;
if ($rows->count() !== 2 || $rows->where("status", App\Models\EventAttendance::CHECKED_IN)->count() !== 2) {
    throw new RuntimeException("M11 incomplete attendance after parallel requests");
}
' | tee "$OUT/check.log"
# This probe reports collision responses and final state; do not treat it as
# proof of complete atomicity until per-request rollback/audit assertions exist.
echo "M11 parallel collision probe completed (not full concurrency acceptance)"
