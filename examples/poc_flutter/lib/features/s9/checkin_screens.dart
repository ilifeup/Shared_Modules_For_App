import 'dart:async';

import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../core/device/device_services.dart';
import '../../core/providers.dart';
import '../../ui/content_unavailable.dart';
import '../../ui/status_box.dart';

final checkinStatusProvider = FutureProvider.autoDispose
    .family<CheckinStatus, String>(
      (ref, eventId) => ref.watch(checkinApiProvider).status(eventId),
    );

String checkinLabel(String? status) => switch (status) {
  'checked_in' => '【已簽到】',
  'checked_out' => '【已簽退】',
  'revoked' => '【已撤銷】',
  _ => '【未簽到】',
};

String resultLabel(CheckinResult r) => switch (r.result) {
  'checked_in' => '簽到成功',
  'already_checked_in' => '已簽到過',
  'already_checked_out' => '已簽退（如需更正請洽管理員）',
  'checked_out' => '簽退成功',
  _ => r.result,
};

/// Member check-in for one occurrence: choose attendees, scan the event QR or
/// a Location QR (GPS when the occurrence requires it). Online only.
class CheckinScreen extends ConsumerStatefulWidget {
  const CheckinScreen({super.key, required this.eventId, this.locationToken});

  final String eventId;

  /// Set when opened from a Location QR link.
  final String? locationToken;

  @override
  ConsumerState<CheckinScreen> createState() => _CheckinScreenState();
}

class _CheckinScreenState extends ConsumerState<CheckinScreen> {
  final _selected = <String>{};
  bool _busy = false;
  Object? _error;
  List<CheckinResult>? _results;

  Future<void> _scanAndSend({required bool checkout}) async {
    if (_busy || _selected.isEmpty) return;
    String? eventToken;
    var locationToken = widget.locationToken;
    if (locationToken == null) {
      final raw = await ref.read(qrScannerProvider)(context);
      if (raw == null || !mounted) return;
      final host = ref.read(appConfigProvider).appLinkHost;
      final scan = CheckinScan.parse(raw, appHost: host.isEmpty ? null : host);
      if (scan == null || scan.kind == 'attendee') {
        setState(() => _error = '這不是此活動的簽到 QR Code。');
        return;
      }
      if (scan.kind == 'event') {
        eventToken = scan.value;
      } else {
        locationToken = scan.value;
      }
    }

    setState(() {
      _busy = true;
      _error = null;
      _results = null;
    });
    try {
      final api = ref.read(checkinApiProvider);
      final status = ref.read(checkinStatusProvider(widget.eventId)).value;
      GpsFix? gps;
      if (locationToken != null && (status?.settings.gpsRequired ?? false)) {
        gps = await ref.read(gpsProvider)();
        if (gps == null) {
          if (mounted) {
            setState(() => _error = '無法取得定位。請允許定位權限，或請現場工作人員協助簽到。');
          }
          return;
        }
      }
      final ids = _selected.toList();
      final results = checkout
          ? await api.checkOut(
              widget.eventId,
              ids,
              eventToken: eventToken,
              locationToken: locationToken,
            )
          : await api.checkIn(
              widget.eventId,
              ids,
              eventToken: eventToken,
              locationToken: locationToken,
              lat: gps?.lat,
              lng: gps?.lng,
              accuracy: gps?.accuracy,
            );
      if (mounted) setState(() => _results = results);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      ref.invalidate(checkinStatusProvider(widget.eventId));
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final status = ref.watch(checkinStatusProvider(widget.eventId));

    return Scaffold(
      appBar: AppBar(title: const Text('活動簽到')),
      body: status.when(
        loading: () => const LoadingView(),
        error: (error, _) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ErrorRetry(
              error: ErrorBox(error: error),
              onRetry: () =>
                  ref.invalidate(checkinStatusProvider(widget.eventId)),
            ),
          ],
        ),
        data: (s) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            StatusBox(
              key: const Key('checkin-window'),
              label: s.settings.openNow ? '【開放簽到中】' : '【目前不在簽到時間】',
              detail: s.settings.gpsRequired ? '此場次使用地點 QR Code 時需要定位。' : '',
            ),
            for (final a in s.attendees)
              CheckboxListTile(
                key: Key('checkin-attendee-${a.id}'),
                value: _selected.contains(a.id),
                onChanged: a.bookingStatus == 'confirmed'
                    ? (v) => setState(
                        () => v == true
                            ? _selected.add(a.id)
                            : _selected.remove(a.id),
                      )
                    : null,
                title: Text(a.name),
                subtitle: Text(
                  a.bookingStatus == 'waitlisted'
                      ? '【候補中】無法簽到'
                      : checkinLabel(a.checkinStatus),
                ),
              ),
            if (_error is String)
              StatusBox(label: '【提醒】', detail: _error as String)
            else if (_error != null)
              ErrorBox(error: _error!),
            if (_results != null)
              StatusBox(
                key: const Key('checkin-results'),
                label: '【完成】',
                detail: _results!
                    .map((r) => '#${r.attendeeId} ${resultLabel(r)}')
                    .join('\n'),
              ),
            FilledButton(
              key: const Key('checkin-scan'),
              onPressed: _busy || _selected.isEmpty || !s.settings.openNow
                  ? null
                  : () => _scanAndSend(checkout: false),
              child: Text(
                _busy
                    ? '處理中…'
                    : widget.locationToken != null
                    ? '簽到'
                    : '掃描 QR Code 簽到',
              ),
            ),
            if (s.settings.checkoutEnabled)
              OutlinedButton(
                key: const Key('checkout-scan'),
                onPressed: _busy || _selected.isEmpty
                    ? null
                    : () => _scanAndSend(checkout: true),
                child: const Text('簽退'),
              ),
          ],
        ),
      ),
    );
  }
}

