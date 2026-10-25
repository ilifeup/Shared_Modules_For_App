import 'api_envelope.dart';
import 'member_api.dart';
import 'member_profile.dart';

/// M01 profile edits (S3). Email and phone changes need the current password;
/// a new phone number is saved only after its code is verified, and a new
/// email is saved as unverified until the member opens the link.
class ProfileApi {
  const ProfileApi(this.transport);

  final ApiTransport transport;

  Future<MemberProfile> update({String? name, String? displayName}) async {
    final body = <String, dynamic>{
      if (name != null) 'name': name.trim(),
      if (displayName != null) 'display_name': displayName.trim(),
    };
    if (body.isEmpty || body.values.any((value) => (value as String).isEmpty)) {
      throw ArgumentError('Name or display name is required');
    }
    return _profile(
      await transport.request('PATCH', '/api/v1/me/profile', body: body),
    );
  }

  /// S8 public profile: nickname (2–20 characters, unique) and the like-list
  /// privacy switch. 422 when the nickname is taken or invalid.
  Future<MemberProfile> updatePublicProfile({
    String? nickname,
    bool? showInLikeList,
  }) async {
    final trimmed = nickname?.trim();
    if (trimmed != null && (trimmed.length < 2 || trimmed.length > 20)) {
      throw ArgumentError.value(nickname, 'nickname', 'Use 2–20 characters');
    }
    final body = <String, dynamic>{
      if (trimmed != null) 'nickname': trimmed,
      if (showInLikeList != null) 'show_in_like_list': showInLikeList,
    };
    if (body.isEmpty) throw ArgumentError('Nothing to update');
    return _profile(
      await transport.request('PATCH', '/api/v1/me/profile', body: body),
    );
  }

  Future<MemberProfile> changeEmail(String email, String password) async {
    if (!email.contains('@') || password.isEmpty) {
      throw ArgumentError('Email and current password are required');
    }
    return _profile(
      await transport.request(
        'POST',
        '/api/v1/me/email',
        body: {'email': email.trim(), 'password': password},
      ),
    );
  }

  Future<void> sendPhoneChangeCode(String phone, String password) async {
    if (phone.trim().isEmpty || password.isEmpty) {
      throw ArgumentError('Phone and current password are required');
    }
    await transport.request(
      'POST',
      '/api/v1/me/phone/send-otp',
      body: {'phone': phone.trim(), 'password': password},
    );
  }

  Future<MemberProfile> confirmPhoneChange(String phone, String code) async {
    final normalized = code.trim();
    if (!RegExp(r'^\d{6}$').hasMatch(normalized)) {
      throw ArgumentError.value(code, 'code', 'Code must be 6 digits');
    }
    return _profile(
      await transport.request(
        'POST',
        '/api/v1/me/phone/verify',
        body: {'phone': phone.trim(), 'code': normalized},
      ),
    );
  }

  static MemberProfile _profile(Map<String, dynamic> json) =>
      ApiEnvelope<MemberProfile>.fromJson(json, (data) {
        if (data is! Map<String, dynamic>) {
          throw const FormatException('Invalid profile response');
        }
        return MemberProfile.fromJson(data);
      }).data;
}
