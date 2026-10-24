import 'api_envelope.dart';
import 'member_api.dart';

/// S5 M09 calendar event. Times are UTC from the API; use [startsAt].toLocal()
/// for display. Cancelled events are kept and shown with [isCancelled].
class CalendarEvent {
  const CalendarEvent({
    required this.id,
    required this.title,
    required this.startsAt,
    required this.endsAt,
    required this.status,
    this.description,
    this.location,
    this.audience = 'members',
    this.cancelledAt,
    this.cancelReason,
  });

  /// Stable id (future bookings refer to it).
  final String id;
  final String title;
  final String? description;
  final String? location;
  final DateTime startsAt;
  final DateTime endsAt;

  /// `published` or `cancelled`.
  final String status;
  final String audience;
  final DateTime? cancelledAt;
  final String? cancelReason;

  bool get isCancelled => status == 'cancelled';

  /// True when the event overlaps the local calendar day of [day].
  bool occursOn(DateTime day) {
    final start = DateTime(day.year, day.month, day.day);
    final end = DateTime(day.year, day.month, day.day + 1);
    return startsAt.isBefore(end) && endsAt.isAfter(start);
  }
}

class CalendarEventDto {
  const CalendarEventDto._();

  static CalendarEvent fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final title = json['title'];
    final startsAt = _date(json['starts_at']);
    final endsAt = _date(json['ends_at']);
    final status = json['status'];
    if (id is! String ||
        title is! String ||
        startsAt == null ||
        endsAt == null ||
        status is! String) {
      throw const FormatException('Invalid event');
    }
    final audience = json['audience'];
    return CalendarEvent(
      id: id,
      title: title,
      description: _text(json['description']),
      location: _text(json['location']),
      startsAt: startsAt,
      endsAt: endsAt,
      status: status,
      audience: audience is String ? audience : 'members',
      cancelledAt: _date(json['cancelled_at']),
      cancelReason: _text(json['cancel_reason']),
    );
  }

  static String? _text(Object? value) => value is String ? value : null;

  static DateTime? _date(Object? value) =>
      value is String ? DateTime.tryParse(value)?.toUtc() : null;
}

/// Events overlapping a range; [truncated] means the server limit was reached.
class EventRange {
  const EventRange({required this.items, this.truncated = false});

  final List<CalendarEvent> items;
  final bool truncated;

  /// Events on one local calendar day, in start order.
  List<CalendarEvent> on(DateTime day) =>
      items.where((event) => event.occursOn(day)).toList(growable: false);
}

/// Local month containing [day], as an exclusive [from, to) range.
({DateTime from, DateTime to}) localMonthRange(DateTime day) {
  return (
    from: DateTime(day.year, day.month),
    to: DateTime(day.year, day.month + 1),
  );
}

class EventApi {
  const EventApi(this.transport);

  final ApiTransport transport;

  /// Public and member events (requires login and the verification policy).
  Future<EventRange> memberEvents(DateTime from, DateTime to) =>
      _range('/api/v1/events', from, to);

  Future<CalendarEvent> memberEvent(String id) =>
      _one('/api/v1/events/${Uri.encodeComponent(id)}');

  Future<EventRange> publicEvents(DateTime from, DateTime to) =>
      _range('/api/v1/public/events', from, to);

  Future<CalendarEvent> publicEvent(String id) =>
      _one('/api/v1/public/events/${Uri.encodeComponent(id)}');

  Future<EventRange> _range(String path, DateTime from, DateTime to) async {
    if (!to.isAfter(from)) {
      throw ArgumentError('`to` must be after `from`');
    }
    final query =
        'from=${Uri.encodeQueryComponent(_iso(from))}'
        '&to=${Uri.encodeQueryComponent(_iso(to))}';
    final json = await transport.request('GET', '$path?$query');
    final items = ApiEnvelope<List<CalendarEvent>>.fromJson(json, (data) {
      if (data is! List) throw const FormatException('Expected events');
      return data
          .map(
            (item) => CalendarEventDto.fromJson(item as Map<String, dynamic>),
          )
          .toList(growable: false);
    }).data;
    final meta = json['meta'];
    return EventRange(
      items: items,
      truncated: meta is Map && meta['truncated'] == true,
    );
  }

  Future<CalendarEvent> _one(String path) async {
    final json = await transport.request('GET', path);
    return ApiEnvelope<CalendarEvent>.fromJson(
      json,
      (data) => CalendarEventDto.fromJson(data as Map<String, dynamic>),
    ).data;
  }

  /// Sends instants in UTC, so the server range matches the local day exactly.
  static String _iso(DateTime value) => value.toUtc().toIso8601String();
}
