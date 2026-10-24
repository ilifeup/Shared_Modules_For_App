/// Lightweight S1 client-side classification; the Laravel App is authoritative.
/// Phone formatting and country-code normalization are intentionally deferred.
enum LoginIdentifierKind { email, phone }

LoginIdentifierKind classifyLoginIdentifier(String value) {
  final normalized = value.trim();
  if (normalized.isEmpty) {
    throw ArgumentError.value(value, 'identifier', 'Identifier is required');
  }
  if (normalized.contains('@')) {
    return LoginIdentifierKind.email;
  }
  return LoginIdentifierKind.phone;
}
