import 'api_envelope.dart';
import 'member_api.dart';

/// S6 M08 booking of the current member (`confirmed` or `cancelled`).
class Booking {
  const Booking({
    required this.id,
    required this.eventId,
    required this.status,
    required this.bookedAt,
    this.cancelledAt,
    this.eventTitle,
    this.eventStartsAt,
  });

  final String id;
  final String eventId;
  final String status;
  final DateTime bookedAt;
  final DateTime? cancelledAt;

  /// Present in `GET /me/bookings`.
  final String? eventTitle;
  final DateTime? eventStartsAt;

  bool get isConfirmed => status == 'confirmed';
}

class BookingDto {
  const BookingDto._();

  static Booking fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final eventId = json['event_id'];
    final status = json['status'];
    final bookedAt = _date(json['booked_at']);
    if (id is! String ||
        eventId is! String ||
        status is! String ||
        bookedAt == null) {
      throw const FormatException('Invalid booking');
    }
    final event = json['event'];
    return Booking(
      id: id,
      eventId: eventId,
      status: status,
      bookedAt: bookedAt,
      cancelledAt: _date(json['cancelled_at']),
      eventTitle: event is Map && event['title'] is String
          ? event['title'] as String
          : null,
      eventStartsAt: event is Map ? _date(event['starts_at']) : null,
    );
  }

  static DateTime? _date(Object? value) =>
      value is String ? DateTime.tryParse(value)?.toUtc() : null;
}

/// Seats and the member's booking for one event.
class BookingInfo {
  const BookingInfo({
    required this.eventId,
    required this.enabled,
    required this.bookedCount,
    this.capacity,
    this.remaining,
    this.opensAt,
    this.closesAt,
    this.unavailableReason,
    this.myBooking,
  });

  final String eventId;
  final bool enabled;
  final int? capacity;
  final int bookedCount;
  final int? remaining;
  final DateTime? opensAt;
  final DateTime? closesAt;

  /// Null when a new booking is possible now. Otherwise one of
  /// `already_booked`, `event_not_published`, `event_cancelled`, `disabled`,
  /// `not_open`, `closed`, `full`.
  final String? unavailableReason;
  final Booking? myBooking;

  bool get canBook => unavailableReason == null;

  factory BookingInfo.fromJson(Map<String, dynamic> json) {
    final eventId = json['event_id'];
    final bookedCount = json['booked_count'];
    if (eventId is! String || bookedCount is! int) {
      throw const FormatException('Invalid booking info');
    }
    final mine = json['my_booking'];
    final reason = json['unavailable_reason'];
    final capacity = json['capacity'];
    final remaining = json['remaining'];
    return BookingInfo(
      eventId: eventId,
      enabled: json['enabled'] == true,
      bookedCount: bookedCount,
      capacity: capacity is int ? capacity : null,
      remaining: remaining is int ? remaining : null,
      opensAt: BookingDto._date(json['opens_at']),
      closesAt: BookingDto._date(json['closes_at']),
      unavailableReason: reason is String ? reason : null,
      myBooking: mine is Map<String, dynamic>
          ? BookingDto.fromJson(mine)
          : null,
    );
  }
}

class BookingApi {
  const BookingApi(this.transport);

  final ApiTransport transport;

  Future<BookingInfo> info(String eventId) async {
    final path = '/api/v1/events/${Uri.encodeComponent(eventId)}/booking';
    return _info(await transport.request('GET', path));
  }

  /// 409 errors: BOOKING_FULL, ALREADY_BOOKED, BOOKING_UNAVAILABLE.
  Future<BookingInfo> book(String eventId) async {
    final path = '/api/v1/events/${Uri.encodeComponent(eventId)}/bookings';
    return _info(await transport.request('POST', path));
  }

  /// Own bookings only. 409 BOOKING_NOT_ACTIVE when already cancelled.
  Future<BookingInfo> cancel(String bookingId) async {
    final path = '/api/v1/me/bookings/${Uri.encodeComponent(bookingId)}/cancel';
    return _info(await transport.request('POST', path));
  }

  Future<List<Booking>> mine() async {
    final json = await transport.request('GET', '/api/v1/me/bookings');
    return ApiEnvelope<List<Booking>>.fromJson(json, (data) {
      if (data is! List) throw const FormatException('Expected bookings');
      return data
          .map((item) => BookingDto.fromJson(item as Map<String, dynamic>))
          .toList(growable: false);
    }).data;
  }

  static BookingInfo _info(Map<String, dynamic> json) =>
      ApiEnvelope<BookingInfo>.fromJson(
        json,
        (data) => BookingInfo.fromJson(data as Map<String, dynamic>),
      ).data;
}
