/// Compile-time environment configuration.
///
/// Values come from `--dart-define-from-file=env/<env>.json`.
/// Never put secrets in these files; they are compiled into the App.
class AppConfig {
  const AppConfig({
    required this.environment,
    required this.apiBaseUrl,
    this.appLinkHost = '',
  });

  factory AppConfig.fromEnvironment() {
    return const AppConfig(
      environment: String.fromEnvironment('APP_ENV', defaultValue: 'dev'),
      // Host only; API paths (/api/v1/...) come from the shared contract.
      // 10.0.2.2 is the host machine as seen from the Android emulator.
      apiBaseUrl: String.fromEnvironment(
        'API_BASE_URL',
        defaultValue: 'http://10.0.2.2:8000',
      ),
      // S9 M14: the App's own HTTPS domain for App Links / shared URLs.
      appLinkHost: String.fromEnvironment('APP_LINK_HOST'),
    );
  }

  final String environment;
  final String apiBaseUrl;

  /// Empty = deep links disabled (no real domain configured yet).
  final String appLinkHost;
}
