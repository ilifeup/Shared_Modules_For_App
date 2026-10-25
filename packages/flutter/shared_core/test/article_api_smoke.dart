import 'package:eachcore_shared_core/eachcore_shared_core.dart';

const _summary = {
  'id': '7',
  'slug': 'xin-fa-abc123',
  'title': '心法',
  'summary': null,
  'cover_url': 'https://example.com/c.jpg',
  'author_name': '講師',
  'categories': [
    {'id': '1', 'name': '教義'},
  ],
  'visibility': 'members',
  'is_featured': true,
  'published_at': '2026-11-01T00:00:00Z',
  'like_count': 3,
  'view_count': 10,
};

class FakeTransport implements ApiTransport {
  final List<String> calls = [];
  final List<Map<String, dynamic>?> bodies = [];
  bool liked = false;

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    calls.add('$method $path');
    bodies.add(body);
    if (path.startsWith('/api/v1/articles?') || path == '/api/v1/articles') {
      return {
        'data': [_summary],
        'meta': {'current_page': 1, 'last_page': 2, 'total': 21},
      };
    }
    if (path == '/api/v1/articles/featured') {
      return {
        'data': [_summary],
      };
    }
    if (path == '/api/v1/articles/7') {
      return {
        'data': {
          ..._summary,
          'body_html': '<h2>標</h2><p>段</p>',
          'blocks': [
            {'type': 'heading', 'level': 2, 'text': '標'},
            {'type': 'list_item', 'text': '一', 'ordered': true},
          ],
          'share_url': 'https://app.example/articles/xin-fa-abc123',
          'liked_by_me': false,
          'comment_count': 1,
          'can_interact': true,
        },
      };
    }
    if (path == '/api/v1/articles/7/like') {
      liked = method == 'POST';
      return {
        'data': {'liked': liked, 'like_count': liked ? 4 : 3},
      };
    }
    if (path == '/api/v1/articles/7/likes') {
      return {
        'data': {
          'total': 3,
          'listed': [
            {'nickname': '小明'},
          ],
          'unlisted_count': 2,
        },
      };
    }
    if (path == '/api/v1/articles/7/comments' && method == 'POST') {
      return {
        'data': {
          'id': '5',
          'nickname': '小明',
          'body': body!['body'],
          'created_at': '2026-11-01T00:00:00Z',
          'is_mine': true,
        },
      };
    }
    if (path == '/api/v1/articles/7/comments') {
      return {
        'data': [
          {'id': '5', 'nickname': '小明', 'body': '讚', 'is_mine': false},
        ],
        'meta': {'current_page': 1, 'last_page': 1},
      };
    }
    if (path == '/api/v1/article-categories') {
      return {
        'data': [
          {
            'id': '1',
            'name': '教義',
            'children': [
              {'id': '2', 'name': '入門', 'children': <Object>[]},
            ],
          },
        ],
      };
    }
    if (path == '/api/v1/article-report-reasons') {
      return {
        'data': [
          {'code': 'spam', 'label': '垃圾訊息或廣告'},
        ],
      };
    }
    return {
      'data': {'reported': true},
    };
  }
}

void check(bool condition, String what) {
  if (!condition) throw StateError('Article contract failed: $what');
}

Future<void> main() async {
  final fake = FakeTransport();
  final api = ArticleApi(fake);

  final page = await api.list(categoryId: '1', popular: true, page: 2);
  check(
    fake.calls.last == 'GET /api/v1/articles?category=1&sort=popular&page=2',
    'list query',
  );
  check(page.items.single.isMembersOnly && page.hasMore, 'list');
  check((await api.featured()).single.isFeatured, 'featured');

  final detail = await api.detail('7');
  check(detail.summary.categories.single.name == '教義', 'categories');
  check(detail.blocks.first.level == 2 && detail.blocks.last.ordered, 'blocks');
  check(detail.canInteract && detail.shareUrl.endsWith('abc123'), 'detail');

  check((await api.like('7')).likeCount == 4, 'like');
  check(!(await api.unlike('7')).liked, 'unlike');
  check(fake.calls.last == 'DELETE /api/v1/articles/7/like', 'unlike path');

  final likes = await api.likes('7');
  check(likes.nicknames.single == '小明' && likes.unlistedCount == 2, 'likes');

  final comment = await api.comment('7', '  感謝  ');
  check(fake.bodies.last!['body'] == '感謝' && comment.isMine, 'comment');
  check((await api.comments('7')).items.single.nickname == '小明', 'comments');

  await api.report('5', 'spam', note: '  ');
  check(
    fake.calls.last == 'POST /api/v1/article-comments/5/reports' &&
        !fake.bodies.last!.containsKey('note'),
    'report',
  );

  check((await api.categories()).single.children.single.name == '入門', 'tree');
  check((await api.reportReasons()).single.code == 'spam', 'reasons');

  var rejected = false;
  try {
    await api.comment('7', '   ');
  } on ArgumentError {
    rejected = true;
  }
  check(rejected, 'empty comment');

  final profile = MemberProfile.fromJson({
    'id': '1',
    'display_name': '王',
    'nickname': '小王',
    'privacy': {'show_in_like_list': true},
  });
  check(profile.hasNickname && profile.showInLikeList, 'profile privacy');
  check(
    !MemberProfile.fromJson({'id': '1', 'display_name': '王'}).showInLikeList,
    'privacy default off',
  );
}
