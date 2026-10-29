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

final manageableFamilyMembersProvider = FutureProvider.autoDispose<List<ManageableFamilyMember>>(
  (ref) => ref.watch(bookingGroupApiProvider).manageableMembers(),
);

/// Text for a group status (shown with words, not colour only).
String groupStatusLabel(BookingGroup g) => switch (g.status) {
  'confirmed' => '【正式報名】',
  'waitlisted' => '【候補中】第 ${g.waitlistPosition ?? '-'} 順位',
  'event_cancelled' => '【活動已取消】',
  _ => '【已取消】',
};

/// S9 M08: one verified member per booking, self or one delegated family member.
class GroupBookingScreen extends ConsumerStatefulWidget {
  const GroupBookingScreen({super.key, required this.eventId});
  final String eventId;

  @override
  ConsumerState<GroupBookingScreen> createState() => _GroupBookingScreenState();
}

class _GroupBookingScreenState extends ConsumerState<GroupBookingScreen> {
  int? _memberUserId;
  int? _pendingMemberUserId;
  bool _hasPendingRequest = false;
  bool _busy = false;
  Object? _error;
  BookingGroup? _result;
  String _requestId = newRequestId();

  Future<void> _submit() async {
    if (_busy) return;
    final selected = _hasPendingRequest ? _pendingMemberUserId : _memberUserId;
    if (!_hasPendingRequest && selected != null) {
      final allowed = ref.read(manageableFamilyMembersProvider).value;
      if (allowed == null || !allowed.any((member) => member.id == selected)) {
        setState(() => _error = '請選擇已授權的家庭會員。');
        return;
      }
    }
    setState(() {
      _busy = true;
      _error = null;
      _pendingMemberUserId = selected;
      _hasPendingRequest = true;
    });
    try {
      final booking = await ref.read(bookingGroupApiProvider).book(
        widget.eventId,
        clientRequestId: _requestId,
        memberUserId: selected,
      );
      if (!mounted) return;
      setState(() {
        _result = booking;
        _pendingMemberUserId = null;
        _hasPendingRequest = false;
        _requestId = newRequestId();
      });
      ref.invalidate(myBookingGroupsProvider);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        if (error is ApiException &&
            error.statusCode != null &&
            error.statusCode! >= 400 &&
            error.statusCode! < 500 &&
            error.statusCode != 408 &&
            error.statusCode != 429) {
          _pendingMemberUserId = null;
          _hasPendingRequest = false;
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
    final family = ref.watch(manageableFamilyMembersProvider);
    final availableMembers = family.value;
    final result = _result;

    return Scaffold(
      appBar: AppBar(title: const Text('單人報名／候補')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (event.value != null)
            Text(event.value!.title, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 8),
          if (result != null) ...[
            StatusBox(
              key: const Key('group-result'),
              label: groupStatusLabel(result),
              detail: result.attendees.map((a) => a.name).join('、'),
            ),
            TextButton(
              onPressed: () => context.push('/me/booking-groups'),
              child: const Text('查看我的報名'),
            ),
          ] else ...[
            DropdownButtonFormField<int?>(
              key: const Key('booking-member'),
              initialValue: _memberUserId,
              items: [
                const DropdownMenuItem<int?>(value: null, child: Text('我本人')),
                for (final member in availableMembers ?? <ManageableFamilyMember>[])
                  DropdownMenuItem<int?>(
                    value: member.id,
                    child: Text('${member.name}（ID ${member.id}）'),
                  ),
              ],
              onChanged: _busy || _hasPendingRequest || availableMembers == null
                  ? null
                  : (id) => setState(() => _memberUserId = id),
              decoration: const InputDecoration(
                labelText: '本次參加會員（僅一位）',
                border: OutlineInputBorder(),
              ),
            ),
            if (family.hasError)
              ErrorRetry(
                error: ErrorBox(error: family.error!),
                onRetry: () => ref.invalidate(manageableFamilyMembersProvider),
              ),
            const SizedBox(height: 8),
            const Text('每筆預約一位正式會員；名額已滿且開放候補時依順序等候。'),
            const SizedBox(height: 8),
            if (_error is String)
              StatusBox(label: '【提醒】', detail: _error as String)
            else if (_error != null)
              ErrorBox(error: _error!),
            FilledButton(
              key: const Key('group-submit'),
              onPressed: _busy ? null : _submit,
              child: Text(_busy ? '處理中…' : '送出報名／重試'),
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
        title: Text(g.isWaitlisted ? '取消候補？' : '取消報名？'),
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
