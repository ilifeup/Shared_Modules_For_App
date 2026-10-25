import 'event_api.dart';
import 'member_api.dart';
import 's9_json.dart';

/// S9 M09 event category with its colour (`#RRGGBB` or null for the App default).
class EventCategory {
  const EventCategory({required this.id, required this.name, this.color});

  final String id;
  final String name;
  final String? color;

  factory EventCategory.fromJson(Map<String, dynamic> json) => EventCategory(
    id: s9Str(json, 'id'),
    name: s9Str(json, 'name'),
    color: s9OptStr(json['color']),
  );
}

/// An occurrence in "my calendar": the S5 event plus the S9 additive fields.
class MyCalendarEvent {
  const MyCalendarEvent({
    required this.event,
    required this.timezone,
    required this.checkinEnabled,
    this.floor,
    this.studio,
    this.category,
    this.seriesId,
    this.myBookingStatus,
    this.shareUrl,
  });

  final CalendarEvent event;
  final String timezone;
  final String? floor;
  final String? studio;
  final EventCategory? category;
  final String? seriesId;
  final bool checkinEnabled;

  /// `confirmed`, `waitlisted` or null when not booked.
  final String? myBookingStatus;
  final String? shareUrl;

  factory MyCalendarEvent.fromJson(Map<String, dynamic> json) {
    final category = json['category'];
    return MyCalendarEvent(
      event: CalendarEventDto.fromJson(json),
      timezone: s9OptStr(json['timezone']) ?? 'Asia/Taipei',
      floor: s9OptStr(json['floor']),
      studio: s9OptStr(json['studio']),
      category: category is Map<String, dynamic>
          ? EventCategory.fromJson(category)
          : null,
      seriesId: s9OptStr(json['series_id']),
      checkinEnabled: s9Bool(json['checkin_enabled']),
      myBookingStatus: s9OptStr(json['my_booking_status']),
      shareUrl: s9OptStr(json['share_url']),
    );
  }
}

/// S9 M09 VIP private entry (only the owner sees it). [recurrence] is
/// `weekly`, `monthly` or null.
class PrivateEvent {
  const PrivateEvent({
    required this.id,
    required this.title,
    required this.startsAt,
    required this.endsAt,
    required this.timezone,
    required this.editable,
    this.notes,
    this.recurrence,
    this.recurrenceUntil,
  });

  final String id;
  final String title;
  final String? notes;
  final DateTime startsAt;
  final DateTime endsAt;
  final String timezone;
  final String? recurrence;
  final String? recurrenceUntil;
  final bool editable;

  factory PrivateEvent.fromJson(Map<String, dynamic> json) {
    final starts = s9Date(json['starts_at']);
    final ends = s9Date(json['ends_at']);
    if (starts == null || ends == null) {
      throw const FormatException('Invalid private event');
    }
    return PrivateEvent(
      id: s9Str(json, 'id'),
      title: s9Str(json, 'title'),
      notes: s9OptStr(json['notes']),
      startsAt: starts,
      endsAt: ends,
      timezone: s9OptStr(json['timezone']) ?? 'Asia/Taipei',
      recurrence: s9OptStr(json['recurrence']),
      recurrenceUntil: s9OptStr(json['recurrence_until']),
      editable: s9Bool(json['editable']),
    );
  }
}

class MyCalendar {
  const MyCalendar({
    required this.events,
    required this.privateEvents,
    required this.vip,
    required this.truncated,
  });

  final List<MyCalendarEvent> events;
  final List<PrivateEvent> privateEvents;
  final bool vip;
  final bool truncated;
}

/// Google one-way sync status (no tokens ever reach the App).
class GoogleCalendarStatus {
  const GoogleCalendarStatus({
    required this.configured,
    required this.connected,
    required this.status,
    required this.failedItems,
    this.lastSyncedAt,
    this.lastError,
  });

  final bool configured;
  final bool connected;

  /// `none`, `active`, `error` or `disconnected`.
  final String status;
  final int failedItems;
  final DateTime? lastSyncedAt;
  final String? lastError;

  factory GoogleCalendarStatus.fromJson(Map<String, dynamic> json) =>
      GoogleCalendarStatus(
        configured: s9Bool(json['configured']),
        connected: s9Bool(json['connected']),
        status: s9OptStr(json['status']) ?? 'none',
        failedItems: s9Int(json['failed_items']),
        lastSyncedAt: s9Date(json['last_synced_at']),
        lastError: s9OptStr(json['last_error']),
      );
}

/// S9 M09 my calendar, VIP private entries and Google Calendar connection.
class CalendarApi {
  const CalendarApi(this.transport);

  final ApiTransport transport;

  Future<List<EventCategory>> categories() async {
    final json = await transport.request('GET', '/api/v1/event-categories');
    return s9List(json['data'], EventCategory.fromJson);
  }

  /// [from] inclusive, [to] exclusive, at most 62 days (422 otherwise).
  Future<MyCalendar> myCalendar(DateTime from, DateTime to) async {
    final json = await transport.request(
      'GET',
      s9Path('/api/v1/me/calendar', {
        'from': from.toUtc().toIso8601String(),
        'to': to.toUtc().toIso8601String(),
      }),
    );
    final data = s9Data(json);
    return MyCalendar(
      events: s9List(data['events'], MyCalendarEvent.fromJson),
      privateEvents: s9List(data['private_events'], PrivateEvent.fromJson),
      vip: s9Bool(data['vip']),
      truncated: s9Bool(s9Map(json['meta'])['truncated']),
    );
  }

  /// 403 VIP_REQUIRED for members without the calendar VIP level.
  Future<PrivateEvent> createPrivate({
    required String title,
    required DateTime startsAt,
    required DateTime endsAt,
    String? notes,
    String timezone = 'Asia/Taipei',
    String? recurrence,
    String? recurrenceUntil,
  }) async {
    final json = await transport.request(
      'POST',
      '/api/v1/me/private-events',
      body: {
        'title': title,
        'starts_at': startsAt.toUtc().toIso8601String(),
        'ends_at': endsAt.toUtc().toIso8601String(),
        'notes': notes,
        'timezone': timezone,
        'recurrence': recurrence,
        'recurrence_until': recurrenceUntil,
      },
    );
    return PrivateEvent.fromJson(s9Data(json));
  }

  Future<PrivateEvent> updatePrivate(
    String id,
    Map<String, Object?> changes,
  ) async {
    final json = await transport.request(
      'PATCH',
      '/api/v1/me/private-events/${s9Seg(id)}',
      body: changes,
    );
    return PrivateEvent.fromJson(s9Data(json));
  }

  Future<void> deletePrivate(String id) async {
    await transport.request('DELETE', '/api/v1/me/private-events/${s9Seg(id)}');
  }

  Future<GoogleCalendarStatus> googleStatus() async {
    final json = await transport.request('GET', '/api/v1/me/google-calendar');
    return GoogleCalendarStatus.fromJson(s9Data(json));
  }

  /// The Google consent URL to open in the browser. 503 GOOGLE_NOT_CONFIGURED
  /// when this App has no Google OAuth client.
  Future<String> googleConnect() async {
    final json = await transport.request(
      'POST',
      '/api/v1/me/google-calendar/connect',
    );
    return s9Str(s9Data(json), 'authorization_url');
  }

  /// Stops syncing; events already in Google stay there.
  Future<void> googleDisconnect() async {
    await transport.request('DELETE', '/api/v1/me/google-calendar');
  }
}
