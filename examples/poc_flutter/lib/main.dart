import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';

void main() {
  // No automatic provider retries: a failed request is shown to the member,
  // who retries explicitly (avoids repeated calls, e.g. SMS endpoints).
  runApp(
    ProviderScope(retry: (retryCount, error) => null, child: const S1PocApp()),
  );
}
