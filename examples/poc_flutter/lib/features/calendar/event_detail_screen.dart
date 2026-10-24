import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../ui/status_box.dart';
import '../bookings/booking_section.dart';
import 'calendar_providers.dart';

class EventDetailScreen extends ConsumerWidget {
  const EventDetailScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final event = ref.watch(eventDetailProvider(id));

    return Scaffold(
      appBar: AppBar(title: const Text('活動內容')),
      body: event.when(
        loading: () => const LoadingView(),
        error: (error, _) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ErrorRetry(
              error: ErrorBox(error: error),
              onRetry: () => ref.invalidate(eventDetailProvider(id)),
            ),
          ],
        ),
        data: (item) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (item.isCancelled)
              StatusBox(
                key: const Key('event-cancelled'),
                label: '【已取消】此活動已取消',
                detail: item.cancelReason ?? '未提供取消原因。',
              ),
            Text(item.title, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 8),
            Text('時間：${formatDate(item.startsAt)} ${formatEventTime(item)}'),
            if (item.location != null) Text('地點：${item.location}'),
            const SizedBox(height: 12),
            BookingSection(eventId: item.id),
            if (item.description != null) ...[
              const Divider(height: 24),
              SelectableText(item.description!),
            ],
          ],
        ),
      ),
    );
  }
}
