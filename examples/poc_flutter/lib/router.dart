import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'features/articles/article_detail_screen.dart';
import 'features/articles/articles_screen.dart';
import 'features/auth/auth_controller.dart';
import 'features/auth/email_sent_screen.dart';
import 'features/auth/forgot_password_screen.dart';
import 'features/auth/login_screen.dart';
import 'features/auth/register_screen.dart';
import 'features/auth/verify_account_screen.dart';
import 'features/auth/verify_phone_screen.dart';
import 'features/bookings/my_bookings_screen.dart';
import 'features/calendar/calendar_screen.dart';
import 'features/calendar/event_detail_screen.dart';
import 'features/health/health_screen.dart';
import 'features/notices/notice_detail_screen.dart';
import 'features/notices/notices_screen.dart';
import 'features/notices/public_notices_screen.dart';
import 'features/notifications/notification_detail_screen.dart';
import 'features/notifications/notifications_screen.dart';
import 'features/profile/change_email_screen.dart';
import 'features/profile/change_phone_screen.dart';
import 'features/profile/profile_screen.dart';
import 'features/s9/checkin_screens.dart';
import 'features/s9/discovery_screens.dart';
import 'features/s9/group_booking_screen.dart';
import 'features/s9/my_calendar_screen.dart';
import 'features/s9/verification_marketing.dart';

const _publicPaths = {
  '/login',
  '/register',
  '/verify-phone',
  '/email-sent',
  '/forgot-password',
  '/public-notices',
  '/health',
};

/// Screens an unverified member may still open (verification gate).
const _verificationPaths = {
  '/verify-account',
  '/profile/email',
  '/profile/phone',
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
      // S8: articles are open to visitors; the server decides what they may read.
      final isPublic =
          _publicPaths.contains(path) ||
          path == '/articles' ||
          path.startsWith('/articles/') ||
          path == '/search';
      if (status == AuthStatus.signedOut) {
        if (isPublic) return null;
        // S9 M14: come back to a shared link after logging in (in-App paths only).
        final back = safeReturnPath(state.uri.path);
        return back == null ? '/login' : '/login?from=${Uri.encodeComponent(back)}';
      }
      // Signed in but the verification policy is not satisfied yet.
      final profile = ref.read(authControllerProvider).profile;
      if (profile != null && !profile.verificationSatisfied) {
        return _verificationPaths.contains(path) ? null : '/verify-account';
      }
      // Signed in: leave the splash / auth / gate screens for the notice list.
      final from = safeReturnPath(state.uri.queryParameters['from']);
      if (path == '/login' && from != null) return from;
      if (path == '/splash' ||
          path == '/login' ||
          path == '/register' ||
          path == '/forgot-password' ||
          path == '/verify-account') {
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
        path: '/articles',
        builder: (context, state) => const ArticlesScreen(),
      ),
      GoRoute(
        path: '/articles/:id',
        builder: (context, state) =>
            ArticleDetailScreen(id: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/calendar',
        builder: (context, state) => const CalendarScreen(),
      ),
      GoRoute(
        path: '/me/bookings',
        builder: (context, state) => const MyBookingsScreen(),
      ),
      GoRoute(
        path: '/events/:id',
        builder: (context, state) =>
            EventDetailScreen(id: state.pathParameters['id']!),
      ),
      // ---- S9 ----
      GoRoute(
        path: '/events/:id/group-booking',
        builder: (context, state) =>
            GroupBookingScreen(eventId: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/events/:id/checkin',
        builder: (context, state) =>
            CheckinScreen(eventId: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/events/:id/checkin/pass',
        builder: (context, state) =>
            CheckinPassScreen(eventId: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/events/:id/checkin/staff',
        builder: (context, state) =>
            StaffCheckinScreen(eventId: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/checkin/location/:token',
        builder: (context, state) =>
            CheckinLocationScreen(token: state.pathParameters['token']!),
      ),
      GoRoute(
        path: '/me/booking-groups',
        builder: (context, state) => const MyBookingGroupsScreen(),
      ),
      GoRoute(
        path: '/me/calendar',
        builder: (context, state) => const MyCalendarScreen(),
      ),
      GoRoute(
        path: '/search',
        builder: (context, state) =>
            SearchScreen(initialTagId: state.uri.queryParameters['tag']),
      ),
      GoRoute(
        path: '/me/bookmarks',
        builder: (context, state) => const BookmarksScreen(),
      ),
      GoRoute(
        path: '/me/verifications',
        builder: (context, state) => const VerificationScreen(),
      ),
      GoRoute(
        path: '/notifications',
        builder: (context, state) => const NotificationsScreen(),
      ),
      GoRoute(
        path: '/notifications/:id',
        builder: (context, state) =>
            NotificationDetailScreen(id: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/profile',
        builder: (context, state) => const ProfileScreen(),
      ),
      GoRoute(
        path: '/profile/email',
        builder: (context, state) => const ChangeEmailScreen(),
      ),
      GoRoute(
        path: '/profile/phone',
        builder: (context, state) => const ChangePhoneScreen(),
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
        path: '/verify-account',
        builder: (context, state) => const VerifyAccountScreen(),
      ),
      GoRoute(
        path: '/public-notices',
        builder: (context, state) => const PublicNoticesScreen(),
      ),
      GoRoute(
        path: '/forgot-password',
        builder: (context, state) => const ForgotPasswordScreen(),
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
