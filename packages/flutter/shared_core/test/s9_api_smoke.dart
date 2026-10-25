import 'package:eachcore_shared_core/eachcore_shared_core.dart';

/// Contract smoke for the S9 Dart clients (no network): requests go to the
/// documented paths and responses decode into the DTOs.
class FakeTransport implements ApiTransport {
  final List<String> calls = [];
  final List<Map<String, dynamic>?> bodies = [];
  final Map<String, Map<String, dynamic>> responses = {};

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    calls.add('$method $path');
    bodies.add(body);
    final key = responses.keys.firstWhere(
      (k) => '$method $path'.startsWith(k),
      orElse: () => '',
    );
    if (key.isEmpty) return {'data': <String, dynamic>{}};
    return responses[key]!;
  }
}

void check(bool condition, String what) {
  if (!condition) throw StateError('S9 contract failed: $what');
}

const _event = {
  'id': '7',
  'title': '法會',
  'starts_at': '2026-12-01T02:00:00Z',
  'ends_at': '2026-12-01T04:00:00Z',
  'location': '道場',
  'floor': '2F',
  'studio': 'A',
  'status': 'published',
};

Map<String, dynamic> _group(String status) => {
  'id': '3',
  'event_id': '7',
  'status': status,
  'seat_count': 2,
  'contact_name': '會員',
  'is_guest': false,
  'booked_at': '2026-11-01T00:00:00Z',
  'confirmed_at': null,
  'cancelled_at': null,
  'waitlist_position': status == 'waitlisted' ? 1 : null,
  'cancel_deadline': '2026-12-01T02:00:00Z',
  'can_cancel': true,
  'attendees': [
    {'id': '10', 'name': '會員', 'status': status, 'is_me': true},
    {'id': '11', 'name': '家人', 'status': status, 'is_me': false},
  ],
  'event': _event,
};

