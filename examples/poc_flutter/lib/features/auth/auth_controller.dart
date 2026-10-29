import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_exception.dart';
import '../../core/providers.dart';
import '../../core/storage/token_storage.dart';

enum AuthStatus { unknown, signedOut, signedIn }

class AuthState {
  const AuthState._(this.status, this.profile);

  const AuthState.unknown() : this._(AuthStatus.unknown, null);

  const AuthState.signedOut() : this._(AuthStatus.signedOut, null);

  const AuthState.signedIn(MemberProfile profile)
    : this._(AuthStatus.signedIn, profile);

  final AuthStatus status;
  final MemberProfile? profile;
}

final authControllerProvider = NotifierProvider<AuthController, AuthState>(
  AuthController.new,
);

/// Owns the member session: restore on start, login, logout, 401 handling.
///
/// The token lives only in secure storage; it is never logged or kept in
/// widget state.
class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() {
    Future.microtask(restore);
    return const AuthState.unknown();
  }

  /// Long-lived login: a stored token is reused until the server rejects it.
  Future<void> restore() async {
    final storage = ref.read(tokenStorageProvider);
    final token = await storage.read();
    if (token == null || token.isEmpty) {
      state = const AuthState.signedOut();
      return;
    }
    try {
      final profile = await ref.read(memberApiProvider).myProfile();
      state = AuthState.signedIn(profile);
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        try {
          await storage.clear();
        } finally {
          // Credential revocation must sign out even if secure storage fails.
          state = const AuthState.signedOut();
          // Unknown member identity: do not clear another account's pending data.
        }
      } else {
        // Offline or server error: keep the token, let the member retry.
        state = const AuthState.signedOut();
      }
    }
  }

  Future<void> login(String identifier, String password) async {
    final session = ref.read(sessionApiProvider);
    final token = await session.login(identifier, password);
    final storage = ref.read(tokenStorageProvider);
    await storage.write(token);
    try {
      final profile = await ref.read(memberApiProvider).myProfile();
      state = AuthState.signedIn(profile);
    } catch (_) {
      try {
        await storage.clear();
      } finally {
        state = const AuthState.signedOut();
      }
      rethrow;
    }
  }

  Future<void> logout() async {
    final memberId = state.profile?.id;
    try {
      await ref.read(sessionApiProvider).logout();
    } on ApiException {
      // The local credential is cleared even if the server is unreachable.
    } finally {
      try {
        await ref.read(tokenStorageProvider).clear();
      } finally {
        state = const AuthState.signedOut();
        if (memberId != null) await clearPendingBookingStorage(memberId);
      }
    }
  }

  /// Re-reads the profile, e.g. after a verification or a 403
  /// `VERIFICATION_REQUIRED`, so the router can open or close the gate.
  Future<void> refreshProfile() async {
    if (state.status != AuthStatus.signedIn) return;
    try {
      final profile = await ref.read(memberApiProvider).myProfile();
      state = AuthState.signedIn(profile);
    } on ApiException {
      // 401 is handled by the interceptor; other errors keep the current state.
    }
  }

  void updateProfile(MemberProfile profile) {
    state = AuthState.signedIn(profile);
  }

  void handleUnauthorized() {
    if (state.status == AuthStatus.signedIn) {
      state = const AuthState.signedOut();
    }
  }
}
