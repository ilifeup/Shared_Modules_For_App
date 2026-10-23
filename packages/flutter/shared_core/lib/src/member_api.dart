import 'api_envelope.dart';
import 'member_profile.dart';
import 'notice.dart';
import 'notice_dto.dart';

/// A transport boundary implemented by each application with Dio.
abstract interface class ApiTransport {
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  });
}

/// Shared application-independent S1 endpoints.
/// Auth headers, secure token storage and retries are owned by the App.
class MemberApi {
  const MemberApi(this.transport);

  final ApiTransport transport;

  Future<MemberProfile> myProfile() async {
    final json = await transport.request('GET', '/api/v1/me/profile');
    final envelope = ApiEnvelope<MemberProfile>.fromJson(json, (value) {
      return MemberProfile.fromJson(value as Map<String, dynamic>);
    });
    return envelope.data;
  }

  Future<MemberProfile> updateDisplayName(String value) async {
    final normalized = value.trim();
    if (normalized.isEmpty || normalized.runes.length > 80) {
      throw ArgumentError.value(value, 'displayName', 'Invalid display name');
    }
    final json = await transport.request(
      'PATCH',
      '/api/v1/me/profile',
      body: {'display_name': normalized},
    );
    final envelope = ApiEnvelope<MemberProfile>.fromJson(json, (data) {
      return MemberProfile.fromJson(data as Map<String, dynamic>);
    });
    return envelope.data;
  }

  Future<List<Notice>> notices() async {
    final json = await transport.request('GET', '/api/v1/notices');
    return ApiEnvelope<List<Notice>>.fromJson(json, (value) {
      if (value is! List) {
        throw const FormatException('Expected notice list');
      }
      return value.map((item) {
        if (item is! Map<String, dynamic>) {
          throw const FormatException('Invalid notice list item');
        }
        return NoticeDto.fromJson(item);
      }).toList(growable: false);
    }).data;
  }
}
