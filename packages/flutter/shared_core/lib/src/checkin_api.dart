import 'member_api.dart';
import 's9_json.dart';

class CheckinSettings {
  const CheckinSettings({
    required this.enabled,
    required this.checkoutEnabled,
    required this.openNow,
    required this.gpsRequired,
    required this.gpsRadiusM,
    this.opensAt,
    this.closesAt,
  });

  final bool enabled;
  final bool checkoutEnabled;
  final bool openNow;
  final bool gpsRequired;
  final int gpsRadiusM;
  final DateTime? opensAt;
  final DateTime? closesAt;

  factory CheckinSettings.fromJson(Map<String, dynamic> json) =>
      CheckinSettings(
        enabled: s9Bool(json['enabled']),
        checkoutEnabled: s9Bool(json['checkout_enabled']),
        openNow: s9Bool(json['open_now']),
        gpsRequired: s9Bool(json['gps_required']),
        gpsRadiusM: s9Int(json['gps_radius_m'], 100),
        opensAt: s9Date(json['opens_at']),
        closesAt: s9Date(json['closes_at']),
      );
}

class CheckinAttendee {
  const CheckinAttendee({
    required this.id,
    required this.name,
    required this.bookingStatus,
    this.checkinStatus,
    this.checkedInAt,
    this.checkedOutAt,
  });

  final String id;
  final String name;

  /// `confirmed` or `waitlisted` (waitlisted cannot check in).
  final String bookingStatus;

  /// `checked_in`, `checked_out`, `revoked` or null.
  final String? checkinStatus;
  final DateTime? checkedInAt;
  final DateTime? checkedOutAt;

  bool get canCheckIn =>
      bookingStatus == 'confirmed' &&
      (checkinStatus == null || checkinStatus == 'revoked');

  factory CheckinAttendee.fromJson(Map<String, dynamic> json) =>
      CheckinAttendee(
        id: s9Str(json, 'id'),
        name: s9Str(json, 'name'),
        bookingStatus:
            s9OptStr(json['booking_status']) ??
            s9OptStr(json['status']) ??
            'confirmed',
        checkinStatus: s9OptStr(json['checkin_status']),
        checkedInAt: s9Date(json['checked_in_at']),
        checkedOutAt: s9Date(json['checked_out_at']),
      );
}

class CheckinStatus {
  const CheckinStatus({required this.settings, required this.attendees});

  final CheckinSettings settings;
  final List<CheckinAttendee> attendees;
}

class CheckinResult {
  const CheckinResult({
    required this.attendeeId,
    required this.result,
    required this.checkinStatus,
  });

  final String attendeeId;

  /// `checked_in`, `already_checked_in`, `already_checked_out` or `checked_out`.
  final String result;
  final String checkinStatus;

  bool get isNew => result == 'checked_in' || result == 'checked_out';

  factory CheckinResult.fromJson(Map<String, dynamic> json) => CheckinResult(
    attendeeId: s9Str(json, 'attendee_id'),
    result: s9Str(json, 'result'),
    checkinStatus: s9OptStr(json['checkin_status']) ?? '',
  );
}

class CheckinPass {
  const CheckinPass({
    required this.attendeeId,
    required this.name,
    required this.token,
  });

  final String attendeeId;
  final String name;
  final String token;
}

/// Rotating attendee passes (the App refreshes after [expiresIn] seconds).
class CheckinPasses {
  const CheckinPasses({
    required this.passes,
    required this.expiresIn,
    required this.windowSeconds,
  });

  final List<CheckinPass> passes;
  final int expiresIn;
  final int windowSeconds;
}

/// Event QR shown by staff (60-second rotation, 10-second grace on the server).
class CheckinQr {
  const CheckinQr({
    required this.eventId,
    required this.token,
    required this.expiresIn,
    required this.windowSeconds,
  });

  final String eventId;
  final String token;
  final int expiresIn;
  final int windowSeconds;
}

class CheckinLocationEvent {
  const CheckinLocationEvent({
    required this.id,
    required this.title,
    required this.settings,
    required this.attendees,
    this.startsAt,
  });

