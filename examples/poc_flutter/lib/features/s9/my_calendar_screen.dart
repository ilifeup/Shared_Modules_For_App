import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/device/device_services.dart';
import '../../core/providers.dart';
import '../../ui/status_box.dart';
import '../calendar/calendar_providers.dart';

/// M09-D01: month grid, week and list views.
enum CalendarMode { month, week, list }

typedef MyCalendarView = ({CalendarMode mode, DateTime anchor, DateTime day});

DateTime _dayOf(DateTime t) => DateTime(t.year, t.month, t.day);

/// Monday of the week containing [day] (local).
DateTime weekStart(DateTime day) =>
    _dayOf(day).subtract(Duration(days: day.weekday - 1));

class MyCalendarViewNotifier extends Notifier<MyCalendarView> {
  @override
  MyCalendarView build() {
    final today = _dayOf(ref.watch(clockProvider)());
    return (mode: CalendarMode.month, anchor: today, day: today);
  }

  void setMode(CalendarMode mode) =>
      state = (mode: mode, anchor: state.day, day: state.day);

  void selectDay(DateTime day) =>
      state = (mode: state.mode, anchor: state.anchor, day: _dayOf(day));

  /// One month (month, list) or one week back / forward.
  void move(int delta) {
    final a = state.anchor;
    final next = state.mode == CalendarMode.week
        ? a.add(Duration(days: 7 * delta))
        : DateTime(a.year, a.month + delta);
    state = (mode: state.mode, anchor: next, day: next);
  }
}

final myCalendarViewProvider =
    NotifierProvider<MyCalendarViewNotifier, MyCalendarView>(
      MyCalendarViewNotifier.new,
    );

/// Local [from, to) range shown by the view.
(DateTime, DateTime) viewRange(MyCalendarView v) => v.mode == CalendarMode.week
    ? (weekStart(v.anchor), weekStart(v.anchor).add(const Duration(days: 7)))
    : (
        DateTime(v.anchor.year, v.anchor.month),
        DateTime(v.anchor.year, v.anchor.month + 1),
      );

final myCalendarProvider = FutureProvider.autoDispose
    .family<MyCalendar, (DateTime, DateTime)>(
      (ref, range) =>
          ref.watch(calendarApiProvider).myCalendar(range.$1, range.$2),
    );

final googleStatusProvider = FutureProvider.autoDispose<GoogleCalendarStatus>(
  (ref) => ref.watch(calendarApiProvider).googleStatus(),
);

Color? parseHexColor(String? hex) {
  if (hex == null || !RegExp(r'^#[0-9A-Fa-f]{6}$').hasMatch(hex)) return null;
  return Color(int.parse('FF${hex.substring(1)}', radix: 16));
}

/// One entry of the view (event or private entry) with its local start.
typedef _Entry = ({DateTime at, DateTime end, Widget tile});

/// S9 M09 my calendar: events with category colour and my booking status (as
/// text), VIP private entries and the Google sync status, in month, week or
/// list view.
class MyCalendarScreen extends ConsumerWidget {
  const MyCalendarScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final view = ref.watch(myCalendarViewProvider);
    final notifier = ref.read(myCalendarViewProvider.notifier);
    final range = viewRange(view);
    final data = ref.watch(myCalendarProvider(range));
    final weekEnd = range.$2.subtract(const Duration(days: 1));
    final title = view.mode == CalendarMode.week
        ? '${formatDate(range.$1)} – ${formatDate(weekEnd)}'
        : '${view.anchor.year} 年 ${view.anchor.month} 月';

