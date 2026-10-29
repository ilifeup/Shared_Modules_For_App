#!/usr/bin/env bash
# Real concurrency check for whole-term course enrollment (CI only, synthetic data),
# against `php artisan serve` with several PHP workers and MySQL 8.4 InnoDB:
#  1. parallel enrollments for the last term seat -> 1 pending_payment, the rest
#     a gap-free FIFO waitlist, one term invoice, one derived session seat;
#  2. the seat holder's cancel retries race the administrator's cash confirmation
#     -> one cancellation, never both "unpaid-cancelled" and "paid", no automatic
#     refund, seats released once and the FIFO head promoted exactly once;
#  3. enrollment retries by the promoted member -> the same place, no duplicates.
set -euo pipefail
trap 'echo "::error title=course concurrency::failed at line $LINENO: $BASH_COMMAND"' ERR

BASE="http://127.0.0.1:8006"
OUT="$(mktemp -d)"
MEMBERS=6
export OUT MEMBERS

fail() { echo "::error title=course concurrency::$1"; exit 1; }
tinker() { php artisan tinker --execute="$1" | tail -n 1; }

php artisan cache:clear > /dev/null
php artisan tinker --execute='
app(App\Support\Settings::class)->put(["verification_policy" => "email_required"]);
$start = now()->addDays(30);
$event = App\Models\CalendarEvent::query()->create([
    "title" => "Course concurrency session", "starts_at" => $start, "ends_at" => $start->copy()->addHours(2),
    "status" => "published", "audience" => "members", "booking_enabled" => true, "booking_capacity" => 20,
]);
$term = Illuminate\Support\Facades\DB::table("course_terms")->insertGetId([
    "title" => "Course concurrency term", "price_amount" => 100, "enrollment_capacity" => 1,
    "waitlist_capacity" => 10, "cancel_cutoff_days" => 10, "created_at" => now(), "updated_at" => now(),
]);
Illuminate\Support\Facades\DB::table("course_sessions")->insert([
    "course_term_id" => $term, "calendar_event_id" => $event->id, "created_at" => now(), "updated_at" => now(),
]);
$tokens = [];
foreach (range(1, (int) getenv("MEMBERS")) as $i) {
    $tokens[] = App\Models\User::factory()->create()->createToken("ci")->plainTextToken;
}
$admin = App\Models\User::factory()->create();
$admin->assignRole(App\Models\User::ADMIN_ROLE);
file_put_contents(getenv("OUT")."/term", $term);
file_put_contents(getenv("OUT")."/event", $event->id);
file_put_contents(getenv("OUT")."/tokens", implode("\n", $tokens));
file_put_contents(getenv("OUT")."/admin", $admin->createToken("ci")->plainTextToken);
' > /dev/null
TERM_ID="$(cat "$OUT/term")"
EVENT="$(cat "$OUT/event")"
ADMIN="$(cat "$OUT/admin")"
mapfile -t TOKENS < "$OUT/tokens"

PHP_CLI_SERVER_WORKERS=8 nohup php artisan serve --host=127.0.0.1 --port=8006 > /tmp/course-concurrency-serve.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -fsS "$BASE/api/v1/health" > /dev/null 2>&1 && break; sleep 1; done

post() { # name token path
  curl -sS --max-time 30 -o "$OUT/$1.json" -w '%{http_code}' -X POST "$BASE$3" \
    -H 'Accept: application/json' -H "Authorization: Bearer $2" > "$OUT/$1.code"
}
code() { cat "$OUT/$1.code"; }
field() { python3 -c 'import json,sys; d=json.load(open(sys.argv[1]))["data"]; print(d.get(sys.argv[2]))' "$OUT/$1.json" "$2"; }

