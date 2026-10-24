import 'package:eachcore_shared_core/eachcore_shared_core.dart';

class FakeTransport implements ApiTransport {
  final List<String> calls = [];

  Map<String, dynamic> _item(String id, {bool read = false}) => {
    'id': id,
    'type': 'notice',
    'title': 'T$id',
    'body': 'B',
    'action': {'type': 'notice', 'id': '7'},
    'is_read': read,
    'read_at': read ? '2026-10-24T01:00:00Z' : null,
    'created_at': '2026-10-24T00:00:00Z',
  };

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    calls.add('$method $path');
    if (path.startsWith('/api/v1/me/notifications?page=2')) {
      return {
        'data': [_item('3')],
        'meta': {'current_page': 2, 'last_page': 2, 'unread_count': 2},
      };
    }
    if (path == '/api/v1/me/notifications') {
      return {
        'data': [_item('1'), _item('2', read: true)],
        'meta': {'current_page': 1, 'last_page': 2, 'unread_count': 2},
      };
    }
    if (path == '/api/v1/me/notifications/unread-count') {
      return {
        'data': {'unread_count': 2},
      };
    }
    if (path == '/api/v1/me/notifications/read-all') {
      return {
        'data': {'updated': 2, 'unread_count': 0},
      };
    }
    if (path.endsWith('/read')) return {'data': _item('1', read: true)};
    return {'data': _item('1')};
  }
}

void check(bool condition) {
  if (!condition) throw StateError('Notification contract failed');
}

Future<void> main() async {
  final fake = FakeTransport();
  final api = NotificationApi(fake);

  final page = await api.list();
  check(page.items.length == 2 && page.unreadCount == 2 && page.hasMore);
  check(page.items.first.actionType == 'notice');
  check(page.items.first.actionId == '7');
  check(!page.items.first.isRead && page.items.last.isRead);

  final second = await api.list(page: 2);
  check(!second.hasMore && second.items.single.id == '3');
  check(fake.calls.last == 'GET /api/v1/me/notifications?page=2');

  check(await api.unreadCount() == 2);
  check((await api.detail('1')).title == 'T1');
  check((await api.markRead('1')).isRead);
  check(fake.calls.last == 'POST /api/v1/me/notifications/1/read');
  check(await api.markAllRead() == 2);

  final minimal = MemberNotificationDto.fromJson({
    'id': 'x',
    'title': 't',
    'body': 'b',
  });
  check(minimal.type == 'system' && minimal.actionType == null);

  print('S4 Dart notification API smoke: PASS');
}
