import 'dart:typed_data';

import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/providers.dart';
import '../auth/auth_controller.dart';

final membershipProvider = FutureProvider.autoDispose<Membership>(
  (ref) => ref.watch(membershipApiProvider).mine(),
);

/// Avatar bytes of the signed-in member; reloads when the profile changes.
final avatarBytesProvider = FutureProvider.autoDispose<Uint8List?>((ref) {
  final hasAvatar = ref.watch(
    authControllerProvider.select((state) => state.profile?.hasAvatar ?? false),
  );
  if (!hasAvatar) return Future.value(null);
  return ref.watch(avatarRepositoryProvider).fetch();
});

/// Picks a photo from the gallery (overridden in tests).
typedef PickedImage = ({Uint8List bytes, String filename});

final avatarPickerProvider = Provider<Future<PickedImage?> Function()>((ref) {
  return () async {
    final file = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      maxWidth: 1024,
      maxHeight: 1024,
      imageQuality: 90,
    );
    if (file == null) return null;
    return (bytes: await file.readAsBytes(), filename: file.name);
  };
});