  final String id;
  final String title;
  final DateTime? startsAt;
  final CheckinSettings settings;
  final List<CheckinAttendee> attendees;
}

class CheckinLocation {
  const CheckinLocation({
    required this.name,
    required this.hasCoordinates,
    required this.events,
  });

  final String name;
  final bool hasCoordinates;
  final List<CheckinLocationEvent> events;
}

class AttendanceSummary {
  const AttendanceSummary({
    required this.booked,
    required this.checkedIn,
    required this.absent,
    required this.checkedOut,
    required this.checkoutEnabled,
  });

  final int booked;
  final int checkedIn;
  final int absent;
  final int checkedOut;
  final bool checkoutEnabled;
}

class Attendance {
  const Attendance({required this.summary, required this.attendees});

  final AttendanceSummary summary;
  final List<CheckinAttendee> attendees;
}

/// What a scanned QR code means for check-in (never trusted by itself: the
/// server validates every token).
class CheckinScan {
  const CheckinScan._(this.kind, this.value);

  /// Event QR shown at the venue: `e.{eventId}.{window}.{sig}`.
  const CheckinScan.event(String token) : this._('event', token);

  /// Attendee pass shown by a member (staff scan it): `a.{id}.{window}.{sig}`.
  const CheckinScan.attendee(String token) : this._('attendee', token);

  /// Fixed Location QR: `https://<app domain>/checkin/l/{token}`.
  const CheckinScan.location(String token) : this._('location', token);

  /// `event`, `attendee` or `location`.
  final String kind;
  final String value;

  /// Event id inside an event token.
  String? get eventId => kind == 'event' ? value.split('.')[1] : null;

  static final _token = RegExp(
    r'^([ea])\.(\d{1,19})\.(\d{1,12})\.([0-9a-f]{24})$',
  );
  static final _location = RegExp(r'^/checkin/l/([A-Za-z0-9]{16,40})/?$');

  /// Null when the code is not a check-in code of this App. [appHost] limits
  /// Location URLs to the App's own domain.
  static CheckinScan? parse(String raw, {String? appHost}) {
    final text = raw.trim();
    final token = _token.firstMatch(text);
    if (token != null) {
      return token.group(1) == 'e'
          ? CheckinScan.event(text)
          : CheckinScan.attendee(text);
    }
    final uri = Uri.tryParse(text);
    if (uri == null || uri.scheme != 'https') return null;
    if (appHost != null && uri.host != appHost) return null;
    final match = _location.firstMatch(uri.path);
    return match == null ? null : CheckinScan.location(match.group(1)!);
  }
}

/// S9 M11 check-in (online only). Members check in their own attendees with
/// the event QR or a Location QR (+ GPS when required); staff with
/// `checkin.staff` show the event QR, scan passes and read attendance.
class CheckinApi {
  const CheckinApi(this.transport);

  final ApiTransport transport;

  String _event(String id) => '/api/v1/events/${s9Seg(id)}';

  Future<CheckinStatus> status(String eventId) async {
    final data = s9Data(
      await transport.request('GET', '${_event(eventId)}/checkin'),
    );
    return CheckinStatus(
      settings: CheckinSettings.fromJson(s9Map(data['settings'])),
      attendees: s9List(data['attendees'], CheckinAttendee.fromJson),
    );
  }

  /// 422 QR_EXPIRED / LOCATION_EVENT_MISMATCH / GPS_REQUIRED / GPS_OUT_OF_RANGE;
  /// 409 CHECKIN_CLOSED / CHECKIN_DISABLED / WAITLISTED / NOT_CONFIRMED.
  Future<List<CheckinResult>> checkIn(
    String eventId,
    List<String> attendeeIds, {
    String? eventToken,
    String? locationToken,
    double? lat,
    double? lng,
    double? accuracy,
  }) => _scan(
    '${_event(eventId)}/checkin',
    attendeeIds,
    eventToken,
    locationToken,
    lat,
    lng,
    accuracy,
  );

