import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../ui/status_box.dart';
import 'notice_providers.dart';

class NoticesScreen extends ConsumerWidget {
  const NoticesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notices = ref.watch(noticesProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('公告'),
        actions: [
          IconButton(
            key: const Key('open-profile'),
            tooltip: '個人資料',
            icon: const Icon(Icons.person),
            onPressed: () => context.push('/profile'),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(noticesProvider.future),
        child: notices.when(
          loading: () => const Center(child: Text('【載入中】')),
          error: (error, _) => ListView(
            padding: const EdgeInsets.all(16),
            children: [ErrorBox(error: error)],
          ),
          data: (items) => items.isEmpty
              ? ListView(
                  padding: const EdgeInsets.all(16),
                  children: const [
                    StatusBox(label: '【目前沒有公告】', detail: '有新公告時會顯示在這裡。'),
                  ],
                )
              : ListView.separated(
                  itemCount: items.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (context, index) {
                    final notice = items[index];
                    return ListTile(
                      title: Text(notice.title),
                      subtitle: Text(formatPublishedAt(notice.publishedAt)),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => context.push('/notices/${notice.id}'),
                    );
                  },
                ),
        ),
      ),
    );
  }
}
