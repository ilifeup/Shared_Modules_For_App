import 'api_envelope.dart';
import 'member_api.dart';

/// Result of a self-registration: which verifications were started.
///
/// Values are `sent`, `send_failed:<CODE>` or `null` when nothing was sent
/// (the phone code is sent only under the `phone_required` policy).
class RegistrationResult {
  const RegistrationResult({this.email, this.phone});

  final String? email;
  final String? phone;

  bool get needsEmailVerification => email == 'sent';

  bool get needsPhoneVerification =>
      phone == 'sent' || (phone?.startsWith('send_failed') ?? false);
}

/// Server verification policy (`GET /api/v1/auth/policy`).
class AuthPolicy {
  const AuthPolicy({required this.verificationPolicy, this.requiredFields});

  static const emailRequired = 'email_required';
  static const phoneRequired = 'phone_required';

  final String verificationPolicy;
  final List<String>? requiredFields;

  bool get requiresPhone => verificationPolicy == phoneRequired;
}

/// Self-registration and email / SMS OTP verification endpoints.
/// Each App runs its own backend; nothing here is shared between Apps.
class RegistrationApi {
  const RegistrationApi(this.transport);

  final ApiTransport transport;

  Future<RegistrationResult> register({
    required String name,
    required String email,
    required String phone,
    required String password,
    required String passwordConfirmation,
  }) async {
    if (name.trim().isEmpty || email.trim().isEmpty || phone.trim().isEmpty) {
      throw ArgumentError('Name, email and phone are required');
    }
    final json = await transport.request(
      'POST',
      '/api/v1/auth/register',
      body: {
        'name': name.trim(),
        'email': email.trim(),
        'phone': phone.trim(),
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

  Future<AuthPolicy> policy() async {
    final json = await transport.request('GET', '/api/v1/auth/policy');
    return ApiEnvelope<AuthPolicy>.fromJson(json, (data) {
      if (data is! Map<String, dynamic>) {
        throw const FormatException('Invalid policy response');
      }
      final policy = data['verification_policy'];
      final fields = data['required_fields'];
      if (policy is! String) {
        throw const FormatException('Missing verification policy');
      }
      return AuthPolicy(
        verificationPolicy: policy,
        requiredFields: fields is List
            ? fields.map((field) => field.toString()).toList()
            : null,
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
