import 'package:dio/dio.dart';

/// Error returned by the API envelope
/// `{"error": {"code", "message", "fields"?, "request_id"}}`
/// or created for network failures.
class ApiException implements Exception {
  const ApiException({
    required this.code,
    required this.message,
    this.statusCode,
    this.requestId,
    this.fields = const {},
  });

  factory ApiException.fromDio(DioException error) {
    final response = error.response;
    final headerRequestId = response?.headers.value('x-request-id');
    final data = response?.data;

    if (data is Map && data['error'] is Map) {
      final body = Map<String, dynamic>.from(data['error'] as Map);
      return ApiException(
        code: body['code'] as String? ?? 'UNKNOWN_ERROR',
        message: body['message'] as String? ?? '發生未預期的錯誤。',
        statusCode: response?.statusCode,
        requestId: body['request_id'] as String? ?? headerRequestId,
        fields: _parseFields(body['fields']),
      );
    }

    final isNetworkError = switch (error.type) {
      DioExceptionType.connectionTimeout ||
      DioExceptionType.sendTimeout ||
      DioExceptionType.receiveTimeout ||
      DioExceptionType.connectionError => true,
      _ => false,
    };

    return ApiException(
      code: isNetworkError ? 'NETWORK_ERROR' : 'UNKNOWN_ERROR',
      message: isNetworkError ? '無法連線到伺服器。' : '發生未預期的錯誤。',
      statusCode: response?.statusCode,
      requestId: headerRequestId,
    );
  }

  final String code;
  final String message;
  final int? statusCode;
  final String? requestId;
  final Map<String, List<String>> fields;

  static Map<String, List<String>> _parseFields(Object? raw) {
    if (raw is! Map) {
      return const {};
    }
    return raw.map(
      (key, value) => MapEntry(
        key.toString(),
        value is List
            ? value.map((item) => item.toString()).toList()
            : <String>[value.toString()],
      ),
    );
  }

  @override
  String toString() =>
      'ApiException(code: $code, status: $statusCode, requestId: $requestId)';
}
