import 'package:flutter/material.dart';

/// Short success / failure message at the bottom of the screen.
///
/// Takes the [ScaffoldMessengerState] so callers can capture it before an
/// `await` or a navigation that disposes the current screen.
void showFeedback(ScaffoldMessengerState messenger, String message) {
  messenger.showSnackBar(SnackBar(content: Text(message)));
}
