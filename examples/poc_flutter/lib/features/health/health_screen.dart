import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_exception.dart';
import '../../core/providers.dart';
import 'health_repository.dart';

/// Phase 1 connectivity check screen.
///
/// Status is shown with text labels and a bordered box, not by color alone.
class HealthScreen extends ConsumerWidget {
  const HealthScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(appConfigProvider);
    final health = ref.watch(healthStatusProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('連線檢查')),
      body: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('環境：${config.environment}'),
            const SizedBox(height: 8),
            Text('API：${config.apiBaseUrl}'),
            const SizedBox(height: 24),
            health.when(
              data: (status) => StatusBox(
                label: status.isOk ? '【正常】API 連線正常' : '【異常】API 回應異常',
                detail: '資料庫：${status.database}',
              ),
              error: (error, _) => StatusBox(
                label: '【失敗】無法取得 API 狀態',
                detail: error is ApiException
                    ? '${error.code}（request_id：${error.requestId ?? '無'}）'
                    : '發生未預期的錯誤',
              ),
              loading: () => const Text('【檢查中】正在連線…'),
            ),
            const SizedBox(height: 16),
            OutlinedButton(
              onPressed: () => ref.invalidate(healthStatusProvider),
              child: const Text('重新檢查'),
            ),
          ],
        ),
      ),
    );
  }
}

class StatusBox extends StatelessWidget {
  const StatusBox({super.key, required this.label, required this.detail});

  final String label;
  final String detail;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(border: Border.all()),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 4),
          Text(detail),
        ],
      ),
    );
  }
}
