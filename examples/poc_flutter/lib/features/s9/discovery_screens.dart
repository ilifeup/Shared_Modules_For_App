import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../core/device/device_services.dart';
import '../../core/providers.dart';
import '../../ui/status_box.dart';
import '../calendar/calendar_providers.dart';
import 'my_calendar_screen.dart' show parseHexColor;

final publicTagsProvider = FutureProvider.autoDispose<List<ContentTag>>(
  (ref) => ref.watch(discoveryApiProvider).tags(),
);

/// M19-D08 / D17: bookmarks grouped by content type (null = all).
class BookmarkTypeFilter extends Notifier<String?> {
  @override
  String? build() => null;

  void select(String? type) => state = type;
}

final bookmarkTypeProvider = NotifierProvider<BookmarkTypeFilter, String?>(
  BookmarkTypeFilter.new,
);

final bookmarksProvider = FutureProvider.autoDispose<BookmarkPage>(
  (ref) => ref
      .watch(discoveryApiProvider)
      .bookmarks(type: ref.watch(bookmarkTypeProvider)),
);

Widget tagChip(ContentTag tag, {bool selected = false, VoidCallback? onTap}) {
  final color = parseHexColor(tag.color);
  return Padding(
    padding: const EdgeInsets.only(right: 6, bottom: 6),
    child: FilterChip(
      key: Key('tag-${tag.id}'),
      label: Text('#${tag.name}${tag.count != null ? ' ${tag.count}' : ''}'),
      selected: selected,
      onSelected: onTap == null ? null : (_) => onTap(),
      side: color != null ? BorderSide(color: color, width: 2) : null,
    ),
  );
}

/// S9 M19 search: AND keywords, tag (AND) and type filters, sort, 20 per page
/// with "load more". Visibility is decided by the server.
class SearchScreen extends ConsumerStatefulWidget {
  const SearchScreen({super.key, this.initialTagId});

  final String? initialTagId;

  @override
  ConsumerState<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends ConsumerState<SearchScreen> {
  final _q = TextEditingController();
  late final Set<String> _tags = {?widget.initialTagId};
  String? _type;
  String _sort = 'relevance';
  final _items = <ContentCard>[];
  int _page = 0;
  int _lastPage = 1;
  int _total = 0;
  bool _busy = false;
  Object? _error;

  @override
  void initState() {
    super.initState();
    if (_tags.isNotEmpty) _search();
  }

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  Future<void> _search({bool more = false}) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await ref
          .read(discoveryApiProvider)
          .search(
            SearchQuery(
              q: _q.text,
              type: _type,
              tagIds: _tags.toList(),
              sort: _sort,
              page: more ? _page + 1 : 1,
            ),
          );
      if (!mounted) return;
      setState(() {
        if (!more) _items.clear();
        _items.addAll(page.items);
        _page = page.currentPage;
        _lastPage = page.lastPage;
        _total = page.total;
      });
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final tags = ref.watch(publicTagsProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('搜尋')),
      body: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          TextField(
            key: const Key('search-q'),
            controller: _q,
            textInputAction: TextInputAction.search,
            onSubmitted: (_) => _search(),
            decoration: const InputDecoration(
              labelText: '關鍵字（以空白分隔，需全部符合）',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 8),
          Wrap(
            children: [
              for (final t in const [
                (null, '全部'),
                ('article', '文章'),
                ('event', '活動'),
              ])
                Padding(
                  padding: const EdgeInsets.only(right: 6),
                  child: ChoiceChip(
                    key: Key('search-type-${t.$1 ?? 'all'}'),
                    label: Text(t.$2),
                    selected: _type == t.$1,
                    onSelected: (_) => setState(() => _type = t.$1),
                  ),
                ),
              DropdownButton<String>(
                key: const Key('search-sort'),
                value: _sort,
                items: const [
                  DropdownMenuItem(value: 'relevance', child: Text('相關度')),
                  DropdownMenuItem(value: 'newest', child: Text('最新')),
                  DropdownMenuItem(value: 'oldest', child: Text('最舊')),
                ],
                onChanged: (v) => setState(() => _sort = v ?? 'relevance'),
              ),
            ],
          ),
          tags.maybeWhen(
            data: (list) => Wrap(
              children: [
                for (final t in list)
                  tagChip(
                    t,
                    selected: _tags.contains(t.id),
                    onTap: () => setState(
                      () => _tags.contains(t.id)
                          ? _tags.remove(t.id)
                          : _tags.add(t.id),
                    ),
                  ),
              ],
            ),
            orElse: () => const SizedBox.shrink(),
          ),
          FilledButton(
            key: const Key('search-submit'),
            onPressed: _busy ? null : _search,
            child: Text(_busy ? '搜尋中…' : '搜尋'),
          ),
          if (_error != null) ErrorBox(error: _error!),
          if (_page > 0)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Text('共 $_total 筆', key: const Key('search-total')),
            ),
          if (_page > 0 && _items.isEmpty)
            const StatusBox(label: '【找不到符合的內容】', detail: '請減少關鍵字或篩選條件。'),
          // M19-D28: offer to clear the filters, no automatic recommendations.
          if (_page > 0 &&
              _items.isEmpty &&
              (_tags.isNotEmpty || _type != null))
            OutlinedButton(
              key: const Key('search-clear-filters'),
              onPressed: () {
                setState(() {
                  _tags.clear();
                  _type = null;
                });
                _search();
              },
              child: const Text('清除篩選'),
            ),
          for (final item in _items) ContentCardTile(item: item),
          if (_page > 0 && _page < _lastPage)
            OutlinedButton(
              key: const Key('search-more'),
              onPressed: _busy ? null : () => _search(more: true),
              child: const Text('載入更多'),
            ),
        ],
      ),
    );
  }
}

