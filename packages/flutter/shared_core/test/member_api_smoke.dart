import 'package:eachcore_shared_core/eachcore_shared_core.dart';

class FakeTransport implements ApiTransport {
  String? lastMethod;
  String? lastPath;
  Map<String, dynamic>? lastBody;

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    lastMethod = method;
    lastPath = path;
    lastBody = body;
    if (path == '/api/v1/notices') {
      return {'data': <Map<String, dynamic>>[]};
    }
    return {
      'data': {'id': 'synthetic-1', 'display_name': body?['display_name'] ?? 'Test'},
    };
  }
}

void check(bool value) {
  if (!value) throw StateError('Member API smoke check failed');
}

Future<void> main() async {
  final fake = FakeTransport();
  final api = MemberApi(fake);
  final profile = await api.myProfile();
  check(profile.displayName == 'Test');
  check(fake.lastMethod == 'GET');
  check(fake.lastPath == '/api/v1/me/profile');

  final edited = await api.updateDisplayName('  New Name  ');
  check(edited.displayName == 'New Name');
  check(fake.lastMethod == 'PATCH');
  check(fake.lastBody?.length == 1);
  check(fake.lastBody?['display_name'] == 'New Name');

  var rejected = false;
  try {
    await api.updateDisplayName('  ');
  } on ArgumentError {
    rejected = true;
  }
  check(rejected);
  check((await api.notices()).isEmpty);
  check(fake.lastPath == '/api/v1/notices');
  print('S1 Dart member API smoke: PASS');
}
