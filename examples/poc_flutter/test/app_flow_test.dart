import 'dart:typed_data';

import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:s1_poc_app/app.dart';
import 'package:s1_poc_app/core/network/api_exception.dart';
import 'package:s1_poc_app/core/providers.dart';
import 'package:s1_poc_app/core/storage/token_storage.dart';
import 'package:s1_poc_app/features/profile/avatar_repository.dart';
import 'package:s1_poc_app/features/profile/profile_providers.dart';

/// In-memory backend speaking the S1/S3 API contract.
class FakeBackend implements ApiTransport {
  final List<String> calls = [];
  final List<Map<String, dynamic>?> bodies = [];
  bool acceptLogin = true;
  String displayName = '小明';

  /// Null = verification policy satisfied; otherwise `email` or `phone`.
  String? missing;
  String policy = 'email_required';
  String? email = 'member@example.com';
  String? phone = '+886912345678';
  bool emailVerified = true;
  bool hasAvatar = false;
  String levelCode = 'general';
  final Set<String> readIds = {};
  final Map<String, bool> notifications = {
    'n-1': false,
    'n-2': false,
    'n-3': true,
  };
  bool notificationsFail = false;
  bool eventsFail = false;
  final List<Map<String, dynamic>> events = [
    _event('1', '社區講座', '2026-12-30T02:00:00Z', '2026-12-30T04:00:00Z'),
    _event(
      '2',
      '戶外活動',
      '2026-12-30T06:00:00Z',
      '2026-12-30T08:00:00Z',
      cancelled: true,
    ),
    _event('3', '跨年活動', '2026-12-31T22:00:00Z', '2027-01-01T01:00:00Z'),
  ];

