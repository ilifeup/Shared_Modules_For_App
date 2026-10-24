import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';

/// First page of the member's notifications (20 per page) plus the unread count.
final notificationsProvider = FutureProvider.autoDispose<NotificationPage>(
  (ref) => ref.watch(notificationApiProvider).list(),
);

/// Unread badge for the app bar; refreshed when notifications change.
final unreadNotificationsProvider = FutureProvider.autoDispose<int>(
  (ref) => ref.watch(notificationApiProvider).unreadCount(),
);

/// Opening a notification marks it read (idempotent on the server).
final notificationDetailProvider = FutureProvider.autoDispose
    .family<MemberNotification, String>((ref, id) async {
      final api = ref.watch(notificationApiProvider);
      final item = await api.detail(id);
      if (item.isRead) return item;
      final read = await api.markRead(id);
      ref.invalidate(notificationsProvider);
      ref.invalidate(unreadNotificationsProvider);
      return read;
    });

const notificationTypeLabels = {
  'system': '系統',
  'account': '帳號',
  'membership': '會員等級',
  'notice': '公告',
};
