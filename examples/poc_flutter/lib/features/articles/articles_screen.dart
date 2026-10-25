import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../ui/status_box.dart';
import 'article_providers.dart';

/// S8 M05 article list: featured first, then latest or popular, by category.
/// Visitors see public articles only; the server decides what may be read.
class ArticlesScreen extends ConsumerWidget {
  const ArticlesScreen({super.key});

  Widget _filters(WidgetRef ref) {
    final query = ref.watch(articleQueryProvider);
    final notifier = ref.read(articleQueryProvider.notifier);
    final categories = flattenCategories(
      ref.watch(articleCategoriesProvider).value ?? const [],
    );
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      child: Wrap(
        spacing: 8,
        children: [
          ChoiceChip(
            key: const Key('article-sort-latest'),
            label: const Text('最新'),
            selected: !query.popular,
            onSelected: (_) => notifier.setPopular(false),
          ),
          ChoiceChip(
            key: const Key('article-sort-popular'),
            label: const Text('人氣'),
            selected: query.popular,
            onSelected: (_) => notifier.setPopular(true),
          ),
          const SizedBox(width: 8),
          ChoiceChip(
            key: const Key('article-category-all'),
            label: const Text('全部分類'),
            selected: query.categoryId == null,
            onSelected: (_) => notifier.selectCategory(null),
          ),
          for (final category in categories)
            ChoiceChip(
              key: Key('article-category-${category.id}'),
              label: Text(category.label),
              selected: query.categoryId == category.id,
              onSelected: (_) => notifier.selectCategory(category.id),
            ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final articles = ref.watch(articlesProvider);
    final featured = ref.watch(featuredArticlesProvider).value ?? const [];

    return Scaffold(
      appBar: AppBar(title: const Text('文章')),
      body: RefreshIndicator(
        onRefresh: () {
          ref.invalidate(featuredArticlesProvider);
          return ref.refresh(articlesProvider.future);
        },
        child: ListView(
          children: [
            if (featured.isNotEmpty) ...[
              const Padding(
                padding: EdgeInsets.fromLTRB(16, 12, 16, 0),
                child: Text('【精選文章】'),
              ),
              for (final article in featured)
                ArticleTile(
                  key: Key('featured-${article.id}'),
                  article: article,
                  featured: true,
                ),
              const Divider(),
            ],
            _filters(ref),
            ...articles.when(
              loading: () => [
                const Padding(
                  padding: EdgeInsets.all(24),
                  child: LoadingView(),
                ),
              ],
              error: (error, _) => [
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: ErrorRetry(
                    error: ErrorBox(error: error),
                    onRetry: () => ref.invalidate(articlesProvider),
                  ),
                ),
              ],
              data: (page) => page.items.isEmpty
                  ? [
                      const Padding(
                        padding: EdgeInsets.all(16),
                        child: StatusBox(
                          label: '【目前沒有文章】',
                          detail: '登入會員後可以看到更多文章。',
                        ),
                      ),
                    ]
                  : [
                      for (final article in page.items)
                        ArticleTile(article: article),
                    ],
            ),
          ],
        ),
      ),
    );
  }
}

class ArticleTile extends StatelessWidget {
  const ArticleTile({super.key, required this.article, this.featured = false});

  final ArticleSummary article;
  final bool featured;

  @override
  Widget build(BuildContext context) {
    final labels = [
      if (featured) '【精選】',
      if (article.isMembersOnly) '【會員】',
    ].join();
    return ListTile(
      key: Key('article-${article.id}${featured ? '-featured' : ''}'),
      title: Text('$labels${article.title}'),
      subtitle: Text(
        [
          article.authorName,
          formatArticleDate(article.publishedAt),
          '讚 ${article.likeCount}',
          if (article.categories.isNotEmpty)
            article.categories.map((c) => c.name).join('、'),
        ].join('｜'),
      ),
      trailing: const Icon(Icons.chevron_right),
      onTap: () => context.push('/articles/${article.id}'),
    );
  }
}
