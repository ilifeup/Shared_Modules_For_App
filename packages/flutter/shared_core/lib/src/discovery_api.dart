import 'member_api.dart';
import 's9_json.dart';

/// S9 M16 tag (single level). [count] is set on the public tag list only.
class ContentTag {
  const ContentTag({
    required this.id,
    required this.name,
    required this.color,
    this.count,
  });

  final String id;
  final String name;

  /// `#RRGGBB` (automatic palette colour when the admin chose none).
  final String color;
  final int? count;

  factory ContentTag.fromJson(Map<String, dynamic> json) {
    final count = json['count'];
    return ContentTag(
      id: s9Str(json, 'id'),
      name: s9Str(json, 'name'),
      color: s9OptStr(json['color']) ?? '#1E88E5',
      count: count is int ? count : null,
    );
  }
}

/// Public-safe card of an article, event or notice (search, bookmarks).
class ContentCard {
  const ContentCard({
    required this.contentType,
    required this.contentId,
    required this.title,
    required this.url,
    this.summary,
    this.imageUrl,
    this.date,
    this.location,
    this.tags = const [],
    this.bookmarked,
  });

  /// `article`, `event` or `notice`.
  final String contentType;
  final String contentId;
  final String title;
  final String? summary;
  final String? imageUrl;
  final DateTime? date;
  final String? location;

  /// HTTPS fixed-id URL (M14) — also the in-App route path.
  final String url;
  final List<ContentTag> tags;

  /// Only when signed in.
  final bool? bookmarked;

  /// In-App route, e.g. `/articles/12`.
  String get appPath => '/${contentType}s/$contentId';

  factory ContentCard.fromJson(Map<String, dynamic> json) {
    final bookmarked = json['bookmarked'];
    return ContentCard(
      contentType: s9Str(json, 'content_type'),
      contentId: s9Str(json, 'content_id'),
      title: s9Str(json, 'title'),
      summary: s9OptStr(json['summary']),
      imageUrl: s9OptStr(json['image_url']),
      date: s9Date(json['date']),
      location: s9OptStr(json['location']),
      url: s9OptStr(json['url']) ?? '',
      tags: s9List(json['tags'], ContentTag.fromJson),
      bookmarked: bookmarked is bool ? bookmarked : null,
    );
  }
}

class SearchPage {
  const SearchPage({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.total,
    required this.types,
  });

  final List<ContentCard> items;
  final int currentPage;
  final int lastPage;
  final int total;

  /// Content types this App searches (`article`, `event`).
  final List<String> types;

  bool get hasMore => currentPage < lastPage;
}

/// One bookmark; [item] is null when the content can no longer be opened
/// (removed, unpublished or no longer permitted) — show a placeholder.
class BookmarkItem {
  const BookmarkItem({
    required this.contentType,
    required this.contentId,
    required this.available,
    this.bookmarkedAt,
    this.item,
  });

  final String contentType;
  final String contentId;
  final bool available;
  final DateTime? bookmarkedAt;
  final ContentCard? item;

  factory BookmarkItem.fromJson(Map<String, dynamic> json) {
    final item = json['item'];
    return BookmarkItem(
      contentType: s9Str(json, 'content_type'),
      contentId: s9Str(json, 'content_id'),
      available: s9Bool(json['available']),
      bookmarkedAt: s9Date(json['bookmarked_at']),
      item: item is Map<String, dynamic> ? ContentCard.fromJson(item) : null,
    );
  }
}

class BookmarkPage {
  const BookmarkPage({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.count,
    required this.limit,
  });

  final List<BookmarkItem> items;
  final int currentPage;
  final int lastPage;

  /// All bookmarks of the member (placeholders included) and the limit (1,000).
  final int count;
  final int limit;
}

/// Search filters (M19-D07–D16). Keywords are AND-combined on the server.
class SearchQuery {
  const SearchQuery({
    this.q,
    this.type,
    this.tagIds = const [],
    this.articleCategoryId,
    this.eventCategoryId,
    this.from,
    this.to,
    this.location,
    this.sort = 'relevance',
    this.page = 1,
  });

