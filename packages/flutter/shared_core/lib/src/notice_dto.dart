import 'notice.dart';

/// Decodes only the agreed portable fields; application authorization is
/// enforced by the server, never by this DTO.
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
    final date = publishedAt == null
        ? null
        : DateTime.tryParse(publishedAt);
    if (publishedAt != null && date == null) {
      throw const FormatException('Invalid publication timestamp');
    }
    return Notice(
      id: id,
      title: title,
      body: body,
      status: status,
      publishedAt: date,
    );
  }
}
