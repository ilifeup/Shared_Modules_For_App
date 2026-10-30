<?php

namespace App\Services\Payment;

use App\Models\EventBooking;
use Illuminate\Support\Facades\DB;

/**
 * Read-only pre-cutover audit. Never repairs, charges or cancels records.
 * Call against an isolated migrated test database before enabling paid cutover.
 */
final class CanonicalInvoiceAudit
{
    /** @return array{checked:int,issues:array<int,array{booking_id:int,code:string}>} */
    public function inspect(int $limit = 500): array
    {
        if ($limit < 1 || $limit > 5000) {
            throw new \InvalidArgumentException('Audit limit must be between 1 and 5000.');
        }

        $result = ['checked' => 0, 'issues' => []];
        // A mixed legacy/canonical event is not safe to audit as canonical-only.
        // Report it explicitly instead of mislabelling every legacy invoice as missing.
        EventBooking::query()
            ->join('calendar_events as e', 'e.id', '=', 'event_bookings.calendar_event_id')
            ->where('e.price_amount', '>', 0)
            ->where('event_bookings.active', 1)
            ->where('event_bookings.status', EventBooking::STATUS_CONFIRMED)
            ->select('event_bookings.*', 'e.price_amount as expected_amount')
            ->orderBy('event_bookings.id')
            ->chunkById(min(200, $limit), function ($bookings) use (&$result, $limit) {
                // Fetch dependencies once per bounded page, not once per booking.
                $page = $bookings->take($limit - $result['checked']);
                $bookingIds = $page->pluck('id')->map(fn ($id) => (int) $id)->all();
                $eventIds = $page->pluck('calendar_event_id')->unique()->values()->all();
                $legacyEvents = DB::table('event_booking_groups')
                    ->whereIn('calendar_event_id', $eventIds)
                    ->distinct()->pluck('calendar_event_id')->mapWithKeys(fn ($id) => [(int) $id => true]);
                $invoicesByBooking = DB::table('invoices')
                    ->where('booking_type', CanonicalEventInvoiceService::BOOKING_TYPE)
                    ->whereIn('booking_id', $bookingIds)
                    ->get()->groupBy('booking_id');

                foreach ($page as $booking) {
                    $result['checked']++;
                    if ($legacyEvents->has((int) $booking->calendar_event_id)) {
                        $result['issues'][] = ['booking_id' => (int) $booking->id, 'code' => 'LEGACY_LEDGER_PRESENT'];
                        continue;
                    }
                    $invoices = $invoicesByBooking->get($booking->id, collect());
                    $code = null;
                    if ($invoices->count() !== 1) {
                        $code = $invoices->isEmpty() ? 'MISSING_INVOICE' : 'DUPLICATE_INVOICE';
                    } else {
                        $invoice = $invoices->first();
                        if ((int) $invoice->product_id !== (int) $booking->calendar_event_id
                            || (int) $invoice->member_id !== (int) $booking->member_user_id
                            || (int) $invoice->booker_user_id !== (int) $booking->booker_user_id
                            || bccomp((string) $invoice->invoice_amount, (string) $booking->expected_amount, 2) !== 0) {
                            $code = 'INVOICE_IDENTITY_OR_AMOUNT_MISMATCH';
                        }
                    }
                    if ($code !== null) {
                        $result['issues'][] = ['booking_id' => (int) $booking->id, 'code' => $code];
                    }
                }
                return $result['checked'] < $limit;
            }, 'event_bookings.id', 'id');

        return $result;
    }
}
