import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_exception.dart';
import '../../core/providers.dart';
import '../../ui/status_box.dart';
import '../auth/auth_controller.dart';
import 'article_comments.dart';
import 'article_providers.dart';

/// S8 M05 article detail rendered natively from the server's blocks (no web
/// view, no extra package). Permission errors come from the server.
class ArticleDetailScreen extends ConsumerWidget {
  const ArticleDetailScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(articleDetailProvider(id));

    return Scaffold(
      appBar: AppBar(title: const Text('文章')),
      body: detail.when(
        loading: () => const LoadingView(),
        error: (error, _) => ListView(
          padding: const EdgeInsets.all(16),
          children: [_ArticleDenied(error: error, id: id)],
        ),
        data: (article) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (article.summary.coverUrl != null)
              _Cover(url: article.summary.coverUrl!),
            Text(
              article.summary.title,
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 4),
            Text(
              [
                article.summary.authorName,
                formatArticleDate(article.summary.publishedAt),
                if (article.summary.categories.isNotEmpty)
                  article.summary.categories.map((c) => c.name).join('、'),
              ].join('｜'),
            ),
            const SizedBox(height: 12),
            for (final block in article.blocks) _Block(block: block),
            const Divider(height: 32),
            LikeBar(article: article),
            const Divider(height: 32),
            ArticleComments(article: article),
          ],
        ),
      ),
    );
  }
}

class _ArticleDenied extends ConsumerWidget {
  const _ArticleDenied({required this.error, required this.id});

  final Object error;
  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final code = error is ApiException ? (error as ApiException).code : null;
    final signedIn =
        ref.watch(authControllerProvider).status == AuthStatus.signedIn;
    return switch (code) {
      'ARTICLE_LOGIN_REQUIRED' => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const StatusBox(
            key: Key('article-login-required'),
            label: '【會員文章】',
            detail: '這篇文章僅限會員閱讀，請先登入。',
          ),
          if (!signedIn)
            FilledButton(
              key: const Key('article-login'),
              onPressed: () => context.go('/login'),
              child: const Text('登入'),
            ),
        ],
      ),
      'ARTICLE_FORBIDDEN' => const StatusBox(
        key: Key('article-forbidden'),
        label: '【無法閱讀】',
        detail: '這篇文章僅限特定會員等級或身分閱讀。',
      ),
      _ => ErrorRetry(
        error: ErrorBox(error: error),
        onRetry: () => ref.invalidate(articleDetailProvider(id)),
      ),
    };
  }
}

class _Cover extends ConsumerWidget {
  const _Cover({required this.url});

  final String url;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Uploaded covers need the token (same permission as the article).
    final viaApi = url.contains('/api/v1/articles/');
    final Widget image;
    if (viaApi) {
      final bytes = ref.watch(articleCoverBytesProvider(url)).value;
      if (bytes == null) return const SizedBox.shrink();
      image = Image.memory(bytes, fit: BoxFit.cover);
    } else {
      image = Image.network(
        url,
        fit: BoxFit.cover,
        errorBuilder: (_, _, _) => const SizedBox.shrink(),
      );
    }
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: AspectRatio(aspectRatio: 16 / 9, child: image),
    );
  }
}

class _Block extends StatelessWidget {
  const _Block({required this.block});

  final ArticleBlock block;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context).textTheme;
    final Widget child = switch (block.type) {
      'heading' => Text(
        block.text,
        style: (block.level ?? 2) <= 2 ? theme.titleMedium : theme.titleSmall,
      ),
      'list_item' => Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('• '),
          Expanded(child: SelectableText(block.text)),
        ],
      ),
      'quote' => Container(
        padding: const EdgeInsets.only(left: 12),
        decoration: BoxDecoration(
          border: Border(
            left: BorderSide(color: Theme.of(context).dividerColor, width: 4),
          ),
        ),
        child: SelectableText(block.text),
      ),
      'image' when block.src != null => Image.network(
        block.src!,
        errorBuilder: (_, _, _) => Text('【圖片】${block.text}'),
      ),
      'code' => SelectableText(
        block.text,
        style: const TextStyle(fontFamily: 'monospace'),
      ),
      _ => SelectableText(block.text),
    };
    return Padding(padding: const EdgeInsets.only(bottom: 8), child: child);
  }
}

/// Like / unlike, the like list (nicknames of members who opted in) and the
/// share link. Buttons are disabled while a request runs.
class LikeBar extends ConsumerStatefulWidget {
  const LikeBar({super.key, required this.article});

  final ArticleDetail article;

  @override
  ConsumerState<LikeBar> createState() => _LikeBarState();
}

class _LikeBarState extends ConsumerState<LikeBar> {
  bool _busy = false;
  Object? _error;
  late bool _liked = widget.article.likedByMe;
  late int _count = widget.article.summary.likeCount;

  String get _id => widget.article.summary.id;

  Future<void> _toggle() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final api = ref.read(articleApiProvider);
      final state = _liked ? await api.unlike(_id) : await api.like(_id);
      if (mounted) {
        setState(() {
          _liked = state.liked;
          _count = state.likeCount;
        });
      }
      ref.invalidate(articleLikesProvider(_id));
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _share() async {
    final messenger = ScaffoldMessenger.of(context);
    await Clipboard.setData(ClipboardData(text: widget.article.shareUrl));
    showFeedback(messenger, '已複製文章連結');
  }

  Future<void> _showLikes() async {
    await showModalBottomSheet<void>(
      context: context,
      builder: (context) => Consumer(
        builder: (context, ref, _) {
          final likes = ref.watch(articleLikesProvider(_id));
          return Padding(
            padding: const EdgeInsets.all(16),
            child: likes.when(
              loading: () => const LoadingView(),
              error: (error, _) => ErrorBox(error: error),
              data: (list) => ListView(
                key: const Key('like-list'),
                shrinkWrap: true,
                children: [
                  Text('共 ${list.total} 個讚'),
                  for (final name in list.nicknames)
                    ListTile(title: Text(name)),
                  if (list.unlistedCount > 0)
                    Text('另有 ${list.unlistedCount} 位會員未公開名稱'),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final canInteract = widget.article.canInteract;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_error != null) ErrorBox(error: _error!),
        Wrap(
          spacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            FilledButton.tonal(
              key: const Key('article-like'),
              onPressed: canInteract && !_busy ? _toggle : null,
              child: Text(_liked ? '已按讚（$_count）' : '按讚（$_count）'),
            ),
            TextButton(
              key: const Key('article-like-list'),
              onPressed: _showLikes,
              child: const Text('按讚名單'),
            ),
            TextButton(
              key: const Key('article-share'),
              onPressed: _share,
              child: const Text('分享連結'),
            ),
          ],
        ),
        if (!canInteract) const Text('登入並完成驗證的會員可以按讚與留言。'),
      ],
    );
  }
}
