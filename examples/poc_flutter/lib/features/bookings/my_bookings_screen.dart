import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../ui/status_box.dart';
import '../calendar/calendar_providers.dart';
import 'booking_providers.dart';

class MyBookingsScreen extends ConsumerWidget {
  const MyBookingsScreen({super.key});

  static String _subtitle(Booking booking) {
    final state = booking.isConfirmed ? '已預約' : '【已取消】';
    final start = booking.eventStartsAt;
    if (start == null) return state;
    return '$state　${formatDate(start)} ${formatTime(start)}';
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final bookings = ref.watch(myBookingsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('我的預約')),
      body: bookings.when(
        loading: () => const Center(child: Text('【載入中】')),
        error: (error, _) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ErrorBox(error: error),
            OutlinedButton(
              onPressed: () => ref.invalidate(myBookingsProvider),
              child: const Text('重試'),
            ),
          ],
        ),
        data: (items) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (items.isEmpty)
              const StatusBox(label: '【目前沒有預約】', detail: '可在活動內容中預約。'),
            for (final booking in items)
              ListTile(
                key: Key('my-booking-${booking.id}'),
                title: Text(booking.eventTitle ?? '活動 ${booking.eventId}'),
                subtitle: Text(_subtitle(booking)),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => context.push('/events/${booking.eventId}'),
              ),
          ],
        ),
      ),
    );
  }
}
