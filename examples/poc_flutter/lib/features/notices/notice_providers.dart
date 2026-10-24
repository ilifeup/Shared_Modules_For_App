import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';

final noticesProvider = FutureProvider.autoDispose<List<Notice>>(
  (ref) => ref.watch(memberApiProvider).notices(),
);

final noticeProvider = FutureProvider.autoDispose.family<Notice, String>(
  (ref, id) => ref.watch(memberApiProvider).notice(id),
);

String formatPublishedAt(DateTime? value) {
  if (value == null) return '';
  final local = value.toLocal();
  String two(int n) => n.toString().padLeft(2, '0');
  return '${local.year}-${two(local.month)}-${two(local.day)} '
      '${two(local.hour)}:${two(local.minute)}';
}
