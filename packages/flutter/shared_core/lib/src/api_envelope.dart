/// Parsed API response contract. Transport and auth remain App-owned.
class ApiEnvelope<T> {
  const ApiEnvelope({required this.data, this.meta});

  final T data;
  final Map<String, dynamic>? meta;

  factory ApiEnvelope.fromJson(
    Map<String, dynamic> json,
    T Function(Object?) decode,
  ) {
    return ApiEnvelope<T>(
      data: decode(json['data']),
      meta: json['meta'] is Map<String, dynamic>
          ? json['meta'] as Map<String, dynamic>
          : null,
    );
  }
}

/// Error contract. Never include secrets in messages or logs.
class ApiFailure {
  const ApiFailure({
    required this.code,
    required this.message,
    required this.requestId,
  });

  final String code;
  final String message;
  final String requestId;

  factory ApiFailure.fromJson(Map<String, dynamic> json) {
    final error = json['error'];
    if (error is! Map<String, dynamic>) {
      throw const FormatException('Invalid API error envelope');
    }
    return ApiFailure(
      code: error['code'] as String,
      message: error['message'] as String,
      requestId: error['request_id'] as String,
    );
  }
}
