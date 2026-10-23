import 'package:eachcore_shared_core/eachcore_shared_core.dart';

void verify(bool ok) {
  if (!ok) throw StateError('Contract smoke test failed');
}

void main() {
  final response = ApiEnvelope<MemberProfile>.fromJson({
    'data': {'id': 'test-1', 'display_name': 'Demo'},
  }, (raw) => MemberProfile.fromJson(raw as Map<String, dynamic>));
  verify(response.data.displayName == 'Demo');
  verify(response.data.toDisplayNamePatch().length == 1);
  verify(response.data.toDisplayNamePatch()['display_name'] == 'Demo');

  final failure = ApiFailure.fromJson({
    'error': {
      'code': 'UNAUTHORIZED',
      'message': 'Denied',
      'request_id': 'req-test',
    },
  });
  verify(failure.code == 'UNAUTHORIZED');
  verify(failure.requestId == 'req-test');

  final notice = NoticeDto.fromJson({
    'id': 'n1',
    'title': 'Demo notice',
    'body': 'Content',
    'status': 'published',
    'published_at': '2026-10-23T08:00:00Z',
  });
  verify(notice.isVisibleAt(DateTime.utc(2026, 10, 23, 9)));
  verify(
    !NoticeDto.fromJson({
      'id': 'n2',
      'title': 'Draft',
      'body': '',
      'status': 'draft',
      'published_at': null,
    }).isVisibleAt(DateTime.utc(2026, 10, 23, 9)),
  );

  var invalidRejected = false;
  try {
    MemberProfile.fromJson({'id': 'test-1'});
  } on FormatException {
    invalidRejected = true;
  }
  verify(invalidRejected);
  print('S1 Dart API contract smoke: PASS');
}