  static Map<String, dynamic> _event(
    String id,
    String title,
    String start,
    String end, {
    bool cancelled = false,
  }) => {
    'id': id,
    'title': title,
    'description': '$title 說明',
    'location': '活動中心',
    'starts_at': start,
    'ends_at': end,
    'status': cancelled ? 'cancelled' : 'published',
    'audience': 'members',
    'cancelled_at': cancelled ? '2026-12-01T00:00:00Z' : null,
    'cancel_reason': cancelled ? '天候不佳' : null,
  };

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    calls.add('$method $path');
    bodies.add(body);
    switch ('$method $path') {
      case 'GET /api/v1/auth/policy':
        return {
          'data': {
            'verification_policy': policy,
            'required_fields': ['name', 'email', 'phone', 'password'],
          },
        };
      case 'POST /api/v1/auth/email/resend':
      case 'POST /api/v1/auth/phone/send-otp':
      case 'POST /api/v1/auth/password/forgot':
      case 'POST /api/v1/auth/password/phone/send-otp':
        return {
          'data': {'message': 'ok'},
        };
      case 'POST /api/v1/auth/phone/verify':
        if (body?['code'] != '123456') {
          throw const ApiException(
            code: 'OTP_INVALID',
            message: '驗證碼錯誤或已失效。',
            statusCode: 422,
          );
        }
        if (missing == 'phone') missing = null;
        return {
          'data': {'verified': true},
        };
      case 'GET /api/v1/me/notifications':
        if (notificationsFail) {
          throw const ApiException(
            code: 'SERVER_ERROR',
            message: '伺服器發生錯誤，請稍後再試。',
            statusCode: 500,
          );
        }
        return {
          'data': [for (final id in notifications.keys) _notification(id)],
          'meta': {
            'current_page': 1,
            'last_page': 1,
            'unread_count': _unreadNotifications(),
          },
        };
      case 'GET /api/v1/me/notifications/unread-count':
        return {
          'data': {'unread_count': _unreadNotifications()},
        };
      case 'POST /api/v1/me/notifications/read-all':
        final updated = _unreadNotifications();
        notifications.updateAll((_, _) => true);
        return {
          'data': {'updated': updated, 'unread_count': 0},
        };
      case 'GET /api/v1/notice-categories':
        return {
          'data': [
            {'id': '3', 'name': '活動'},
          ],
        };
      case 'GET /api/v1/notices?category=3':
        return {
          'data': [_notice('2', '活動公告', category: true)],
          'meta': {'unread_count': 1},
        };
      case 'POST /api/v1/notices/1/read':
        readIds.add('1');
        return {
          'data': {'id': '1', 'is_read': true},
        };
      case 'GET /api/v1/public/notices':
        return {
          'data': [_notice('9', '公開活動')],
        };
      case 'GET /api/v1/me/membership':
        return {
          'data': {
            'level': {
              'code': levelCode,
              'name': levelCode == 'vip' ? 'VIP 會員' : '一般會員',
              'benefits': levelCode == 'vip' ? '優先報名' : null,
              'is_default': levelCode != 'vip',
            },
            'starts_at': null,
            'ends_at': levelCode == 'vip' ? '2026-12-31T00:00:00Z' : null,
          },
        };
      case 'POST /api/v1/me/email':
        if (body?['password'] != 'secret-1') {
          throw const ApiException(
            code: 'VALIDATION_FAILED',
            message: '密碼錯誤。',
            statusCode: 422,
            fields: {
              'password': ['密碼錯誤。'],
            },
          );
        }
        email = body!['email'] as String;
        emailVerified = false;
        if (policy == 'email_required') missing = 'email';
        return {'data': _profile()};
      case 'POST /api/v1/me/phone/send-otp':
        return {
          'data': {'message': 'ok'},
        };
      case 'POST /api/v1/me/phone/verify':
        if (body?['code'] != '123456') {
          throw const ApiException(
            code: 'OTP_INVALID',
            message: '驗證碼錯誤或已失效。',
            statusCode: 422,
          );
        }
        phone = '+886${(body!['phone'] as String).substring(1)}';
        if (missing == 'phone') missing = null;
        return {'data': _profile()};
      case 'POST /api/v1/auth/password/phone/reset':
        return {
          'data': {'reset': true},
        };
      case 'POST /api/v1/auth/login':
        if (!acceptLogin) {
          throw const ApiException(
            code: 'INVALID_CREDENTIALS',
            message: '帳號或密碼錯誤。',
            statusCode: 401,
            requestId: 'req-login-1',
          );
        }
        return {
          'data': {'token': 'synthetic-token', 'token_type': 'Bearer'},
        };
      case 'GET /api/v1/me/profile':
        return {'data': _profile()};
      case 'PATCH /api/v1/me/profile':
        displayName = body!['display_name'] as String? ?? displayName;
        return {'data': _profile()};
      case 'GET /api/v1/notices':
        if (missing != null) {
          throw ApiException(
            code: 'VERIFICATION_REQUIRED',
            message: '請先完成驗證。',
            statusCode: 403,
            fields: {
              'verification': [missing!],
            },
          );
        }
        return {
          'data': [_notice('1', '第一則公告', pinned: true)],
          'meta': {
            'current_page': 1,
            'unread_count': readIds.length == 1 ? 0 : 1,
          },
        };
      case 'GET /api/v1/notices/1':
        return {'data': _notice('1', '第一則公告')};
      case 'POST /api/v1/auth/logout':
        return {
          'data': {'logged_out': true},
        };
      case 'POST /api/v1/auth/register':
        return {
          'data': {
            'user': _profile(),
            'verification': {'email': 'sent', 'phone': null},
          },
        };
    }
    if (path.startsWith('/api/v1/events?')) {
      if (eventsFail) {
        throw const ApiException(
          code: 'SERVER_ERROR',
          message: '伺服器發生錯誤，請稍後再試。',
          statusCode: 500,
        );
      }
      final query = Uri.parse(path).queryParameters;
      final from = DateTime.parse(query['from']!);
      final to = DateTime.parse(query['to']!);
      return {
        'data': [
          for (final event in events)
            if (DateTime.parse(event['starts_at'] as String).isBefore(to) &&
                DateTime.parse(event['ends_at'] as String).isAfter(from))
              event,
        ],
        'meta': {'truncated': false},
      };
    }
    final eventMatch = RegExp(r'^/api/v1/events/(\d+)$').firstMatch(path);
    if (eventMatch != null) {
      return {'data': events.firstWhere((e) => e['id'] == eventMatch.group(1))};
    }
    final match = RegExp(
      r'^/api/v1/me/notifications/([\w-]+)(/read)?$',
    ).firstMatch(path);
    if (match != null && notifications.containsKey(match.group(1))) {
      final id = match.group(1)!;
      if (method == 'POST') notifications[id] = true;
      return {
        'data': _notification(id),
        'meta': {'unread_count': _unreadNotifications()},
      };
    }
    throw ApiException(code: 'NOT_FOUND', message: '找不到 $path');
  }

  Map<String, dynamic> _profile() => {
    'id': '1',
    'display_name': displayName,
    'name': '王小明',
    'email': email,
    'phone': phone,
    'email_verified': emailVerified && missing != 'email',
    'phone_verified': missing != 'phone',
    'has_avatar': hasAvatar,
    'profile': {
      'complete': email != null && phone != null,
      'missing': [if (email == null) 'email', if (phone == null) 'phone'],
    },
    'verification': {
      'policy': policy,
      'satisfied': missing == null,
      'missing': missing,
    },
  };

  int _unreadNotifications() =>
      notifications.values.where((read) => !read).length;

  Map<String, dynamic> _notification(String id) => {
    'id': id,
    'type': 'notice',
    'title': '通知 $id',
    'body': '通知內容 $id',
    'action': {'type': 'notice', 'id': '1'},
    'is_read': notifications[id],
    'read_at': notifications[id]! ? '2026-10-24T02:00:00Z' : null,
    'created_at': '2026-10-24T01:00:00Z',
  };

  Map<String, dynamic> _notice(
    String id,
    String title, {
    bool pinned = false,
    bool category = false,
  }) => {
    'id': id,
    'title': title,
    'body': '公告內容',
    'status': 'published',
    'published_at': '2026-10-24T01:00:00Z',
    'is_pinned': pinned,
    'category': category ? {'id': '3', 'name': '活動'} : null,
    'is_read': readIds.contains(id),
  };
}

