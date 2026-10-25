import 'dart:math';

import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_exception.dart';
import '../../core/providers.dart';
import '../../ui/status_box.dart';
import '../calendar/calendar_providers.dart';

/// Random UUID v4 for idempotent retries (client_request_id).
String newRequestId([Random? random]) {
  final r = random ?? Random.secure();
  final b = List<int>.generate(16, (_) => r.nextInt(256));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  final h = b.map((x) => x.toRadixString(16).padLeft(2, '0')).join();
  return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-'
      '${h.substring(16, 20)}-${h.substring(20)}';
}

final myBookingGroupsProvider = FutureProvider.autoDispose<BookingGroupPage>(
  (ref) => ref.watch(bookingGroupApiProvider).mine(),
);

final claimableGuestBookingsProvider =
    FutureProvider.autoDispose<List<BookingGroup>>(
      (ref) => ref.watch(bookingGroupApiProvider).claimable(),
    );

/// Text for a group status (shown with words, not colour only).
String groupStatusLabel(BookingGroup g) => switch (g.status) {
  'confirmed' => '【正式報名】',
  'waitlisted' => '【候補中】第 ${g.waitlistPosition ?? '-'} 順位',
  'event_cancelled' => '【活動已取消】',
  _ => '【已取消】',
};

/// S9 M08 group booking: the member and/or named attendees, booked or
/// waitlisted together. One request id per attempt, reused on retry, so a
/// double tap or a network retry never books twice.
class GroupBookingScreen extends ConsumerStatefulWidget {
  const GroupBookingScreen({super.key, required this.eventId});

  final String eventId;

  @override
  ConsumerState<GroupBookingScreen> createState() => _GroupBookingScreenState();
}

class _GroupBookingScreenState extends ConsumerState<GroupBookingScreen> {
  final _names = <TextEditingController>[];
  bool _includeSelf = true;
  bool _busy = false;
  Object? _error;
  BookingGroup? _result;
  String _requestId = newRequestId();
  // Keep the exact original payload for retries whose server outcome is unknown.
  List<String>? _pendingNames;
  bool? _pendingIncludeSelf;

