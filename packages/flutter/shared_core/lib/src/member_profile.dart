class MemberProfile {
  const MemberProfile({
    required this.id,
    required this.displayName,
    this.email,
    this.phone,
    this.emailVerified = false,
    this.phoneVerified = false,
    this.verificationPolicy,
    this.verificationSatisfied = true,
    this.verificationMissing,
    this.name,
    this.hasAvatar = false,
    this.missingFields = const [],
    this.nickname,
    this.showInLikeList = false,
  });

  final String id;
  final String displayName;
  final String? email;
  final String? phone;
  final bool emailVerified;
  final bool phoneVerified;

  /// `email_required` or `phone_required`; null when the server predates S3.
  final String? verificationPolicy;

  /// False while the member may only use verification, logout and recovery.
  final bool verificationSatisfied;

  /// `email` or `phone`: the contact that still has to be verified.
  final String? verificationMissing;

  /// Real name (required at registration since S3).
  final String? name;

  /// Avatar bytes are fetched from `GET /api/v1/me/avatar` (owner only).
  final bool hasAvatar;

  /// Required fields an older account still lacks: `name`, `email`, `phone`.
  final List<String> missingFields;

  /// S8: the single public nickname shown with likes and comments.
  final String? nickname;

  /// S8: show my nickname in like lists (default off; still counted when off).
  final bool showInLikeList;

  bool get profileComplete => missingFields.isEmpty;

  bool get hasNickname => nickname != null && nickname!.trim().isNotEmpty;

  factory MemberProfile.fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final name = json['display_name'];
    final email = json['email'];
    final phone = json['phone'];
    if (id is! String || id.isEmpty || name is! String) {
      throw const FormatException('Invalid member profile');
    }
    final verification = json['verification'];
    final policy = verification is Map ? verification['policy'] : null;
    final satisfied = verification is Map ? verification['satisfied'] : null;
    final missing = verification is Map ? verification['missing'] : null;
    final realName = json['name'];
    final profile = json['profile'];
    final missingFields = profile is Map ? profile['missing'] : null;
    final nickname = json['nickname'];
    final privacy = json['privacy'];
    return MemberProfile(
      id: id,
      displayName: name,
      email: email is String ? email : null,
      phone: phone is String ? phone : null,
      emailVerified: json['email_verified'] == true,
      phoneVerified: json['phone_verified'] == true,
      verificationPolicy: policy is String ? policy : null,
      verificationSatisfied: satisfied is bool ? satisfied : true,
      verificationMissing: missing is String ? missing : null,
      name: realName is String ? realName : null,
      hasAvatar: json['has_avatar'] == true,
      missingFields: missingFields is List
          ? missingFields.map((field) => field.toString()).toList()
          : const [],
      nickname: nickname is String ? nickname : null,
      showInLikeList: privacy is Map && privacy['show_in_like_list'] == true,
    );
  }

  Map<String, String> toDisplayNamePatch() => {'display_name': displayName};
}
