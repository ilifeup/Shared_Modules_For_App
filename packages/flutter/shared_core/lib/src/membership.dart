import 'api_envelope.dart';
import 'member_api.dart';

/// M02 member level (一般 / VIP). Levels describe member benefits only and
/// never grant admin rights.
class MembershipLevel {
  const MembershipLevel({
    required this.code,
    required this.name,
    this.benefits,
    this.isDefault = false,
  });

  final String code;
  final String name;
  final String? benefits;
  final bool isDefault;

  bool get isVip => code == 'vip';

  factory MembershipLevel.fromJson(Map<String, dynamic> json) {
    final code = json['code'];
    final name = json['name'];
    final benefits = json['benefits'];
    if (code is! String || name is! String) {
      throw const FormatException('Invalid membership level');
    }
    return MembershipLevel(
      code: code,
      name: name,
      benefits: benefits is String ? benefits : null,
      isDefault: json['is_default'] == true,
    );
  }
}

/// The member's current level and, for an assigned level, its period.
class Membership {
  const Membership({required this.level, this.startsAt, this.endsAt});

  final MembershipLevel level;
  final DateTime? startsAt;

  /// Null when the level has no end date (or is the default level).
  final DateTime? endsAt;

  factory Membership.fromJson(Map<String, dynamic> json) {
    final level = json['level'];
    if (level is! Map<String, dynamic>) {
      throw const FormatException('Invalid membership');
    }
    return Membership(
      level: MembershipLevel.fromJson(level),
      startsAt: _date(json['starts_at']),
      endsAt: _date(json['ends_at']),
    );
  }

  static DateTime? _date(Object? value) =>
      value is String ? DateTime.tryParse(value)?.toUtc() : null;
}

class MembershipApi {
  const MembershipApi(this.transport);

  final ApiTransport transport;

  Future<Membership> mine() async {
    final json = await transport.request('GET', '/api/v1/me/membership');
    return ApiEnvelope<Membership>.fromJson(json, (data) {
      if (data is! Map<String, dynamic>) {
        throw const FormatException('Invalid membership response');
      }
      return Membership.fromJson(data);
    }).data;
  }

  Future<List<MembershipLevel>> levels() async {
    final json = await transport.request('GET', '/api/v1/membership-levels');
    return ApiEnvelope<List<MembershipLevel>>.fromJson(json, (data) {
      if (data is! List) {
        throw const FormatException('Expected level list');
      }
      return data
          .map((item) => MembershipLevel.fromJson(item as Map<String, dynamic>))
          .toList(growable: false);
    }).data;
  }
}
