/// Display-name normalization and basic length validation.
///
/// The Laravel application must repeat authoritative validation.
class DisplayName {
  const DisplayName._();

  static String normalize(String value) => value.trim();

  static bool isValid(String value) {
    final normalized = normalize(value);
    return normalized.isNotEmpty && normalized.runes.length <= 80;
  }
}