Future<void> main() async {
  final fake = FakeTransport();

  // M08 group booking.
  fake.responses['POST /api/v1/events/7/booking-groups'] = {
    'data': _group('waitlisted'),
  };
  final group = await BookingGroupApi(fake)
      .book('7', clientRequestId: 'uuid-1', attendeeNames: ['家人']);
  check(group.isWaitlisted && group.waitlistPosition == 1, 'waitlist');
  check(group.venue == '道場 2F A' && group.attendees.length == 2, 'group');
  check(fake.bodies.last!['client_request_id'] == 'uuid-1', 'idempotency');
  fake.responses['GET /api/v1/me/booking-groups'] = {
    'data': [_group('confirmed')],
    'meta': {'current_page': 1, 'last_page': 2},
  };
  final page = await BookingGroupApi(fake).mine(status: 'active');
  check(
    fake.calls.last == 'GET /api/v1/me/booking-groups?status=active',
    'mine path',
  );
  check(page.items.single.isConfirmed && page.lastPage == 2, 'mine');

  // M09 calendar.
  fake.responses['GET /api/v1/me/calendar'] = {
    'data': {
      'events': [
        {
          ..._event,
          'audience': 'members',
          'timezone': 'Asia/Taipei',
          'category': {'id': '1', 'name': '共修', 'color': '#43A047'},
          'my_booking_status': 'confirmed',
          'checkin_enabled': true,
        },
      ],
      'private_events': [
        {
          'id': '5',
          'title': '私人',
          'starts_at': '2026-12-02T00:00:00Z',
          'ends_at': '2026-12-02T01:00:00Z',
          'timezone': 'Asia/Taipei',
          'recurrence': 'weekly',
          'editable': true,
        },
      ],
      'vip': true,
    },
    'meta': {'truncated': false},
  };
  final cal = await CalendarApi(fake)
      .myCalendar(DateTime.utc(2026, 12, 1), DateTime.utc(2027, 1, 1));
  check(
    fake.calls.last.startsWith('GET /api/v1/me/calendar?from=2026-12-01'),
    'calendar path',
  );
  check(cal.events.single.category!.color == '#43A047', 'category colour');
  check(cal.events.single.myBookingStatus == 'confirmed', 'booking status');
  check(cal.privateEvents.single.recurrence == 'weekly' && cal.vip, 'private');

  // M11 check-in.
  check(
    CheckinScan.parse('e.7.29999999.0123456789abcdef01234567')!.eventId == '7',
    'event token',
  );
  check(
    CheckinScan.parse(
          'https://app.example/checkin/l/ABCDEFGHIJKLMNOP',
          appHost: 'app.example',
        )!.kind ==
        'location',
    'location url',
  );
  check(
    CheckinScan.parse(
          'https://evil.example/checkin/l/ABCDEFGHIJKLMNOP',
          appHost: 'app.example',
        ) ==
        null,
    'foreign host',
  );
  fake.responses['POST /api/v1/events/7/checkin'] = {
    'data': [
      {
        'attendee_id': '10',
        'result': 'checked_in',
        'checkin_status': 'checked_in',
      },
    ],
  };
  final results = await CheckinApi(fake).checkIn(
    '7',
    ['10'],
    locationToken: 'ABCDEFGHIJKLMNOP',
    lat: 25.03,
    lng: 121.56,
  );
  check(results.single.isNew, 'check-in result');
  check(
    fake.bodies.last!['attendee_ids'][0] == 10 &&
        !fake.bodies.last!.containsKey('event_token'),
    'scan body',
  );

  // M19 search and bookmarks.
  const query = SearchQuery(q: '靜坐 入門', tagIds: ['1', '2'], sort: 'newest');
  check(
    query.toPath() == '/api/v1/search?q=%E9%9D%9C%E5%9D%90+%E5%85%A5%E9%96%80&tags[]=1&tags[]=2&sort=newest',
    'search path ${query.toPath()}',
  );
  fake.responses['GET /api/v1/me/bookmarks'] = {
    'data': [
      {
        'content_type': 'article',
        'content_id': '9',
        'available': false,
        'item': null,
      },
    ],
    'meta': {'count': 1000, 'limit': 1000},
  };
  final marks = await DiscoveryApi(fake).bookmarks();
  check(
    !marks.items.single.available && marks.count == marks.limit,
    'placeholder',
  );

  // M03 badges.
  fake.responses['GET /api/v1/me/verifications'] = {
    'data': {
      'requests': [
        {
          'id': '1',
          'type': {'name': '志工'},
          'status': 'pending',
          'approvals': 1,
          'required_approvals': 2,
        },
      ],
      'badges': [
        {
          'id': '4',
          'type': {'name': '教職'},
          'state': 'valid',
          'is_public': true,
        },
      ],
    },
  };
  final mine = await VerificationApi(fake).mine();
  check(
    mine.requests.single.isPending && mine.badges.single.isValid,
    'verification',
  );

  // M14 deep links, M15 marketing, M17 statistics never throw.
  const parser = DeepLinkParser({'app.example'});
  final link = parser.parse(
    Uri.parse(
      'https://app.example/articles/12?utm_source=line&utm_campaign=x%3Cy',
    ),
  );
  check(
    link!.appPath == '/articles/12' && link.utm['utm_source'] == 'line',
    'deep link',
  );
  check(!link.utm.containsKey('utm_campaign'), 'unsafe utm dropped');
  check(
    parser.parse(Uri.parse('http://app.example/articles/12')) == null,
    'http',
  );
  check(parser.parse(Uri.parse('https://app.example/admin')) == null, 'path');
  check(safeReturnPath('/events/3') == '/events/3', 'return path');
  check(
    safeReturnPath('//evil.example/x') == null &&
        safeReturnPath('https://x') == null,
    'external return',
  );
  var t = DateTime.utc(2026);
  final dedupe = DeepLinkDeduper(now: () => t);
  final uri = Uri.parse('https://app.example/events/3');
  check(!dedupe.isDuplicate(uri) && dedupe.isDuplicate(uri), 'dedupe');
  t = t.add(const Duration(seconds: 10));
  check(!dedupe.isDuplicate(uri), 'dedupe window');

  fake.responses['GET /api/v1/marketing/placements/home_banner'] = {
    'data': [
      {
        'id': '2',
        'title': '推廣',
        'button_text': '查看',
        'target': {
          'type': 'event',
          'id': '7',
          'url': 'https://app.example/events/7?utm_source=app',
          'app_path': '/events/7',
        },
        'utm': {
          'utm_source': 'app',
          'utm_medium': 'marketing',
          'utm_campaign': 'c2',
        },
      },
    ],
  };
  final banners = await MarketingApi(fake).placement('home_banner');
  check(
    banners.single.appPath == '/events/7' && !banners.single.isExternal,
    'marketing',
  );

  // M17-D20: optional UTM on share links; unknown keys are dropped.
  fake.responses['GET /api/v1/content/event/7/share'] = {
    'data': {
      'content_type': 'event',
      'content_id': '7',
      'shareable': true,
      'is_public': true,
      'url': 'https://app.example/events/7?utm_source=line',
    },
  };
  fake.responses['POST /api/v1/events/7/checkin/staff'] = {
    'data': {
      'attendee_id': '12',
      'result': 'checked_in',
      'checkin_status': 'checked_in',
    },
  };
  await LinkApi(fake)
      .share('event', '7', utm: {'utm_source': 'line', 'x': 'y'});
  check(
    fake.calls.last == 'GET /api/v1/content/event/7/share?utm_source=line',
    'share utm',
  );
  // M11-D11: manual guest check-in sends the stated contact.
  fake.responses.remove('POST /api/v1/events/7/checkin'); // prefix of the staff path
  await CheckinApi(fake)
      .staffScan('7', attendeeId: '12', contact: 'g@example.com');
  check(fake.bodies.last!['contact'] == 'g@example.com', 'staff contact');

  final failing = _Throwing();
  check(
    await LinkApi(failing).click('campaign', '2') == null,
    'click never throws',
  );
  check(!await LinkApi(failing).opened('article', '9'), 'open never throws');

  print('S9 Dart API smoke: PASS');
}

class _Throwing implements ApiTransport {
  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) => Future.error(StateError('offline'));
}
