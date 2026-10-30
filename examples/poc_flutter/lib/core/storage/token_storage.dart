import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Stores the Sanctum API token.
abstract interface class TokenStorage {
  Future<String?> read();

  Future<void> write(String token);

  Future<void> clear();
}

class SecureTokenStorage implements TokenStorage {
  SecureTokenStorage([FlutterSecureStorage? storage])
    : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'auth_token';

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read() => _storage.read(key: _key);

  @override
  Future<void> write(String token) => _storage.write(key: _key, value: token);

  @override
  Future<void> clear() => _storage.delete(key: _key);
}

/// In-memory storage for tests.
class MemoryTokenStorage implements TokenStorage {
  MemoryTokenStorage([this._token]);

  String? _token;

  @override
  Future<String?> read() async => _token;

  @override
  Future<void> write(String token) async => _token = token;

  @override
  Future<void> clear() async => _token = null;
}

/// Clear only the signed-in member's unresolved booking attempts.
/// A failed session restore has no trusted member identity, so it must not
/// erase other accounts' durable idempotency keys.
Future<void> clearPendingBookingStorage(
  String memberId, [
  FlutterSecureStorage? storage,
]) async {
  final secure = storage ?? const FlutterSecureStorage();
  final prefix = 'pending_booking_v1:${Uri.encodeComponent(memberId)}:';
  final values = await secure.readAll();
  for (final key in values.keys.where((key) => key.startsWith(prefix)).toList()) {
    await secure.delete(key: key);
  }
}
