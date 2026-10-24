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
    );
  }

  Map<String, String> toDisplayNamePatch() => {'display_name': displayName};
}
