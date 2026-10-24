import 'package:flutter/material.dart';

/// An error message (the App's own error widget) with a retry button below.
class ErrorRetry extends StatelessWidget {
  const ErrorRetry({
    super.key,
    required this.error,
    required this.onRetry,
    this.retryLabel = '重試',
  });

  final Widget error;
  final VoidCallback onRetry;
  final String retryLabel;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        error,
        OutlinedButton(onPressed: onRetry, child: Text(retryLabel)),
      ],
    );
  }
}