  final String? q;
  final String? type;
  final List<String> tagIds;
  final String? articleCategoryId;
  final String? eventCategoryId;
  final DateTime? from;
  final DateTime? to;
  final String? location;

  /// `relevance`, `newest` or `oldest`.
  final String sort;
  final int page;

  String toPath() {
    final parts = <String>[
      if (q != null && q!.trim().isNotEmpty) 'q=${Uri.encodeQueryComponent(q!.trim())}',
      if (type != null) 'type=$type',
      for (final id in tagIds) 'tags[]=${Uri.encodeQueryComponent(id)}',
      if (articleCategoryId != null) 'article_category_id=$articleCategoryId',
      if (eventCategoryId != null) 'event_category_id=$eventCategoryId',
      if (from != null)
        'from=${Uri.encodeQueryComponent(from!.toUtc().toIso8601String())}',
      if (to != null)
        'to=${Uri.encodeQueryComponent(to!.toUtc().toIso8601String())}',
      if (location != null && location!.trim().isNotEmpty)
        'location=${Uri.encodeQueryComponent(location!.trim())}',
      if (sort != 'relevance') 'sort=$sort',
      if (page > 1) 'page=$page',
    ];
    return parts.isEmpty ? '/api/v1/search' : '/api/v1/search?${parts.join('&')}';
  }
}

/// S9 M16 public tags, M19 search (optional login) and bookmarks (members).
class DiscoveryApi {
  const DiscoveryApi(this.transport);

  final ApiTransport transport;

  /// [sort]: `order` or `popular`.
  Future<List<ContentTag>> tags({String? q, String sort = 'order'}) async {
    final json = await transport.request(
      'GET',
      s9Path('/api/v1/tags', {
        if (q != null && q.isNotEmpty) 'q': q,
        if (sort != 'order') 'sort': sort,
      }),
    );
    return s9List(json['data'], ContentTag.fromJson);
  }

  Future<SearchPage> search(SearchQuery query) async {
    final json = await transport.request('GET', query.toPath());
    final meta = s9Map(json['meta']);
    final types = meta['types'];
    return SearchPage(
      items: s9List(json['data'], ContentCard.fromJson),
      currentPage: s9Int(meta['current_page'], 1),
      lastPage: s9Int(meta['last_page'], 1),
      total: s9Int(meta['total']),
      types: types is List ? types.whereType<String>().toList() : const [],
    );
  }

  Future<BookmarkPage> bookmarks({String? type, int page = 1}) async {
    final json = await transport.request(
      'GET',
      s9Path('/api/v1/me/bookmarks', {
        if (type != null) 'type': type,
        if (page > 1) 'page': '$page',
      }),
    );
    final meta = s9Map(json['meta']);
    return BookmarkPage(
      items: s9List(json['data'], BookmarkItem.fromJson),
      currentPage: s9Int(meta['current_page'], 1),
      lastPage: s9Int(meta['last_page'], 1),
      count: s9Int(meta['count']),
      limit: s9Int(meta['limit'], 1000),
    );
  }

  /// Idempotent. 409 BOOKMARK_LIMIT_REACHED, 422 BOOKMARK_TYPE_DISABLED,
  /// 404 when the content cannot be opened.
  Future<void> addBookmark(String contentType, String contentId) async {
    await transport.request(
      'POST',
      '/api/v1/me/bookmarks',
      body: {
        'content_type': contentType,
        'content_id': int.tryParse(contentId) ?? contentId,
      },
    );
  }

  Future<void> removeBookmark(String contentType, String contentId) async {
    await transport.request(
      'DELETE',
      '/api/v1/me/bookmarks/${s9Seg(contentType)}/${s9Seg(contentId)}',
    );
  }

  /// Clears every bookmark of the member in this App (confirm twice in the UI).
  Future<int> clearBookmarks() async {
    final json = await transport.request('DELETE', '/api/v1/me/bookmarks');
    return s9Int(s9Data(json)['deleted']);
  }
}
