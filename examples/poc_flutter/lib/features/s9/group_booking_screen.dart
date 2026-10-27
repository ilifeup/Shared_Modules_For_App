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

final manageableFamilyMemberIdsProvider = FutureProvider.autoDispose<List<int>>(
  (ref) => ref.watch(bookingGroupApiProvider).manageableMemberIds(),
);

/// Text for a group status (shown with words, not colour only).
String groupStatusLabel(BookingGroup g) => switch (g.status) {
  'confirmed' => '【正式報名】',
  'waitlisted' => '【候補中】第 ${g.waitlistPosition ?? '-'} 順位',
  'event_cancelled' => '【活動已取消】',
  _ => '【已取消】',
};

/// S9 M08 group booking: the member and/or authorized family members, booked or
/// waitlisted together. One request id per attempt, reused on retry, so a
/// double tap or a network retry never books twice.
class GroupBookingScreen extends ConsumerStatefulWidget {
  const GroupBookingScreen({super.key, required this.eventId});

  final String eventId;

  @override
  ConsumerState<GroupBookingScreen> createState() => _GroupBookingScreenState();
}

class _GroupBookingScreenState extends ConsumerState<GroupBookingScreen> {
  final _memberIds = <int?>[];
  bool _includeSelf = true;
  bool _busy = false;
  Object? _error;
  BookingGroup? _result;
  String _requestId = newRequestId();
  // Keep the exact original payload for retries whose server outcome is unknown.
  List<int>? _pendingMemberIds;
  bool? _pendingIncludeSelf;

  Future<void> _submit() async {
    if (_busy) return;
    final memberIds = _pendingMemberIds ?? _memberIds.whereType<int>().toList();
    final includeSelf = _pendingIncludeSelf ?? _includeSelf;
    if (_pendingMemberIds == null) {
      final allowed = ref.read(manageableFamilyMemberIdsProvider).value;
      if (_memberIds.any((id) => id == null) ||
          memberIds.toSet().length != memberIds.length ||
          (memberIds.isNotEmpty &&
              (allowed == null ||
                  memberIds.any((id) => !allowed.contains(id))))) {
        setState(() => _error = '請選擇不同且已授權的家庭會員。');
        return;
      }
    }
    if (!includeSelf && memberIds.isEmpty) {
      setState(() => _error = '請至少選擇一位會員參加。');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _pendingMemberIds ??= List<int>.unmodifiable(memberIds);
      _pendingIncludeSelf ??= includeSelf;
    });
    try {
      final group = await ref
          .read(bookingGroupApiProvider)
          .book(
            widget.eventId,
            clientRequestId: _requestId,
            includeSelf: _pendingIncludeSelf!,
            attendeeMemberIds: _pendingMemberIds!,
          );
      if (!mounted) return;
      setState(() {
        _result = group;
        _pendingMemberIds = null;
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
          _pendingMemberIds = null;
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
    final family = ref.watch(manageableFamilyMemberIdsProvider);
    final availableIds = family.value;
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
              onChanged: _busy || _pendingMemberIds != null
                  ? null
                  : (v) => setState(() => _includeSelf = v),
            ),
            if (family.hasError)
              ErrorRetry(
                error: ErrorBox(error: family.error!),
                onRetry: () =>
                    ref.invalidate(manageableFamilyMemberIdsProvider),
              ),
            if (availableIds != null && availableIds.isEmpty)
              const Text('目前沒有已授權可代訂的家庭會員。'),
            for (var i = 0; i < _memberIds.length; i++)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Row(
                  children: [
                    Expanded(
                      child: DropdownButtonFormField<int>(
                        key: Key('group-member-$i'),
                        value: _memberIds[i],
                        items: [
                          for (final id in availableIds ?? <int>[])
                            DropdownMenuItem<int>(
                              value: id,
                              child: Text('會員 ID $id'),
                            ),
                        ],
                        onChanged:
                            _busy ||
                                _pendingMemberIds != null ||
                                availableIds == null
                            ? null
                            : (id) => setState(() => _memberIds[i] = id),
                        decoration: InputDecoration(
                          labelText: '同行會員 ${i + 1}（已授權）',
                          border: const OutlineInputBorder(),
                        ),
                      ),
                    ),
                    IconButton(
                      key: Key('group-remove-member-$i'),
                      tooltip: '移除同行會員',
                      onPressed: _busy || _pendingMemberIds != null
                          ? null
                          : () => setState(() => _memberIds.removeAt(i)),
                      icon: const Icon(Icons.remove_circle_outline),
                    ),
                  ],
                ),
              ),
            OutlinedButton(
              key: const Key('group-add-member'),
              onPressed:
                  _busy ||
                      _pendingMemberIds != null ||
                      availableIds == null ||
                      _memberIds.length >= availableIds.length ||
                      _memberIds.length >= 9
                  ? null
                  : () => setState(() => _memberIds.add(null)),
              child: const Text('新增已授權家庭會員'),
            ),
            const SizedBox(height: 8),
            const Text('名額不足時整組進入候補，依順序遞補；遞補成功會通知您。'),
            const SizedBox(height: 8),
            if (_pendingMemberIds != null && !_busy)
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
                    : _pendingMemberIds != null
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

/// My S9 member booking groups.
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

    return Scaffold(
      appBar: AppBar(title: const Text('我的報名')),
      body: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          if (_error != null) ErrorBox(error: _error!),
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