  /// Same QR entry as check-in; 409 NOT_CHECKED_IN / CHECKOUT_DISABLED.
  Future<List<CheckinResult>> checkOut(
    String eventId,
    List<String> attendeeIds, {
    String? eventToken,
    String? locationToken,
  }) => _scan(
    '${_event(eventId)}/checkout',
    attendeeIds,
    eventToken,
    locationToken,
    null,
    null,
    null,
  );

  Future<List<CheckinResult>> _scan(
    String path,
    List<String> attendeeIds,
    String? eventToken,
    String? locationToken,
    double? lat,
    double? lng,
    double? accuracy,
  ) async {
    final json = await transport.request(
      'POST',
      path,
      body: {
        'attendee_ids': [for (final id in attendeeIds) int.tryParse(id) ?? id],
        if (eventToken != null) 'event_token': eventToken,
        if (locationToken != null) 'location_token': locationToken,
        if (lat != null) 'lat': lat,
        if (lng != null) 'lng': lng,
        if (accuracy != null) 'accuracy': accuracy,
      },
    );
    return s9List(json['data'], CheckinResult.fromJson);
  }

  Future<CheckinPasses> passes(String eventId) async {
    final data = s9Data(
      await transport.request('GET', '${_event(eventId)}/checkin/pass'),
    );
    return CheckinPasses(
      passes: s9List(
        data['passes'],
        (p) => CheckinPass(
          attendeeId: s9Str(p, 'attendee_id'),
          name: s9Str(p, 'name'),
          token: s9Str(p, 'token'),
        ),
      ),
      expiresIn: s9Int(data['expires_in'], 60),
      windowSeconds: s9Int(data['window_seconds'], 60),
    );
  }

  Future<CheckinLocation> location(String token) async {
    final data = s9Data(
      await transport.request(
        'GET',
        '/api/v1/checkin/locations/${s9Seg(token)}',
      ),
    );
    final location = s9Map(data['location']);
    return CheckinLocation(
      name: s9OptStr(location['name']) ?? '',
      hasCoordinates: s9Bool(location['has_coordinates']),
      events: s9List(
        data['events'],
        (e) => CheckinLocationEvent(
          id: s9Str(e, 'id'),
          title: s9Str(e, 'title'),
          startsAt: s9Date(e['starts_at']),
          settings: CheckinSettings.fromJson(s9Map(e['settings'])),
          attendees: s9List(e['attendees'], CheckinAttendee.fromJson),
        ),
      ),
    );
  }

  /// Staff only (403 FORBIDDEN otherwise).
  Future<CheckinQr> eventQr(String eventId) async {
    final data = s9Data(
      await transport.request('GET', '${_event(eventId)}/checkin/qr'),
    );
    return CheckinQr(
      eventId: s9Str(data, 'event_id'),
      token: s9Str(data, 'token'),
      expiresIn: s9Int(data['expires_in'], 60),
      windowSeconds: s9Int(data['window_seconds'], 60),
    );
  }

  /// Staff scan of an attendee pass ([attendeeToken]) or manual check-in
  /// ([attendeeId]); [checkout] for checkout.
  Future<CheckinResult> staffScan(
    String eventId, {
    String? attendeeToken,
    String? attendeeId,
    bool checkout = false,
  }) async {
    final json = await transport.request(
      'POST',
      '${_event(eventId)}/checkin/staff',
      body: {
        if (attendeeToken != null) 'attendee_token': attendeeToken,
        if (attendeeId != null) 'attendee_id': int.tryParse(attendeeId),
        'action': checkout ? 'checkout' : 'checkin',
      },
    );
    return CheckinResult.fromJson(s9Data(json));
  }

  Future<Attendance> attendance(String eventId) async {
    final data = s9Data(
      await transport.request('GET', '${_event(eventId)}/attendance'),
    );
    final s = s9Map(data['summary']);
    return Attendance(
      summary: AttendanceSummary(
        booked: s9Int(s['booked']),
        checkedIn: s9Int(s['checked_in']),
        absent: s9Int(s['absent']),
        checkedOut: s9Int(s['checked_out']),
        checkoutEnabled: s9Bool(s['checkout_enabled']),
      ),
      attendees: s9List(data['attendees'], CheckinAttendee.fromJson),
    );
  }
}
