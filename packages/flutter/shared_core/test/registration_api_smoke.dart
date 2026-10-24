import 'package:eachcore_shared_core/eachcore_shared_core.dart';

class FakeRegistrationTransport implements ApiTransport {
  final List<String> paths = [];
  Map<String, dynamic>? lastBody;

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    paths.add('$method $path');
    lastBody = body;
    if (path == '/api/v1/auth/register') {
      return {
        'data': {
          'user': {'id': '1', 'display_name': 'Test'},
          'verification': {'email': 'sent', 'phone': null},
        },
      };
    }
    if (path == '/api/v1/auth/policy') {
      return {
        'data': {
          'verification_policy': 'phone_required',
          'required_fields': ['name', 'email', 'phone', 'password'],
        },
      };
    }
    if (path == '/api/v1/notices/7') {
      return {
        'data': {
          'id': '7',
          'title': 'T',
          'body': 'B',
          'status': 'published',
          'published_at': '2026-10-24T00:00:00Z',
        },
      };
    }
    return {'data': null};
  }
}

void check(bool condition) {
  if (!condition) throw StateError('Registration contract failed');
}

Future<void> main() async {
  final fake = FakeRegistrationTransport();
  final api = RegistrationApi(fake);

  final result = await api.register(
    name: ' Test ',
    email: ' member@example.com ',
    phone: ' 0912345678 ',
    password: 'synthetic-pass',
    passwordConfirmation: 'synthetic-pass',
  );
  check(result.needsEmailVerification);
  check(!result.needsPhoneVerification);
  check(fake.lastBody?['email'] == 'member@example.com');
  check(fake.lastBody?['phone'] == '0912345678');

  var missing = false;
  try {
    await api.register(
      name: 'Test',
      email: 'member@example.com',
      phone: ' ',
      password: 'synthetic-pass',
      passwordConfirmation: 'synthetic-pass',
    );
  } on ArgumentError {
    missing = true;
  }
  check(missing);

  final policy = await api.policy();
  check(policy.requiresPhone);
  check(policy.requiredFields?.length == 4);

  await api.sendPhoneOtp(' 0912345678 ');
  check(fake.paths.last == 'POST /api/v1/auth/phone/send-otp');

  var rejected = false;
  try {
    await api.verifyPhoneOtp('0912345678', '12ab');
  } on ArgumentError {
    rejected = true;
  }
  check(rejected);

  await api.verifyPhoneOtp('0912345678', '123456');
  check(fake.lastBody?['code'] == '123456');

  final notice = await MemberApi(fake).notice('7');
  check(notice.title == 'T');

  final profile = MemberProfile.fromJson({
    'id': '1',
    'display_name': 'Test',
    'email': 'member@example.com',
    'phone': null,
    'email_verified': true,
  });
  check(profile.emailVerified && !profile.phoneVerified);
  check(profile.phone == null);
  // Older servers without the verification block are treated as satisfied.
  check(profile.verificationSatisfied && profile.verificationMissing == null);

  final gated = MemberProfile.fromJson({
    'id': '2',
    'display_name': 'New',
    'verification': {
      'policy': 'phone_required',
      'satisfied': false,
      'missing': 'phone',
    },
  });
  check(!gated.verificationSatisfied);
  check(gated.verificationMissing == 'phone');
  check(gated.verificationPolicy == AuthPolicy.phoneRequired);

  print('S3 Dart registration API smoke: PASS');
}