/// Rotating attendee passes that staff scan (refreshes before expiry).
class CheckinPassScreen extends ConsumerStatefulWidget {
  const CheckinPassScreen({super.key, required this.eventId});

  final String eventId;

  @override
  ConsumerState<CheckinPassScreen> createState() => _CheckinPassScreenState();
}

class _CheckinPassScreenState extends ConsumerState<CheckinPassScreen> {
  CheckinPasses? _passes;
  Object? _error;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final passes = await ref.read(checkinApiProvider).passes(widget.eventId);
      if (!mounted) return;
      setState(() {
        _passes = passes;
        _error = null;
      });
      _timer?.cancel();
      _timer = Timer(
        Duration(seconds: passes.expiresIn.clamp(5, 60)),
        () => unawaited(_load()),
      );
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
  }

  @override
  Widget build(BuildContext context) {
    final passes = _passes;
    return Scaffold(
      appBar: AppBar(title: const Text('我的入場 QR Code')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_error != null) ErrorBox(error: _error!),
          if (passes == null && _error == null) const LoadingView(),
          if (passes != null && passes.passes.isEmpty)
            const StatusBox(label: '【沒有可簽到的報名】', detail: ''),
          if (passes != null)
            for (final p in passes.passes)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    children: [
                      Text(
                        p.name,
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      QrImageView(
                        key: Key('pass-${p.attendeeId}'),
                        data: p.token,
                        size: 200,
                      ),
                      const Text('QR Code 每分鐘更新，請出示給工作人員掃描。'),
                    ],
                  ),
                ),
              ),
        ],
      ),
    );
  }
}

/// Staff: show the event QR, scan attendee passes, see attendance counts.
class StaffCheckinScreen extends ConsumerStatefulWidget {
  const StaffCheckinScreen({super.key, required this.eventId});

  final String eventId;

  @override
  ConsumerState<StaffCheckinScreen> createState() => _StaffCheckinScreenState();
}

