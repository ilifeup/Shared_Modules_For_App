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
      showFeedback(ScaffoldMessenger.of(context), '密碼已重設，所有裝置皆已登出，請以新密碼登入');
    }
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
            LabeledTextField(
              fieldKey: const Key('forgot-email'),
              controller: _email,
              label: 'Email',
              keyboardType: TextInputType.emailAddress,
            ),
            FilledButton(
              key: const Key('forgot-email-submit'),
              onPressed: _busy ? null : _sendEmail,
              child: const Text('寄送重設密碼連結'),
            ),
          ] else ...[
            LabeledTextField(
              fieldKey: const Key('forgot-phone'),
              controller: _phone,
              label: '手機號碼',
              keyboardType: TextInputType.phone,
            ),
            OutlinedButton(
              key: const Key('forgot-send-code'),
              onPressed: _busy ? null : _sendCode,
              child: Text(_codeSent ? '重新發送驗證碼' : '發送密碼重設驗證碼'),
            ),
            const SizedBox(height: 12),
            if (_codeSent) ...[
              LabeledTextField(
                fieldKey: const Key('forgot-code'),
                controller: _code,
                label: '6 位數驗證碼',
                keyboardType: TextInputType.number,
                maxLength: 6,
              ),
              LabeledTextField(
                fieldKey: const Key('forgot-password'),
                controller: _password,
                label: '新密碼（至少 8 字元）',
                obscureText: true,
              ),
              LabeledTextField(
                fieldKey: const Key('forgot-confirm'),
                controller: _confirmation,
                label: '再次輸入新密碼',
                obscureText: true,
              ),
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