    return Scaffold(
      appBar: AppBar(title: const Text('我的行事曆')),
      floatingActionButton: data.value?.vip == true
          ? FloatingActionButton.extended(
              key: const Key('private-add'),
              onPressed: () => showDialog<void>(
                context: context,
                builder: (_) => _PrivateEventDialog(day: view.day),
              ),
              label: const Text('新增私人行程'),
            )
          : null,
      body: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          SegmentedButton<CalendarMode>(
            key: const Key('my-calendar-mode'),
            segments: const [
              ButtonSegment(value: CalendarMode.month, label: Text('月')),
              ButtonSegment(value: CalendarMode.week, label: Text('週')),
              ButtonSegment(value: CalendarMode.list, label: Text('列表')),
            ],
            selected: {view.mode},
            onSelectionChanged: (s) => notifier.setMode(s.first),
          ),
          Row(
            children: [
              TextButton(
                key: const Key('my-calendar-prev'),
                onPressed: () => notifier.move(-1),
                child: Text(view.mode == CalendarMode.week ? '‹ 上週' : '‹ 上個月'),
              ),
              Expanded(
                child: Text(
                  title,
                  key: const Key('my-calendar-month'),
                  textAlign: TextAlign.center,
                ),
              ),
              TextButton(
                key: const Key('my-calendar-next'),
                onPressed: () => notifier.move(1),
                child: Text(view.mode == CalendarMode.week ? '下週 ›' : '下個月 ›'),
              ),
            ],
          ),
          const _GoogleCard(),
          ...data.when(
            loading: () => const [LoadingView()],
            error: (error, _) => [
              ErrorRetry(
                error: ErrorBox(error: error),
                onRetry: () => ref.invalidate(myCalendarProvider(range)),
              ),
            ],
            data: (cal) {
              final items = <_Entry>[
                for (final e in cal.events)
                  (
                    at: e.event.startsAt.toLocal(),
                    end: e.event.endsAt.toLocal(),
                    tile: _EventTile(item: e),
                  ),
                for (final p in cal.privateEvents)
                  (
                    at: p.startsAt.toLocal(),
                    end: p.endsAt.toLocal(),
                    tile: _PrivateTile(item: p),
                  ),
              ]..sort((a, b) => a.at.compareTo(b.at));
              return switch (view.mode) {
                CalendarMode.list =>
                  items.isEmpty
                      ? const [StatusBox(label: '【本月沒有行程】', detail: '')]
                      : items.map((i) => i.tile).toList(),
                CalendarMode.week => [
                  for (var i = 0; i < 7; i++)
                    ..._daySection(range.$1.add(Duration(days: i)), items),
                ],
                CalendarMode.month => [
                  _MonthGrid(
                    month: range.$1,
                    selected: view.day,
                    counts: {
                      for (
                        var d = range.$1;
                        d.isBefore(range.$2);
                        d = DateTime(d.year, d.month, d.day + 1)
                      )
                        d: items.where((i) => _overlaps(i, d)).length,
                    },
                    onSelect: notifier.selectDay,
                  ),
                  ..._daySection(view.day, items),
                ],
              };
            },
          ),
        ],
      ),
    );
  }
}

bool _overlaps(_Entry e, DateTime day) {
  final next = DateTime(day.year, day.month, day.day + 1);
  return e.at.isBefore(next) && e.end.isAfter(day);
}

List<Widget> _daySection(DateTime day, List<_Entry> items) {
  final mine = items.where((i) => _overlaps(i, day)).toList();
  return [
    Padding(
      padding: const EdgeInsets.only(top: 12, bottom: 4),
      child: Text(
        '${formatDate(day)}（${weekdayLabels[day.weekday - 1]}）'
        '${mine.isEmpty ? '　沒有行程' : '　${mine.length} 項'}',
        key: Key('my-day-${formatDate(day)}'),
        style: const TextStyle(fontWeight: FontWeight.bold),
      ),
    ),
    ...mine.map((i) => i.tile),
  ];
}

/// Month grid (Monday first). Days show their number and the item count as text,
/// the selected day has a thick border — never colour alone.
class _MonthGrid extends StatelessWidget {
  const _MonthGrid({
    required this.month,
    required this.selected,
    required this.counts,
    required this.onSelect,
  });

  final DateTime month;
  final DateTime selected;
  final Map<DateTime, int> counts;
  final ValueChanged<DateTime> onSelect;

  @override
  Widget build(BuildContext context) {
    final first = DateTime(month.year, month.month);
    final lead = first.weekday - 1;
    final days = DateTime(month.year, month.month + 1, 0).day;
    final cells = <Widget>[
      for (final w in weekdayLabels) Center(child: Text(w)),
      for (var i = 0; i < lead; i++) const SizedBox.shrink(),
      for (var d = 1; d <= days; d++)
        _cell(context, DateTime(month.year, month.month, d)),
    ];
    return GridView.count(
      crossAxisCount: 7,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      childAspectRatio: 1.1,
      children: cells,
    );
  }

  Widget _cell(BuildContext context, DateTime day) {
    final count = counts[day] ?? 0;
    final isSelected = day == selected;
    return InkWell(
      key: Key('my-cell-${formatDate(day)}'),
      onTap: () => onSelect(day),
      child: Container(
        margin: const EdgeInsets.all(1),
        decoration: BoxDecoration(
          border: Border.all(width: isSelected ? 3 : 1),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text('${day.day}'),
            if (count > 0)
              Text('$count 項', style: const TextStyle(fontSize: 11)),
          ],
        ),
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
  const _PrivateTile({required this.item});

  final PrivateEvent item;

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
        // M09-D14: after VIP ends private entries are read-only (no delete either).
        trailing: !item.editable
            ? const Text('唯讀')
            : IconButton(
                tooltip: '刪除',
                icon: const Icon(Icons.delete_outline),
                onPressed: () async {
                  await ref.read(calendarApiProvider).deletePrivate(item.id);
                  ref.invalidate(myCalendarProvider);
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
  const _PrivateEventDialog({required this.day});

  final DateTime day;

  @override
  ConsumerState<_PrivateEventDialog> createState() =>
      _PrivateEventDialogState();
}

class _PrivateEventDialogState extends ConsumerState<_PrivateEventDialog> {
  final _title = TextEditingController();
  late DateTime _day = DateTime(
    widget.day.year,
    widget.day.month,
    widget.day.day,
    9,
  );
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
      ref.invalidate(myCalendarProvider);
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
