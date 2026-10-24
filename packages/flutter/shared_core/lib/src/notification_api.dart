import 'api_envelope.dart';
import 'member_api.dart';

/// S4 M04 in-app notification. [actionType] / [actionId] are an optional
/// target (for example `notice` + id); each App decides which screen to open.
class MemberNotification {
  const MemberNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.isRead,
    this.actionType,
    this.actionId,
    this.readAt,
    this.createdAt,
  });

  final String id;
  final String type;
  final String title;
  final String body;
  final bool isRead;
  final String? actionType;
  final String? actionId;
  final DateTime? readAt;
  final DateTime? createdAt;
}

class MemberNotificationDto {
  const MemberNotificationDto._();

  static MemberNotification fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final title = json['title'];
    final body = json['body'];
    if (id is! String || title is! String || body is! String) {
      throw const FormatException('Invalid notification');
    }
    final type = json['type'];
    final action = json['action'];
    return MemberNotification(
      id: id,
      type: type is String ? type : 'system',
      title: title,
      body: body,
      isRead: json['is_read'] == true,
      actionType: action is Map && action['type'] is String
          ? action['type'] as String
          : null,
      actionId: action is Map ? action['id']?.toString() : null,
      readAt: _date(json['read_at']),
      createdAt: _date(json['created_at']),
    );
  }

  static DateTime? _date(Object? value) =>
      value is String ? DateTime.tryParse(value)?.toUtc() : null;
}

class NotificationPage {
  const NotificationPage({
    required this.items,
    required this.unreadCount,
    this.currentPage = 1,
    this.lastPage = 1,
  });

  final List<MemberNotification> items;
  final int unreadCount;
  final int currentPage;
  final int lastPage;

  bool get hasMore => currentPage < lastPage;
}

/// Own notifications only; the server answers 404 for anyone else's id.
class NotificationApi {
  const NotificationApi(this.transport);

  final ApiTransport transport;

  Future<NotificationPage> list({int page = 1}) async {
    if (page < 1) throw ArgumentError.value(page, 'page');
    final json = await transport.request(
      'GET',
      '/api/v1/me/notifications${page == 1 ? '' : '?page=$page'}',
    );
    final items = ApiEnvelope<List<MemberNotification>>.fromJson(json, (data) {
      if (data is! List) throw const FormatException('Expected notifications');
      return data
          .map(
            (item) =>
                MemberNotificationDto.fromJson(item as Map<String, dynamic>),
          )
          .toList(growable: false);
    }).data;
    final meta = json['meta'];
    int read(String key, int fallback) {
      final value = meta is Map ? meta[key] : null;
      return value is int ? value : fallback;
    }

    return NotificationPage(
      items: items,
      unreadCount: read('unread_count', 0),
      currentPage: read('current_page', page),
      lastPage: read('last_page', page),
    );
  }

  Future<int> unreadCount() async {
    final json = await transport.request(
      'GET',
      '/api/v1/me/notifications/unread-count',
    );
    final data = json['data'];
    final count = data is Map ? data['unread_count'] : null;
    if (count is! int) throw const FormatException('Invalid unread count');
    return count;
  }

  Future<MemberNotification> detail(String id) async {
    final json = await transport.request(
      'GET',
      '/api/v1/me/notifications/${Uri.encodeComponent(id)}',
    );
    return ApiEnvelope<MemberNotification>.fromJson(
      json,
      (data) => MemberNotificationDto.fromJson(data as Map<String, dynamic>),
    ).data;
  }

  /// Idempotent. Returns the updated notification.
  Future<MemberNotification> markRead(String id) async {
    final json = await transport.request(
      'POST',
      '/api/v1/me/notifications/${Uri.encodeComponent(id)}/read',
    );
    return ApiEnvelope<MemberNotification>.fromJson(
      json,
      (data) => MemberNotificationDto.fromJson(data as Map<String, dynamic>),
    ).data;
  }

  /// Returns how many notifications were changed (0 on a repeated call).
  Future<int> markAllRead() async {
    final json = await transport.request(
      'POST',
      '/api/v1/me/notifications/read-all',
    );
    final data = json['data'];
    final updated = data is Map ? data['updated'] : null;
    return updated is int ? updated : 0;
  }
}