class FakeAvatars implements AvatarRepository {
  FakeAvatars(this.backend);

  final FakeBackend backend;
  final List<String> uploads = [];

  @override
  Future<void> upload(Uint8List bytes, String filename) async {
    uploads.add(filename);
    backend.hasAvatar = true;
  }

  @override
  Future<Uint8List?> fetch() async => null;

  @override
  Future<void> delete() async {
    backend.hasAvatar = false;
  }
}

Future<void> pumpApp(
  WidgetTester tester,
  FakeBackend backend,
  MemoryTokenStorage storage, {
  FakeAvatars? avatars,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      retry: (retryCount, error) => null,
      overrides: [
        apiTransportProvider.overrideWithValue(backend),
        tokenStorageProvider.overrideWithValue(storage),
        avatarRepositoryProvider.overrideWithValue(
          avatars ?? FakeAvatars(backend),
        ),
        clockProvider.overrideWithValue(() => DateTime(2026, 12, 30, 10)),
        avatarPickerProvider.overrideWithValue(
          () async =>
              (bytes: Uint8List.fromList([1, 2, 3]), filename: 'me.jpg'),
        ),
      ],
      child: const S1PocApp(),
    ),
  );
  await tester.pumpAndSettle();
}

/// Scrolls the page's main ListView until [key] is built and visible.
Future<void> scrollTo(WidgetTester tester, String key) async {
  await tester.scrollUntilVisible(
    find.byKey(Key(key)),
    200,
    scrollable: find.byType(Scrollable).first,
  );
  await tester.pumpAndSettle();
}

Future<void> scrollToTop(WidgetTester tester) async {
  await tester.drag(find.byType(ListView).first, const Offset(0, 2000));
  await tester.pumpAndSettle();
}

