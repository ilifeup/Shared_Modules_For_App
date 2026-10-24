import 'package:dio/dio.dart';
import 'package:eachcore_shared_core/eachcore_shared_core.dart';

import 'api_exception.dart';

/// Implements the shared [ApiTransport] contract with Dio.
///
/// Every failure is converted to [ApiException] so screens can show the
/// server error code and request id.
class DioTransport implements ApiTransport {
  DioTransport(this._dio);

  final Dio _dio;

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    try {
      final response = await _dio.request<Object?>(
        path,
        data: body,
        options: Options(method: method),
      );
      final data = response.data;
      if (data is Map<String, dynamic>) {
        return data;
      }
      throw const ApiException(
        code: 'INVALID_RESPONSE',
        message: '伺服器回應格式錯誤。',
      );
    } on DioException catch (error) {
      throw ApiException.fromDio(error);
    }
  }
}
