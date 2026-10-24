/// Portable notice contract; no application identity or API host is embedded.
class Notice {
  const Notice({
    required this.id,
    required this.title,
    required this.body,
    required this.status,
    required this.publishedAt,
    this.publishEndsAt,
    this.isPinned = false,
    this.audience = 'members',
    this.categoryId,
    this.categoryName,
    this.isRead,
  });

  final String id;
  final String title;
  final String body;
  final String status;
  final DateTime? publishedAt;

  /// S3: optional end of the publish window.
  final DateTime? publishEndsAt;
  final bool isPinned;

  /// `public` or `members`.
  final String audience;
  final String? categoryId;
  final String? categoryName;

  /// Null on public endpoints (no member context).
  final bool? isRead;

  bool isVisibleAt(DateTime nowUtc) =>
      status == 'published' &&
      publishedAt != null &&
      !publishedAt!.toUtc().isAfter(nowUtc.toUtc()) &&
      (publishEndsAt == null || publishEndsAt!.toUtc().isAfter(nowUtc.toUtc()));
}

class NoticeCategory {
  const NoticeCategory({required this.id, required this.name});

  final String id;
  final String name;
}

/// One page of member notices plus the member's total unread count.
class NoticePage {
  const NoticePage({required this.items, this.unreadCount});

  final List<Notice> items;
  final int? unreadCount;
}
