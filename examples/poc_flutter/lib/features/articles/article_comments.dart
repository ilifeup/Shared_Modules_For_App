import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers.dart';
import '../../ui/status_box.dart';
import '../auth/auth_controller.dart';
import 'article_providers.dart';

/// S8 M05-B04/B05 comments: shown with the public nickname, posted at once
/// after the server's blacklist check, and reportable by other members.
class ArticleComments extends ConsumerStatefulWidget {
  const ArticleComments({super.key, required this.article});

  final ArticleDetail article;

  @override
  ConsumerState<ArticleComments> createState() => _ArticleCommentsState();
}

class _ArticleCommentsState extends ConsumerState<ArticleComments> {
  final _body = TextEditingController();
  bool _busy = false;
  Object? _error;
  String? _message;

  String get _id => widget.article.summary.id;

  @override
  void dispose() {
    _body.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final text = _body.text.trim();
    if (text.isEmpty || _busy) return;
    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });
    try {
      await ref.read(articleApiProvider).comment(_id, text);
      _body.clear();
      if (mounted) setState(() => _message = '【已送出】留言已公開。');
      ref.invalidate(articleCommentsProvider(_id));
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _report(ArticleComment comment) async {
    final reported = await showDialog<bool>(
      context: context,
      builder: (context) => ReportDialog(commentId: comment.id),
    );
    if (reported == true && mounted) {
      setState(() {
        _error = null;
        _message = '【已檢舉】感謝回報，管理員會處理。';
      });
    }
  }

  Widget _composer() {
    final profile = ref.watch(authControllerProvider).profile;
    if (!widget.article.canInteract) {
      return const StatusBox(label: '【留言】', detail: '登入並完成驗證的會員可以留言。');
    }
    if (profile == null || !profile.hasNickname) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const StatusBox(
            key: Key('comment-nickname-required'),
            label: '【請先設定公開暱稱】',
            detail: '留言與按讚名單只顯示公開暱稱，不會顯示您的姓名。',
          ),
          OutlinedButton(
            key: const Key('comment-set-nickname'),
            onPressed: () => context.push('/profile'),
            child: const Text('前往設定暱稱'),
          ),
        ],
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const Key('comment-body'),
          controller: _body,
          maxLength: 1000,
          minLines: 1,
          maxLines: 4,
          decoration: InputDecoration(
            labelText: '以「${profile.nickname}」留言',
            border: const OutlineInputBorder(),
          ),
        ),
        FilledButton(
          key: const Key('comment-send'),
          onPressed: _busy ? null : _send,
          child: Text(_busy ? '送出中…' : '送出留言'),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final comments = ref.watch(articleCommentsProvider(_id));
    final canReport = widget.article.canInteract;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('留言', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        if (_message != null) StatusBox(label: _message!, detail: ''),
        if (_error != null) ErrorBox(error: _error!),
        _composer(),
        const SizedBox(height: 12),
        comments.when(
          loading: () => const LoadingView(),
          error: (error, _) => ErrorRetry(
            error: ErrorBox(error: error),
            onRetry: () => ref.invalidate(articleCommentsProvider(_id)),
          ),
          data: (page) => page.items.isEmpty
              ? const Text('還沒有留言。')
              : Column(
                  children: [
                    for (final comment in page.items)
                      ListTile(
                        key: Key('comment-${comment.id}'),
                        contentPadding: EdgeInsets.zero,
                        title: Text(comment.nickname),
                        subtitle: Text(comment.body),
                        trailing: canReport && !comment.isMine
                            ? TextButton(
                                key: Key('comment-report-${comment.id}'),
                                onPressed: () => _report(comment),
                                child: const Text('檢舉'),
                              )
                            : null,
                      ),
                  ],
                ),
        ),
      ],
    );
  }
}

/// Preset reason (required) and an optional note. Pops `true` when reported.
class ReportDialog extends ConsumerStatefulWidget {
  const ReportDialog({super.key, required this.commentId});

  final String commentId;

  @override
  ConsumerState<ReportDialog> createState() => _ReportDialogState();
}

class _ReportDialogState extends ConsumerState<ReportDialog> {
  final _note = TextEditingController();
  String? _reason;
  bool _busy = false;
  Object? _error;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final reason = _reason;
    if (reason == null || _busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref
          .read(articleApiProvider)
          .report(widget.commentId, reason, note: _note.text);
      if (mounted) Navigator.of(context).pop(true);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final reasons = ref.watch(reportReasonsProvider);
    return AlertDialog(
      title: const Text('檢舉留言'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_error != null) ErrorBox(error: _error!),
            reasons.when(
              loading: () => const LoadingView(),
              error: (error, _) => ErrorBox(error: error),
              data: (items) => RadioGroup<String>(
                groupValue: _reason,
                onChanged: (value) => setState(() => _reason = value),
                child: Column(
                  children: [
                    for (final item in items)
                      RadioListTile<String>(
                        key: Key('report-reason-${item.code}'),
                        value: item.code,
                        title: Text(item.label),
                      ),
                  ],
                ),
              ),
            ),
            TextField(
              key: const Key('report-note'),
              controller: _note,
              maxLength: 500,
              decoration: const InputDecoration(labelText: '補充說明（選填）'),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('取消'),
        ),
        FilledButton(
          key: const Key('report-submit'),
          onPressed: _reason == null || _busy ? null : _submit,
          child: const Text('送出檢舉'),
        ),
      ],
    );
  }
}