Future<void> login(WidgetTester tester) async {
  await tester.enterText(
    find.byKey(const Key('login-identifier')),
    'member@example.com',
  );
  await tester.enterText(find.byKey(const Key('login-password')), 'secret-1');
  await tester.tap(find.byKey(const Key('login-submit')));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('shows the login screen when no token is stored', (tester) async {
    await pumpApp(tester, FakeBackend(), MemoryTokenStorage());

    expect(find.text('會員登入'), findsOneWidget);
  });

  testWidgets('shows the API error code and keeps no token on bad login', (
    tester,
  ) async {
    final backend = FakeBackend()..acceptLogin = false;
    final storage = MemoryTokenStorage();
    await pumpApp(tester, backend, storage);

    await login(tester);

    expect(find.text('【錯誤】帳號或密碼錯誤。'), findsOneWidget);
    expect(find.textContaining('INVALID_CREDENTIALS'), findsOneWidget);
    expect(find.textContaining('req-login-1'), findsOneWidget);
    expect(await storage.read(), isNull);
  });

  testWidgets('logs in, stores the token and lists published notices', (
    tester,
  ) async {
    final storage = MemoryTokenStorage();
    await pumpApp(tester, FakeBackend(), storage);

    await login(tester);

    expect(await storage.read(), 'synthetic-token');
    expect(find.text('第一則公告'), findsOneWidget);

    await tester.tap(find.text('第一則公告'));
    await tester.pumpAndSettle();
    // SelectableText renders the body through an EditableText as well.
    expect(find.text('公告內容'), findsWidgets);
  });

  testWidgets('restores a saved session without asking to log in again', (
    tester,
  ) async {
    await pumpApp(tester, FakeBackend(), MemoryTokenStorage('saved-token'));

    expect(find.text('公告（未讀 1）'), findsOneWidget);
    expect(find.text('會員登入'), findsNothing);
  });

  testWidgets('returns to login when the saved token was revoked', (
    tester,
  ) async {
    final storage = MemoryTokenStorage('revoked-token');
    final backend = _RevokedBackend();
    await pumpApp(tester, backend, storage);

    expect(find.text('會員登入'), findsOneWidget);
    expect(await storage.read(), isNull);
  });

  testWidgets('edits the display name and validates it locally', (
    tester,
  ) async {
    final backend = FakeBackend();
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));

    await tester.tap(find.byKey(const Key('open-profile')));
    await tester.pumpAndSettle();

    await scrollTo(tester, 'profile-save');
    await tester.enterText(find.byKey(const Key('profile-display-name')), '  ');
    await tester.tap(find.byKey(const Key('profile-save')));
    await tester.pumpAndSettle();
    await scrollToTop(tester);
    expect(find.textContaining('【請修正】'), findsOneWidget);
    expect(backend.calls, isNot(contains('PATCH /api/v1/me/profile')));

    await scrollTo(tester, 'profile-save');
    await tester.enterText(
      find.byKey(const Key('profile-display-name')),
      '新名字',
    );
    await tester.tap(find.byKey(const Key('profile-save')));
    await tester.pumpAndSettle();
    await scrollToTop(tester);
    expect(find.textContaining('【錯誤】'), findsNothing);
    expect(find.textContaining('【已儲存】'), findsOneWidget);
    expect(backend.displayName, '新名字');
  });

  testWidgets('logout revokes on the server and clears the local token', (
    tester,
  ) async {
    final backend = FakeBackend();
    final storage = MemoryTokenStorage('saved-token');
    await pumpApp(tester, backend, storage);

    await tester.tap(find.byKey(const Key('open-profile')));
    await tester.pumpAndSettle();
    await scrollTo(tester, 'logout');
    await tester.tap(find.byKey(const Key('logout')));
    await tester.pumpAndSettle();

    expect(backend.calls, contains('POST /api/v1/auth/logout'));
    expect(await storage.read(), isNull);
    expect(find.text('會員登入'), findsOneWidget);
  });

  testWidgets('registration with email leads to the verification notice', (
    tester,
  ) async {
    await pumpApp(tester, FakeBackend(), MemoryTokenStorage());

    await tester.tap(find.text('註冊新帳號'));
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const Key('register-name')), '王小明');
    await tester.enterText(
      find.byKey(const Key('register-email')),
      'new@example.com',
    );
    await tester.enterText(
      find.byKey(const Key('register-phone')),
      '0912345678',
    );
    await tester.enterText(
      find.byKey(const Key('register-password')),
      'secret-12',
    );
    await tester.enterText(
      find.byKey(const Key('register-confirm')),
      'secret-12',
    );
    await tester.ensureVisible(find.byKey(const Key('register-submit')));
    await tester.tap(find.byKey(const Key('register-submit')));
    await tester.pumpAndSettle();

    expect(find.text('【待驗證】驗證信已寄出'), findsOneWidget);
    expect(find.textContaining('new@example.com'), findsOneWidget);
  });

  testWidgets('registration requires the phone number', (tester) async {
    final backend = FakeBackend();
    await pumpApp(tester, backend, MemoryTokenStorage());

    await tester.tap(find.text('註冊新帳號'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('register-name')), '王小明');
    await tester.enterText(
      find.byKey(const Key('register-email')),
      'new@example.com',
    );
    await tester.enterText(
      find.byKey(const Key('register-password')),
      'secret-12',
    );
    await tester.enterText(
      find.byKey(const Key('register-confirm')),
      'secret-12',
    );
    await tester.ensureVisible(find.byKey(const Key('register-submit')));
    await tester.tap(find.byKey(const Key('register-submit')));
    await tester.pumpAndSettle();

    expect(find.text('請輸入手機號碼。'), findsOneWidget);
    expect(backend.calls, isNot(contains('POST /api/v1/auth/register')));
  });

  testWidgets('an unverified member only sees the verification gate', (
    tester,
  ) async {
    final backend = FakeBackend()..missing = 'email';
    final storage = MemoryTokenStorage();
    await pumpApp(tester, backend, storage);

    await login(tester);

    expect(await storage.read(), 'synthetic-token');
    expect(find.text('【待驗證】請完成 Email 驗證'), findsOneWidget);
    expect(find.text('第一則公告'), findsNothing);
    expect(backend.calls, isNot(contains('GET /api/v1/notices')));

    await tester.tap(find.byKey(const Key('gate-resend-email')));
    await tester.pumpAndSettle();
    expect(backend.calls, contains('POST /api/v1/auth/email/resend'));

    // The member clicks the email link elsewhere, then re-checks.
    backend.missing = null;
    await tester.tap(find.byKey(const Key('gate-recheck')));
    await tester.pumpAndSettle();

    expect(find.text('第一則公告'), findsOneWidget);
  });

  testWidgets('phone policy gate verifies the phone and opens the App', (
    tester,
  ) async {
    final backend = FakeBackend()
      ..policy = 'phone_required'
      ..missing = 'phone';
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));

    expect(find.text('【待驗證】請完成手機驗證'), findsOneWidget);

    await tester.tap(find.byKey(const Key('gate-phone')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('verify-code')), '123456');
    await tester.tap(find.byKey(const Key('verify-submit')));
    await tester.pumpAndSettle();

    expect(backend.calls, contains('POST /api/v1/auth/phone/verify'));
    expect(find.text('第一則公告'), findsOneWidget);
  });

  testWidgets('forgot password by phone resets and returns to login', (
    tester,
  ) async {
    final backend = FakeBackend();
    await pumpApp(tester, backend, MemoryTokenStorage());

    await tester.tap(find.byKey(const Key('login-forgot')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('用手機'));
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const Key('forgot-phone')), '0912345678');
    await tester.tap(find.byKey(const Key('forgot-send-code')));
    await tester.pumpAndSettle();
    expect(
      backend.calls,
      contains('POST /api/v1/auth/password/phone/send-otp'),
    );
    expect(find.text('若此手機已註冊，密碼重設驗證碼已發送。'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('forgot-code')), '123456');
    await tester.enterText(
      find.byKey(const Key('forgot-password')),
      'new-secret-1',
    );
    await tester.enterText(
      find.byKey(const Key('forgot-confirm')),
      'new-other-1',
    );
    await tester.ensureVisible(find.byKey(const Key('forgot-reset-submit')));
    await tester.tap(find.byKey(const Key('forgot-reset-submit')));
    await tester.pumpAndSettle();
    expect(find.text('兩次輸入的密碼不一致。'), findsOneWidget);
    expect(
      backend.calls,
      isNot(contains('POST /api/v1/auth/password/phone/reset')),
    );

    await tester.enterText(
      find.byKey(const Key('forgot-confirm')),
      'new-secret-1',
    );
    await tester.ensureVisible(find.byKey(const Key('forgot-reset-submit')));
    await tester.tap(find.byKey(const Key('forgot-reset-submit')));
    await tester.pumpAndSettle();

    expect(backend.calls, contains('POST /api/v1/auth/password/phone/reset'));
    expect(backend.bodies.last?['code'], '123456');
    expect(find.text('會員登入'), findsOneWidget);
  });

  testWidgets('forgot password by email shows the same generic answer', (
    tester,
  ) async {
    final backend = FakeBackend();
    await pumpApp(tester, backend, MemoryTokenStorage());

    await tester.tap(find.byKey(const Key('login-forgot')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('forgot-email')),
      'someone@example.com',
    );
    await tester.tap(find.byKey(const Key('forgot-email-submit')));
    await tester.pumpAndSettle();

    expect(backend.calls, contains('POST /api/v1/auth/password/forgot'));
    expect(find.textContaining('若此 Email 已註冊'), findsOneWidget);
  });

  testWidgets('profile shows the level, benefits and missing fields', (
    tester,
  ) async {
    final backend = FakeBackend()
      ..levelCode = 'vip'
      ..phone = null;
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));

    await tester.tap(find.byKey(const Key('open-profile')));
    await tester.pumpAndSettle();

    expect(find.text('【會員等級】VIP 會員'), findsOneWidget);
    expect(find.textContaining('待遇：優先報名'), findsOneWidget);
    expect(find.text('【請補齊資料】'), findsOneWidget);
    expect(find.textContaining('尚缺：手機號碼'), findsOneWidget);
    expect(find.text('手機：未設定'), findsOneWidget);
  });

  testWidgets('adds a phone number only after the code is confirmed', (
    tester,
  ) async {
    final backend = FakeBackend()..phone = null;
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));

    await tester.tap(find.byKey(const Key('open-profile')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('profile-change-phone')));
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const Key('change-phone')), '0922333444');
    await tester.enterText(
      find.byKey(const Key('change-phone-password')),
      'secret-1',
    );
    await tester.tap(find.byKey(const Key('change-phone-send')));
    await tester.pumpAndSettle();
    expect(backend.calls, contains('POST /api/v1/me/phone/send-otp'));
    expect(backend.phone, isNull);

    await tester.enterText(
      find.byKey(const Key('change-phone-code')),
      '123456',
    );
    await tester.tap(find.byKey(const Key('change-phone-confirm')));
    await tester.pumpAndSettle();

    expect(backend.phone, '+886922333444');
    expect(find.textContaining('【錯誤】'), findsNothing);
    expect(find.text('手機號碼設定'), findsNothing, reason: 'back on profile');
    expect(find.text('個人資料'), findsOneWidget);
    expect(find.text('手機：+886922333444'), findsOneWidget);
    expect(find.text('【請補齊資料】'), findsNothing);
  });

  testWidgets('changing the email needs the password and re-verification', (
    tester,
  ) async {
    final backend = FakeBackend();
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));

    await tester.tap(find.byKey(const Key('open-profile')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('profile-change-email')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('change-email')),
      'new@example.com',
    );
    await tester.enterText(
      find.byKey(const Key('change-email-password')),
      'wrong',
    );
    await tester.tap(find.byKey(const Key('change-email-submit')));
    await tester.pumpAndSettle();
    expect(find.text('【錯誤】密碼錯誤。'), findsOneWidget);
    expect(backend.email, 'member@example.com');

    await tester.enterText(
      find.byKey(const Key('change-email-password')),
      'secret-1',
    );
    await tester.tap(find.byKey(const Key('change-email-submit')));
    await tester.pumpAndSettle();

    // Email policy: the new address must be verified before full access.
    expect(backend.email, 'new@example.com');
    expect(find.text('【待驗證】請完成 Email 驗證'), findsOneWidget);
  });

  testWidgets('uploads an avatar picked from the gallery', (tester) async {
    final backend = FakeBackend();
    final avatars = FakeAvatars(backend);
    await pumpApp(
      tester,
      backend,
      MemoryTokenStorage('saved-token'),
      avatars: avatars,
    );

    await tester.tap(find.byKey(const Key('open-profile')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('avatar-delete')), findsNothing);

    await tester.tap(find.byKey(const Key('avatar-pick')));
    await tester.pumpAndSettle();

    expect(avatars.uploads, ['me.jpg']);
    expect(find.text('【已儲存】頭像已更新。'), findsOneWidget);
    expect(find.byKey(const Key('avatar-delete')), findsOneWidget);
  });

  testWidgets('an old member without email can add it from the gate', (
    tester,
  ) async {
    final backend = FakeBackend()
      ..email = null
      ..missing = 'email';
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));

    expect(find.text('【待驗證】請完成 Email 驗證'), findsOneWidget);
    await tester.tap(find.byKey(const Key('gate-add-email')));
    await tester.pumpAndSettle();

    expect(find.text('Email 設定'), findsOneWidget);
  });

  testWidgets('shows pinned and unread labels and marks a notice read', (
    tester,
  ) async {
    final backend = FakeBackend();
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));

    expect(find.text('公告（未讀 1）'), findsOneWidget);
    expect(find.textContaining('【置頂】【未讀】'), findsOneWidget);

    await tester.tap(find.text('第一則公告'));
    await tester.pumpAndSettle();
    expect(backend.calls, contains('POST /api/v1/notices/1/read'));

    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(find.text('公告'), findsOneWidget);
    expect(find.textContaining('【未讀】'), findsNothing);
  });

  testWidgets('filters notices by category', (tester) async {
    final backend = FakeBackend();
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));

    await tester.tap(find.byKey(const Key('notice-category-3')));
    await tester.pumpAndSettle();

    expect(backend.calls, contains('GET /api/v1/notices?category=3'));
    expect(find.text('活動公告'), findsOneWidget);
    expect(find.text('第一則公告'), findsNothing);
  });

  testWidgets('shows public notices without logging in', (tester) async {
    final backend = FakeBackend();
    await pumpApp(tester, backend, MemoryTokenStorage());

    await tester.ensureVisible(find.byKey(const Key('login-public-notices')));
    await tester.tap(find.byKey(const Key('login-public-notices')));
    await tester.pumpAndSettle();

    expect(find.text('公開活動'), findsOneWidget);
    expect(backend.calls, isNot(contains('GET /api/v1/notices')));
  });

  testWidgets('shows the unread badge and opens a notification as read', (
    tester,
  ) async {
    final backend = FakeBackend();
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));

    expect(find.byTooltip('通知（未讀 2）'), findsOneWidget);
    await tester.tap(find.byKey(const Key('open-notifications')));
    await tester.pumpAndSettle();

    expect(find.text('通知（未讀 2）'), findsOneWidget);
    expect(find.textContaining('【未讀】'), findsNWidgets(2));

    await tester.tap(find.text('通知 n-1'));
    await tester.pumpAndSettle();
    expect(find.text('通知內容 n-1'), findsWidgets);
    expect(backend.calls, contains('POST /api/v1/me/notifications/n-1/read'));

    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(find.text('通知（未讀 1）'), findsOneWidget);
    expect(find.textContaining('【未讀】'), findsOneWidget);
  });

  testWidgets('marks all notifications read', (tester) async {
    final backend = FakeBackend();
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));
    await tester.tap(find.byKey(const Key('open-notifications')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('notifications-read-all')));
    await tester.pumpAndSettle();

    expect(backend.calls, contains('POST /api/v1/me/notifications/read-all'));
    expect(find.text('通知'), findsOneWidget);
    expect(find.textContaining('【未讀】'), findsNothing);
  });

  testWidgets('shows empty and error states for notifications', (tester) async {
    final backend = FakeBackend()..notifications.clear();
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));
    await tester.tap(find.byKey(const Key('open-notifications')));
    await tester.pumpAndSettle();
    expect(find.text('【目前沒有通知】'), findsOneWidget);

    backend.notificationsFail = true;
    await tester.pageBack();
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('open-notifications')));
    await tester.pumpAndSettle();
    expect(find.textContaining('SERVER_ERROR'), findsOneWidget);

    backend.notificationsFail = false;
    await tester.tap(find.text('重試'));
    await tester.pumpAndSettle();
    expect(find.text('【目前沒有通知】'), findsOneWidget);
  });

  testWidgets('shows the month, the selected day and its events', (
    tester,
  ) async {
    final backend = FakeBackend();
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));

    await tester.tap(find.byKey(const Key('open-calendar')));
    await tester.pumpAndSettle();

    expect(find.text('2026 年 12 月'), findsOneWidget);
    expect(find.text('2026-12-30（三）的活動'), findsOneWidget);
    expect(find.text('社區講座'), findsOneWidget);
    expect(find.text('【已取消】戶外活動'), findsOneWidget);
    expect(
      backend.calls.where((c) => c.startsWith('GET /api/v1/events?')),
      hasLength(1),
    );

    await tester.tap(find.byKey(const Key('calendar-day-31')));
    await tester.pumpAndSettle();
    expect(find.text('跨年活動'), findsOneWidget);
    expect(find.text('社區講座'), findsNothing);

    await tester.tap(find.byKey(const Key('calendar-day-5')));
    await tester.pumpAndSettle();
    expect(find.text('【這天沒有活動】'), findsOneWidget);
  });

  testWidgets('moves across the year and shows events spanning midnight', (
    tester,
  ) async {
    final backend = FakeBackend();
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));
    await tester.tap(find.byKey(const Key('open-calendar')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('calendar-next')));
    await tester.pumpAndSettle();

    expect(find.text('2027 年 1 月'), findsOneWidget);
    expect(find.text('2027-01-01（五）的活動'), findsOneWidget);
    expect(find.text('跨年活動'), findsOneWidget);
    expect(
      backend.calls.last,
      startsWith('GET /api/v1/events?from=2027-01-01T00'),
    );

    await tester.tap(find.byKey(const Key('calendar-prev')));
    await tester.tap(find.byKey(const Key('calendar-prev')));
    await tester.pumpAndSettle();
    expect(find.text('2026 年 11 月'), findsOneWidget);
  });

  testWidgets('shows a cancelled event with its reason', (tester) async {
    await pumpApp(tester, FakeBackend(), MemoryTokenStorage('saved-token'));
    await tester.tap(find.byKey(const Key('open-calendar')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('【已取消】戶外活動'));
    await tester.pumpAndSettle();

    expect(find.text('【已取消】此活動已取消'), findsOneWidget);
    expect(find.text('天候不佳'), findsOneWidget);
    expect(find.text('地點：活動中心'), findsOneWidget);
  });

  testWidgets('retries after a calendar loading error', (tester) async {
    final backend = FakeBackend()..eventsFail = true;
    await pumpApp(tester, backend, MemoryTokenStorage('saved-token'));
    await tester.tap(find.byKey(const Key('open-calendar')));
    await tester.pumpAndSettle();

    expect(find.textContaining('SERVER_ERROR'), findsOneWidget);
    backend.eventsFail = false;
    await tester.ensureVisible(find.byKey(const Key('calendar-retry')));
    await tester.tap(find.byKey(const Key('calendar-retry')));
    await tester.pumpAndSettle();
    expect(find.text('社區講座'), findsOneWidget);
  });
}

class _RevokedBackend extends FakeBackend {
  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    if (path == '/api/v1/me/profile') {
      throw const ApiException(
        code: 'UNAUTHENTICATED',
        message: '請先登入。',
        statusCode: 401,
      );
    }
    return super.request(method, path, body: body);
  }
}
