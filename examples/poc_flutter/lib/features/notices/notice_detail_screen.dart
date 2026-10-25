import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../ui/content_unavailable.dart';
import '../../ui/status_box.dart';
import 'notice_providers.dart';

class NoticeDetailScreen extends ConsumerWidget {
  const NoticeDetailScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notice = ref.watch(noticeProvider(id));

    return Scaffold(
      appBar: AppBar(title: const Text('公告內容')),
      body: notice.when(
        loading: () => const LoadingView(),
        error: (error, _) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ContentUnavailable(
              error: error,
              onRetry: () => ref.invalidate(noticeDetailProvider(id)),
            ),
          ],
        ),
        data: (item) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(item.title, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 4),
            Text('發布時間：${formatPublishedAt(item.publishedAt)}'),
            if (noticeLabels(item).isNotEmpty) Text(noticeLabels(item)),
            if (item.publishEndsAt != null)
              Text('下架時間：${formatPublishedAt(item.publishEndsAt)}'),
            const Divider(height: 24),
            SelectableText(item.body),
          ],
        ),
      ),
    );
  }
}
