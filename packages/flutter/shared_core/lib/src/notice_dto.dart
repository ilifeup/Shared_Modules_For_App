import 'notice.dart';

class NoticeDto {
  const NoticeDto._();

  static Notice fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final title = json['title'];
    final body = json['body'];
    final status = json['status'];
    final publishedAt = json['published_at'];
    if (id is! String ||
        title is! String ||
        body is! String ||
        status is! String ||
        (publishedAt != null && publishedAt is! String)) {
      throw const FormatException('Invalid notice');
    }
    final date = publishedAt == null ? null : DateTime.tryParse(publishedAt);
    if (publishedAt != null && date == null) {
      throw const FormatException('Invalid publication timestamp');
    }
    final endsAt = json['publish_ends_at'];
    final category = json['category'];
    final audience = json['audience'];
    final isRead = json['is_read'];
    return Notice(
      id: id,
      title: title,
      body: body,
      status: status,
      publishedAt: date,
      publishEndsAt: endsAt is String ? DateTime.tryParse(endsAt) : null,
      isPinned: json['is_pinned'] == true,
      audience: audience is String ? audience : 'members',
      categoryId: category is Map ? category['id']?.toString() : null,
      categoryName: category is Map && category['name'] is String
          ? category['name'] as String
          : null,
      isRead: isRead is bool ? isRead : null,
    );
  }
}
