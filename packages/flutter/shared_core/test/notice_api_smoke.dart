import 'package:eachcore_shared_core/eachcore_shared_core.dart';

class FakeTransport implements ApiTransport {
  final List<String> calls = [];

  Map<String, dynamic> _notice(String id, {bool pinned = false}) => {
    'id': id,
    'title': 'T$id',
    'body': 'B',
    'status': 'published',
    'published_at': '2026-10-24T00:00:00Z',
    'publish_ends_at': null,
    'is_pinned': pinned,
    'audience': 'public',
    'category': {'id': '3', 'name': '活動'},
    'is_read': false,
  };

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    calls.add('$method $path');
    if (path.startsWith('/api/v1/notices?') || path == '/api/v1/notices') {
      return {
        'data': [_notice('1', pinned: true), _notice('2')],
        'meta': {'unread_count': 2},
      };
    }
    if (path == '/api/v1/notice-categories') {
      return {
        'data': [
          {'id': '3', 'name': '活動'},
        ],
      };
    }
    if (path == '/api/v1/public/notices') {
      return {
        'data': [_notice('5')],
      };
    }
    if (path == '/api/v1/public/notices/5') return {'data': _notice('5')};
    return {'data': null};
  }
}

void check(bool condition) {
  if (!condition) throw StateError('Notice contract failed');
}

Future<void> main() async {
  final fake = FakeTransport();
  final api = NoticeApi(fake);

  final page = await api.memberNotices(categoryId: '3');
  check(fake.calls.last == 'GET /api/v1/notices?category=3');
  check(page.unreadCount == 2 && page.items.first.isPinned);
  check(page.items.first.categoryName == '活動');
  check(page.items.first.isRead == false);

  await api.markRead('1');
  check(fake.calls.last == 'POST /api/v1/notices/1/read');

  check((await api.publicNotices()).single.audience == 'public');
  check((await api.publicNotice('5')).id == '5');
  check((await api.categories()).single.name == '活動');

  // S1 payloads (no S3 fields) still parse.
  final legacy = NoticeDto.fromJson({
    'id': '9',
    'title': 'Old',
    'body': 'B',
    'status': 'published',
    'published_at': '2026-10-23T00:00:00Z',
  });
  check(!legacy.isPinned && legacy.audience == 'members' && legacy.isRead == null);
  final ended = Notice(
    id: 'x',
    title: 'x',
    body: 'x',
    status: 'published',
    publishedAt: DateTime.utc(2026, 10, 1),
    publishEndsAt: DateTime.utc(2026, 10, 2),
  );
  check(!ended.isVisibleAt(DateTime.utc(2026, 10, 3)));

  print('S3 Dart notice API smoke: PASS');
}
