import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers.dart';
import '../../ui/status_box.dart';
import '../auth/auth_controller.dart';

/// Add or change the email. The new address is saved as unverified and a
/// verification link is sent; the current password is required.
class ChangeEmailScreen extends ConsumerStatefulWidget {
  const ChangeEmailScreen({super.key});

  @override
  ConsumerState<ChangeEmailScreen> createState() => _ChangeEmailScreenState();
}

class _ChangeEmailScreenState extends ConsumerState<ChangeEmailScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  Object? _error;
  String? _localError;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final local = !_email.text.contains('@')
        ? '請輸入 Email。'
        : _password.text.isEmpty
        ? '請輸入目前的密碼。'
        : null;
    setState(() {
      _localError = local;
      _error = null;
    });
    if (local != null) return;

    setState(() => _busy = true);
    // Updating the session can rebuild this route, so navigate with handles
    // captured before the request instead of this State's context.
    final router = GoRouter.of(context);
    final messenger = ScaffoldMessenger.of(context);
    final auth = ref.read(authControllerProvider.notifier);
    try {
      final profile = await ref
          .read(profileApiProvider)
          .changeEmail(_email.text, _password.text);
      messenger.showSnackBar(
        const SnackBar(content: Text('Email 已更新，請到信箱點擊驗證連結')),
      );
      // Leave this screen before publishing the new profile: the session change
      // refreshes the router, which would otherwise restore this route.
      if (profile.verificationSatisfied) {
        if (router.canPop()) {
          router.pop();
        } else {
          router.go('/profile');
        }
        auth.updateProfile(profile);
      } else {
        // Still blocked by the verification policy: show the gate.
        auth.updateProfile(profile);
        router.go('/verify-account');
      }
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Email 設定')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const StatusBox(
            label: '【說明】',
            detail: '變更後新 Email 需重新驗證；驗證完成前，若目前規定須驗證 Email，部分功能將暫停使用。',
          ),
          if (_localError != null)
            StatusBox(label: '【請修正】', detail: _localError!),
          if (_error != null) ErrorBox(error: _error!),
          TextField(
            key: const Key('change-email'),
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(
              labelText: '新 Email',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('change-email-password'),
            controller: _password,
            obscureText: true,
            decoration: const InputDecoration(
              labelText: '目前的密碼',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 16),
          FilledButton(
            key: const Key('change-email-submit'),
            onPressed: _busy ? null : _submit,
            child: Text(_busy ? '送出中…' : '儲存並寄送驗證信'),
          ),
        ],
      ),
    );
  }
}
