import 'dart:math';

import 'package:dio/dio.dart';

import '../storage/token_storage.dart';

const requestIdHeader = 'X-Request-Id';

Dio createApiClient({
  required String baseUrl,
  required TokenStorage tokenStorage,
  HttpClientAdapter? adapter,
  void Function()? onUnauthorized,
  void Function()? onVerificationRequired,
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
    ApiInterceptor(
      tokenStorage,
      onUnauthorized: onUnauthorized,
      onVerificationRequired: onVerificationRequired,
    ),
  );
  return dio;
}

/// Adds X-Request-Id and the Bearer token; clears the token on 401 and
/// tells the session layer so the App returns to the login screen.
/// A 403 `VERIFICATION_REQUIRED` keeps the token (the member is signed in but
/// must verify first) and tells the session layer to show the verification gate.
class ApiInterceptor extends Interceptor {
  ApiInterceptor(
    this._tokenStorage, {
    Random? random,
    this.onUnauthorized,
    this.onVerificationRequired,
  }) : _random = random ?? Random.secure();

  final TokenStorage _tokenStorage;
  final Random _random;
  final void Function()? onUnauthorized;
  final void Function()? onVerificationRequired;

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
    final status = err.response?.statusCode;
    if (status == 401) {
      await _tokenStorage.clear();
      onUnauthorized?.call();
    } else if (status == 403 && _errorCode(err) == 'VERIFICATION_REQUIRED') {
      onVerificationRequired?.call();
    }
    handler.next(err);
  }

  static String? _errorCode(DioException err) {
    final data = err.response?.data;
    if (data is Map && data['error'] is Map) {
      final code = (data['error'] as Map)['code'];
      return code is String ? code : null;
    }
    return null;
  }

  String _newRequestId() {
    final bytes = List<int>.generate(16, (_) => _random.nextInt(256));
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }
}
