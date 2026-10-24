import 'api_envelope.dart';
import 'member_api.dart';
import 'notice.dart';
import 'notice_dto.dart';

/// S3 M06 notice endpoints: categories, read status and public notices.
/// The S1 `MemberApi.notices()` / `notice()` remain unchanged.
class NoticeApi {
  const NoticeApi(this.transport);

  final ApiTransport transport;

  Future<NoticePage> memberNotices({String? categoryId}) async {
    final query = categoryId == null
        ? ''
        : '?category=${Uri.encodeQueryComponent(categoryId)}';
    final json = await transport.request('GET', '/api/v1/notices$query');
    final items = _list(json);
    final meta = json['meta'];
    final unread = meta is Map ? meta['unread_count'] : null;
    return NoticePage(items: items, unreadCount: unread is int ? unread : null);
  }

  Future<void> markRead(String id) async {
    await transport.request(
      'POST',
      '/api/v1/notices/${Uri.encodeComponent(id)}/read',
    );
  }

  Future<List<Notice>> publicNotices() async =>
      _list(await transport.request('GET', '/api/v1/public/notices'));

  Future<Notice> publicNotice(String id) async {
    final json = await transport.request(
      'GET',
      '/api/v1/public/notices/${Uri.encodeComponent(id)}',
    );
    return ApiEnvelope<Notice>.fromJson(
      json,
      (data) => NoticeDto.fromJson(data as Map<String, dynamic>),
    ).data;
  }

  Future<List<NoticeCategory>> categories() async {
    final json = await transport.request('GET', '/api/v1/notice-categories');
    return ApiEnvelope<List<NoticeCategory>>.fromJson(json, (data) {
      if (data is! List) throw const FormatException('Expected categories');
      return data
          .map((item) {
            final map = item as Map<String, dynamic>;
            return NoticeCategory(
              id: map['id'].toString(),
              name: map['name'] as String,
            );
          })
          .toList(growable: false);
    }).data;
  }

  static List<Notice> _list(Map<String, dynamic> json) =>
      ApiEnvelope<List<Notice>>.fromJson(json, (data) {
        if (data is! List) throw const FormatException('Expected notice list');
        return data
            .map((item) => NoticeDto.fromJson(item as Map<String, dynamic>))
            .toList(growable: false);
      }).data;
}
