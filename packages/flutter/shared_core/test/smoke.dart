import 'package:eachcore_shared_core/eachcore_shared_core.dart';

void check(bool condition, String message) {
  if (!condition) {
    throw StateError('FAIL: $message');
  }
}

void main() {
  check(DisplayName.normalize(' Test ') == 'Test', 'trim display name');
  check(DisplayName.isValid('Test'), 'valid display name');
  check(!DisplayName.isValid('   '), 'reject empty display name');
  check(
    !DisplayName.isValid(List.filled(81, 'x').join()),
    'reject long display name',
  );

  final now = DateTime.utc(2026, 10, 23, 9);
  final published = Notice(
    id: 'test',
    title: 'Notice',
    body: 'Body',
    status: 'published',
    publishedAt: DateTime.utc(2026, 10, 23, 8),
  );
  check(published.isVisibleAt(now), 'published notice visible');
  check(
    Notice(
      id: 'draft',
      title: 'Draft',
      body: '',
      status: 'draft',
      publishedAt: now,
    ).isVisibleAt(now) == false,
    'draft hidden',
  );
  print('S1 shared Dart domain smoke tests: PASS');
}
