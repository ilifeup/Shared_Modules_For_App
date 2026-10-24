import 'dart:math';

import 'package:dio/dio.dart';

import '../storage/token_storage.dart';

const requestIdHeader = 'X-Request-Id';

Dio createApiClient({
  required String baseUrl,
  required TokenStorage tokenStorage,
  HttpClientAdapter? adapter,
  void Function()? onUnauthorized,
}) {
  final dio = Dio(
    BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 20),
      headers: {'Accept': 'application/json'},
    ),
  );
  if (adapter != null) {
    dio.httpClientAdapter = adapter;
  }
  dio.interceptors.add(
    ApiInterceptor(tokenStorage, onUnauthorized: onUnauthorized),
  );
  return dio;
}

/// Adds X-Request-Id and the Bearer token; clears the token on 401 and
/// tells the session layer so the App returns to the login screen.
class ApiInterceptor extends Interceptor {
  ApiInterceptor(this._tokenStorage, {Random? random, this.onUnauthorized})
    : _random = random ?? Random.secure();

  final TokenStorage _tokenStorage;
  final Random _random;
  final void Function()? onUnauthorized;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    options.headers[requestIdHeader] = _newRequestId();
    final token = await _tokenStorage.read();
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    if (err.response?.statusCode == 401) {
      await _tokenStorage.clear();
      onUnauthorized?.call();
    }
    handler.next(err);
  }

  String _newRequestId() {
    final bytes = List<int>.generate(16, (_) => _random.nextInt(256));
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }
}
