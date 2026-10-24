import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'features/auth/auth_controller.dart';
import 'features/auth/email_sent_screen.dart';
import 'features/auth/login_screen.dart';
import 'features/auth/register_screen.dart';
import 'features/auth/verify_phone_screen.dart';
import 'features/health/health_screen.dart';
import 'features/notices/notice_detail_screen.dart';
import 'features/notices/notices_screen.dart';
import 'features/profile/profile_screen.dart';

const _publicPaths = {
  '/login',
  '/register',
  '/verify-phone',
  '/email-sent',
  '/health',
};

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier<int>(0);
  ref.listen(authControllerProvider, (_, _) => refresh.value++);

  final router = GoRouter(
    initialLocation: '/',
    refreshListenable: refresh,
    redirect: (context, state) {
      final status = ref.read(authControllerProvider).status;
      final path = state.matchedLocation;
      if (status == AuthStatus.unknown) {
        return path == '/splash' ? null : '/splash';
      }
      final isPublic = _publicPaths.contains(path);
      if (status == AuthStatus.signedOut) {
        return isPublic ? null : '/login';
      }
      // Signed in: leave the splash / auth screens for the notice list.
      if (path == '/splash' || path == '/login' || path == '/register') {
        return '/';
      }
      return null;
    },
    routes: [
      GoRoute(
        path: '/splash',
        builder: (context, state) =>
            const Scaffold(body: Center(child: Text('【啟動中】'))),
      ),
      GoRoute(path: '/', builder: (context, state) => const NoticesScreen()),
      GoRoute(
        path: '/notices/:id',
        builder: (context, state) =>
            NoticeDetailScreen(id: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/profile',
        builder: (context, state) => const ProfileScreen(),
      ),
      GoRoute(path: '/login', builder: (context, state) => const LoginScreen()),
      GoRoute(
        path: '/register',
        builder: (context, state) => const RegisterScreen(),
      ),
      GoRoute(
        path: '/verify-phone',
        builder: (context, state) =>
            VerifyPhoneScreen(initialPhone: state.uri.queryParameters['phone']),
      ),
      GoRoute(
        path: '/email-sent',
        builder: (context, state) =>
            EmailSentScreen(email: state.uri.queryParameters['email'] ?? ''),
      ),
      GoRoute(
        path: '/health',
        builder: (context, state) => const HealthScreen(),
      ),
    ],
  );
  ref.onDispose(() {
    router.dispose();
    refresh.dispose();
  });
  return router;
});
