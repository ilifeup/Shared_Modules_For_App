import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers.dart';
import '../../ui/status_box.dart';
import 'auth_controller.dart';

/// Verification gate: shown while the member is signed in but the server's
/// verification policy is not satisfied. Only verification, logout and
/// connection checks are available here.
class VerifyAccountScreen extends ConsumerStatefulWidget {
  const VerifyAccountScreen({super.key});

  @override
  ConsumerState<VerifyAccountScreen> createState() =>
      _VerifyAccountScreenState();
}

class _VerifyAccountScreenState extends ConsumerState<VerifyAccountScreen> {
  bool _busy = false;
  Object? _error;
  String? _notice;

  Future<void> _run(Future<void> Function() action, String? success) async {
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });
    try {
      await action();
      if (mounted && success != null) setState(() => _notice = success);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final profile = ref.watch(authControllerProvider).profile;
    final missingPhone = profile?.verificationMissing == 'phone';
    final email = profile?.email ?? '';
    final phone = profile?.phone ?? '';

    return Scaffold(
      appBar: AppBar(title: const Text('完成帳號驗證')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          StatusBox(
            label: missingPhone ? '【待驗證】請完成手機驗證' : '【待驗證】請完成 Email 驗證',
            detail: missingPhone
                ? '目前規定須驗證手機才能使用會員功能。驗證碼將傳送至 $phone。'
                : '目前規定須驗證 Email 才能使用會員功能。請到 $email 收信並點擊驗證連結。',
          ),
          if (_notice != null) StatusBox(label: '【已送出】', detail: _notice!),
          if (_error != null) ErrorBox(error: _error!),
          if (missingPhone)
            FilledButton(
              key: const Key('gate-phone'),
              onPressed: _busy
                  ? null
                  : () => context.push(
                      '/verify-phone?phone=${Uri.encodeQueryComponent(phone)}',
                    ),
              child: const Text('前往手機驗證'),
            )
          else
            OutlinedButton(
              key: const Key('gate-resend-email'),
              onPressed: _busy || email.isEmpty
                  ? null
                  : () => _run(
                      () => ref
                          .read(registrationApiProvider)
                          .resendEmailVerification(email),
                      '若此 Email 尚未驗證，驗證信已寄出。',
                    ),
              child: const Text('重新寄送驗證信'),
            ),
          const SizedBox(height: 8),
          FilledButton.tonal(
            key: const Key('gate-recheck'),
            onPressed: _busy
                ? null
                : () => _run(
                    () => ref
                        .read(authControllerProvider.notifier)
                        .refreshProfile(),
                    null,
                  ),
            child: const Text('我已完成驗證，重新檢查'),
          ),
          const SizedBox(height: 8),
          TextButton(
            key: const Key('gate-logout'),
            onPressed: _busy
                ? null
                : () => ref.read(authControllerProvider.notifier).logout(),
            child: const Text('登出'),
          ),
        ],
      ),
    );
  }
}
