import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers.dart';
import '../../ui/status_box.dart';
import '../auth/auth_controller.dart';

/// Add or change the mobile number: a code is sent to the NEW number, and the
/// number is saved (as verified) only after the code is confirmed.
class ChangePhoneScreen extends ConsumerStatefulWidget {
  const ChangePhoneScreen({super.key});

  @override
  ConsumerState<ChangePhoneScreen> createState() => _ChangePhoneScreenState();
}

class _ChangePhoneScreenState extends ConsumerState<ChangePhoneScreen> {
  final _phone = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();
  bool _codeSent = false;
  bool _busy = false;
  Object? _error;
  String? _localError;

  @override
  void dispose() {
    _phone.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _guard(Future<void> Function() action) async {
    setState(() {
      _busy = true;
      _error = null;
      _localError = null;
    });
    try {
      await action();
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _send() async {
    if (_phone.text.trim().isEmpty || _password.text.isEmpty) {
      setState(() => _localError = '請輸入新手機號碼與目前的密碼。');
      return;
    }
    await _guard(() async {
      await ref
          .read(profileApiProvider)
          .sendPhoneChangeCode(_phone.text, _password.text);
      if (mounted) setState(() => _codeSent = true);
    });
  }

  Future<void> _confirm() async {
    if (!RegExp(r'^\d{6}$').hasMatch(_code.text.trim())) {
      setState(() => _localError = '請輸入 6 位數驗證碼。');
      return;
    }
    // Updating the session can rebuild this route, so navigate with handles
    // captured before the request instead of this State's context.
    final router = GoRouter.of(context);
    final messenger = ScaffoldMessenger.of(context);
    final auth = ref.read(authControllerProvider.notifier);
    await _guard(() async {
      final profile = await ref
          .read(profileApiProvider)
          .confirmPhoneChange(_phone.text, _code.text);
      messenger.showSnackBar(const SnackBar(content: Text('手機號碼已驗證並更新')));
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
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('手機號碼設定')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_codeSent)
            const StatusBox(label: '【已送出】', detail: '驗證碼已發送至新手機號碼。'),
          if (_localError != null)
            StatusBox(label: '【請修正】', detail: _localError!),
          if (_error != null) ErrorBox(error: _error!),
          TextField(
            key: const Key('change-phone'),
            controller: _phone,
            enabled: !_codeSent,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(
              labelText: '新手機號碼（例如 0912345678）',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          if (!_codeSent) ...[
            TextField(
              key: const Key('change-phone-password'),
              controller: _password,
              obscureText: true,
              decoration: const InputDecoration(
                labelText: '目前的密碼',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 16),
            FilledButton(
              key: const Key('change-phone-send'),
              onPressed: _busy ? null : _send,
              child: const Text('發送驗證碼到新號碼'),
            ),
          ] else ...[
            TextField(
              key: const Key('change-phone-code'),
              controller: _code,
              keyboardType: TextInputType.number,
              maxLength: 6,
              decoration: const InputDecoration(
                labelText: '6 位數驗證碼',
                border: OutlineInputBorder(),
              ),
            ),
            FilledButton(
              key: const Key('change-phone-confirm'),
              onPressed: _busy ? null : _confirm,
              child: const Text('驗證並更新'),
            ),
            TextButton(
              onPressed: _busy
                  ? null
                  : () => setState(() {
                      _codeSent = false;
                      _code.clear();
                    }),
              child: const Text('重新輸入號碼'),
            ),
          ],
        ],
      ),
    );
  }
}
