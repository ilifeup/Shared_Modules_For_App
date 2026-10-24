import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../ui/status_box.dart';
import '../notifications/notification_providers.dart';
import 'notice_providers.dart';

class NoticesScreen extends ConsumerWidget {
  const NoticesScreen({super.key});

  Widget _filter(WidgetRef ref) {
    final categories = ref.watch(noticeCategoriesProvider).value ?? const [];
    if (categories.isEmpty) return const SizedBox.shrink();
    final selected = ref.watch(noticeCategoryFilterProvider);
    final filter = ref.read(noticeCategoryFilterProvider.notifier);
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      child: Wrap(
        spacing: 8,
        children: [
          ChoiceChip(
            key: const Key('notice-category-all'),
            label: const Text('全部'),
            selected: selected == null,
            onSelected: (_) => filter.select(null),
          ),
          for (final category in categories)
            ChoiceChip(
              key: Key('notice-category-${category.id}'),
              label: Text(category.name),
              selected: selected == category.id,
              onSelected: (_) => filter.select(category.id),
            ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notices = ref.watch(noticesProvider);
    final unread = notices.value?.unreadCount;
    final unreadNotifications =
        ref.watch(unreadNotificationsProvider).value ?? 0;

    return Scaffold(
      appBar: AppBar(
        title: Text(unread != null && unread > 0 ? '公告（未讀 $unread）' : '公告'),
        actions: [
          IconButton(
            key: const Key('open-notifications'),
            tooltip: unreadNotifications > 0
                ? '通知（未讀 $unreadNotifications）'
                : '通知',
            icon: Badge(
              isLabelVisible: unreadNotifications > 0,
              label: Text('$unreadNotifications'),
              child: const Icon(Icons.notifications),
            ),
            onPressed: () => context.push('/notifications'),
          ),
          IconButton(
            key: const Key('open-profile'),
            tooltip: '個人資料',
            icon: const Icon(Icons.person),
            onPressed: () => context.push('/profile'),
          ),
        ],
      ),
      body: Column(
        children: [
          _filter(ref),
          Expanded(
            child: RefreshIndicator(
              onRefresh: () => ref.refresh(noticesProvider.future),
              child: notices.when(
                loading: () => const Center(child: Text('【載入中】')),
                error: (error, _) => ListView(
                  padding: const EdgeInsets.all(16),
                  children: [ErrorBox(error: error)],
                ),
                data: (page) => page.items.isEmpty
                    ? ListView(
                        padding: const EdgeInsets.all(16),
                        children: const [
                          StatusBox(label: '【目前沒有公告】', detail: '有新公告時會顯示在這裡。'),
                        ],
                      )
                    : ListView.separated(
                        itemCount: page.items.length,
                        separatorBuilder: (_, _) => const Divider(height: 1),
                        itemBuilder: (context, index) {
                          final notice = page.items[index];
                          final labels = noticeLabels(notice);
                          return ListTile(
                            title: Text(notice.title),
                            subtitle: Text(
                              [
                                if (labels.isNotEmpty) labels,
                                formatPublishedAt(notice.publishedAt),
                              ].join(' '),
                            ),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () => context.push('/notices/${notice.id}'),
                          );
                        },
                      ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
