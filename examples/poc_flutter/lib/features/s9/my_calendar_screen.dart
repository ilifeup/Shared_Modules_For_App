import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/device/device_services.dart';
import '../../core/providers.dart';
import '../../ui/status_box.dart';
import '../calendar/calendar_providers.dart';

/// First day (local) of the month shown in "my calendar".
class MyCalendarMonth extends Notifier<DateTime> {
  @override
  DateTime build() {
    final now = ref.watch(clockProvider)();
    return DateTime(now.year, now.month);
  }

  void move(int delta) => state = DateTime(state.year, state.month + delta);
}

final myCalendarMonthProvider = NotifierProvider<MyCalendarMonth, DateTime>(
  MyCalendarMonth.new,
);

final myCalendarProvider = FutureProvider.autoDispose
    .family<MyCalendar, DateTime>(
      (ref, month) => ref
          .watch(calendarApiProvider)
          .myCalendar(month, DateTime(month.year, month.month + 1)),
    );

final googleStatusProvider = FutureProvider.autoDispose<GoogleCalendarStatus>(
  (ref) => ref.watch(calendarApiProvider).googleStatus(),
);

Color? parseHexColor(String? hex) {
  if (hex == null || !RegExp(r'^#[0-9A-Fa-f]{6}$').hasMatch(hex)) return null;
  return Color(int.parse('FF${hex.substring(1)}', radix: 16));
}

/// S9 M09 list view of my month: events with category colour and my booking
/// status (as text), VIP private entries, and the Google sync status.
class MyCalendarScreen extends ConsumerWidget {
  const MyCalendarScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final month = ref.watch(myCalendarMonthProvider);
    final data = ref.watch(myCalendarProvider(month));

    return Scaffold(
      appBar: AppBar(title: const Text('我的行事曆')),
      floatingActionButton: data.value?.vip == true
          ? FloatingActionButton.extended(
              key: const Key('private-add'),
              onPressed: () => showDialog<void>(
                context: context,
                builder: (_) => _PrivateEventDialog(month: month),
              ),
              label: const Text('新增私人行程'),
            )
          : null,
      body: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          Row(
            children: [
              TextButton(
                key: const Key('my-calendar-prev'),
                onPressed: () =>
                    ref.read(myCalendarMonthProvider.notifier).move(-1),
                child: const Text('‹ 上個月'),
              ),
              Expanded(
                child: Text(
                  '${month.year} 年 ${month.month} 月',
                  key: const Key('my-calendar-month'),
                  textAlign: TextAlign.center,
                ),
              ),
              TextButton(
                key: const Key('my-calendar-next'),
                onPressed: () =>
                    ref.read(myCalendarMonthProvider.notifier).move(1),
                child: const Text('下個月 ›'),
              ),
            ],
          ),
          const _GoogleCard(),
          ...data.when(
            loading: () => const [LoadingView()],
            error: (error, _) => [
              ErrorRetry(
                error: ErrorBox(error: error),
                onRetry: () => ref.invalidate(myCalendarProvider(month)),
              ),
            ],
            data: (cal) {
              final items = <({DateTime at, Widget tile})>[
                for (final e in cal.events)
                  (at: e.event.startsAt, tile: _EventTile(item: e)),
                for (final p in cal.privateEvents)
                  (at: p.startsAt, tile: _PrivateTile(item: p, month: month)),
              ]..sort((a, b) => a.at.compareTo(b.at));
              return items.isEmpty
                  ? const [StatusBox(label: '【本月沒有行程】', detail: '')]
                  : items.map((i) => i.tile).toList();
            },
          ),
        ],
      ),
    );
  }
}

class _EventTile extends StatelessWidget {
  const _EventTile({required this.item});

  final MyCalendarEvent item;

  @override
  Widget build(BuildContext context) {
    final e = item.event;
    final color = parseHexColor(item.category?.color);
    final status = switch (item.myBookingStatus) {
      'confirmed' => '【已報名】',
      'waitlisted' => '【候補中】',
      _ => '',
    };
    return Card(
      child: ListTile(
        key: Key('my-event-${e.id}'),
        leading: Container(width: 6, color: color ?? Colors.grey),
        title: Text('$status${e.isCancelled ? '【已取消】' : ''}${e.title}'),
        subtitle: Text(
          '${formatDate(e.startsAt)} ${formatEventTime(e)}'
          '${item.category != null ? '・${item.category!.name}' : ''}'
          '${[e.location, item.floor, item.studio].whereType<String>().isEmpty ? '' : '\n${[e.location, item.floor, item.studio].whereType<String>().join(' ')}'}',
        ),
        onTap: () => context.push('/events/${e.id}'),
      ),
    );
  }
}

