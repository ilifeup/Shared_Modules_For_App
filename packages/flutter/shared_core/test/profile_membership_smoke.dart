import 'package:eachcore_shared_core/eachcore_shared_core.dart';

class FakeTransport implements ApiTransport {
  final List<String> calls = [];
  Map<String, dynamic>? lastBody;

  Map<String, dynamic> _profile() => {
    'id': '1',
    'name': '王小明',
    'display_name': '小明',
    'email': 'me@example.com',
    'phone': '+886912345678',
    'has_avatar': true,
    'profile': {
      'complete': false,
      'missing': ['phone'],
    },
  };

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    calls.add('$method $path');
    lastBody = body;
    switch (path) {
      case '/api/v1/me/membership':
        return {
          'data': {
            'level': {
              'code': 'vip',
              'name': 'VIP 會員',
              'benefits': '優先報名',
              'is_default': false,
            },
            'starts_at': '2026-10-01T00:00:00Z',
            'ends_at': '2026-12-31T00:00:00Z',
          },
        };
      case '/api/v1/membership-levels':
        return {
          'data': [
            {'code': 'general', 'name': '一般會員', 'is_default': true},
            {'code': 'vip', 'name': 'VIP 會員', 'benefits': null},
          ],
        };
      case '/api/v1/me/phone/send-otp':
        return {'data': null};
    }
    return {'data': _profile()};
  }
}

void check(bool condition) {
  if (!condition) throw StateError('Profile / membership contract failed');
}

Future<void> main() async {
  final fake = FakeTransport();

  final membership = await MembershipApi(fake).mine();
  check(membership.level.isVip);
  check(membership.endsAt == DateTime.utc(2026, 12, 31));
  final levels = await MembershipApi(fake).levels();
  check(levels.length == 2 && levels.first.isDefault);

  final profiles = ProfileApi(fake);
  final updated = await profiles.update(name: ' 王小明 ');
  check(fake.lastBody?['name'] == '王小明');
  check(!(fake.lastBody?.containsKey('display_name') ?? true));
  check(updated.name == '王小明' && updated.hasAvatar);
  check(!updated.profileComplete && updated.missingFields.single == 'phone');

  var rejected = false;
  try {
    await profiles.update();
  } on ArgumentError {
    rejected = true;
  }
  check(rejected);

  await profiles.changeEmail(' new@example.com ', 'pw');
  check(fake.calls.last == 'POST /api/v1/me/email');
  check(fake.lastBody?['email'] == 'new@example.com');

  await profiles.sendPhoneChangeCode('0912345678', 'pw');
  await profiles.confirmPhoneChange('0912345678', ' 123456 ');
  check(fake.calls.last == 'POST /api/v1/me/phone/verify');
  check(fake.lastBody?['code'] == '123456');

  print('S3 Dart profile / membership smoke: PASS');
}
