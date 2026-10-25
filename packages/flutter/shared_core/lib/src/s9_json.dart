/// Small JSON readers shared by the S9 DTOs (internal; not exported).
library;

DateTime? s9Date(Object? value) =>
    value is String ? DateTime.tryParse(value)?.toUtc() : null;

String s9Str(Map<String, dynamic> json, String key) {
  final value = json[key];
  if (value is String) return value;
  if (value is num) return '$value';
  throw FormatException('Missing $key');
}

String? s9OptStr(Object? value) => value is String ? value : null;

int s9Int(Object? value, [int fallback = 0]) => value is int ? value : fallback;

bool s9Bool(Object? value) => value == true;

Map<String, dynamic> s9Map(Object? value) =>
    value is Map<String, dynamic> ? value : const <String, dynamic>{};

List<T> s9List<T>(Object? value, T Function(Map<String, dynamic>) decode) {
  if (value is! List) return const [];
  return value
      .whereType<Map<String, dynamic>>()
      .map(decode)
      .toList(growable: false);
}

Map<String, dynamic> s9Data(Map<String, dynamic> json) {
  final data = json['data'];
  if (data is! Map<String, dynamic>) {
    throw const FormatException('Expected an object in data');
  }
  return data;
}

String s9Path(String path, [Map<String, String> query = const {}]) => Uri(
  path: path,
  queryParameters: query.isEmpty ? null : query,
).toString();

String s9Seg(String value) => Uri.encodeComponent(value);
