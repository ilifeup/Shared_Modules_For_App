import 'api_envelope.dart';
import 'member_api.dart';

/// S8 M05 article category (up to three levels).
class ArticleCategory {
  const ArticleCategory({
    required this.id,
    required this.name,
    this.children = const [],
  });

  final String id;
  final String name;
  final List<ArticleCategory> children;

  factory ArticleCategory.fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final name = json['name'];
    if (id is! String || name is! String) {
      throw const FormatException('Invalid article category');
    }
    final children = json['children'];
    return ArticleCategory(
      id: id,
      name: name,
      children: children is List
          ? children
                .map(
                  (child) =>
                      ArticleCategory.fromJson(child as Map<String, dynamic>),
                )
                .toList(growable: false)
          : const [],
    );
  }
}

/// A list item. Read permission is decided by the server.
class ArticleSummary {
  const ArticleSummary({
    required this.id,
    required this.slug,
    required this.title,
    required this.authorName,
    required this.visibility,
    this.summary,
    this.coverUrl,
    this.categories = const [],
    this.isFeatured = false,
    this.publishedAt,
    this.likeCount = 0,
    this.viewCount = 0,
  });

  final String id;
  final String slug;
  final String title;
  final String? summary;

  /// Uploaded covers are served by the API (send the Bearer token); external
  /// covers are plain URLs.
  final String? coverUrl;
  final String authorName;
  final List<ArticleCategory> categories;

  /// `public`, `members` or `restricted`.
  final String visibility;
  final bool isFeatured;
  final DateTime? publishedAt;
  final int likeCount;
  final int viewCount;

  bool get isMembersOnly => visibility != 'public';

  factory ArticleSummary.fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final slug = json['slug'];
    final title = json['title'];
    final author = json['author_name'];
    final visibility = json['visibility'];
    if (id is! String ||
        slug is! String ||
        title is! String ||
        author is! String ||
        visibility is! String) {
      throw const FormatException('Invalid article');
    }
    final categories = json['categories'];
    final summary = json['summary'];
    final cover = json['cover_url'];
    final likes = json['like_count'];
    final views = json['view_count'];
    return ArticleSummary(
      id: id,
      slug: slug,
      title: title,
      authorName: author,
      visibility: visibility,
      summary: summary is String ? summary : null,
      coverUrl: cover is String ? cover : null,
      categories: categories is List
          ? categories
                .map((c) => ArticleCategory.fromJson(c as Map<String, dynamic>))
                .toList(growable: false)
          : const [],
      isFeatured: json['is_featured'] == true,
      publishedAt: _date(json['published_at']),
      likeCount: likes is int ? likes : 0,
      viewCount: views is int ? views : 0,
    );
  }
}

/// A native rendering block derived from the sanitized HTML body.
class ArticleBlock {
  const ArticleBlock({
    required this.type,
    this.text = '',
    this.level,
    this.ordered = false,
    this.src,
  });

  /// `heading`, `paragraph`, `list_item`, `quote`, `image` or `code`.
  final String type;
  final String text;
  final int? level;
  final bool ordered;
  final String? src;

  factory ArticleBlock.fromJson(Map<String, dynamic> json) {
    final type = json['type'];
    if (type is! String) throw const FormatException('Invalid article block');
    final text = json['text'];
    final level = json['level'];
    final src = json['src'];
    return ArticleBlock(
      type: type,
      text: text is String ? text : '',
      level: level is int ? level : null,
      ordered: json['ordered'] == true,
      src: src is String ? src : null,
    );
  }
}

class ArticleDetail {
  const ArticleDetail({
    required this.summary,
    required this.bodyHtml,
    required this.shareUrl,
    this.blocks = const [],
    this.likedByMe = false,
    this.commentCount = 0,
    this.canInteract = false,
  });

  final ArticleSummary summary;
  final String bodyHtml;
  final List<ArticleBlock> blocks;
  final String shareUrl;
  final bool likedByMe;
  final int commentCount;

  /// Logged in and verified: may like, comment and report.
  final bool canInteract;

