import 'dart:async';

import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/device/device_services.dart';
import 'core/providers.dart';
import 'router.dart';

class S1PocApp extends ConsumerStatefulWidget {
  const S1PocApp({super.key});

  @override
  ConsumerState<S1PocApp> createState() => _S1PocAppState();
}

class _S1PocAppState extends ConsumerState<S1PocApp> {
  StreamSubscription<Uri>? _links;
  final _dedupe = DeepLinkDeduper();
  bool _coldStart = true;

  @override
  void initState() {
    super.initState();
    // S9 M14: App Links of the App's own domain only (cold and warm start).
    final host = ref.read(appConfigProvider).appLinkHost;
    if (host.isEmpty) return;
    final parser = DeepLinkParser({host.toLowerCase()});
    // Only a link delivered right at start-up is a cold start.
    Timer(const Duration(seconds: 3), () => _coldStart = false);
    _links = ref.read(deepLinkStreamProvider).listen((uri) {
      final target = parser.parse(uri);
      if (target == null || _dedupe.isDuplicate(uri)) return;
      // M14-D25: a cold-start link opens on top of the home page (back returns
      // home instead of closing the App); a warm-start link is pushed on top of
      // the current page (back returns there).
      final router = ref.read(routerProvider);
      if (_coldStart) {
        router.go('/');
      }
      _coldStart = false;
      unawaited(router.push(target.appPath));
      if (target.type != 'checkin_location') {
        // Counted only as an explicit open report (M17-D21); never blocks.
        unawaited(
          ref
              .read(linkApiProvider)
              .opened(target.type, target.id, utm: target.utm),
        );
      }
    }, onError: (Object _) {});
  }

  @override
  void dispose() {
    unawaited(_links?.cancel());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'S1 PoC',
      theme: ThemeData(colorSchemeSeed: Colors.indigo),
      routerConfig: ref.watch(routerProvider),
    );
  }
}
