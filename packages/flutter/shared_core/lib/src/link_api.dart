import 'member_api.dart';
import 's9_json.dart';

/// S9 M15 campaign shown in a placement (already filtered by the server for
/// schedule, priority, limit and the viewer's permission).
class MarketingItem {
  const MarketingItem({
    required this.id,
    required this.title,
    required this.buttonText,
    required this.targetType,
    required this.url,
    this.summary,
    this.imageUrl,
    this.targetId,
    this.appPath,
    this.utm = const {},
  });

  final String id;
  final String title;
  final String? summary;
  final String? imageUrl;
  final String buttonText;

  /// `article`, `event`, `notice` or `url` (external HTTPS).
  final String targetType;
  final String? targetId;

  /// M14 HTTPS URL (with utm) or the external URL.
  final String url;

  /// In-App route for content targets.
  final String? appPath;
  final Map<String, String> utm;

  bool get isExternal => targetType == 'url';

  factory MarketingItem.fromJson(Map<String, dynamic> json) {
    final target = s9Map(json['target']);
    final utm = s9Map(json['utm']);
    return MarketingItem(
      id: s9Str(json, 'id'),
      title: s9Str(json, 'title'),
      summary: s9OptStr(json['summary']),
      imageUrl: s9OptStr(json['image_url']),
      buttonText: s9OptStr(json['button_text']) ?? '',
      targetType: s9Str(target, 'type'),
      targetId: s9OptStr(target['id']),
      url: s9Str(target, 'url'),
      appPath: s9OptStr(target['app_path']),
      utm: {
        for (final e in utm.entries)
          if (e.value is String) e.key: e.value as String,
      },
    );
  }
}

class ShareLink {
  const ShareLink({
    required this.contentType,
    required this.contentId,
    required this.shareable,
    required this.isPublic,
    this.url,
  });

  final String contentType;
  final String contentId;

  /// False when the App turned sharing off for this content type.
  final bool shareable;

  /// Member content: the link opens a login prompt without the title.
  final bool isPublic;
  final String? url;
}

class MarketingApi {
  const MarketingApi(this.transport);

  final ApiTransport transport;

  /// Placement code, e.g. `home_banner`. Unknown codes return an empty list.
  Future<List<MarketingItem>> placement(String code) async {
    final json = await transport.request(
      'GET',
      '/api/v1/marketing/placements/${s9Seg(code)}',
    );
    return s9List(json['data'], MarketingItem.fromJson);
  }
}

/// S9 M14 share links and M17 link statistics. Statistics calls never throw:
/// a failed report must not block opening content (S9-INT-D11).
class LinkApi {
  const LinkApi(this.transport);

  final ApiTransport transport;

  Future<ShareLink> share(String contentType, String contentId) async {
    final data = s9Data(
      await transport.request(
        'GET',
        '/api/v1/content/${s9Seg(contentType)}/${s9Seg(contentId)}/share',
      ),
    );
    return ShareLink(
      contentType: s9Str(data, 'content_type'),
      contentId: s9Str(data, 'content_id'),
      shareable: s9Bool(data['shareable']),
      isPublic: s9Bool(data['is_public']),
      url: s9OptStr(data['url']),
    );
  }

  /// App tap on a tracked link (e.g. a campaign). Returns the click id or null.
  Future<String?> click(
    String contentType,
    String contentId, {
    Map<String, String> utm = const {},
  }) async {
    try {
      final json = await transport.request(
        'POST',
        '/api/v1/links/clicks',
        body: {
          'content_type': contentType,
          'content_id': int.tryParse(contentId) ?? contentId,
          ...utm,
        },
      );
      return s9OptStr(s9Map(json['data'])['click_id']);
    } catch (_) {
      return null;
    }
  }

  /// The App opened [contentType]/[contentId] from a link (counted once per
  /// open; call only after the content screen is shown).
  Future<bool> opened(
    String contentType,
    String contentId, {
    Map<String, String> utm = const {},
  }) async {
    try {
      final json = await transport.request(
        'POST',
        '/api/v1/links/opens',
        body: {
          'content_type': contentType,
          'content_id': int.tryParse(contentId) ?? contentId,
          ...utm,
        },
      );
      return s9Bool(s9Map(json['data'])['counted']);
    } catch (_) {
      return false;
    }
  }
}

/// Where an incoming App Link / Universal Link points inside the App.
class DeepLinkTarget {
  const DeepLinkTarget({
    required this.type,
    required this.id,
    this.utm = const {},
  });

  /// `article`, `event`, `notice` or `checkin_location`.
  final String type;

  /// Content id, or the Location QR token for `checkin_location`.
  final String id;

  /// utm_source / utm_medium / utm_campaign of the link (for M17).
  final Map<String, String> utm;

  /// go_router location of the screen.
  String get appPath => switch (type) {
    'article' => '/articles/$id',
    'event' => '/events/$id',
    'notice' => '/notices/$id',
    _ => '/checkin/location/$id',
  };
}

/// Parses HTTPS links of the App's own domain(s) (M14-D06/D07): only the fixed
/// paths are accepted, anything else (other hosts, schemes, paths) is ignored so
/// a crafted link cannot open arbitrary screens.
class DeepLinkParser {
  const DeepLinkParser(this.hosts);

  /// Allowed hosts, e.g. `{'tiandi.example.org'}`.
  final Set<String> hosts;

  static final _content = RegExp(r'^/(articles|events|notices)/(\d{1,19})/?$');
  static final _location = RegExp(r'^/checkin/l/([A-Za-z0-9]{16,40})/?$');

  DeepLinkTarget? parse(Uri uri) {
    if (uri.scheme != 'https' || !hosts.contains(uri.host.toLowerCase())) {
      return null;
    }
    final utm = <String, String>{
      for (final key in const ['utm_source', 'utm_medium', 'utm_campaign'])
        if (_safe(uri.queryParameters[key]) case final value?) key: value,
    };
    final content = _content.firstMatch(uri.path);
    if (content != null) {
      final type = switch (content.group(1)) {
        'articles' => 'article',
        'events' => 'event',
        _ => 'notice',
      };
      return DeepLinkTarget(type: type, id: content.group(2)!, utm: utm);
    }
    final location = _location.firstMatch(uri.path);
    if (location != null) {
      return DeepLinkTarget(type: 'checkin_location', id: location.group(1)!);
    }
    return null;
  }

  static String? _safe(String? value) =>
      value != null && RegExp(r'^[A-Za-z0-9_.\-]{1,80}$').hasMatch(value)
      ? value
      : null;
}

/// Drops a link that arrives twice in a short time (cold start + stream).
class DeepLinkDeduper {
  DeepLinkDeduper({this.window = const Duration(seconds: 5), DateTime Function()? now})
    : _now = now ?? DateTime.now;

  final Duration window;
  final DateTime Function() _now;
  String? _last;
  DateTime? _at;

  bool isDuplicate(Uri uri) {
    final key = uri.toString();
    final now = _now();
    final dup = key == _last && _at != null && now.difference(_at!) < window;
    _last = key;
    _at = now;
    return dup;
  }
}

/// Safe return after login: only in-App paths, never external URLs (M14-D20).
String? safeReturnPath(String? path) {
  if (path == null || !path.startsWith('/') || path.startsWith('//')) {
    return null;
  }
  final uri = Uri.tryParse(path);
  if (uri == null || uri.hasScheme || uri.hasAuthority) return null;
  return RegExp(r'^/(articles|events|notices)/\d{1,19}$|^/checkin/location/[A-Za-z0-9]{16,40}$')
          .hasMatch(uri.path)
      ? uri.path
      : null;
}
