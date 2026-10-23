import 'package:eachcore_shared_core/eachcore_shared_core.dart';

void check(bool value) {
  if (!value) throw StateError('Smoke check failed');
}

void main() {
  check(DisplayName.normalize(' Test ') == 'Test');
  check(DisplayName.isValid('Test'));
  check(!DisplayName.isValid('   '));
  check(!DisplayName.isValid(List.filled(81, 'x').join()));

  final now = DateTime.utc(2026, 10, 23, 9);
  final notice = Notice(
    id: 'test',
    title: 'Notice',
    body: 'Body',
    status: 'published',
    publishedAt: DateTime.utc(2026, 10, 23, 8),
  );
  check(notice.isVisibleAt(now));
  print('S1 Dart smoke tests: PASS');
}
