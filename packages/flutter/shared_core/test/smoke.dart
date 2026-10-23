import 'package:eachcore_shared_core/eachcore_shared_core.dart';

void main() {
  assert(DisplayName.normalize(' Test ') == 'Test');
  assert(DisplayName.isValid('Test'));
  assert(!DisplayName.isValid('   '));
  assert(!DisplayName.isValid(List.filled(81, 'x').join()));

  final now = DateTime.utc(2026, 10, 23, 9);
  final notice = Notice(
    id: 'test',
    title: 'Notice',
    body: 'Body',
    status: 'published',
    publishedAt: DateTime.utc(2026, 10, 23, 8),
  );
  assert(notice.isVisibleAt(now));
  print('S1 Dart smoke tests: PASS');
}
