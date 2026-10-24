import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';

/// Returns a fixed JSON response and records the last request.
class FakeAdapter implements HttpClientAdapter {
  FakeAdapter({required this.statusCode, required this.body});

  final int statusCode;
  final Map<String, dynamic> body;
  RequestOptions? lastRequest;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    lastRequest = options;
    return ResponseBody.fromString(
      jsonEncode(body),
      statusCode,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
        'x-request-id': ['srv-req-1'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
