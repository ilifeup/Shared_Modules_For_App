import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';

/// List options: category (null = all) and popular / latest order.
class ArticleQuery {
  const ArticleQuery({this.categoryId, this.popular = false});

  final String? categoryId;
  final bool popular;
}

final articleQueryProvider = NotifierProvider<ArticleQueryNotifier, ArticleQuery>(
  ArticleQueryNotifier.new,
);

class ArticleQueryNotifier extends Notifier<ArticleQuery> {
  @override
  ArticleQuery build() => const ArticleQuery();

  void selectCategory(String? id) =>
      state = ArticleQuery(categoryId: id, popular: state.popular);

  void setPopular(bool popular) =>
      state = ArticleQuery(categoryId: state.categoryId, popular: popular);
}

final articlesProvider = FutureProvider.autoDispose<ArticlePage>((ref) {
  final query = ref.watch(articleQueryProvider);
  return ref
      .watch(articleApiProvider)
      .list(categoryId: query.categoryId, popular: query.popular);
});

final featuredArticlesProvider =
    FutureProvider.autoDispose<List<ArticleSummary>>(
      (ref) => ref.watch(articleApiProvider).featured(),
    );

final articleCategoriesProvider =
    FutureProvider.autoDispose<List<ArticleCategory>>(
      (ref) => ref.watch(articleApiProvider).categories(),
    );

final articleDetailProvider = FutureProvider.autoDispose
    .family<ArticleDetail, String>(
      (ref, id) => ref.watch(articleApiProvider).detail(id),
    );

final articleCommentsProvider = FutureProvider.autoDispose
    .family<ArticleCommentPage, String>(
      (ref, id) => ref.watch(articleApiProvider).comments(id),
    );

final articleLikesProvider = FutureProvider.autoDispose
    .family<ArticleLikeList, String>(
      (ref, id) => ref.watch(articleApiProvider).likes(id),
    );

final reportReasonsProvider = FutureProvider.autoDispose<List<ReportReason>>(
  (ref) => ref.watch(articleApiProvider).reportReasons(),
);

/// Uploaded covers are served by the API with the article's read permission,
/// so they are fetched through Dio (which adds the Bearer token).
final articleCoverBytesProvider = FutureProvider.autoDispose
    .family<Uint8List?, String>((ref, url) async {
      final response = await ref
          .watch(dioProvider)
          .get<List<int>>(
            url,
            options: Options(responseType: ResponseType.bytes),
          );
      final data = response.data;
      return data == null ? null : Uint8List.fromList(data);
    });

/// Flattens the tree for the filter chips: "上層 › 子分類".
List<({String id, String label})> flattenCategories(
  List<ArticleCategory> tree, [
  String prefix = '',
]) => [
  for (final category in tree) ...[
    (id: category.id, label: '$prefix${category.name}'),
    ...flattenCategories(category.children, '$prefix${category.name} › '),
  ],
];

String formatArticleDate(DateTime? value) {
  if (value == null) return '';
  final local = value.toLocal();
  String two(int n) => n.toString().padLeft(2, '0');
  return '${local.year}-${two(local.month)}-${two(local.day)}';
}
