class MemberProfile {
  const MemberProfile({
    required this.id,
    required this.displayName,
    this.email,
    this.phone,
    this.emailVerified = false,
    this.phoneVerified = false,
  });

  final String id;
  final String displayName;
  final String? email;
  final String? phone;
  final bool emailVerified;
  final bool phoneVerified;

  factory MemberProfile.fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final name = json['display_name'];
    final email = json['email'];
    final phone = json['phone'];
    if (id is! String || id.isEmpty || name is! String) {
      throw const FormatException('Invalid member profile');
    }
    return MemberProfile(
      id: id,
      displayName: name,
      email: email is String ? email : null,
      phone: phone is String ? phone : null,
      emailVerified: json['email_verified'] == true,
      phoneVerified: json['phone_verified'] == true,
    );
  }

  Map<String, String> toDisplayNamePatch() => {'display_name': displayName};
}
