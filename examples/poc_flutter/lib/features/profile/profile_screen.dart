import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers.dart';
import '../../ui/status_box.dart';
import '../auth/auth_controller.dart';
import 'profile_providers.dart';

class ProfileScreen extends ConsumerStatefulWidget {
  const ProfileScreen({super.key});

  @override
  ConsumerState<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends ConsumerState<ProfileScreen> {
  late final _name = TextEditingController(
    text: ref.read(authControllerProvider).profile?.name ?? '',
  );
  late final _displayName = TextEditingController(
    text: ref.read(authControllerProvider).profile?.displayName ?? '',
  );
  bool _busy = false;
  Object? _error;
  String? _message;

  @override
  void dispose() {
    _name.dispose();
    _displayName.dispose();
    super.dispose();
  }

  Future<void> _run(Future<void> Function() action, String success) async {
    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });
    try {
      await action();
      if (mounted) setState(() => _message = success);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) {
      setState(() {
        _error = null;
        _message = '【請修正】請輸入姓名。';
      });
      return;
    }
    if (!DisplayName.isValid(_displayName.text)) {
      setState(() {
        _error = null;
        _message = '【請修正】顯示名稱需為 1–80 字。';
      });
      return;
    }
    await _run(() async {
      final profile = await ref
          .read(profileApiProvider)
          .update(name: _name.text, displayName: _displayName.text);
      ref.read(authControllerProvider.notifier).updateProfile(profile);
    }, '【已儲存】個人資料已更新。');
  }

  Future<void> _pickAvatar() async {
    final picked = await ref.read(avatarPickerProvider)();
    if (picked == null) return;
    await _run(() async {
      await ref
          .read(avatarRepositoryProvider)
          .upload(picked.bytes, picked.filename);
      await ref.read(authControllerProvider.notifier).refreshProfile();
      ref.invalidate(avatarBytesProvider);
    }, '【已儲存】頭像已更新。');
  }

  Future<void> _deleteAvatar() => _run(() async {
    await ref.read(avatarRepositoryProvider).delete();
    await ref.read(authControllerProvider.notifier).refreshProfile();
    ref.invalidate(avatarBytesProvider);
  }, '【已移除】頭像已刪除。');

  String _verified(bool value) => value ? '✔ 已驗證' : '✖ 未驗證';

  static const _fieldLabels = {'name': '姓名', 'email': 'Email', 'phone': '手機號碼'};

  Widget _avatar() {
    final bytes = ref.watch(avatarBytesProvider);
    return Row(
      children: [
        CircleAvatar(
          radius: 36,
          backgroundImage: bytes.value != null
              ? MemoryImage(bytes.value!)
              : null,
          child: bytes.value == null
              ? const Icon(Icons.person, size: 36)
              : null,
        ),
        const SizedBox(width: 16),
        Expanded(
          child: Wrap(
            spacing: 8,
            children: [
              OutlinedButton(
                key: const Key('avatar-pick'),
                onPressed: _busy ? null : _pickAvatar,
                child: const Text('更換頭像'),
              ),
              if (ref.watch(authControllerProvider).profile?.hasAvatar ?? false)
                TextButton(
                  key: const Key('avatar-delete'),
                  onPressed: _busy ? null : _deleteAvatar,
                  child: const Text('移除'),
                ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _membership() {
    final membership = ref.watch(membershipProvider);
    return membership.when(
      loading: () => const StatusBox(label: '【會員等級】讀取中…', detail: ''),
      error: (error, _) => ErrorBox(error: error),
      data: (value) {
        final until = value.endsAt;
        return StatusBox(
          key: const Key('membership-card'),
          label: '【會員等級】${value.level.name}',
          detail: [
            if (until != null)
              '有效至 ${until.toLocal().toString().substring(0, 10)}',
            value.level.benefits?.isNotEmpty == true
                ? '待遇：${value.level.benefits}'
                : '待遇：（尚未設定）',
          ].join('\n'),
        );
      },
    );
  }

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
          if (profile != null && !profile.profileComplete)
            StatusBox(
              key: const Key('profile-incomplete'),
              label: '【請補齊資料】',
              detail:
                  '尚缺：${profile.missingFields.map((f) => _fieldLabels[f] ?? f).join('、')}',
            ),
          _avatar(),
          const SizedBox(height: 16),
          _membership(),
          if (profile != null) ...[
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text('Email：${profile.email ?? '未設定'}'),
              subtitle: Text(
                profile.email == null
                    ? '請新增 Email'
                    : 'Email 驗證：${_verified(profile.emailVerified)}',
              ),
              trailing: TextButton(
                key: const Key('profile-change-email'),
                onPressed: _busy ? null : () => context.push('/profile/email'),
                child: Text(profile.email == null ? '新增' : '變更'),
              ),
            ),
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text('手機：${profile.phone ?? '未設定'}'),
              subtitle: Text(
                profile.phone == null
                    ? '請新增手機號碼'
                    : '手機驗證：${_verified(profile.phoneVerified)}',
              ),
              trailing: TextButton(
                key: const Key('profile-change-phone'),
                onPressed: _busy ? null : () => context.push('/profile/phone'),
                child: Text(profile.phone == null ? '新增' : '變更'),
              ),
            ),
            const SizedBox(height: 8),
          ],
          TextField(
            key: const Key('profile-name'),
            controller: _name,
            decoration: const InputDecoration(
              labelText: '姓名（必填）',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
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
