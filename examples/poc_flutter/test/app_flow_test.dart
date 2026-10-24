import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:s1_poc_app/app.dart';
import 'package:s1_poc_app/core/network/api_exception.dart';
import 'package:s1_poc_app/core/providers.dart';
import 'package:s1_poc_app/core/storage/token_storage.dart';

/// In-memory backend speaking the S1 API contract.
class FakeBackend implements ApiTransport {
  final List<String> calls = [];
  bool acceptLogin = true;
  String displayName = '小明';

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    calls.add('$method $path');
    switch ('$method $path') {
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
        displayName = body!['display_name'] as String;
        return {'data': _profile()};
      case 'GET /api/v1/notices':
        return {
          'data': [_notice('1', '第一則公告')],
          'meta': {'current_page': 1},
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
    throw ApiException(code: 'NOT_FOUND', message: '找不到 $path');
  }

  Map<String, dynamic> _profile() => {
    'id': '1',
    'display_name': displayName,
    'email': 'member@example.com',
    'phone': null,
    'email_verified': true,
    'phone_verified': false,
  };

  Map<String, dynamic> _notice(String id, String title) => {
    'id': id,
    'title': title,
    'body': '公告內容',
    'status': 'published',
    'published_at': '2026-10-24T01:00:00Z',
  };
}

Future<void> pumpApp(
  WidgetTester tester,
  FakeBackend backend,
  MemoryTokenStorage storage,
) async {
  await tester.pumpWidget(
    ProviderScope(
      retry: (retryCount, error) => null,
      overrides: [
        apiTransportProvider.overrideWithValue(backend),
        tokenStorageProvider.overrideWithValue(storage),
      ],
      child: const S1PocApp(),
    ),
  );
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

    expect(find.text('公告'), findsOneWidget);
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

    await tester.enterText(find.byKey(const Key('profile-display-name')), '  ');
    await tester.tap(find.byKey(const Key('profile-save')));
    await tester.pumpAndSettle();
    expect(find.textContaining('【請修正】'), findsOneWidget);
    expect(backend.calls, isNot(contains('PATCH /api/v1/me/profile')));

    await tester.enterText(
      find.byKey(const Key('profile-display-name')),
      '新名字',
    );
    await tester.tap(find.byKey(const Key('profile-save')));
    await tester.pumpAndSettle();
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
    await tester.ensureVisible(find.byKey(const Key('logout')));
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
