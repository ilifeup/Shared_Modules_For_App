import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';

/// The displayed month (local, first day) and the selected local day.
class CalendarSelection {
  const CalendarSelection({required this.month, required this.day});

  final DateTime month;
  final DateTime day;
}

final calendarSelectionProvider =
    NotifierProvider<CalendarSelectionNotifier, CalendarSelection>(
      CalendarSelectionNotifier.new,
    );

class CalendarSelectionNotifier extends Notifier<CalendarSelection> {
  @override
  CalendarSelection build() {
    final now = ref.read(clockProvider)();
    return CalendarSelection(
      month: DateTime(now.year, now.month),
      day: DateTime(now.year, now.month, now.day),
    );
  }

  /// Moves by whole months (crosses years); selects the 1st of the new month.
  void moveMonth(int delta) {
    final month = DateTime(state.month.year, state.month.month + delta);
    state = CalendarSelection(month: month, day: month);
  }

  void selectDay(DateTime day) {
    state = CalendarSelection(
      month: state.month,
      day: DateTime(day.year, day.month, day.day),
    );
  }
}

/// Member events of one local month (one request per month).
final monthEventsProvider = FutureProvider.autoDispose
    .family<EventRange, DateTime>((ref, month) {
      final range = localMonthRange(month);
      return ref.watch(eventApiProvider).memberEvents(range.from, range.to);
    });

final eventDetailProvider = FutureProvider.autoDispose
    .family<CalendarEvent, String>(
      (ref, id) => ref.watch(eventApiProvider).memberEvent(id),
    );

String _two(int n) => n.toString().padLeft(2, '0');

String formatTime(DateTime value) {
  final local = value.toLocal();
  return '${_two(local.hour)}:${_two(local.minute)}';
}

String formatDate(DateTime value) {
  final local = value.toLocal();
  return '${local.year}-${_two(local.month)}-${_two(local.day)}';
}

const weekdayLabels = ['一', '二', '三', '四', '五', '六', '日'];

/// "14:00–16:00", or with dates when the event spans several local days.
String formatEventTime(CalendarEvent event) {
  final start = event.startsAt.toLocal();
  final end = event.endsAt.toLocal();
  if (formatDate(start) == formatDate(end)) {
    return '${formatTime(start)}–${formatTime(end)}';
  }
  final from = '${formatDate(start)} ${formatTime(start)}';
  return '$from–${formatDate(end)} ${formatTime(end)}';
}
