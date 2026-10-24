import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../ui/status_box.dart';
import 'auth_controller.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _identifier = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  Object? _error;

  @override
  void dispose() {
    _identifier.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_identifier.text.trim().isEmpty || _password.text.isEmpty) {
      setState(() => _error = null);
      showFeedback(ScaffoldMessenger.of(context), '請輸入帳號與密碼');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final auth = ref.read(authControllerProvider.notifier);
      await auth.login(_identifier.text.trim(), _password.text);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('會員登入')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_error != null) ErrorBox(error: _error!),
          TextField(
            key: const Key('login-identifier'),
            controller: _identifier,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(
              labelText: 'Email 或手機號碼',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('login-password'),
            controller: _password,
            obscureText: true,
            decoration: const InputDecoration(
              labelText: '密碼',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 16),
          FilledButton(
            key: const Key('login-submit'),
            onPressed: _busy ? null : _submit,
            child: Text(_busy ? '登入中…' : '登入'),
          ),
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: _busy ? null : () => context.push('/register'),
            child: const Text('註冊新帳號'),
          ),
          TextButton(
            key: const Key('login-forgot'),
            onPressed: _busy ? null : () => context.push('/forgot-password'),
            child: const Text('忘記密碼'),
          ),
          TextButton(
            onPressed: _busy ? null : () => context.push('/verify-phone'),
            child: const Text('輸入手機驗證碼'),
          ),
          TextButton(
            key: const Key('login-public-notices'),
            onPressed: () => context.push('/public-notices'),
            child: const Text('查看公開公告'),
          ),
          TextButton(
            onPressed: () => context.push('/health'),
            child: const Text('連線檢查'),
          ),
        ],
      ),
    );
  }
}