class ContentCardTile extends StatelessWidget {
  const ContentCardTile({super.key, required this.item});

  final ContentCard item;

  @override
  Widget build(BuildContext context) {
    final kind = switch (item.contentType) {
      'article' => '文章',
      'event' => '活動',
      _ => '公告',
    };
    return Card(
      child: ListTile(
        key: Key('result-${item.contentType}-${item.contentId}'),
        title: Text('【$kind】${item.title}'),
        subtitle: Text(
          [
            if (item.date != null) formatDate(item.date!.toLocal()),
            ?item.location,
            if (item.tags.isNotEmpty)
              item.tags.map((t) => '#${t.name}').join(' '),
          ].join('・'),
        ),
        onTap: () => context.push(item.appPath),
      ),
    );
  }
}

/// S9 M19 my bookmarks, newest first; unavailable items show a placeholder.
class BookmarksScreen extends ConsumerWidget {
  const BookmarksScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final page = ref.watch(bookmarksProvider);
    return Scaffold(
      appBar: AppBar(
        title: const Text('我的收藏'),
        actions: [
          TextButton(
            key: const Key('bookmarks-clear'),
            onPressed: () async {
              final first = await _confirm(context, '清除全部收藏？', '此動作無法復原。');
              if (!first || !context.mounted) return;
              final second = await _confirm(
                context,
                '再次確認',
                '確定要清除此 App 的全部收藏嗎？',
              );
              if (!second) return;
              await ref.read(discoveryApiProvider).clearBookmarks();
              ref.invalidate(bookmarksProvider);
            },
            child: const Text('全部清除'),
          ),
        ],
      ),
      body: page.when(
        loading: () => const LoadingView(),
        error: (error, _) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ErrorRetry(
              error: ErrorBox(error: error),
              onRetry: () => ref.invalidate(bookmarksProvider),
            ),
          ],
        ),
        data: (p) => ListView(
          padding: const EdgeInsets.all(12),
          children: [
            Text('${p.count} / ${p.limit}', key: const Key('bookmarks-count')),
            Wrap(
              spacing: 6,
              children: [
                for (final t in const [
                  (null, '全部'),
                  ('article', '文章'),
                  ('event', '活動'),
                  ('notice', '公告'),
                ])
                  ChoiceChip(
                    key: Key('bookmark-type-${t.$1 ?? 'all'}'),
                    label: Text(t.$2),
                    selected: ref.watch(bookmarkTypeProvider) == t.$1,
                    onSelected: (_) =>
                        ref.read(bookmarkTypeProvider.notifier).select(t.$1),
                  ),
              ],
            ),
            if (p.items.isEmpty) const StatusBox(label: '【尚無收藏】', detail: ''),
            for (final b in p.items)
              b.item != null
                  ? Dismissible(
                      key: Key('bookmark-${b.contentType}-${b.contentId}'),
                      onDismissed: (_) async {
                        await ref
                            .read(discoveryApiProvider)
                            .removeBookmark(b.contentType, b.contentId);
                        ref.invalidate(bookmarksProvider);
                      },
                      child: ContentCardTile(item: b.item!),
                    )
                  : Card(
                      child: ListTile(
                        key: Key(
                          'bookmark-gone-${b.contentType}-${b.contentId}',
                        ),
                        title: const Text('【內容已無法開啟】'),
                        subtitle: const Text('此內容已下架或您已無權限閱讀。'),
                        trailing: TextButton(
                          onPressed: () async {
                            await ref
                                .read(discoveryApiProvider)
                                .removeBookmark(b.contentType, b.contentId);
                            ref.invalidate(bookmarksProvider);
                          },
                          child: const Text('移除'),
                        ),
                      ),
                    ),
          ],
        ),
      ),
    );
  }
}

