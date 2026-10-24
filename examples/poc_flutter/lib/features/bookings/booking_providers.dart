import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';

final bookingInfoProvider = FutureProvider.autoDispose
    .family<BookingInfo, String>(
      (ref, eventId) => ref.watch(bookingApiProvider).info(eventId),
    );

final myBookingsProvider = FutureProvider.autoDispose<List<Booking>>(
  (ref) => ref.watch(bookingApiProvider).mine(),
);

/// Text shown when a new booking is not possible.
const bookingReasonLabels = {
  'already_booked': '您已預約此活動',
  'event_not_published': '此活動未開放預約',
  'event_cancelled': '活動已取消，無法預約',
  'disabled': '此活動不需預約',
  'not_open': '尚未開放預約',
  'closed': '預約已截止',
  'full': '名額已滿',
};
