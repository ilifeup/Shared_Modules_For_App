import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers.dart';
import '../../ui/status_box.dart';

/// Forgot password: by email (reset link opens a web page) or by phone
/// (password-reset code + new password in the App). Every token of the
/// account is revoked after a successful reset.
class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  ConsumerState<ForgotPasswordScreen> createState() =>
      _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends ConsumerState<ForgotPasswordScreen> {
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();
  bool _byPhone = false;
  bool _codeSent = false;
  bool _busy = false;
  Object? _error;
  String? _notice;
  String? _localError;

  @override
  void dispose() {
    _email.dispose();
    _phone.dispose();
    _code.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  Future<bool> _run(Future<void> Function() action, String success) async {
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });
    try {
      await action();
      if (mounted) setState(() => _notice = success);
      return true;
    } catch (error) {
      if (mounted) setState(() => _error = error);
      return false;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _invalid(String message) {
    setState(() {
      _localError = message;
      _error = null;
      _notice = null;
    });
  }

  Future<void> _sendEmail() async {
    if (!_email.text.contains('@')) {
      _invalid('請輸入 Email。');
      return;
    }
    setState(() => _localError = null);
    final api = ref.read(passwordResetApiProvider);
    await _run(
      () => api.requestEmailLink(_email.text),
      '若此 Email 已註冊，重設密碼連結已寄出。請在 60 分鐘內開啟信中連結設定新密碼。',
    );
  }

  Future<void> _sendCode() async {
    if (_phone.text.trim().isEmpty) {
      _invalid('請輸入手機號碼。');
      return;
    }
    setState(() => _localError = null);
    final api = ref.read(passwordResetApiProvider);
    final ok = await _run(
      () => api.requestPhoneCode(_phone.text),
      '若此手機已註冊，密碼重設驗證碼已發送。',
    );
    if (ok && mounted) setState(() => _codeSent = true);
  }

  Future<void> _resetByPhone() async {
    if (!RegExp(r'^\d{6}$').hasMatch(_code.text.trim())) {
      _invalid('請輸入 6 位數驗證碼。');
      return;
    }
    if (_password.text.length < 8) {
      _invalid('密碼至少 8 個字元。');
      return;
    }
    if (_password.text != _confirmation.text) {
      _invalid('兩次輸入的密碼不一致。');
      return;
    }
    setState(() => _localError = null);
    final api = ref.read(passwordResetApiProvider);
    final ok = await _run(
      () => api.resetWithPhoneCode(
        phone: _phone.text,
        code: _code.text,
        password: _password.text,
        passwordConfirmation: _confirmation.text,
      ),
      '',
    );
    if (ok && mounted) {
      context.go('/login');
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('密碼已重設，所有裝置皆已登出，請以新密碼登入')));
    }
  }

  Widget _field(
    String key,
    TextEditingController controller,
    String label, {
    bool obscure = false,
    TextInputType? keyboard,
    int? maxLength,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextField(
        key: Key(key),
        controller: controller,
        obscureText: obscure,
        keyboardType: keyboard,
        maxLength: maxLength,
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
      appBar: AppBar(title: const Text('忘記密碼')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          SegmentedButton<bool>(
            segments: const [
              ButtonSegment(value: false, label: Text('用 Email')),
              ButtonSegment(value: true, label: Text('用手機')),
            ],
            selected: {_byPhone},
            onSelectionChanged: _busy
                ? null
                : (value) => setState(() {
                    _byPhone = value.first;
                    _error = null;
                    _notice = null;
                    _localError = null;
                  }),
          ),
          const SizedBox(height: 16),
          if (_localError != null)
            StatusBox(label: '【請修正】', detail: _localError!),
          if (_notice != null && _notice!.isNotEmpty)
            StatusBox(label: '【已送出】', detail: _notice!),
          if (_error != null) ErrorBox(error: _error!),
          if (!_byPhone) ...[
            _field(
              'forgot-email',
              _email,
              'Email',
              keyboard: TextInputType.emailAddress,
            ),
            FilledButton(
              key: const Key('forgot-email-submit'),
              onPressed: _busy ? null : _sendEmail,
              child: const Text('寄送重設密碼連結'),
            ),
          ] else ...[
            _field(
              'forgot-phone',
              _phone,
              '手機號碼',
              keyboard: TextInputType.phone,
            ),
            OutlinedButton(
              key: const Key('forgot-send-code'),
              onPressed: _busy ? null : _sendCode,
              child: Text(_codeSent ? '重新發送驗證碼' : '發送密碼重設驗證碼'),
            ),
            const SizedBox(height: 12),
            if (_codeSent) ...[
              _field(
                'forgot-code',
                _code,
                '6 位數驗證碼',
                keyboard: TextInputType.number,
                maxLength: 6,
              ),
              _field(
                'forgot-password',
                _password,
                '新密碼（至少 8 字元）',
                obscure: true,
              ),
              _field('forgot-confirm', _confirmation, '再次輸入新密碼', obscure: true),
              FilledButton(
                key: const Key('forgot-reset-submit'),
                onPressed: _busy ? null : _resetByPhone,
                child: const Text('設定新密碼'),
              ),
            ],
          ],
        ],
      ),
    );
  }
}
