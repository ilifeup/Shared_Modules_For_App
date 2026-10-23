import 'package:eachcore_shared_core/eachcore_shared_core.dart';

class FakeSessionTransport implements ApiTransport {
  String? method;
  String? path;
  Map<String, dynamic>? body;

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    this.method = method;
    this.path = path;
    this.body = body;
    if (path.endsWith('/login')) {
      return {
        'data': {'token': 'synthetic-token'},
      };
    }
    return {'data': null};
  }
}

void check(bool condition) {
  if (!condition) throw StateError('Session contract failed');
}

Future<void> main() async {
  final fake = FakeSessionTransport();
  final api = SessionApi(fake);
  check(await api.login(' test@example.invalid ', 'fake-password') ==
      'synthetic-token');
  check(fake.method == 'POST');
  check(fake.path == '/api/v1/auth/login');
  check(fake.body?['email'] == 'test@example.invalid');
  check(fake.body?['password'] == 'fake-password');
  await api.logout();
  check(fake.method == 'POST');
  check(fake.path == '/api/v1/auth/logout');
  check(fake.body == null);
  print('S1 session API contract: PASS');
}
