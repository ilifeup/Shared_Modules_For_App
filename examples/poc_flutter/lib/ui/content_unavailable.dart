import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../core/network/api_exception.dart';
import 'status_box.dart';

/// Why a content page (often opened from a shared link) cannot be shown.
enum UnavailableKind { gone, forbidden, loginRequired, offline, other }

UnavailableKind unavailableKind(Object error) {
  if (error is! ApiException) return UnavailableKind.other;
  return switch (error.code) {
    'NETWORK_ERROR' => UnavailableKind.offline,
    'NOT_FOUND' => UnavailableKind.gone,
    'UNAUTHENTICATED' ||
    'ARTICLE_LOGIN_REQUIRED' => UnavailableKind.loginRequired,
    'FORBIDDEN' ||
    'ARTICLE_FORBIDDEN' ||
    'VERIFICATION_REQUIRED' => UnavailableKind.forbidden,
    _ => switch (error.statusCode) {
      404 || 410 => UnavailableKind.gone,
      401 => UnavailableKind.loginRequired,
      403 => UnavailableKind.forbidden,
      _ => UnavailableKind.other,
    },
  };
}

/// S9 M14-D05 / D18 / D29: a clear "link no longer valid", "no permission" or
/// "offline" state with retry and a way back home — the page never just closes
/// or silently jumps to the home page.
class ContentUnavailable extends StatelessWidget {
  const ContentUnavailable({
    super.key,
    required this.error,
    required this.onRetry,
  });

  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final kind = unavailableKind(error);
    final (label, detail) = switch (kind) {
      UnavailableKind.gone => ('【連結已失效】', '此內容不存在、已下架或已刪除。'),
      UnavailableKind.forbidden => ('【沒有權限】', '您目前的會員資格無法查看此內容。'),
      UnavailableKind.loginRequired => ('【請先登入】', '此內容僅限會員查看，登入後會回到這裡。'),
      UnavailableKind.offline => ('【目前離線】', '無法連線到伺服器，請確認網路後重試。'),
      UnavailableKind.other => ('', ''),
    };
    return Column(
      key: Key('content-unavailable-${kind.name}'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (kind == UnavailableKind.other)
          ErrorBox(error: error)
        else
          StatusBox(label: label, detail: detail),
        if (kind == UnavailableKind.loginRequired)
          FilledButton(
            key: const Key('content-login'),
            onPressed: () {
              // Back to this page after login (M14-D04 / D19: in-App path only).
              final from = GoRouterState.of(context).uri.path;
              context.go('/login?from=${Uri.encodeComponent(from)}');
            },
            child: const Text('登入'),
          ),
        if (kind == UnavailableKind.offline || kind == UnavailableKind.other)
          OutlinedButton(
            key: const Key('content-retry'),
            onPressed: onRetry,
            child: const Text('重試'),
          ),
        TextButton(
          key: const Key('content-home'),
          onPressed: () => context.go('/'),
          child: const Text('返回首頁'),
        ),
      ],
    );
  }
}