Future<bool> _confirm(BuildContext context, String title, String body) async {
  final ok = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: Text(body),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('取消'),
        ),
        FilledButton(
          key: const Key('confirm-yes'),
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text('確定'),
        ),
      ],
    ),
  );
  return ok == true;
}

final shareLinkProvider = FutureProvider.autoDispose
    .family<ShareLink, (String, String)>(
      (ref, key) => ref.watch(linkApiProvider).share(key.$1, key.$2),
    );

/// Share, QR Code (M14) and bookmark (M19) actions for a content detail app bar.
/// Share and QR are shown only when the server says the content may be shared
/// (M14-D23); failures hide them instead of blocking the page.
class ContentActions extends ConsumerWidget {
  const ContentActions({
    super.key,
    required this.contentType,
    required this.contentId,
    this.canBookmark = true,
  });

  final String contentType;
  final String contentId;
  final bool canBookmark;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final link = ref.watch(shareLinkProvider((contentType, contentId))).value;
    final url = link != null && link.shareable ? link.url : null;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (url != null) ...[
          IconButton(
            key: const Key('content-share'),
            tooltip: '分享',
            icon: const Icon(Icons.share_outlined),
            onPressed: () async {
              final messenger = ScaffoldMessenger.of(context);
              try {
                await ref.read(shareTextProvider)(url);
              } catch (_) {
                messenger.showSnackBar(
                  const SnackBar(content: Text('暫時無法分享，請稍後再試。')),
                );
              }
            },
          ),
          IconButton(
            key: const Key('content-qr'),
            tooltip: 'QR Code',
            icon: const Icon(Icons.qr_code_2),
            onPressed: () => showDialog<void>(
              context: context,
              builder: (_) => ShareQrDialog(url: url),
            ),
          ),
        ],
        if (canBookmark)
          IconButton(
            key: const Key('content-bookmark'),
            tooltip: '收藏',
            icon: const Icon(Icons.bookmark_add_outlined),
            onPressed: () async {
              final messenger = ScaffoldMessenger.of(context);
              try {
                await ref
                    .read(discoveryApiProvider)
                    .addBookmark(contentType, contentId);
                ref.invalidate(bookmarksProvider);
                messenger.showSnackBar(const SnackBar(content: Text('已加入收藏。')));
              } catch (error) {
                messenger.showSnackBar(SnackBar(content: Text('無法收藏：$error')));
              }
            },
          ),
      ],
    );
  }
}

/// M14-D08 / D27: the share URL as a QR Code on screen, shareable as an image.
class ShareQrDialog extends ConsumerWidget {
  const ShareQrDialog({super.key, required this.url});

  final String url;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return AlertDialog(
      title: const Text('分享 QR Code'),
      content: SizedBox(
        width: 240,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ColoredBox(
              color: Colors.white,
              child: QrImageView(
                key: const Key('share-qr'),
                data: url,
                size: 220,
              ),
            ),
            const SizedBox(height: 8),
            SelectableText(url, textAlign: TextAlign.center),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('關閉'),
        ),
        FilledButton(
          key: const Key('share-qr-image'),
          onPressed: () async {
            final messenger = ScaffoldMessenger.of(context);
            try {
              await ref.read(shareImageProvider)(
                await qrPng(url),
                'qrcode.png',
              );
            } catch (_) {
              messenger.showSnackBar(
                const SnackBar(content: Text('暫時無法分享圖片，請稍後再試。')),
              );
            }
          },
          child: const Text('分享圖片'),
        ),
      ],
    );
  }
}

/// PNG of a QR Code on a white background (readable in light and dark viewers).
Future<Uint8List> qrPng(String data, {double size = 600}) async {
  final recorder = ui.PictureRecorder();
  final canvas = Canvas(recorder);
  final margin = size * 0.06;
  canvas.drawRect(
    Rect.fromLTWH(0, 0, size, size),
    Paint()..color = Colors.white,
  );
  canvas.translate(margin, margin);
  QrPainter(
    data: data,
    version: QrVersions.auto,
    gapless: true,
  ).paint(canvas, Size.square(size - 2 * margin));
  final image = await recorder.endRecording().toImage(
    size.toInt(),
    size.toInt(),
  );
  final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
  return bytes!.buffer.asUint8List();
}
