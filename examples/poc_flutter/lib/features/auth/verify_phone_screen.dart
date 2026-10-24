import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers.dart';
import 'auth_controller.dart';
import '../../ui/status_box.dart';

class VerifyPhoneScreen extends ConsumerStatefulWidget {
  const VerifyPhoneScreen({super.key, this.initialPhone});

  final String? initialPhone;

  @override
  ConsumerState<VerifyPhoneScreen> createState() => _VerifyPhoneScreenState();
}

class _VerifyPhoneScreenState extends ConsumerState<VerifyPhoneScreen> {
  late final _phone = TextEditingController(text: widget.initialPhone ?? '');
  final _code = TextEditingController();
  bool _busy = false;
  Object? _error;
  String? _notice;

  @override
  void dispose() {
    _phone.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _run(Future<void> Function() action, String success) async {
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });
    try {
      await action();
      if (mounted) setState(() => _notice = success);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verify() async {
    final api = ref.read(registrationApiProvider);
    await _run(() => api.verifyPhoneOtp(_phone.text, _code.text), '');
    if (!mounted || _error != null) return;
    final auth = ref.read(authControllerProvider);
    if (auth.status == AuthStatus.signedIn) {
      // Signed in behind the verification gate: re-check and continue.
      await ref.read(authControllerProvider.notifier).refreshProfile();
      if (!mounted) return;
      context.go('/');
      showFeedback(ScaffoldMessenger.of(context), '手機驗證成功');
      return;
    }
    context.go('/login');
    showFeedback(ScaffoldMessenger.of(context), '手機驗證成功，請登入');
  }

  Future<void> _resend() {
    final api = ref.read(registrationApiProvider);
    return _run(() => api.sendPhoneOtp(_phone.text), '若此手機尚未驗證，驗證碼已發送。');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('手機驗證')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_notice != null && _notice!.isNotEmpty)
            StatusBox(label: '【已送出】', detail: _notice!),
          if (_error != null) ErrorBox(error: _error!),
          TextField(
            key: const Key('verify-phone'),
            controller: _phone,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(
              labelText: '手機號碼',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('verify-code'),
            controller: _code,
            keyboardType: TextInputType.number,
            maxLength: 6,
            decoration: const InputDecoration(
              labelText: '6 位數驗證碼',
              border: OutlineInputBorder(),
            ),
          ),
          FilledButton(
            key: const Key('verify-submit'),
            onPressed: _busy ? null : _verify,
            child: const Text('驗證'),
          ),
          TextButton(
            onPressed: _busy ? null : _resend,
            child: const Text('重新發送驗證碼'),
          ),
        ],
      ),
    );
  }
}
