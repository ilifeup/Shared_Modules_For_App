import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';

/// Selected category filter (null = all).
final noticeCategoryFilterProvider =
    NotifierProvider<NoticeCategoryFilter, String?>(NoticeCategoryFilter.new);

class NoticeCategoryFilter extends Notifier<String?> {
  @override
  String? build() => null;

  void select(String? id) => state = id;
}

final noticesProvider = FutureProvider.autoDispose<NoticePage>(
  (ref) => ref
      .watch(noticeApiProvider)
      .memberNotices(categoryId: ref.watch(noticeCategoryFilterProvider)),
);

final noticeCategoriesProvider =
    FutureProvider.autoDispose<List<NoticeCategory>>(
      (ref) => ref.watch(noticeApiProvider).categories(),
    );

/// Opening a notice marks it read (idempotent on the server).
final noticeProvider = FutureProvider.autoDispose.family<Notice, String>((
  ref,
  id,
) async {
  final notice = await ref.watch(memberApiProvider).notice(id);
  if (notice.isRead != true) {
    try {
      await ref.read(noticeApiProvider).markRead(id);
      ref.invalidate(noticesProvider);
    } catch (_) {
      // Reading the notice must not fail because the read mark failed.
    }
  }
  return notice;
});

final publicNoticesProvider = FutureProvider.autoDispose<List<Notice>>(
  (ref) => ref.watch(noticeApiProvider).publicNotices(),
);

String formatPublishedAt(DateTime? value) {
  if (value == null) return '';
  final local = value.toLocal();
  String two(int n) => n.toString().padLeft(2, '0');
  return '${local.year}-${two(local.month)}-${two(local.day)} '
      '${two(local.hour)}:${two(local.minute)}';
}

/// Text labels so state is never shown by color only.
String noticeLabels(Notice notice) => [
  if (notice.isPinned) '【置頂】',
  if (notice.isRead == false) '【未讀】',
  if (notice.categoryName != null) '［${notice.categoryName}］',
].join();
