import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:s1_poc_app/core/network/api_client.dart';
import 'package:s1_poc_app/core/network/api_exception.dart';
import 'package:s1_poc_app/core/storage/token_storage.dart';
import 'package:s1_poc_app/features/health/health_repository.dart';

import 'support/fake_adapter.dart';

void main() {
  const baseUrl = 'https://api.example.test/api/v1';

  test('adds Accept, X-Request-Id and Bearer token', () async {
    final adapter = FakeAdapter(statusCode: 200, body: {'data': {}});
    final dio = createApiClient(
      baseUrl: baseUrl,
      tokenStorage: MemoryTokenStorage('token-123'),
      adapter: adapter,
    );

    await dio.get<Map<String, dynamic>>('/health');

    final headers = adapter.lastRequest!.headers;
    expect(headers['Accept'], 'application/json');
    expect(headers['Authorization'], 'Bearer token-123');
    expect(headers[requestIdHeader], matches(RegExp(r'^[0-9a-f]{32}$')));
  });

  test('omits Authorization when there is no token', () async {
    final adapter = FakeAdapter(statusCode: 200, body: {'data': {}});
    final dio = createApiClient(
      baseUrl: baseUrl,
      tokenStorage: MemoryTokenStorage(),
      adapter: adapter,
    );

    await dio.get<Map<String, dynamic>>('/health');

    expect(adapter.lastRequest!.headers.containsKey('Authorization'), isFalse);
  });

  test('parses the error envelope into ApiException', () async {
    final dio = createApiClient(
      baseUrl: baseUrl,
      tokenStorage: MemoryTokenStorage(),
      adapter: FakeAdapter(
        statusCode: 422,
        body: {
          'error': {
            'code': 'VALIDATION_FAILED',
            'message': '輸入資料有誤。',
            'fields': {
              'email': ['email 格式錯誤'],
            },
            'request_id': 'req-422',
          },
        },
      ),
    );

    try {
      await dio.get<Map<String, dynamic>>('/anything');
      fail('expected DioException');
    } on DioException catch (error) {
      final apiError = ApiException.fromDio(error);
      expect(apiError.code, 'VALIDATION_FAILED');
      expect(apiError.statusCode, 422);
      expect(apiError.requestId, 'req-422');
      expect(apiError.fields['email'], ['email 格式錯誤']);
    }
  });

  test('keeps the token and reports 403 VERIFICATION_REQUIRED', () async {
    final storage = MemoryTokenStorage('valid');
    var gated = 0;
    var unauthorized = 0;
    final dio = createApiClient(
      baseUrl: baseUrl,
      tokenStorage: storage,
      onUnauthorized: () => unauthorized++,
      onVerificationRequired: () => gated++,
      adapter: FakeAdapter(
        statusCode: 403,
        body: {
          'error': {
            'code': 'VERIFICATION_REQUIRED',
            'message': '請先完成驗證。',
            'fields': {
              'verification': ['phone'],
            },
            'request_id': 'req-403',
          },
        },
      ),
    );

    await expectLater(
      dio.get<Map<String, dynamic>>('/notices'),
      throwsA(isA<DioException>()),
    );
    expect(await storage.read(), 'valid');
    expect(gated, 1);
    expect(unauthorized, 0);
  });

  test('clears the stored token on 401', () async {
    final storage = MemoryTokenStorage('expired');
    final dio = createApiClient(
      baseUrl: baseUrl,
      tokenStorage: storage,
      adapter: FakeAdapter(
        statusCode: 401,
        body: {
          'error': {
            'code': 'UNAUTHENTICATED',
            'message': '請先登入。',
            'request_id': 'req-401',
          },
        },
      ),
    );

    await expectLater(
      dio.get<Map<String, dynamic>>('/me'),
      throwsA(isA<DioException>()),
    );
    expect(await storage.read(), isNull);
  });

  test('HealthRepository parses a healthy response', () async {
    final dio = createApiClient(
      baseUrl: baseUrl,
      tokenStorage: MemoryTokenStorage(),
      adapter: FakeAdapter(
        statusCode: 200,
        body: {
          'data': {
            'status': 'ok',
            'database': 'ok',
            'server_time': '2026-10-22T00:00:00Z',
          },
        },
      ),
    );

    final status = await HealthRepository(dio).fetch();

    expect(status.isOk, isTrue);
  });

  test('HealthRepository converts errors into ApiException', () async {
    final dio = createApiClient(
      baseUrl: baseUrl,
      tokenStorage: MemoryTokenStorage(),
      adapter: FakeAdapter(
        statusCode: 503,
        body: {
          'error': {
            'code': 'DATABASE_UNAVAILABLE',
            'message': '資料庫暫時無法連線。',
            'request_id': 'req-503',
          },
        },
      ),
    );

    await expectLater(
      HealthRepository(dio).fetch(),
      throwsA(
        isA<ApiException>().having(
          (e) => e.code,
          'code',
          'DATABASE_UNAVAILABLE',
        ),
      ),
    );
  });
}