# ---- 1. last seat: every member enrolls at the same time -------------------------
pids=()
for i in "${!TOKENS[@]}"; do post "enroll$i" "${TOKENS[$i]}" "/api/v1/course-terms/$TERM_ID/enrollments" & pids+=("$!"); done
wait "${pids[@]}"
pending=0; waiting=0; holder=""
for i in "${!TOKENS[@]}"; do
  [ "$(code "enroll$i")" = 201 ] || fail "enroll $i: HTTP $(code "enroll$i") $(head -c 300 "$OUT/enroll$i.json")"
  case "$(field "enroll$i" status)" in
    pending_payment) pending=$((pending + 1)); holder="$i" ;;
    waitlisted) waiting=$((waiting + 1)) ;;
    *) fail "enroll $i: unexpected $(head -c 300 "$OUT/enroll$i.json")" ;;
  esac
done
echo "enrolled: pending=$pending waitlisted=$waiting"
[ "$pending" = 1 ] && [ "$waiting" = $((MEMBERS - 1)) ] || fail "expected 1 pending and $((MEMBERS - 1)) waitlisted"
HOLDER_ID="$(field "enroll$holder" id)"
ledger="$(tinker "
\$t = $TERM_ID; \$db = Illuminate\Support\Facades\DB::class;
echo json_encode([
  'sequences' => \$db::table('course_enrollments')->where('course_term_id', \$t)->where('status', 'waitlisted')->orderBy('waitlist_sequence')->pluck('waitlist_sequence')->map(fn (\$s) => (int) \$s)->all(),
  'invoices' => \$db::table('invoices')->where('booking_type', '02')->where('product_id', \$t)->count(),
  'seats' => \$db::table('event_booking_attendees')->where('calendar_event_id', $EVENT)->where('status', 'confirmed')->count(),
  'invoice' => (int) \$db::table('invoices')->where('booking_type', '02')->where('booking_id', $HOLDER_ID)->value('id'),
]);")"
echo "ledger: $ledger"
python3 - "$ledger" "$MEMBERS" <<'PY' || fail "ledger after parallel enrollment: $ledger"
import json, sys
d = json.loads(sys.argv[1]); n = int(sys.argv[2])
assert d["sequences"] == list(range(1, n)), d
assert d["invoices"] == 1 and d["seats"] == 1 and d["invoice"] > 0, d
PY
INVOICE="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["invoice"])' "$ledger")"
HEAD_TOKEN_INDEX="$(for i in "${!TOKENS[@]}"; do
  if [ "$(field "enroll$i" waitlist_sequence)" = 1 ]; then echo "$i"; fi
done)"
[ -n "$HEAD_TOKEN_INDEX" ] || fail "FIFO head (waitlist_sequence=1) not found"

# ---- 2. cancel retries race the administrator's cash confirmation ----------------
pids=()
for n in 1 2 3; do post "cancel$n" "${TOKENS[$holder]}" "/api/v1/me/course-enrollments/$HOLDER_ID/cancel" & pids+=("$!"); done
for n in 1 2; do post "cash$n" "$ADMIN" "/api/v1/admin/invoices/$INVOICE/confirm-cash" & pids+=("$!"); done
wait "${pids[@]}"
fresh=0
for n in 1 2 3; do
  [ "$(code "cancel$n")" = 200 ] || fail "cancel $n: HTTP $(code "cancel$n") $(head -c 300 "$OUT/cancel$n.json")"
  [ "$(field "cancel$n" replayed)" = False ] && fresh=$((fresh + 1))
done
[ "$fresh" = 1 ] || fail "expected exactly one effective cancellation, got $fresh"
for n in 1 2; do
  c="$(code "cash$n")"; [ "$c" = 200 ] || [ "$c" = 422 ] || fail "cash $n: HTTP $c $(head -c 300 "$OUT/cash$n.json")"
