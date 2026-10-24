import 'package:eachcore_shared_core/eachcore_shared_core.dart';

class FakeTransport implements ApiTransport {
  final List<String> calls = [];

  Map<String, dynamic> _event(String id, String start, String end) => {
    'id': id,
    'title': 'E$id',
    'description': null,
    'location': '大殿',
    'starts_at': start,
    'ends_at': end,
    'status': id == '2' ? 'cancelled' : 'published',
    'audience': 'members',
    'cancelled_at': null,
    'cancel_reason': id == '2' ? '颱風' : null,
  };

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    calls.add('$method $path');
    if (path.startsWith('/api/v1/events?')) {
      return {
        'data': [
          _event('1', '2026-12-31T14:00:00Z', '2027-01-01T02:00:00Z'),
          _event('2', '2027-01-15T01:00:00Z', '2027-01-15T02:00:00Z'),
        ],
        'meta': {'truncated': false},
      };
    }
    return {
      'data': _event('1', '2026-12-31T14:00:00Z', '2027-01-01T02:00:00Z'),
    };
  }
}

void check(bool condition) {
  if (!condition) throw StateError('Event contract failed');
}

Future<void> main() async {
  final fake = FakeTransport();
  final api = EventApi(fake);

  final from = DateTime.utc(2027, 1, 1);
  final to = DateTime.utc(2027, 2, 1);
  final range = await api.memberEvents(from, to);
  const expected =
      'GET /api/v1/events?from=2027-01-01T00%3A00%3A00.000Z'
      '&to=2027-02-01T00%3A00%3A00.000Z';
  check(fake.calls.last == expected);
  check(range.items.length == 2 && !range.truncated);
  check(range.items[1].isCancelled && range.items[1].cancelReason == '颱風');
  check(range.items.first.startsAt == DateTime.utc(2026, 12, 31, 14));

  // Overlap by local day (this smoke runs with the machine's time zone, so
  // build the days from the event's own local start).
  final first = range.items.first;
  final localStart = first.startsAt.toLocal();
  final localEnd = first.endsAt.subtract(const Duration(minutes: 1)).toLocal();
  check(first.occursOn(localStart));
  check(first.occursOn(localEnd));
  // UTC-constructed date labels must preserve their year/month/day fields.
  check(first.occursOn(DateTime.utc(localStart.year, localStart.month, localStart.day)));
  check(first.occursOn(DateTime.utc(localEnd.year, localEnd.month, localEnd.day)));
  check(!first.occursOn(first.endsAt.add(const Duration(days: 2))));

  final month = localMonthRange(DateTime(2026, 12, 15));
  check(month.from == DateTime(2026, 12) && month.to == DateTime(2027, 1));
  final utcMonth = localMonthRange(DateTime.utc(2027, 1, 1));
  check(utcMonth.from == DateTime(2027, 1) && utcMonth.to == DateTime(2027, 2));
  final localEndDay = DateTime(localEnd.year, localEnd.month, localEnd.day);
  check(first.occursOn(localEndDay));
  check(!first.occursOn(DateTime(localEndDay.year, localEndDay.month, localEndDay.day + 2)));

  var rejected = false;
  try {
    await api.memberEvents(to, from);
  } on ArgumentError {
    rejected = true;
  }
  check(rejected);

  check((await api.publicEvent('1')).id == '1');
  check(fake.calls.last == 'GET /api/v1/public/events/1');

  print('S5 Dart event API smoke: PASS');
}