  @override
  void dispose() {
    for (final c in _names) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;
    final names =
        _pendingNames ??
        _names.map((c) => c.text.trim()).where((n) => n.isNotEmpty).toList();
    final includeSelf = _pendingIncludeSelf ?? _includeSelf;
    if (!includeSelf && names.isEmpty) {
      setState(() => _error = '請至少填寫一位參加者。');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _pendingNames ??= List<String>.unmodifiable(names);
      _pendingIncludeSelf ??= includeSelf;
    });
    try {
      final group = await ref
          .read(bookingGroupApiProvider)
          .book(
            widget.eventId,
            clientRequestId: _requestId,
            includeSelf: _pendingIncludeSelf!,
            attendeeNames: _pendingNames!,
          );
      if (!mounted) return;
      setState(() {
        _result = group;
        _pendingNames = null;
        _pendingIncludeSelf = null;
        _requestId = newRequestId(); // the next booking is a new request
      });
      ref.invalidate(myBookingGroupsProvider);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        // Only a definitive client rejection can release the old request ID.
        // Timeout, disconnection, 5xx and malformed responses may follow a commit.
        if (error is ApiException &&
            error.statusCode != null &&
            error.statusCode! >= 400 &&
            error.statusCode! < 500 &&
            error.statusCode != 408 &&
            error.statusCode != 429) {
          _pendingNames = null;
          _pendingIncludeSelf = null;
          _requestId = newRequestId();
        }
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final event = ref.watch(eventDetailProvider(widget.eventId));
    final result = _result;

    return Scaffold(
      appBar: AppBar(title: const Text('多人報名／候補')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (event.value != null)
            Text(
              event.value!.title,
              style: Theme.of(context).textTheme.titleLarge,
            ),
          const SizedBox(height: 8),
          if (result != null) ...[
            StatusBox(
              key: const Key('group-result'),
              label: groupStatusLabel(result),
              detail:
                  '共 ${result.seatCount} 位：'
                  '${result.attendees.map((a) => a.name).join('、')}',
            ),
            TextButton(
              onPressed: () => context.push('/me/booking-groups'),
              child: const Text('查看我的報名'),
            ),
          ] else ...[
            SwitchListTile(
              key: const Key('group-include-self'),
              title: const Text('我本人參加'),
              value: _includeSelf,
              onChanged: _busy || _pendingNames != null
                  ? null
                  : (v) => setState(() => _includeSelf = v),
            ),
            for (var i = 0; i < _names.length; i++)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: TextField(
                  key: Key('group-name-$i'),
                  controller: _names[i],
                  enabled: !_busy && _pendingNames == null,
                  maxLength: 80,
                  decoration: InputDecoration(
                    labelText: '同行者 ${i + 1} 姓名',
                    border: const OutlineInputBorder(),
                  ),
                ),
              ),
            OutlinedButton(
              key: const Key('group-add-name'),
              onPressed: _busy || _pendingNames != null || _names.length >= 9
                  ? null
                  : () => setState(() => _names.add(TextEditingController())),
              child: const Text('新增同行者'),
            ),
            const SizedBox(height: 8),
            const Text('名額不足時整組進入候補，依順序遞補；遞補成功會通知您。'),
            const SizedBox(height: 8),
            if (_pendingNames != null && !_busy)
              const StatusBox(
                label: '【結果待確認】',
                detail:
                    '上次送出可能已成功。請重試原請求，或至「我的報名」確認；'
                    '在結果確定前不可修改參加者資料。',
              ),
            if (_error is String)
              StatusBox(label: '【提醒】', detail: _error as String)
            else if (_error != null)
              ErrorBox(error: _error!),
            FilledButton(
              key: const Key('group-submit'),
              onPressed: _busy ? null : _submit,
              child: Text(
                _busy
                    ? '處理中…'
                    : _pendingNames != null
                    ? '重試原請求'
                    : '送出報名',
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// My S9 booking groups and guest bookings I can claim.
class MyBookingGroupsScreen extends ConsumerStatefulWidget {
  const MyBookingGroupsScreen({super.key});

  @override
  ConsumerState<MyBookingGroupsScreen> createState() =>
      _MyBookingGroupsScreenState();
}

class _MyBookingGroupsScreenState extends ConsumerState<MyBookingGroupsScreen> {
  bool _busy = false;
  Object? _error;

  Future<void> _act(Future<void> Function() action) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await action();
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      ref.invalidate(myBookingGroupsProvider);
      ref.invalidate(claimableGuestBookingsProvider);
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancel(BookingGroup g) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(g.isWaitlisted ? '取消候補？' : '取消整組報名？'),
        content: const Text('取消後名額會依候補順序遞補給其他人。'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('保留'),
          ),
          FilledButton(
            key: const Key('group-cancel-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('確定取消'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    await _act(() => ref.read(bookingGroupApiProvider).cancel(g.id));
  }

  @override
  Widget build(BuildContext context) {
    final groups = ref.watch(myBookingGroupsProvider);
    final claimable = ref.watch(claimableGuestBookingsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('我的報名')),
      body: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          if (_error != null) ErrorBox(error: _error!),
          ...claimable.maybeWhen(
            data: (items) => [
              if (items.isNotEmpty) const Text('可認領的訪客報名（與您已驗證的 Email／手機相同）'),
              for (final g in items)
                Card(
                  child: ListTile(
                    title: Text(g.eventTitle),
                    subtitle: Text(
                      '${formatDate(g.eventStartsAt)}・${g.seatCount} 位',
                    ),
                    trailing: TextButton(
                      key: Key('claim-${g.id}'),
                      onPressed: _busy
                          ? null
                          : () => _act(
                              () =>
                                  ref.read(bookingGroupApiProvider).claim(g.id),
                            ),
                      child: const Text('認領'),
                    ),
                  ),
                ),
            ],
            orElse: () => const <Widget>[],
          ),
          ...groups.when(
            loading: () => const [LoadingView()],
            error: (error, _) => [
              ErrorRetry(
                error: ErrorBox(error: error),
                onRetry: () => ref.invalidate(myBookingGroupsProvider),
              ),
            ],
            data: (page) => page.items.isEmpty
                ? const [StatusBox(label: '【沒有報名】', detail: '您目前沒有活動報名。')]
                : [
                    for (final g in page.items)
                      Card(
                        key: Key('group-${g.id}'),
                        child: ListTile(
                          title: Text(g.eventTitle),
                          subtitle: Text(
                            '${groupStatusLabel(g)}\n'
                            '${formatDate(g.eventStartsAt)} ${g.venue}\n'
                            '${g.attendees.map((a) => a.name).join('、')}',
                          ),
                          isThreeLine: true,
                          trailing: g.canCancel
                              ? TextButton(
                                  key: Key('group-cancel-${g.id}'),
                                  onPressed: _busy ? null : () => _cancel(g),
                                  child: const Text('取消'),
                                )
                              : null,
                          onTap: () => context.push('/events/${g.eventId}'),
                        ),
                      ),
                  ],
          ),
        ],
      ),
    );
  }
}