done
state="$(tinker "
\$db = Illuminate\Support\Facades\DB::class;
\$head = \$db::table('course_enrollments')->where('course_term_id', $TERM_ID)->where('status', 'pending_payment')->first();
echo json_encode([
  'holder' => \$db::table('course_enrollments')->where('id', $HOLDER_ID)->value('status'),
  'invoice_status' => (int) \$db::table('invoices')->where('id', $INVOICE)->value('invoice_status'),
  'collected' => \$db::table('payment_transactions')->where('invoice_id', $INVOICE)->where('transaction_amount', '>', 0)->count(),
  'refunds' => \$db::table('payment_transactions')->where('invoice_id', $INVOICE)->where('transaction_amount', '<', 0)->count() + \$db::table('invoice_refund_audits')->where('invoice_id', $INVOICE)->count(),
  'holder_seats' => \$db::table('event_booking_attendees')->where('calendar_event_id', $EVENT)->whereIn('event_booking_group_id', \$db::table('event_booking_groups')->where('client_request_id', (string) Ramsey\Uuid\Uuid::uuid5(Ramsey\Uuid\Uuid::NAMESPACE_URL, 'course-enrollment:$HOLDER_ID:event:$EVENT'))->pluck('id'))->where('status', 'confirmed')->count(),
  'pending' => \$db::table('course_enrollments')->where('course_term_id', $TERM_ID)->where('status', 'pending_payment')->count(),
  'head_sequence_was_first' => \$head !== null && \$db::table('course_enrollments')->where('course_term_id', $TERM_ID)->where('status', 'waitlisted')->min('waitlist_sequence') == 2,
  'head_invoices' => \$head === null ? 0 : \$db::table('invoices')->where('booking_type', '02')->where('booking_id', \$head->id)->where('invoice_status', 0)->count(),
  'seats' => \$db::table('event_booking_attendees')->where('calendar_event_id', $EVENT)->where('status', 'confirmed')->count(),
  'waiting' => \$db::table('course_enrollments')->where('course_term_id', $TERM_ID)->where('status', 'waitlisted')->count(),
  'audits' => App\Models\AuditEvent::query()->where('event', 'course.enrollment_cancelled')->where('subject_id', $HOLDER_ID)->count(),
]);")"
echo "after race: $state"
python3 - "$state" "$MEMBERS" <<'PY' || fail "inconsistent state after cancel/cash race: $state"
import json, sys
d = json.loads(sys.argv[1]); n = int(sys.argv[2])
assert d["holder"] == "cancelled", d
# Either cash won (paid, kept for a manual refund) or the cancellation won (unpaid -> cancelled).
assert (d["invoice_status"], d["collected"]) in ((1, 1), (2, 0)), d
assert d["refunds"] == 0 and d["holder_seats"] == 0, d
assert d["pending"] == 1 and d["head_sequence_was_first"] and d["head_invoices"] == 1, d
assert d["seats"] == 1 and d["waiting"] == n - 2 and d["audits"] == 1, d
PY

# ---- 3. the promoted member's enrollment retries return the same place ----------
pids=()
for n in 1 2 3; do post "replay$n" "${TOKENS[$HEAD_TOKEN_INDEX]}" "/api/v1/course-terms/$TERM_ID/enrollments" & pids+=("$!"); done
wait "${pids[@]}"
ids=""
for n in 1 2 3; do
  [ "$(code "replay$n")" = 200 ] && [ "$(field "replay$n" replayed)" = True ] || fail "replay $n: HTTP $(code "replay$n") $(head -c 300 "$OUT/replay$n.json")"
  ids="$ids $(field "replay$n" id)"
done
[ "$(echo "$ids" | tr ' ' '\n' | sed '/^$/d' | sort -u | wc -l)" = 1 ] || fail "replays returned different ids:$ids"
dupes="$(tinker "echo Illuminate\Support\Facades\DB::table('invoices')->where('booking_type', '02')->where('product_id', $TERM_ID)->where('invoice_status', 0)->count().':'.Illuminate\Support\Facades\DB::table('event_booking_attendees')->where('calendar_event_id', $EVENT)->where('status', 'confirmed')->count();")"
[ "$dupes" = "1:1" ] || fail "enrollment replay created extra invoices or seats ($dupes)"

if grep -qiE 'deadlock|SQLSTATE\[40001\]|Lock wait timeout' storage/logs/laravel.log 2>/dev/null; then
  fail "deadlock or lock wait timeout found in laravel.log"
fi
echo "PASS: parallel last-seat enrollment, FIFO, cancel replay vs cash race and enrollment replay are consistent"
