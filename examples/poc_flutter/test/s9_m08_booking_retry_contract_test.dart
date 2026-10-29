import 'package:flutter_test/flutter_test.dart';
import 'package:s1_poc_app/features/s9/group_booking_screen.dart';

void main() {
  group('M08 booking retry identity contract', () {
    test('new request IDs are UUID v4 with RFC 4122 variant', () {
      final ids = List.generate(100, (_) => newRequestId());
      final uuidV4 = RegExp(
        r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
      );
      expect(ids.every((id) => uuidV4.hasMatch(id)), isTrue);
      expect(ids.toSet().length, ids.length);
    });

    test('retry preserves self booking even after delegate selection changes', () {
      const saved = PendingBookingRequest(
        '123e4567-e89b-42d3-a456-426614174000',
        null,
      );
      expect(bookingMemberForRetry(saved, 42), isNull);
      expect(saved.requestId, '123e4567-e89b-42d3-a456-426614174000');
    });

    test('retry preserves the original delegated family member', () {
      const saved = PendingBookingRequest(
        '123e4567-e89b-42d3-a456-426614174000',
        12,
      );
      expect(bookingMemberForRetry(saved, 42), 12);
      expect(bookingMemberForRetry(null, 42), 42);
      expect(bookingMemberForRetry(null, null), isNull);
    });

    test('member and event identifiers are encoded into distinct scopes', () {
      expect(bookingRequestScope('a:b', 'c'), isNot(bookingRequestScope('a', 'b:c')));
      expect(bookingRequestScope('member 1', 'event/2'),
          'member%201:event%2F2');
    });
  });
}
