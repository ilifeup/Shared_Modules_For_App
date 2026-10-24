import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers.dart';
import '../../ui/status_box.dart';
import 'calendar_providers.dart';

/// S5 M09: month grid (Monday first), selected day and that day's events.
/// Days are marked with text (event count, 今, selected border) — not color only.
class CalendarScreen extends ConsumerWidget {
  const CalendarScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final selection = ref.watch(calendarSelectionProvider);
    final notifier = ref.read(calendarSelectionProvider.notifier);
    final events = ref.watch(monthEventsProvider(selection.month));
    final month = selection.month;

    return Scaffold(
      appBar: AppBar(
        title: const Text('活動行事曆'),
        actions: [
          TextButton(
            key: const Key('open-my-bookings'),
            onPressed: () => context.push('/me/bookings'),
            child: const Text('我的預約'),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          Row(
            children: [
              TextButton(
                key: const Key('calendar-prev'),
                onPressed: () => notifier.moveMonth(-1),
                child: const Text('‹ 上個月'),
              ),
              Expanded(
                child: Text(
                  '${month.year} 年 ${month.month} 月',
                  key: const Key('calendar-month'),
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              TextButton(
                key: const Key('calendar-next'),
                onPressed: () => notifier.moveMonth(1),
                child: const Text('下個月 ›'),
              ),
            ],
          ),
          _MonthGrid(
            month: month,
            selected: selection.day,
            today: ref.watch(clockProvider)(),
            events: events.value,
            onSelect: notifier.selectDay,
          ),
          const SizedBox(height: 12),
          Text(
            '${formatDate(selection.day)}（'
            '${weekdayLabels[selection.day.weekday - 1]}）的活動',
            key: const Key('calendar-selected-day'),
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 8),
          ...events.when<List<Widget>>(
            loading: () => const [Center(child: Text('【載入中】'))],
            error: (error, _) => [
              ErrorBox(error: error),
              OutlinedButton(
                key: const Key('calendar-retry'),
                onPressed: () => ref.invalidate(monthEventsProvider(month)),
                child: const Text('重試'),
              ),
            ],
            data: (range) {
              final items = range.on(selection.day);
              return [
                if (range.truncated)
                  const StatusBox(
                    label: '【部分顯示】',
                    detail: '本月活動過多，僅顯示前 200 筆。',
                  ),
                if (items.isEmpty)
                  const StatusBox(label: '【這天沒有活動】', detail: '請選擇其他日期。'),
                for (final event in items) _EventTile(event: event),
              ];
            },
          ),
        ],
      ),
    );
  }
}

class _EventTile extends StatelessWidget {
  const _EventTile({required this.event});

  final CalendarEvent event;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(border: Border.all()),
      child: ListTile(
        key: Key('event-${event.id}'),
        title: Text(
          '${event.isCancelled ? '【已取消】' : ''}${event.title}',
          style: event.isCancelled
              ? const TextStyle(decoration: TextDecoration.lineThrough)
              : null,
        ),
        subtitle: Text(
          [
            formatEventTime(event),
            if (event.location != null) event.location!,
          ].join('　'),
        ),
        trailing: const Icon(Icons.chevron_right),
        onTap: () => context.push('/events/${event.id}'),
      ),
    );
  }
}

class _MonthGrid extends StatelessWidget {
  const _MonthGrid({
    required this.month,
    required this.selected,
    required this.today,
    required this.events,
    required this.onSelect,
  });

  final DateTime month;
  final DateTime selected;
  final DateTime today;
  final EventRange? events;
  final void Function(DateTime day) onSelect;

  bool _same(DateTime a, DateTime b) =>
      a.year == b.year && a.month == b.month && a.day == b.day;

  @override
  Widget build(BuildContext context) {
    final daysInMonth = DateTime(month.year, month.month + 1, 0).day;
    final leading = DateTime(month.year, month.month).weekday - 1;
    final cells = <Widget>[
      for (final label in weekdayLabels)
        Center(
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Text(label),
          ),
        ),
      for (var i = 0; i < leading; i++) const SizedBox.shrink(),
      for (var d = 1; d <= daysInMonth; d++)
        _cell(DateTime(month.year, month.month, d)),
    ];

    return GridView(
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 7,
        mainAxisExtent: 52,
      ),
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      children: cells,
    );
  }

  Widget _cell(DateTime day) {
    final count = events?.on(day).length ?? 0;
    final isSelected = _same(day, selected);
    final isToday = _same(day, today);
    return InkWell(
      key: Key('calendar-day-${day.day}'),
      onTap: () => onSelect(day),
      child: Container(
        margin: const EdgeInsets.all(2),
        decoration: BoxDecoration(
          border: Border.all(width: isSelected ? 2.5 : 0.5),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(
              '${day.day}',
              style: TextStyle(
                fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
              ),
            ),
            Text(
              [if (isToday) '今', if (count > 0) '•$count'].join(' '),
              style: const TextStyle(fontSize: 11),
            ),
          ],
        ),
      ),
    );
  }
}
