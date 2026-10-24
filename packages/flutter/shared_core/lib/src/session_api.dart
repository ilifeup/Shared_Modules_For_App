import 'api_envelope.dart';
import 'member_api.dart';

/// Login credentials are transmitted only through the application's
/// authenticated HTTPS transport; this package never stores secrets.
class SessionApi {
  const SessionApi(this.transport);

  final ApiTransport transport;

  Future<String> login(String identifier, String password) async {
    if (identifier.trim().isEmpty || password.isEmpty) {
      throw ArgumentError('Identifier and password are required');
    }
    final json = await transport.request(
      'POST',
      '/api/v1/auth/login',
      body: {'identifier': identifier.trim(), 'password': password},
    );
    return ApiEnvelope<String>.fromJson(json, (data) {
      if (data is! Map<String, dynamic>) {
        throw const FormatException('Invalid login response');
      }
      final token = data['token'];
      if (token is! String || token.isEmpty) {
        throw const FormatException('Missing access token');
      }
      return token;
    }).data;
  }

  /// Server must revoke the current bearer token. The caller clears secure
  /// storage only after a successful response or explicit re-auth handling.
  Future<void> logout() async {
    await transport.request('POST', '/api/v1/auth/logout');
  }
}
