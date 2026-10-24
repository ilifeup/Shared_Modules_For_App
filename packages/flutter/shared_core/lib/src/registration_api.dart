import 'api_envelope.dart';
import 'member_api.dart';

/// Result of a self-registration: which verifications were started.
///
/// Values are `sent`, `verified`, `send_failed:<CODE>` or `null` when the
/// identifier was not provided.
class RegistrationResult {
  const RegistrationResult({this.email, this.phone});

  final String? email;
  final String? phone;

  bool get needsEmailVerification => email == 'sent';

  bool get needsPhoneVerification =>
      phone == 'sent' || (phone?.startsWith('send_failed') ?? false);
}

/// Self-registration and email / SMS OTP verification endpoints.
/// Each App runs its own backend; nothing here is shared between Apps.
class RegistrationApi {
  const RegistrationApi(this.transport);

  final ApiTransport transport;

  Future<RegistrationResult> register({
    required String name,
    String? email,
    String? phone,
    required String password,
    required String passwordConfirmation,
  }) async {
    final normalizedEmail = email?.trim();
    final normalizedPhone = phone?.trim();
    final json = await transport.request(
      'POST',
      '/api/v1/auth/register',
      body: {
        'name': name.trim(),
        if (normalizedEmail != null && normalizedEmail.isNotEmpty)
          'email': normalizedEmail,
        if (normalizedPhone != null && normalizedPhone.isNotEmpty)
          'phone': normalizedPhone,
        'password': password,
        'password_confirmation': passwordConfirmation,
      },
    );
    return ApiEnvelope<RegistrationResult>.fromJson(json, (data) {
      if (data is! Map<String, dynamic>) {
        throw const FormatException('Invalid registration response');
      }
      final verification = data['verification'];
      if (verification is! Map<String, dynamic>) {
        return const RegistrationResult();
      }
      final emailState = verification['email'];
      final phoneState = verification['phone'];
      return RegistrationResult(
        email: emailState is String ? emailState : null,
        phone: phoneState is String ? phoneState : null,
      );
    }).data;
  }

  Future<void> resendEmailVerification(String email) async {
    await transport.request(
      'POST',
      '/api/v1/auth/email/resend',
      body: {'email': email.trim()},
    );
  }

  Future<void> sendPhoneOtp(String phone) async {
    await transport.request(
      'POST',
      '/api/v1/auth/phone/send-otp',
      body: {'phone': phone.trim()},
    );
  }

  Future<void> verifyPhoneOtp(String phone, String code) async {
    final normalized = code.trim();
    if (!RegExp(r'^\d{6}$').hasMatch(normalized)) {
      throw ArgumentError.value(code, 'code', 'OTP must be 6 digits');
    }
    await transport.request(
      'POST',
      '/api/v1/auth/phone/verify',
      body: {'phone': phone.trim(), 'code': normalized},
    );
  }
}
