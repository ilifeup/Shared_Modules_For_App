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
