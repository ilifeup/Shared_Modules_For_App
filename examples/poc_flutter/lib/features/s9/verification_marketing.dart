import 'package:dio/dio.dart';
import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/device/device_services.dart';
import '../../core/network/api_exception.dart';
import '../../core/providers.dart';
import '../../ui/status_box.dart';
import '../profile/profile_providers.dart';

final verificationTypesProvider =
    FutureProvider.autoDispose<List<VerificationType>>(
      (ref) => ref.watch(verificationApiProvider).types(),
    );

final myVerificationsProvider = FutureProvider.autoDispose<MyVerifications>(
  (ref) => ref.watch(verificationApiProvider).mine(),
);

/// Multipart application with private evidence (the shared transport is JSON only).
abstract interface class VerificationUploader {
  Future<void> apply(String typeId, String? note, List<PickedImage> files);
}

class DioVerificationUploader implements VerificationUploader {
  DioVerificationUploader(this._dio);

  final Dio _dio;

  @override
  Future<void> apply(
    String typeId,
    String? note,
    List<PickedImage> files,
  ) async {
    try {
      await _dio.post<Object?>(
        VerificationApi.applyPath,
        data: FormData.fromMap({
          'type_id': typeId,
          'note': ?note,
          'evidence[]': [
            for (final f in files)
              MultipartFile.fromBytes(f.bytes, filename: f.filename),
          ],
        }),
      );
    } on DioException catch (error) {
      throw ApiException.fromDio(error);
    }
  }
}

final verificationUploaderProvider = Provider<VerificationUploader>(
  (ref) => DioVerificationUploader(ref.watch(dioProvider)),
);

String verificationStatusLabel(String status) => switch (status) {
  'pending' => '【審核中】',
  'approved' => '【已通過】',
  'rejected' => '【未通過】',
  'withdrawn' => '【已撤回】',
  _ => status,
};

/// S9 M03 member side: apply (evidence is private), follow status, show or
/// hide badges. Verification is separate from roles and admin permissions.
class VerificationScreen extends ConsumerStatefulWidget {
  const VerificationScreen({super.key});

  @override
  ConsumerState<VerificationScreen> createState() => _VerificationScreenState();
}

class _VerificationScreenState extends ConsumerState<VerificationScreen> {
  bool _busy = false;
  Object? _error;
  final _files = <PickedImage>[];

  Future<void> _run(Future<void> Function() action) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await action();
      _files.clear();
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      ref.invalidate(myVerificationsProvider);
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _apply(VerificationType type) => _run(() async {
    if (type.requiresEvidence) {
      if (_files.isEmpty) {
        throw const ApiException(
          code: 'VALIDATION_FAILED',
          message: '此認證需要上傳證明文件。',
        );
      }
      await ref
          .read(verificationUploaderProvider)
          .apply(type.id, null, List.of(_files));
    } else {
      await ref.read(verificationApiProvider).applyWithoutEvidence(type.id);
    }
  });

  @override
  Widget build(BuildContext context) {
    final types = ref.watch(verificationTypesProvider);
    final mine = ref.watch(myVerificationsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('身分認證')),
      body: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          if (_error != null) ErrorBox(error: _error!),
          ...mine.maybeWhen(
            data: (m) => [
              for (final b in m.badges)
                SwitchListTile(
                  key: Key('badge-${b.id}'),
                  title: Text(
                    '${b.typeName}${b.isValid
                        ? ''
                        : b.state == 'expired'
                        ? '（已過期）'
                        : '（已撤銷）'}',
                  ),
                  subtitle: Text(b.isPublic ? '公開顯示於留言' : '不公開'),
                  value: b.isPublic,
                  onChanged: _busy || !b.isValid
                      ? null
                      : (v) => _run(
                          () => ref
                              .read(verificationApiProvider)
                              .setBadgePublic(b.id, v),
                        ),
                ),
              for (final r in m.requests)
                ListTile(
                  key: Key('request-${r.id}'),
                  title: Text(
                    '${r.typeName} ${verificationStatusLabel(r.status)}',
                  ),
                  subtitle: Text(
                    r.isPending
                        ? '核准 ${r.approvals} / ${r.requiredApprovals}'
                        : r.rejectionReason ?? '',
                  ),
                  trailing: r.isPending
                      ? TextButton(
                          key: Key('withdraw-${r.id}'),
                          onPressed: _busy
                              ? null
                              : () => _run(
                                  () => ref
                                      .read(verificationApiProvider)
                                      .withdraw(r.id),
                                ),
                          child: const Text('撤回'),
                        )
                      : null,
                ),
            ],
            orElse: () => const <Widget>[],
          ),
          const Divider(),
          Text('附件：${_files.length} 個（JPG／PNG，僅審核人員可查看）'),
          OutlinedButton(
            key: const Key('verification-pick'),
            onPressed: _busy || _files.length >= 5
                ? null
                : () async {
                    final picked = await ref.read(avatarPickerProvider)();
                    if (picked != null && mounted)
                      setState(() => _files.add(picked));
                  },
            child: const Text('選擇證明文件'),
          ),
          ...types.when(
            loading: () => const [LoadingView()],
            error: (error, _) => [ErrorBox(error: error)],
            data: (list) => [
              for (final t in list)
                Card(
                  child: ListTile(
                    key: Key('vtype-${t.id}'),
                    title: Text(t.name),
                    subtitle: Text(
                      [
                        ?t.description,
                        if (t.requiresEvidence) '需上傳證明文件',
                        if (t.requiredApprovals > 1)
                          '需 ${t.requiredApprovals} 位審核人員核准',
                        if (t.validityDays != null) '有效 ${t.validityDays} 天',
                      ].join('・'),
                    ),
                    trailing: TextButton(
                      key: Key('apply-${t.id}'),
                      onPressed: _busy ? null : () => _apply(t),
                      child: const Text('申請'),
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

final placementProvider = FutureProvider.autoDispose
    .family<List<MarketingItem>, String>(
      (ref, code) => ref.watch(marketingApiProvider).placement(code),
    );

/// S9 M15 placement banner. Never blocks the screen: loading, errors and empty
/// placements render nothing. Taps are counted by M17 and open via M14 paths.
class MarketingBanner extends ConsumerWidget {
  const MarketingBanner({super.key, this.code = 'home_banner'});

  final String code;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = ref.watch(placementProvider(code)).value ?? const [];
    if (items.isEmpty) return const SizedBox.shrink();
    return Column(
      children: [
        for (final item in items)
          Card(
            key: Key('banner-${item.id}'),
            child: ListTile(
              title: Text(item.title),
              subtitle: item.summary != null ? Text(item.summary!) : null,
              trailing: TextButton(
                onPressed: () async {
                  final router = GoRouter.maybeOf(context);
                  await ref
                      .read(linkApiProvider)
                      .click('campaign', item.id, utm: item.utm);
                  if (item.isExternal) {
                    await ref.read(openUrlProvider)(Uri.parse(item.url));
                  } else if (item.appPath != null && router != null) {
                    await router.push(item.appPath!);
                  }
                },
                child: Text(item.buttonText),
              ),
            ),
          ),
      ],
    );
  }
}
