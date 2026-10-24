import 'package:flutter/material.dart';

/// Outlined text field with a label and the standard bottom spacing used in forms.
class LabeledTextField extends StatelessWidget {
  const LabeledTextField({
    super.key,
    required this.controller,
    required this.label,
    this.fieldKey,
    this.obscureText = false,
    this.keyboardType,
    this.maxLength,
  });

  final TextEditingController controller;
  final String label;

  /// Key of the inner [TextField] (used by tests to enter text).
  final Key? fieldKey;
  final bool obscureText;
  final TextInputType? keyboardType;
  final int? maxLength;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextField(
        key: fieldKey,
        controller: controller,
        obscureText: obscureText,
        keyboardType: keyboardType,
        maxLength: maxLength,
        decoration: InputDecoration(
          labelText: label,
          border: const OutlineInputBorder(),
        ),
      ),
    );
  }
}
