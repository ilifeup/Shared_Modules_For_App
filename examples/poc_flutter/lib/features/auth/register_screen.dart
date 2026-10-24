import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers.dart';
import '../../ui/status_box.dart';

class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();
  bool _busy = false;
  Object? _error;
  String? _localError;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  String? _validate() {
    if (!DisplayName.isValid(_name.text)) {
      return '請輸入姓名（1–80 字）。';
    }
    if (_email.text.trim().isEmpty || !_email.text.contains('@')) {
      return '請輸入 Email。';
    }
    if (_phone.text.trim().isEmpty) {
      return '請輸入手機號碼。';
    }
    if (_password.text.length < 8) {
      return '密碼至少 8 個字元。';
    }
    if (_password.text != _confirmation.text) {
      return '兩次輸入的密碼不一致。';
    }
    return null;
  }

  Future<void> _submit() async {
    final localError = _validate();
    setState(() {
      _localError = localError;
      _error = null;
    });
    if (localError != null) return;

    setState(() => _busy = true);
    try {
      final api = ref.read(registrationApiProvider);
      final result = await api.register(
        name: _name.text,
        email: _email.text,
        phone: _phone.text,
        password: _password.text,
        passwordConfirmation: _confirmation.text,
      );
      if (!mounted) return;
      // Under phone_required the SMS code is the step that unlocks the App.
      if (result.needsPhoneVerification) {
        final phone = Uri.encodeQueryComponent(_phone.text.trim());
        context.go('/verify-phone?phone=$phone');
      } else if (result.needsEmailVerification) {
        final email = Uri.encodeQueryComponent(_email.text.trim());
        context.go('/email-sent?email=$email');
      } else {
        context.go('/login');
      }
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _field(
    String key,
    TextEditingController controller,
    String label, {
    bool obscure = false,
    TextInputType? keyboard,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextField(
        key: Key(key),
        controller: controller,
        obscureText: obscure,
        keyboardType: keyboard,
        decoration: InputDecoration(
          labelText: label,
          border: const OutlineInputBorder(),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('註冊')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_localError != null)
            StatusBox(label: '【請修正】', detail: _localError!),
          if (_error != null) ErrorBox(error: _error!),
          _field('register-name', _name, '姓名（必填）'),
          _field(
            'register-email',
            _email,
            'Email（必填）',
            keyboard: TextInputType.emailAddress,
          ),
          _field(
            'register-phone',
            _phone,
            '手機號碼（必填，例如 0912345678）',
            keyboard: TextInputType.phone,
          ),
          _field('register-password', _password, '密碼（至少 8 字元）', obscure: true),
          _field('register-confirm', _confirmation, '再次輸入密碼', obscure: true),
          FilledButton(
            key: const Key('register-submit'),
            onPressed: _busy ? null : _submit,
            child: Text(_busy ? '送出中…' : '註冊'),
          ),
        ],
      ),
    );
  }
}
