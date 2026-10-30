import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_exception.dart';
import '../../core/providers.dart';
import '../auth/auth_controller.dart';
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

final manageableFamilyMembersProvider =
    FutureProvider.autoDispose<List<ManageableFamilyMember>>(
      (ref) => ref.watch(bookingGroupApiProvider).manageableMembers(),
    );

/// Persist unresolved booking attempts before sending HTTP, so a lost response
/// followed by an app restart reuses the exact same idempotency key and member.
/// Storage is scoped to the authenticated member and event.
class PendingBookingRequest {
  const PendingBookingRequest(this.requestId, this.memberUserId);
  final String requestId;
  final int? memberUserId;
}

/// A restored request owns its original member, including null for self.
int? bookingMemberForRetry(PendingBookingRequest? saved, int? selected) =>
    saved == null ? selected : saved.memberUserId;

class PendingBookingRequests
    extends Notifier<Map<String, PendingBookingRequest>> {
  FlutterSecureStorage get _storage => ref.read(pendingBookingStorageProvider);
  final Set<String> _loaded = {};
  final Set<String> _loading = {};

  @override
  Map<String, PendingBookingRequest> build() {
    ref.listen(authControllerProvider, (previous, next) {
      // A transient offline restore is not an explicit logout. Keep persisted
      // requests so the next successful login can recover the original key.
      if (previous?.status == AuthStatus.signedIn &&
          (next.status == AuthStatus.signedOut ||
              (next.status == AuthStatus.signedIn &&
                  previous?.profile?.id != next.profile?.id))) {
        state = {};
        _loaded.clear();
      }
    });
    return {};
  }

  String _key(String scope) => 'pending_booking_v1:$scope';

  Future<PendingBookingRequest?> load(String scope) async {
    if (_loaded.contains(scope)) return state[scope];
    if (!_loading.add(scope)) {
      // Never generate a second key while another restore is in progress.
      throw StateError('預約資料正在還原，請稍後重試。');
    }
    try {
      final raw = await _storage.read(key: _key(scope));
      PendingBookingRequest? restored;
      // Recheck the session before publishing any persisted request.
      final authBefore = ref.read(authControllerProvider);
      if (authBefore.status != AuthStatus.signedIn ||
          authBefore.profile == null ||
          !scope.startsWith(
            '${Uri.encodeComponent(authBefore.profile!.id)}:',
          )) {
        throw StateError('登入會員已變更，請重新開啟預約畫面。');
      }
      if (raw != null) {
        final parts = raw.split('|');
        if (parts.length == 2 &&
            RegExp(
              r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89aAbB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
            ).hasMatch(parts[0])) {
          final member = parts[1] == 'self' ? null : int.tryParse(parts[1]);
          if (parts[1] == 'self' || (member != null && member > 0)) {
            restored = PendingBookingRequest(parts[0], member);
          } else {
            throw const FormatException('已保存的預約對象資料異常，停止重新送出以避免重複報名。');
          }
        } else {
          throw const FormatException('已保存的預約識別碼資料異常，停止重新送出以避免重複報名。');
        }
      }
      // The account may have signed out while secure storage was reading.
      // Never publish a restored request into another account's session.
      final auth = ref.read(authControllerProvider);
      if (auth.status != AuthStatus.signedIn ||
          auth.profile == null ||
          !scope.startsWith('${Uri.encodeComponent(auth.profile!.id)}:')) {
        state = {...state}..remove(scope);
        throw StateError('登入會員已變更，請重新開啟預約畫面。');
      }
      if (restored != null) state = {...state, scope: restored};
      _loaded.add(scope);
      return restored;
    } finally {
      _loading.remove(scope);
    }
  }

  Future<void> save(String scope, PendingBookingRequest request) async {
    // Durable write must finish before the booking HTTP request is sent.
    await _storage.write(
      key: _key(scope),
      value: '${request.requestId}|${request.memberUserId ?? 'self'}',
    );
    _loaded.add(scope);
    state = {...state, scope: request};
  }

  Future<void> clear(String scope) async {
    await _storage.delete(key: _key(scope));
    state = {...state}..remove(scope);
    _loaded.add(scope);
  }

  void clearMemory() {
    state = {};
    _loaded.clear();
  }
}

