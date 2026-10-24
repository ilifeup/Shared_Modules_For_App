import 'package:eachcore_shared_core/eachcore_shared_core.dart';

class FakeTransport implements ApiTransport {
  final List<String> calls = [];
  bool booked = false;

  Map<String, dynamic> _info() => {
    'event_id': '5',
    'enabled': true,
    'capacity': 2,
    'booked_count': booked ? 1 : 0,
    'remaining': booked ? 1 : 2,
    'opens_at': null,
    'closes_at': '2026-12-31T00:00:00Z',
    'unavailable_reason': booked ? 'already_booked' : null,
    'my_booking': booked
        ? {
            'id': '9',
            'event_id': '5',
            'status': 'confirmed',
            'booked_at': '2026-12-01T00:00:00Z',
            'cancelled_at': null,
          }
        : null,
  };

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    calls.add('$method $path');
    if (path == '/api/v1/events/5/bookings') booked = true;
    if (path == '/api/v1/me/bookings/9/cancel') booked = false;
    if (path == '/api/v1/me/bookings') {
      return {
        'data': [
          {
            'id': '9',
            'event_id': '5',
            'status': 'cancelled',
            'booked_at': '2026-12-01T00:00:00Z',
            'cancelled_at': '2026-12-02T00:00:00Z',
            'event': {
              'id': '5',
              'title': '講座',
              'starts_at': '2026-12-31T00:00:00Z',
            },
          },
        ],
      };
    }
    return {'data': _info()};
  }
}

void check(bool condition) {
  if (!condition) throw StateError('Booking contract failed');
}

Future<void> main() async {
  final fake = FakeTransport();
  final api = BookingApi(fake);

  final before = await api.info('5');
  check(before.canBook && before.remaining == 2 && before.myBooking == null);

  final after = await api.book('5');
  check(fake.calls.last == 'POST /api/v1/events/5/bookings');
  check(!after.canBook && after.unavailableReason == 'already_booked');
  check(after.myBooking!.isConfirmed && after.myBooking!.id == '9');

  final cancelled = await api.cancel('9');
  check(fake.calls.last == 'POST /api/v1/me/bookings/9/cancel');
  check(cancelled.canBook && cancelled.myBooking == null);

  final mine = await api.mine();
  check(mine.single.eventTitle == '講座' && !mine.single.isConfirmed);

  var rejected = false;
  try {
    BookingInfo.fromJson({'event_id': '5'});
  } on FormatException {
    rejected = true;
  }
  check(rejected);

  print('S6 Dart booking API smoke: PASS');
}
