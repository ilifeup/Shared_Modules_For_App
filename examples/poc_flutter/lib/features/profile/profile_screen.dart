import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../ui/status_box.dart';
import '../auth/auth_controller.dart';

class ProfileScreen extends ConsumerStatefulWidget {
  const ProfileScreen({super.key});

  @override
  ConsumerState<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends ConsumerState<ProfileScreen> {
  late final _displayName = TextEditingController(
    text: ref.read(authControllerProvider).profile?.displayName ?? '',
  );
  bool _busy = false;
  Object? _error;
  String? _message;

  @override
  void dispose() {
    _displayName.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!DisplayName.isValid(_displayName.text)) {
      setState(() {
        _error = null;
        _message = '【請修正】顯示名稱需為 1–80 字。';
      });
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });
    try {
      final api = ref.read(memberApiProvider);
      final profile = await api.updateDisplayName(_displayName.text);
      ref.read(authControllerProvider.notifier).updateProfile(profile);
      if (mounted) setState(() => _message = '【已儲存】顯示名稱已更新。');
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _verified(bool value) => value ? '✔ 已驗證' : '✖ 未驗證';

  @override
  Widget build(BuildContext context) {
    final profile = ref.watch(authControllerProvider).profile;

    return Scaffold(
      appBar: AppBar(title: const Text('個人資料')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_message != null) StatusBox(label: _message!, detail: ''),
          if (_error != null) ErrorBox(error: _error!),
          if (profile != null) ...[
            Text('Email：${profile.email ?? '未設定'}'),
            if (profile.email != null)
              Text('Email 驗證：${_verified(profile.emailVerified)}'),
            const SizedBox(height: 4),
            Text('手機：${profile.phone ?? '未設定'}'),
            if (profile.phone != null)
              Text('手機驗證：${_verified(profile.phoneVerified)}'),
            const SizedBox(height: 16),
          ],
          TextField(
            key: const Key('profile-display-name'),
            controller: _displayName,
            decoration: const InputDecoration(
              labelText: '顯示名稱',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          FilledButton(
            key: const Key('profile-save'),
            onPressed: _busy ? null : _save,
            child: const Text('儲存'),
          ),
          const Divider(height: 32),
          OutlinedButton(
            key: const Key('logout'),
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
