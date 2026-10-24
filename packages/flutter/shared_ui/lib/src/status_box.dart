import 'package:flutter/material.dart';

/// Bordered message with a text label, so state is never shown by color only.
/// Also used for empty states (e.g. label 【目前沒有通知】).
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
