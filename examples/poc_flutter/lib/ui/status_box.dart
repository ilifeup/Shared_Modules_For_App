import 'package:eachcore_shared_ui/eachcore_shared_ui.dart';
import 'package:flutter/material.dart';

import '../core/network/api_exception.dart';

// S7: StatusBox, LoadingView, ErrorRetry, LabeledTextField and showFeedback
// come from the shared UI package.
export 'package:eachcore_shared_ui/eachcore_shared_ui.dart';

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