  factory ArticleDetail.fromJson(Map<String, dynamic> json) {
    final body = json['body_html'];
    final share = json['share_url'];
    if (body is! String || share is! String) {
      throw const FormatException('Invalid article detail');
    }
    final blocks = json['blocks'];
    final comments = json['comment_count'];
    return ArticleDetail(
      summary: ArticleSummary.fromJson(json),
      bodyHtml: body,
      shareUrl: share,
      blocks: blocks is List
          ? blocks
                .map((b) => ArticleBlock.fromJson(b as Map<String, dynamic>))
                .toList(growable: false)
          : const [],
      likedByMe: json['liked_by_me'] == true,
      commentCount: comments is int ? comments : 0,
      canInteract: json['can_interact'] == true,
    );
  }
}

class ArticlePage {
  const ArticlePage({
    required this.items,
    required this.currentPage,
    required this.lastPage,
  });

  final List<ArticleSummary> items;
  final int currentPage;
  final int lastPage;

  bool get hasMore => currentPage < lastPage;
}

/// Public comment: the author's current nickname, never the real name.
class ArticleComment {
  const ArticleComment({
    required this.id,
    required this.nickname,
    required this.body,
    this.createdAt,
    this.isMine = false,
  });

  final String id;
  final String nickname;
  final String body;
  final DateTime? createdAt;
  final bool isMine;

  factory ArticleComment.fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final nickname = json['nickname'];
    final body = json['body'];
    if (id is! String || nickname is! String || body is! String) {
      throw const FormatException('Invalid comment');
    }
    return ArticleComment(
      id: id,
      nickname: nickname,
      body: body,
      createdAt: _date(json['created_at']),
      isMine: json['is_mine'] == true,
    );
  }
}

class ArticleCommentPage {
  const ArticleCommentPage({
    required this.items,
    required this.currentPage,
    required this.lastPage,
  });

  final List<ArticleComment> items;
  final int currentPage;
  final int lastPage;

  bool get hasMore => currentPage < lastPage;
}

/// Total likes; only members who opted in are listed by nickname.
class ArticleLikeList {
  const ArticleLikeList({
    required this.total,
    required this.nicknames,
    required this.unlistedCount,
  });

  final int total;
  final List<String> nicknames;
  final int unlistedCount;

  factory ArticleLikeList.fromJson(Map<String, dynamic> json) {
    final total = json['total'];
    final listed = json['listed'];
    final unlisted = json['unlisted_count'];
    if (total is! int || listed is! List || unlisted is! int) {
      throw const FormatException('Invalid like list');
    }
    return ArticleLikeList(
      total: total,
      nicknames: listed
          .map((item) => (item as Map<String, dynamic>)['nickname'])
          .whereType<String>()
          .toList(growable: false),
      unlistedCount: unlisted,
    );
  }
}

class ArticleLikeState {
  const ArticleLikeState({required this.liked, required this.likeCount});

  final bool liked;
  final int likeCount;

  factory ArticleLikeState.fromJson(Map<String, dynamic> json) {
    final liked = json['liked'];
    final count = json['like_count'];
    if (liked is! bool || count is! int) {
      throw const FormatException('Invalid like state');
    }
    return ArticleLikeState(liked: liked, likeCount: count);
  }
}

class ReportReason {
  const ReportReason({required this.code, required this.label});

  final String code;
  final String label;
}

DateTime? _date(Object? value) =>
    value is String ? DateTime.tryParse(value)?.toUtc() : null;

/// S8 M05 articles. Read endpoints work without a token (public articles only);
/// likes, comments and reports need a verified member.
class ArticleApi {
  const ArticleApi(this.transport);

  final ApiTransport transport;

  Future<ArticlePage> list({
    String? categoryId,
    bool popular = false,
    int page = 1,
  }) async {
    final query = <String, String>{
      if (categoryId != null) 'category': categoryId,
      if (popular) 'sort': 'popular',
      if (page > 1) 'page': '$page',
    };
    final path = Uri(
      path: '/api/v1/articles',
      queryParameters: query.isEmpty ? null : query,
    ).toString();
    final json = await transport.request('GET', path);
    final items = _list(json['data'], ArticleSummary.fromJson);
    final meta = json['meta'];
    return ArticlePage(
      items: items,
      currentPage: _int(meta, 'current_page', 1),
      lastPage: _int(meta, 'last_page', 1),
    );
  }

