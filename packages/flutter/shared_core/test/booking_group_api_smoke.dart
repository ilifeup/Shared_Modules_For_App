import 'package:eachcore_shared_core/eachcore_shared_core.dart';

class RecordingTransport implements ApiTransport {
  final calls = <String>[];
  final bodies = <Map<String, dynamic>?>[];
  Map<String, dynamic>? familyResponse;

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    calls.add('$method $path');
    bodies.add(body);
    if (path == '/api/v1/me/family-memberships') {
      return familyResponse ??
          {
            'data': [
              {'member_user_id': 42, 'name': '家人甲'},
            ],
          };
    }
    return {
      'data': {
        'id': '12',
        'event_id': '5',
        'status': 'confirmed',
        'seat_count': 1,
        'booked_at': '2026-12-01T00:00:00Z',
        'cancel_deadline': '2026-12-31T00:00:00Z',
        'can_cancel': true,
        'attendees': [
          {'id': '20', 'name': '會員', 'status': 'confirmed', 'is_me': true},
        ],
        'event': {
          'id': '5',
          'title': '活動',
          'starts_at': '2026-12-31T01:00:00Z',
          'ends_at': '2026-12-31T02:00:00Z',
        },
      },
    };
  }
}

void check(bool condition, String message) {
  if (!condition) throw StateError(message);
}

Future<void> main() async {
  final transport = RecordingTransport();
  final api = BookingGroupApi(transport);
  const key = '550e8400-e29b-41d4-a716-446655440000';

  final self = await api.book('5', clientRequestId: key);
  check(
    self.seatCount == 1 && self.attendees.length == 1,
    'Expected exactly one confirmed attendee',
  );
  check(
    transport.calls.last == 'POST /api/v1/events/5/booking-groups',
    'Wrong booking endpoint',
  );
  check(
    transport.bodies.last!['client_request_id'] == key,
    'Missing idempotency key',
  );
  check(
    !transport.bodies.last!.containsKey('member_user_id'),
    'Self booking must omit member_user_id',
  );
  check(
    !transport.bodies.last!.containsKey('include_self') &&
        !transport.bodies.last!.containsKey('attendees'),
    'Obsolete multi-attendee fields must not be sent',
  );

  await api.book('5', clientRequestId: key, memberUserId: 42);
  check(
    transport.bodies.last!['member_user_id'] == 42,
    'Delegated booking must specify exactly one member ID',
  );
  check(
    transport.bodies.last!.length == 2,
    'Delegated booking must have only member_user_id and client_request_id',
  );

  var rejected = false;
  try {
    await api.book('5', clientRequestId: key, memberUserId: 0);
  } on ArgumentError {
    rejected = true;
  }
  check(rejected, 'Invalid member ID must be rejected before transport');
  check(
    transport.calls.length == 2,
    'Invalid member ID must not reach transport',
  );
  final family = await api.manageableMembers();
  check(
    family.length == 1 && family.single.id == 42 && family.single.name == '家人甲',
    'Family member name and ID must be retained',
  );
  final ids = await api.manageableMemberIds();
  check(
    ids.length == 1 && ids.single == 42,
    'Legacy IDs API must remain compatible',
  );
  transport.familyResponse = {
    'data': [
      {'member_user_id': 42},
    ],
  };
  var malformedRejected = false;
  try {
    await api.manageableMembers();
  } on FormatException {
    malformedRejected = true;
  }
  check(malformedRejected, 'Missing family member name must fail explicitly');
  print('S9 single-member booking API contract: PASS');
}
