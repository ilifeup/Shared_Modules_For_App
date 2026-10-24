import 'package:flutter/material.dart';

import '../core/network/api_exception.dart';

/// Bordered message with a text label, so state is never shown by color only.
class StatusBox extends StatelessWidget {
  const StatusBox({super.key, required this.label, required this.detail});

  final String label;
  final String detail;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      margin: const EdgeInsets.only(bottom: 12),
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

/// Shows an API error with its code and request id for support.
class ErrorBox extends StatelessWidget {
  const ErrorBox({super.key, required this.error});

  final Object error;

  @override
  Widget build(BuildContext context) {
    final error = this.error;
    if (error is ApiException) {
      final fieldErrors = error.fields.values.expand((e) => e).join('\n');
      return StatusBox(
        label: '【錯誤】${error.message}',
        detail: [
          if (fieldErrors.isNotEmpty) fieldErrors,
          '代碼：${error.code}',
          if (error.requestId != null) 'request_id：${error.requestId}',
        ].join('\n'),
      );
    }
    return const StatusBox(label: '【錯誤】發生未預期的錯誤', detail: '請稍後再試。');
  }
}
