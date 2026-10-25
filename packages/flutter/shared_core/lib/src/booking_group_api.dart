import 'member_api.dart';
import 's9_json.dart';

/// One attendee (seat) of an S9 M08 booking group.
class BookingAttendee {
  const BookingAttendee({
    required this.id,
    required this.name,
    required this.status,
    required this.isMe,
    this.checkinStatus,
  });

  final String id;
  final String name;

  /// `confirmed`, `waitlisted` or `cancelled`.
  final String status;
  final bool isMe;

  /// `checked_in`, `checked_out`, `revoked` or null.
  final String? checkinStatus;

  factory BookingAttendee.fromJson(Map<String, dynamic> json) =>
      BookingAttendee(
        id: s9Str(json, 'id'),
        name: s9Str(json, 'name'),
        status: s9Str(json, 'status'),
        isMe: s9Bool(json['is_me']),
        checkinStatus: s9OptStr(json['checkin_status']),
      );
}

/// S9 M08 booking group: one booker (or guest contact) with 1..n attendees;
/// confirmed or waitlisted as a whole (strict FIFO).
class BookingGroup {
  const BookingGroup({
    required this.id,
    required this.eventId,
    required this.status,
    required this.seatCount,
    required this.isGuest,
    required this.bookedAt,
    required this.cancelDeadline,
    required this.canCancel,
    required this.attendees,
    required this.eventTitle,
    required this.eventStartsAt,
    required this.eventEndsAt,
    this.contactName,
    this.confirmedAt,
    this.cancelledAt,
    this.waitlistPosition,
    this.location,
    this.floor,
    this.studio,
  });

  final String id;
  final String eventId;

  /// `confirmed`, `waitlisted`, `cancelled` or `event_cancelled`.
  final String status;
  final int seatCount;
  final bool isGuest;
  final String? contactName;
  final DateTime bookedAt;
  final DateTime? confirmedAt;
  final DateTime? cancelledAt;
  final int? waitlistPosition;
  final DateTime cancelDeadline;
  final bool canCancel;
  final List<BookingAttendee> attendees;
  final String eventTitle;
  final DateTime eventStartsAt;
  final DateTime eventEndsAt;
  final String? location;
  final String? floor;
  final String? studio;

  bool get isConfirmed => status == 'confirmed';
  bool get isWaitlisted => status == 'waitlisted';
  bool get isActive => isConfirmed || isWaitlisted;

  /// Venue text for display: location, floor and studio.
  String get venue => [
    location,
    floor,
    studio,
  ].whereType<String>().where((s) => s.isNotEmpty).join(' ');

  factory BookingGroup.fromJson(Map<String, dynamic> json) {
    final event = s9Map(json['event']);
    final bookedAt = s9Date(json['booked_at']);
    final deadline = s9Date(json['cancel_deadline']);
    final starts = s9Date(event['starts_at']);
    final ends = s9Date(event['ends_at']);
    if (bookedAt == null ||
        deadline == null ||
        starts == null ||
        ends == null) {
      throw const FormatException('Invalid booking group');
    }
    final position = json['waitlist_position'];
    return BookingGroup(
      id: s9Str(json, 'id'),
      eventId: s9Str(json, 'event_id'),
      status: s9Str(json, 'status'),
      seatCount: s9Int(json['seat_count'], 1),
      isGuest: s9Bool(json['is_guest']),
      contactName: s9OptStr(json['contact_name']),
      bookedAt: bookedAt,
      confirmedAt: s9Date(json['confirmed_at']),
      cancelledAt: s9Date(json['cancelled_at']),
      waitlistPosition: position is int ? position : null,
      cancelDeadline: deadline,
      canCancel: s9Bool(json['can_cancel']),
      attendees: s9List(json['attendees'], BookingAttendee.fromJson),
      eventTitle: s9Str(event, 'title'),
      eventStartsAt: starts,
      eventEndsAt: ends,
      location: s9OptStr(event['location']),
      floor: s9OptStr(event['floor']),
      studio: s9OptStr(event['studio']),
    );
  }
}

class BookingGroupPage {
  const BookingGroupPage({
    required this.items,
    required this.currentPage,
    required this.lastPage,
  });

  final List<BookingGroup> items;
  final int currentPage;
  final int lastPage;
}

/// S9 M08 group booking, waitlist and guest-booking claim. Retries must reuse
/// the same [clientRequestId] (UUID) so a booking is never created twice.
class BookingGroupApi {
  const BookingGroupApi(this.transport);

  final ApiTransport transport;

  /// 409: BOOKING_FULL (no waitlist), ALREADY_BOOKED, BOOKING_UNAVAILABLE,
  /// IDEMPOTENCY_CONFLICT; 403 ELIGIBILITY_FAILED; 422 VALIDATION_FAILED.
  Future<BookingGroup> book(
    String eventId, {
    required String clientRequestId,
    bool includeSelf = true,
    List<String> attendeeNames = const [],
  }) async {
    final json = await transport.request(
      'POST',
      '/api/v1/events/${s9Seg(eventId)}/booking-groups',
      body: {
        'include_self': includeSelf,
        'attendees': [
          for (final name in attendeeNames) {'name': name},
        ],
        'client_request_id': clientRequestId,
      },
    );
    return BookingGroup.fromJson(s9Data(json));
  }

  /// [status]: `active`, `confirmed`, `waitlisted` or `cancelled`.
  Future<BookingGroupPage> mine({String? status, int page = 1}) async {
    final json = await transport.request(
      'GET',
      s9Path('/api/v1/me/booking-groups', {
        if (status != null) 'status': status,
        if (page > 1) 'page': '$page',
      }),
    );
    final meta = s9Map(json['meta']);
    return BookingGroupPage(
      items: s9List(json['data'], BookingGroup.fromJson),
      currentPage: s9Int(meta['current_page'], 1),
      lastPage: s9Int(meta['last_page'], 1),
    );
  }

  /// 409 CANCEL_DEADLINE_PASSED / BOOKING_NOT_ACTIVE.
  Future<BookingGroup> cancel(String groupId) async {
    final json = await transport.request(
      'POST',
      '/api/v1/me/booking-groups/${s9Seg(groupId)}/cancel',
    );
    return BookingGroup.fromJson(s9Data(json));
  }

  /// Guest bookings made with this member's verified email or phone.
  Future<List<BookingGroup>> claimable() async {
    final json = await transport.request('GET', '/api/v1/me/guest-bookings');
    return s9List(json['data'], BookingGroup.fromJson);
  }

  /// 404 when not claimable; 409 CLAIM_CONFLICT (already booked this occurrence).
  Future<BookingGroup> claim(String groupId) async {
    final json = await transport.request(
      'POST',
      '/api/v1/me/guest-bookings/${s9Seg(groupId)}/claim',
    );
    return BookingGroup.fromJson(s9Data(json));
  }
}
