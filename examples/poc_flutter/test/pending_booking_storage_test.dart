import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:s1_poc_app/core/storage/token_storage.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({
      'auth_token': 'keep-token',
      'unrelated_key': 'keep-value',
      'pending_booking_v1:member-a:event-1': 'request-a|self',
      'pending_booking_v1:member-b:event-2': 'request-b|123',
    });
  });

  test('logout cleanup removes only the current member pending booking keys', () async {
    const storage = FlutterSecureStorage();
    await clearPendingBookingStorage('member-a', storage);

    expect(await storage.read(key: 'pending_booking_v1:member-a:event-1'), isNull);
    expect(await storage.read(key: 'pending_booking_v1:member-b:event-2'), 'request-b|123');
    expect(await storage.read(key: 'auth_token'), 'keep-token');
    expect(await storage.read(key: 'unrelated_key'), 'keep-value');
  });

  test('cleanup can be repeated safely', () async {
    const storage = FlutterSecureStorage();
    await clearPendingBookingStorage(storage);
    await clearPendingBookingStorage(storage);
    expect(await storage.read(key: 'auth_token'), 'keep-token');
  });
}
