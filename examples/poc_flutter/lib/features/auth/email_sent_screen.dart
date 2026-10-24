import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers.dart';
import '../../ui/status_box.dart';

class EmailSentScreen extends ConsumerStatefulWidget {
  const EmailSentScreen({super.key, required this.email});

  final String email;

  @override
  ConsumerState<EmailSentScreen> createState() => _EmailSentScreenState();
}

class _EmailSentScreenState extends ConsumerState<EmailSentScreen> {
  bool _busy = false;
  Object? _error;
  bool _resent = false;

  Future<void> _resend() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final api = ref.read(registrationApiProvider);
      await api.resendEmailVerification(widget.email);
      if (mounted) setState(() => _resent = true);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Email 驗證')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          StatusBox(
            label: '【待驗證】驗證信已寄出',
            detail: '請到 ${widget.email} 收信，點擊信中的連結完成驗證後再登入。',
          ),
          if (_resent) const StatusBox(label: '【已重寄】', detail: '請再次檢查信箱。'),
          if (_error != null) ErrorBox(error: _error!),
          FilledButton(
            onPressed: () => context.go('/login'),
            child: const Text('已完成驗證，前往登入'),
          ),
          TextButton(
            onPressed: _busy ? null : _resend,
            child: const Text('重新寄送驗證信'),
          ),
        ],
      ),
    );
  }
}