class _PrivateTile extends ConsumerWidget {
  const _PrivateTile({required this.item, required this.month});

  final PrivateEvent item;
  final DateTime month;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Card(
      child: ListTile(
        key: Key('private-${item.id}-${item.startsAt.toIso8601String()}'),
        leading: const Icon(Icons.lock_outline),
        title: Text('【私人】${item.title}'),
        subtitle: Text(
          '${formatDate(item.startsAt.toLocal())} '
          '${formatTime(item.startsAt.toLocal())}'
          '${item.recurrence == 'weekly'
              ? '・每週'
              : item.recurrence == 'monthly'
              ? '・每月'
              : ''}',
        ),
        trailing: IconButton(
          tooltip: '刪除',
          icon: const Icon(Icons.delete_outline),
          onPressed: () async {
            await ref.read(calendarApiProvider).deletePrivate(item.id);
            ref.invalidate(myCalendarProvider(month));
          },
        ),
      ),
    );
  }
}

class _GoogleCard extends ConsumerWidget {
  const _GoogleCard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final status = ref.watch(googleStatusProvider);
    return status.maybeWhen(
      data: (s) {
        if (!s.configured) {
          return const StatusBox(
            key: Key('google-not-configured'),
            label: '【Google 日曆】',
            detail: '此 App 尚未設定 Google 同步。',
          );
        }
        return Card(
          child: ListTile(
            key: const Key('google-card'),
            title: Text(s.connected ? '【已連結 Google 日曆】' : '【未連結 Google 日曆】'),
            subtitle: Text(
              s.connected
                  ? '單向同步我的報名與私人行程。'
                        '${s.failedItems > 0 ? '\n有 ${s.failedItems} 筆同步失敗，系統會重試。' : ''}'
                  : '連結後，報名的活動會自動加入 Google 日曆。',
            ),
            trailing: TextButton(
              key: const Key('google-toggle'),
              onPressed: () async {
                final api = ref.read(calendarApiProvider);
                if (s.connected) {
                  await api.googleDisconnect();
                } else {
                  final url = await api.googleConnect();
                  await ref.read(openUrlProvider)(Uri.parse(url));
                }
                ref.invalidate(googleStatusProvider);
              },
              child: Text(s.connected ? '停止同步' : '連結'),
            ),
          ),
        );
      },
      orElse: () => const SizedBox.shrink(),
    );
  }
}

class _PrivateEventDialog extends ConsumerStatefulWidget {
  const _PrivateEventDialog({required this.month});

  final DateTime month;

  @override
  ConsumerState<_PrivateEventDialog> createState() =>
      _PrivateEventDialogState();
}

class _PrivateEventDialogState extends ConsumerState<_PrivateEventDialog> {
  final _title = TextEditingController();
  late DateTime _day = DateTime(widget.month.year, widget.month.month, 1, 9);
  String? _recurrence;
  Object? _error;
  bool _busy = false;

  @override
  void dispose() {
    _title.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(calendarApiProvider)
          .createPrivate(
            title: _title.text.trim(),
            startsAt: _day,
            endsAt: _day.add(const Duration(hours: 1)),
            recurrence: _recurrence,
          );
      ref.invalidate(myCalendarProvider(widget.month));
      if (mounted) Navigator.of(context).pop();
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('新增私人行程'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          TextField(
            key: const Key('private-title'),
            controller: _title,
            decoration: const InputDecoration(labelText: '標題'),
          ),
          TextButton(
            key: const Key('private-day'),
            onPressed: () async {
              final picked = await showDatePicker(
                context: context,
                initialDate: _day,
                firstDate: DateTime(_day.year - 1),
                lastDate: DateTime(_day.year + 2),
              );
              if (picked != null) {
                setState(
                  () =>
                      _day = DateTime(picked.year, picked.month, picked.day, 9),
                );
              }
            },
            child: Text('日期：${formatDate(_day)} 09:00（1 小時）'),
          ),
          DropdownButton<String?>(
            key: const Key('private-recurrence'),
            value: _recurrence,
            items: const [
              DropdownMenuItem(value: null, child: Text('不重複')),
              DropdownMenuItem(value: 'weekly', child: Text('每週')),
              DropdownMenuItem(value: 'monthly', child: Text('每月')),
            ],
            onChanged: (v) => setState(() => _recurrence = v),
          ),
          if (_error != null) ErrorBox(error: _error!),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('取消'),
        ),
        FilledButton(
          key: const Key('private-save'),
          onPressed: _busy ? null : _save,
          child: const Text('儲存'),
        ),
      ],
    );
  }
}
