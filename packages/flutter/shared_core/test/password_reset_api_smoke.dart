import 'package:eachcore_shared_core/eachcore_shared_core.dart';

class RecordingTransport implements ApiTransport {
  final List<String> calls = [];
  Map<String, dynamic>? lastBody;

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    calls.add('$method $path');
    lastBody = body;
    return {'data': null};
  }
}

void check(bool condition) {
  if (!condition) throw StateError('Password reset contract failed');
}

Future<void> main() async {
  final fake = RecordingTransport();
  final api = PasswordResetApi(fake);

  await api.requestEmailLink(' member@example.com ');
  check(fake.calls.last == 'POST /api/v1/auth/password/forgot');
  check(fake.lastBody?['email'] == 'member@example.com');

  await api.resetWithEmailToken(
    email: 'member@example.com',
    token: ' tok ',
    password: 'synthetic-pass-2',
    passwordConfirmation: 'synthetic-pass-2',
  );
  check(fake.calls.last == 'POST /api/v1/auth/password/reset');
  check(fake.lastBody?['token'] == 'tok');

  await api.requestPhoneCode(' 0912345678 ');
  check(fake.calls.last == 'POST /api/v1/auth/password/phone/send-otp');

  var rejected = false;
  try {
    await api.resetWithPhoneCode(
      phone: '0912345678',
      code: '12a',
      password: 'synthetic-pass-2',
      passwordConfirmation: 'synthetic-pass-2',
    );
  } on ArgumentError {
    rejected = true;
  }
  check(rejected);
  check(fake.calls.length == 3);

  await api.resetWithPhoneCode(
    phone: '0912345678',
    code: ' 123456 ',
    password: 'synthetic-pass-2',
    passwordConfirmation: 'synthetic-pass-2',
  );
  check(fake.calls.last == 'POST /api/v1/auth/password/phone/reset');
  check(fake.lastBody?['code'] == '123456');

  print('S3 Dart password reset API smoke: PASS');
}
