import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../ui/content_unavailable.dart';
import '../../ui/status_box.dart';
import '../s9/discovery_screens.dart';
import 'calendar_providers.dart';

class EventDetailScreen extends ConsumerWidget {
  const EventDetailScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final event = ref.watch(eventDetailProvider(id));

    return Scaffold(
      appBar: AppBar(
        title: const Text('活動內容'),
        actions: [ContentActions(contentType: 'event', contentId: id)],
      ),
      body: event.when(
        loading: () => const LoadingView(),
        error: (error, _) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ContentUnavailable(
              error: error,
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
            if (!item.isCancelled)
              FilledButton(
                key: const Key('event-booking-s9'),
                onPressed: () => context.push('/events/${item.id}/booking-groups'),
                child: const Text('報名／候補（單人）'),
              ),
            // S9: check-in entries.
            Wrap(
              spacing: 8,
              children: [
                TextButton(
                  key: const Key('open-checkin'),
                  onPressed: () => context.push('/events/${item.id}/checkin'),
                  child: const Text('簽到'),
                ),
                TextButton(
                  key: const Key('open-pass'),
                  onPressed: () =>
                      context.push('/events/${item.id}/checkin/pass'),
                  child: const Text('入場 QR Code'),
                ),
                TextButton(
                  key: const Key('open-staff'),
                  onPressed: () =>
                      context.push('/events/${item.id}/checkin/staff'),
                  child: const Text('工作人員'),
                ),
              ],
            ),
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