  Future<List<ArticleSummary>> featured() async {
    final json = await transport.request('GET', '/api/v1/articles/featured');
    return _list(json['data'], ArticleSummary.fromJson);
  }

  /// 401 ARTICLE_LOGIN_REQUIRED, 403 VERIFICATION_REQUIRED / ARTICLE_FORBIDDEN,
  /// 404 when not published.
  Future<ArticleDetail> detail(String id) async {
    final json = await transport.request('GET', _article(id));
    return ApiEnvelope<ArticleDetail>.fromJson(
      json,
      (data) => ArticleDetail.fromJson(data as Map<String, dynamic>),
    ).data;
  }

  Future<List<ArticleCategory>> categories() async {
    final json = await transport.request('GET', '/api/v1/article-categories');
    return _list(json['data'], ArticleCategory.fromJson);
  }

  Future<ArticleLikeList> likes(String id) async {
    final json = await transport.request('GET', '${_article(id)}/likes');
    return ApiEnvelope<ArticleLikeList>.fromJson(
      json,
      (data) => ArticleLikeList.fromJson(data as Map<String, dynamic>),
    ).data;
  }

  Future<ArticleLikeState> like(String id) async =>
      _likeState(await transport.request('POST', '${_article(id)}/like'));

  Future<ArticleLikeState> unlike(String id) async =>
      _likeState(await transport.request('DELETE', '${_article(id)}/like'));

  Future<ArticleCommentPage> comments(String id, {int page = 1}) async {
    final suffix = page > 1 ? '?page=$page' : '';
    final json = await transport.request(
      'GET',
      '${_article(id)}/comments$suffix',
    );
    final meta = json['meta'];
    return ArticleCommentPage(
      items: _list(json['data'], ArticleComment.fromJson),
      currentPage: _int(meta, 'current_page', 1),
      lastPage: _int(meta, 'last_page', 1),
    );
  }

  /// 422 NICKNAME_REQUIRED (set a nickname first) or COMMENT_BLOCKED.
  Future<ArticleComment> comment(String id, String body) async {
    final text = body.trim();
    if (text.isEmpty || text.length > 1000) {
      throw ArgumentError.value(body, 'body', 'Use 1–1000 characters');
    }
    final json = await transport.request(
      'POST',
      '${_article(id)}/comments',
      body: {'body': text},
    );
    return ApiEnvelope<ArticleComment>.fromJson(
      json,
      (data) => ArticleComment.fromJson(data as Map<String, dynamic>),
    ).data;
  }

  /// 409 ALREADY_REPORTED when this member already reported it in this round.
  Future<void> report(String commentId, String reason, {String? note}) async {
    if (reason.isEmpty) throw ArgumentError('A reason is required');
    final trimmed = note?.trim();
    await transport.request(
      'POST',
      '/api/v1/article-comments/${Uri.encodeComponent(commentId)}/reports',
      body: {
        'reason': reason,
        if (trimmed != null && trimmed.isNotEmpty) 'note': trimmed,
      },
    );
  }

  Future<List<ReportReason>> reportReasons() async {
    final json = await transport.request(
      'GET',
      '/api/v1/article-report-reasons',
    );
    return _list(json['data'], (item) {
      final code = item['code'];
      final label = item['label'];
      if (code is! String || label is! String) {
        throw const FormatException('Invalid report reason');
      }
      return ReportReason(code: code, label: label);
    });
  }

  static String _article(String id) =>
      '/api/v1/articles/${Uri.encodeComponent(id)}';

  static ArticleLikeState _likeState(Map<String, dynamic> json) =>
      ApiEnvelope<ArticleLikeState>.fromJson(
        json,
        (data) => ArticleLikeState.fromJson(data as Map<String, dynamic>),
      ).data;

  static List<T> _list<T>(
    Object? data,
    T Function(Map<String, dynamic>) decode,
  ) {
    if (data is! List) throw const FormatException('Expected a list');
    return data
        .map((item) => decode(item as Map<String, dynamic>))
        .toList(growable: false);
  }

  static int _int(Object? meta, String key, int fallback) {
    final value = meta is Map ? meta[key] : null;
    return value is int ? value : fallback;
  }
}