class _StaffCheckinScreenState extends ConsumerState<StaffCheckinScreen> {
  CheckinQr? _qr;
  Attendance? _attendance;
  Object? _error;
  String? _message;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    unawaited(_refresh());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _refresh() async {
    final api = ref.read(checkinApiProvider);
    try {
      final qr = await api.eventQr(widget.eventId);
      final attendance = await api.attendance(widget.eventId);
      if (!mounted) return;
      setState(() {
        _qr = qr;
        _attendance = attendance;
        _error = null;
      });
      _timer?.cancel();
      _timer = Timer(
        Duration(seconds: qr.expiresIn.clamp(5, 60)),
        () => unawaited(_refresh()),
      );
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
  }

  Future<void> _scanPass() async {
    final raw = await ref.read(qrScannerProvider)(context);
    if (raw == null || !mounted) return;
    final scan = CheckinScan.parse(raw);
    if (scan == null || scan.kind != 'attendee') {
      setState(() => _message = '這不是參加者的入場 QR Code。');
      return;
    }
    try {
      final r = await ref
          .read(checkinApiProvider)
          .staffScan(widget.eventId, attendeeToken: scan.value);
      if (mounted) setState(() => _message = resultLabel(r));
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
    await _refresh();
  }

  /// M11-D17 / D11: manual check-in after the staff member checked the person
  /// (e.g. GPS failed). An unclaimed guest must state the booking Email or phone.
  Future<void> _manual(CheckinAttendee a) async {
    String? contact;
    if (a.isGuest) {
      contact = await showDialog<String>(
        context: context,
        builder: (_) => _ContactDialog(attendee: a),
      );
      if (contact == null || !mounted) return;
    }
    try {
      final r = await ref
          .read(checkinApiProvider)
          .staffScan(widget.eventId, attendeeId: a.id, contact: contact);
      if (mounted) setState(() => _message = '${a.name}：${resultLabel(r)}');
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
    await _refresh();
  }

  @override
  Widget build(BuildContext context) {
    final qr = _qr;
    final s = _attendance?.summary;
    return Scaffold(
      appBar: AppBar(title: const Text('工作人員簽到')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_error != null) ErrorBox(error: _error!),
          if (_message != null)
            StatusBox(
              key: const Key('staff-message'),
              label: '【掃描結果】',
              detail: _message!,
            ),
          if (s != null)
            StatusBox(
              key: const Key('staff-summary'),
              label: '報名 ${s.booked}・已簽到 ${s.checkedIn}・未到 ${s.absent}',
              detail: s.checkoutEnabled ? '已簽退 ${s.checkedOut}' : '',
            ),
          if (qr != null) ...[
            Center(
              child: QrImageView(
                key: const Key('event-qr'),
                data: qr.token,
                size: 240,
              ),
            ),
            const Text(
              '請會員以 App 掃描此 QR Code（每分鐘更新）。',
              textAlign: TextAlign.center,
            ),
          ],
          FilledButton(
            key: const Key('staff-scan'),
            onPressed: _scanPass,
            child: const Text('掃描參加者 QR Code'),
          ),
          if (_attendance != null) ...[
            const Divider(height: 24),
            const Text('手動簽到（掃描或定位失敗時，核對身分後使用）'),
            for (final a in _attendance!.attendees)
              ListTile(
                key: Key('staff-attendee-${a.id}'),
                title: Text('${a.name}${a.isGuest ? '（訪客）' : ''}'),
                subtitle: Text(
                  [
                    checkinLabel(a.checkinStatus),
                    if (a.contactName != null) '聯絡人 ${a.contactName}',
                    ?a.contactHint,
                  ].join('・'),
                ),
                trailing: a.checkinStatus == 'checked_in' ||
                        a.checkinStatus == 'checked_out'
                    ? null
                    : TextButton(
                        key: Key('staff-manual-${a.id}'),
                        onPressed: () => _manual(a),
                        child: const Text('手動簽到'),
                      ),
              ),
          ],
        ],
      ),
    );
  }
}

/// Asks for the Email or phone the guest used when booking (never shown in full).
class _ContactDialog extends StatefulWidget {
  const _ContactDialog({required this.attendee});

  final CheckinAttendee attendee;

  @override
  State<_ContactDialog> createState() => _ContactDialogState();
}

class _ContactDialogState extends State<_ContactDialog> {
  final _contact = TextEditingController();

  @override
  void dispose() {
    _contact.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text('核對訪客：${widget.attendee.name}'),
      content: TextField(
        key: const Key('staff-contact'),
        controller: _contact,
        decoration: InputDecoration(
          labelText: '請訪客說出報名時的 Email 或手機',
          helperText: widget.attendee.contactHint == null
              ? null
              : '報名資料：${widget.attendee.contactHint}',
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('取消'),
        ),
        FilledButton(
          key: const Key('staff-contact-ok'),
          onPressed: () => Navigator.of(context).pop(_contact.text.trim()),
          child: const Text('核對並簽到'),
        ),
      ],
    );
  }
}

/// Location QR landing: pick the open occurrence, then check in there.
class CheckinLocationScreen extends ConsumerWidget {
  const CheckinLocationScreen({super.key, required this.token});

  final String token;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final location = ref.watch(_locationProvider(token));
    return Scaffold(
      appBar: AppBar(title: const Text('地點簽到')),
      body: location.when(
        loading: () => const LoadingView(),
        error: (error, _) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ContentUnavailable(
              error: error,
              onRetry: () => ref.invalidate(_locationProvider(token)),
            ),
          ],
        ),
        data: (l) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(l.name, style: Theme.of(context).textTheme.titleLarge),
            if (l.events.isEmpty)
              const StatusBox(label: '【目前沒有開放簽到的場次】', detail: ''),
            for (final e in l.events)
              ListTile(
                key: Key('location-event-${e.id}'),
                title: Text(e.title),
                subtitle: Text('${e.attendees.length} 位可簽到'),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) =>
                        CheckinScreen(eventId: e.id, locationToken: token),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

final _locationProvider = FutureProvider.autoDispose
    .family<CheckinLocation, String>(
      (ref, token) => ref.watch(checkinApiProvider).location(token),
    );
