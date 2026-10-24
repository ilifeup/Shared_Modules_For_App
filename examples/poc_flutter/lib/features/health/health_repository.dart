import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_exception.dart';
import '../../core/providers.dart';

class HealthStatus {
  const HealthStatus({required this.status, required this.database});

  factory HealthStatus.fromJson(Map<String, dynamic> json) {
    return HealthStatus(
      status: json['status'] as String? ?? 'unknown',
      database: json['database'] as String? ?? 'unknown',
    );
  }

  final String status;
  final String database;

  bool get isOk => status == 'ok' && database == 'ok';
}

class HealthRepository {
  HealthRepository(this._dio);

  final Dio _dio;

  Future<HealthStatus> fetch() async {
    try {
      final response = await _dio.get<Map<String, dynamic>>('/api/v1/health');
      final data = response.data?['data'];
      if (data is! Map) {
        throw const ApiException(
          code: 'INVALID_RESPONSE',
          message: '伺服器回應格式錯誤。',
        );
      }
      return HealthStatus.fromJson(Map<String, dynamic>.from(data));
    } on DioException catch (error) {
      throw ApiException.fromDio(error);
    }
  }
}

final healthRepositoryProvider = Provider<HealthRepository>(
  (ref) => HealthRepository(ref.watch(dioProvider)),
);

final healthStatusProvider = FutureProvider.autoDispose<HealthStatus>(
  (ref) => ref.watch(healthRepositoryProvider).fetch(),
);