final pendingBookingStorageProvider = Provider<FlutterSecureStorage>(
  (ref) => const FlutterSecureStorage(),
);

final pendingBookingRequestsProvider =
    NotifierProvider<
      PendingBookingRequests,
      Map<String, PendingBookingRequest>
    >(PendingBookingRequests.new);

String bookingRequestScope(String memberId, String eventId) =>
    '${Uri.encodeComponent(memberId)}:${Uri.encodeComponent(eventId)}';

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
  bool _busy = false;
  String? _restoredScope;
  String? _restoringScope;
  Object? _restoreError;
  String? _restoreErrorScope;
  String? _selectionScope;
  Object? _error;
  BookingGroup? _result;
  String? _resultScope;
  String? get _requestScope {
    final auth = ref.read(authControllerProvider);
    if (auth.status != AuthStatus.signedIn || auth.profile == null) return null;
    return bookingRequestScope(auth.profile!.id, widget.eventId);
  }

  // Restore durable attempts before allowing the member to change selection.
  // A restored request always keeps its original member and idempotency key.
  Future<void> _restorePending(String scope) async {
    try {
      await ref.read(pendingBookingRequestsProvider.notifier).load(scope);
      if (mounted && _requestScope == scope) {
        setState(() {
          _restoredScope = scope;
          _restoreError = null;
          _restoreErrorScope = null;
        });
      }
    } catch (error) {
      if (mounted && _requestScope == scope) {
        setState(() {
          _restoreError = error;
          _restoreErrorScope = scope;
        });
      }
    } finally {
      if (mounted && _restoringScope == scope) {
        setState(() => _restoringScope = null);
      }
    }
  }

  Future<void> _submit() async {
    final scope = _requestScope;
    if (scope == null) {
      setState(() => _error = '請先登入會員帳號。');
      return;
    }
    if (_busy ||
        _restoringScope == scope ||
        _restoredScope != scope ||
        _restoreErrorScope == scope) {
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final pendingStore = ref.read(pendingBookingRequestsProvider.notifier);
    PendingBookingRequest? saved;
    try {
      saved = await pendingStore.load(scope);
    } catch (error) {
      if (mounted)
        setState(() {
          _busy = false;
          _error = error;
        });
      return;
    }
    if (!mounted || _requestScope != scope) {
      if (mounted) setState(() => _busy = false);
      return;
    }
    final selected = bookingMemberForRetry(saved, _memberUserId);
    final requestId = saved?.requestId ?? newRequestId();
    if (saved == null && selected != null) {
      final allowed = ref.read(manageableFamilyMembersProvider).value;
      if (allowed == null || !allowed.any((member) => member.id == selected)) {
        setState(() {
          _busy = false;
          _error = '請選擇已授權的家庭會員。';
        });
        return;
      }
    }
    try {
      if (saved == null) {
        await pendingStore.save(
          scope,
          PendingBookingRequest(requestId, selected),
        );
      }
    } catch (error) {
      if (mounted)
        setState(() {
          _busy = false;
          _error = error;
        });
      return;
    }
    if (!mounted || _requestScope != scope) {
      if (mounted) setState(() => _busy = false);
      return;
    }
    final api = ref.read(bookingGroupApiProvider);
    try {
      final booking = await api.book(
        widget.eventId,
        clientRequestId: requestId,
        memberUserId: selected,
      );
      if (!mounted || _requestScope != scope) return;
      // Booking succeeded: do not reinterpret a local storage cleanup error
      // as an HTTP failure. The server already accepted the request.
      Object? cleanupError;
      try {
        await _clearPending(scope);
      } catch (error) {
        cleanupError = error;
      }
      if (!mounted || _requestScope != scope) return;
      setState(() {
        _error = cleanupError == null ? null : '報名已成功，但本機重試資料清理失敗。';
        _result = booking;
        _resultScope = scope;
      });
      ref.invalidate(myBookingGroupsProvider);
    } catch (error) {
      if (!mounted || _requestScope != scope) return;
      if (error is ApiException &&
          error.statusCode != null &&
          error.statusCode! >= 400 &&
          error.statusCode! < 500 &&
          error.statusCode != 408 &&
          error.statusCode != 429) {
        try {
          await _clearPending(scope);
        } catch (_) {
          // Keep the original API error; cleanup will be retried later.
        }
      }
      if (mounted && _requestScope == scope) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _clearPending(String scope) =>
      ref.read(pendingBookingRequestsProvider.notifier).clear(scope);

  @override
  Widget build(BuildContext context) {
    final event = ref.watch(eventDetailProvider(widget.eventId));
    final family = ref.watch(manageableFamilyMembersProvider);
    final availableMembers = family.value;
    final auth = ref.watch(authControllerProvider);
    final scope = auth.status == AuthStatus.signedIn && auth.profile != null
        ? bookingRequestScope(auth.profile!.id, widget.eventId)
        : null;
    if (_selectionScope != scope) {
      _selectionScope = scope;
      _memberUserId = null;
      _restoredScope = null;
      _restoreErrorScope = null;
      _restoreError = null;
    }
    final result = scope != null && scope == _resultScope ? _result : null;
    final pending = scope == null
        ? null
        : ref.watch(pendingBookingRequestsProvider)[scope];
    if (scope != null &&
        _restoredScope != scope &&
        _restoringScope != scope &&
        _restoreErrorScope != scope) {
      _restoringScope = scope;
      Future.microtask(() => _restorePending(scope));
    }
    final restoring = scope != null && _restoredScope != scope;

    return Scaffold(
      appBar: AppBar(title: const Text('單人報名／候補')),
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
              detail: result.attendees.map((a) => a.name).join('、'),
            ),
            TextButton(
              onPressed: () => context.push('/me/booking-groups'),
              child: const Text('查看我的報名'),
            ),
          ] else ...[
            DropdownButtonFormField<int?>(
              key: ValueKey('booking-member:${scope ?? 'signed-out'}:${pending?.requestId ?? 'new'}'),
              initialValue: availableMembers == null ||
                      !(availableMembers.any((m) => m.id == (bookingMemberForRetry(pending, _memberUserId))))
                  ? null
                  : bookingMemberForRetry(pending, _memberUserId),
              items: [
                const DropdownMenuItem<int?>(value: null, child: Text('我本人')),
                for (final member in availableMembers ?? <ManageableFamilyMember>[])
                  DropdownMenuItem<int?>(
                    value: member.id,
                    child: Text('${member.name}（ID ${member.id}）'),
                  ),
              ],
              onChanged: _busy || restoring || pending != null || availableMembers == null
                  ? null
                  : (id) => setState(() => _memberUserId = id),
              decoration: const InputDecoration(
                labelText: '本次參加會員（僅一位）',
                border: OutlineInputBorder(),
              ),
            ),
            if (restoring)
              Text(_restoreErrorScope != scope
                  ? '正在還原上次報名資料…'
                  : '上次報名資料無法還原，已停止送出以避免重複報名：$_restoreError'),
            if (pending != null)
              const Text('偵測到未確認的報名請求；重試將沿用原參加會員及識別碼。'),
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
              onPressed: _busy || restoring || scope == null || availableMembers == null
                  ? null
                  : _submit,
              child: Text(_busy ? '處理中…' : restoring ? '還原報名資料中…' : '送出報名／重試'),
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
      if (mounted) {
        ref.invalidate(myBookingGroupsProvider);
        setState(() => _busy = false);
      }
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
    if (ok != true || !mounted) return;
    final api = ref.read(bookingGroupApiProvider);
    await _act(() => api.cancel(g.id));
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

