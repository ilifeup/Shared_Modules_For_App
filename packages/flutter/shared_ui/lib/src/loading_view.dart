import 'package:flutter/material.dart';

/// Centered text loading state (text, not only a spinner).
class LoadingView extends StatelessWidget {
  const LoadingView({super.key, this.label = '【載入中】'});

  final String label;

  @override
  Widget build(BuildContext context) => Center(child: Text(label));
}
