import 'dart:typed_data';

import 'package:dio/dio.dart';

import '../../core/network/api_exception.dart';

/// Avatar upload / download. The file is multipart, so this lives in the App
/// (Dio) rather than the JSON-only shared transport. The server re-encodes the
/// image and serves it to its owner only.
abstract interface class AvatarRepository {
  Future<void> upload(Uint8List bytes, String filename);

  /// Null when the member has no avatar.
  Future<Uint8List?> fetch();

  Future<void> delete();
}

class DioAvatarRepository implements AvatarRepository {
  DioAvatarRepository(this._dio);

  final Dio _dio;

  static const maxBytes = 5 * 1024 * 1024;

  @override
  Future<void> upload(Uint8List bytes, String filename) async {
    if (bytes.length > maxBytes) {
      throw const ApiException(code: 'FILE_TOO_LARGE', message: '圖片不可超過 5 MB。');
    }
    try {
      await _dio.post<Object?>(
        '/api/v1/me/avatar',
        data: FormData.fromMap({
          'avatar': MultipartFile.fromBytes(bytes, filename: filename),
        }),
      );
    } on DioException catch (error) {
      throw ApiException.fromDio(error);
    }
  }

  @override
  Future<Uint8List?> fetch() async {
    try {
      final response = await _dio.get<List<int>>(
        '/api/v1/me/avatar',
        options: Options(responseType: ResponseType.bytes),
      );
      final data = response.data;
      return data == null ? null : Uint8List.fromList(data);
    } on DioException catch (error) {
      if (error.response?.statusCode == 404) return null;
      throw ApiException.fromDio(error);
    }
  }

  @override
  Future<void> delete() async {
    try {
      await _dio.delete<Object?>('/api/v1/me/avatar');
    } on DioException catch (error) {
      throw ApiException.fromDio(error);
    }
  }
}
