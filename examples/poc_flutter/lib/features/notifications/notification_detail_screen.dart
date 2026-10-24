import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../ui/status_box.dart';
import '../notices/notice_providers.dart';
import 'notification_providers.dart';

class NotificationDetailScreen extends ConsumerWidget {
  const NotificationDetailScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notification = ref.watch(notificationDetailProvider(id));

    return Scaffold(
      appBar: AppBar(title: const Text('通知內容')),
      body: notification.when(
        loading: () => const LoadingView(),
        error: (error, _) => ListView(
          padding: const EdgeInsets.all(16),
          children: [ErrorBox(error: error)],
        ),
        data: (item) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(item.title, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 4),
            Text(
              '${notificationTypeLabels[item.type] ?? item.type}　'
              '${formatPublishedAt(item.createdAt)}',
            ),
            const Divider(height: 24),
            SelectableText(item.body),
            // The App decides where an action leads; this PoC knows notices.
            if (item.actionType == 'notice' && item.actionId != null) ...[
              const SizedBox(height: 16),
              OutlinedButton(
                key: const Key('notification-open-target'),
                onPressed: () => context.push('/notices/${item.actionId}'),
                child: const Text('查看相關公告'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
