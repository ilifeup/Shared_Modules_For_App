import 'member_api.dart';

/// Forgot / reset password (S3 A01). Request endpoints answer the same way
/// whether or not the account exists; a successful reset revokes every token
/// of the account on the server, so the App must sign in again.
class PasswordResetApi {
  const PasswordResetApi(this.transport);

  final ApiTransport transport;

  /// Sends a reset link to the email when it belongs to an account.
  Future<void> requestEmailLink(String email) async {
    if (email.trim().isEmpty) {
      throw ArgumentError.value(email, 'email', 'Email is required');
    }
    await transport.request(
      'POST',
      '/api/v1/auth/password/forgot',
      body: {'email': email.trim()},
    );
  }

  /// Completes an email reset with the token from the link.
  Future<void> resetWithEmailToken({
    required String email,
    required String token,
    required String password,
    required String passwordConfirmation,
  }) async {
    await transport.request(
      'POST',
      '/api/v1/auth/password/reset',
      body: {
        'email': email.trim(),
        'token': token.trim(),
        'password': password,
        'password_confirmation': passwordConfirmation,
      },
    );
  }

  /// Sends a password-reset code (a registration code is never accepted).
  Future<void> requestPhoneCode(String phone) async {
    if (phone.trim().isEmpty) {
      throw ArgumentError.value(phone, 'phone', 'Phone is required');
    }
    await transport.request(
      'POST',
      '/api/v1/auth/password/phone/send-otp',
      body: {'phone': phone.trim()},
    );
  }

  Future<void> resetWithPhoneCode({
    required String phone,
    required String code,
    required String password,
    required String passwordConfirmation,
  }) async {
    final normalized = code.trim();
    if (!RegExp(r'^\d{6}$').hasMatch(normalized)) {
      throw ArgumentError.value(code, 'code', 'Code must be 6 digits');
    }
    await transport.request(
      'POST',
      '/api/v1/auth/password/phone/reset',
      body: {
        'phone': phone.trim(),
        'code': normalized,
        'password': password,
        'password_confirmation': passwordConfirmation,
      },
    );
  }
}
