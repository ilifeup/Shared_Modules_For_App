import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'router.dart';

class S1PocApp extends ConsumerWidget {
  const S1PocApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return MaterialApp.router(
      title: 'S1 PoC',
      theme: ThemeData(colorSchemeSeed: Colors.indigo),
      routerConfig: ref.watch(routerProvider),
    );
  }
}
