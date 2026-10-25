import 'package:dio/dio.dart';
import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../features/auth/auth_controller.dart';
import '../features/profile/avatar_repository.dart';
import 'config/app_config.dart';
import 'network/api_client.dart';
import 'network/dio_transport.dart';
import 'storage/token_storage.dart';

final appConfigProvider = Provider<AppConfig>(
  (ref) => AppConfig.fromEnvironment(),
);

final tokenStorageProvider = Provider<TokenStorage>(
  (ref) => SecureTokenStorage(),
);

final dioProvider = Provider<Dio>((ref) {
  final config = ref.watch(appConfigProvider);
  final dio = createApiClient(
    baseUrl: config.apiBaseUrl,
    tokenStorage: ref.watch(tokenStorageProvider),
    onUnauthorized: () =>
        ref.read(authControllerProvider.notifier).handleUnauthorized(),
    onVerificationRequired: () =>
        ref.read(authControllerProvider.notifier).refreshProfile(),
  );
  ref.onDispose(dio.close);
  return dio;
});

final apiTransportProvider = Provider<ApiTransport>(
  (ref) => DioTransport(ref.watch(dioProvider)),
);

final sessionApiProvider = Provider<SessionApi>(
  (ref) => SessionApi(ref.watch(apiTransportProvider)),
);

final memberApiProvider = Provider<MemberApi>(
  (ref) => MemberApi(ref.watch(apiTransportProvider)),
);

final registrationApiProvider = Provider<RegistrationApi>(
  (ref) => RegistrationApi(ref.watch(apiTransportProvider)),
);

final passwordResetApiProvider = Provider<PasswordResetApi>(
  (ref) => PasswordResetApi(ref.watch(apiTransportProvider)),
);

final profileApiProvider = Provider<ProfileApi>(
  (ref) => ProfileApi(ref.watch(apiTransportProvider)),
);

final membershipApiProvider = Provider<MembershipApi>(
  (ref) => MembershipApi(ref.watch(apiTransportProvider)),
);

final avatarRepositoryProvider = Provider<AvatarRepository>(
  (ref) => DioAvatarRepository(ref.watch(dioProvider)),
);

final noticeApiProvider = Provider<NoticeApi>(
  (ref) => NoticeApi(ref.watch(apiTransportProvider)),
);

final notificationApiProvider = Provider<NotificationApi>(
  (ref) => NotificationApi(ref.watch(apiTransportProvider)),
);

final eventApiProvider = Provider<EventApi>(
  (ref) => EventApi(ref.watch(apiTransportProvider)),
);

/// Current time (overridden in tests).
final clockProvider = Provider<DateTime Function()>((ref) => DateTime.now);

final bookingApiProvider = Provider<BookingApi>(
  (ref) => BookingApi(ref.watch(apiTransportProvider)),
);

final articleApiProvider = Provider<ArticleApi>(
  (ref) => ArticleApi(ref.watch(apiTransportProvider)),
);

// ---- S9 shared clients ----

final bookingGroupApiProvider = Provider<BookingGroupApi>(
  (ref) => BookingGroupApi(ref.watch(apiTransportProvider)),
);

final calendarApiProvider = Provider<CalendarApi>(
  (ref) => CalendarApi(ref.watch(apiTransportProvider)),
);

final checkinApiProvider = Provider<CheckinApi>(
  (ref) => CheckinApi(ref.watch(apiTransportProvider)),
);

final discoveryApiProvider = Provider<DiscoveryApi>(
  (ref) => DiscoveryApi(ref.watch(apiTransportProvider)),
);

final verificationApiProvider = Provider<VerificationApi>(
  (ref) => VerificationApi(ref.watch(apiTransportProvider)),
);

final marketingApiProvider = Provider<MarketingApi>(
  (ref) => MarketingApi(ref.watch(apiTransportProvider)),
);

final linkApiProvider = Provider<LinkApi>(
  (ref) => LinkApi(ref.watch(apiTransportProvider)),
);
