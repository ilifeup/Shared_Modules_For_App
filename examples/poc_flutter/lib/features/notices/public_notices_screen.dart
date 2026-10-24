import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../ui/status_box.dart';
import 'notice_providers.dart';

/// Public-audience notices, readable without logging in.
class PublicNoticesScreen extends ConsumerWidget {
  const PublicNoticesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notices = ref.watch(publicNoticesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('公開公告')),
      body: notices.when(
        loading: () => const LoadingView(),
        error: (error, _) => ListView(
          padding: const EdgeInsets.all(16),
          children: [ErrorBox(error: error)],
        ),
        data: (items) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (items.isEmpty)
              const StatusBox(label: '【目前沒有公開公告】', detail: '登入後可查看會員公告。'),
            for (final notice in items)
              ExpansionTile(
                title: Text(notice.title),
                subtitle: Text(
                  '${noticeLabels(notice)} ${formatPublishedAt(notice.publishedAt)}'
                      .trim(),
                ),
                children: [
                  Padding(
                    padding: const EdgeInsets.all(12),
                    child: SelectableText(notice.body),
                  ),
                ],
              ),
          ],
        ),
      ),
    );
  }
}
