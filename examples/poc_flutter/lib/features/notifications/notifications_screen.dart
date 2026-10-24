import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers.dart';
import '../../ui/status_box.dart';
import '../notices/notice_providers.dart';
import 'notification_providers.dart';

/// S4 M04 notification centre: list, unread labels, mark all read.
class NotificationsScreen extends ConsumerStatefulWidget {
  const NotificationsScreen({super.key});

  @override
  ConsumerState<NotificationsScreen> createState() =>
      _NotificationsScreenState();
}

class _NotificationsScreenState extends ConsumerState<NotificationsScreen> {
  bool _busy = false;
  Object? _error;

  Future<void> _markAllRead() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(notificationApiProvider).markAllRead();
      ref.invalidate(notificationsProvider);
      ref.invalidate(unreadNotificationsProvider);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = ref.watch(notificationsProvider);
    final unread = page.value?.unreadCount ?? 0;

    return Scaffold(
      appBar: AppBar(
        title: Text(unread > 0 ? '通知（未讀 $unread）' : '通知'),
        actions: [
          TextButton(
            key: const Key('notifications-read-all'),
            onPressed: _busy || unread == 0 ? null : _markAllRead,
            child: const Text('全部標記已讀'),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(notificationsProvider.future),
        child: page.when(
          loading: () => const LoadingView(),
          error: (error, _) => ListView(
            padding: const EdgeInsets.all(16),
            children: [
              ErrorRetry(
                error: ErrorBox(error: error),
                onRetry: () => ref.invalidate(notificationsProvider),
              ),
            ],
          ),
          data: (value) => ListView(
            children: [
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: ErrorBox(error: _error!),
                ),
              if (value.items.isEmpty)
                const Padding(
                  padding: EdgeInsets.all(16),
                  child: StatusBox(label: '【目前沒有通知】', detail: '有新通知時會顯示在這裡。'),
                ),
              for (final item in value.items)
                ListTile(
                  key: Key('notification-${item.id}'),
                  title: Text(item.title),
                  subtitle: Text(
                    [
                      item.isRead ? '已讀' : '【未讀】',
                      notificationTypeLabels[item.type] ?? item.type,
                      formatPublishedAt(item.createdAt),
                    ].join('　'),
                  ),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => context.push('/notifications/${item.id}'),
                ),
              if (value.hasMore)
                const Padding(
                  padding: EdgeInsets.all(16),
                  child: Text('僅顯示最新 20 則通知。'),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
