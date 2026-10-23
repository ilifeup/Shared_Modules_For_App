class MemberProfile {
  const MemberProfile({required this.id, required this.displayName});

  final String id;
  final String displayName;

  factory MemberProfile.fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final name = json['display_name'];
    if (id is! String || id.isEmpty || name is! String) {
      throw const FormatException('Invalid member profile');
    }
    return MemberProfile(id: id, displayName: name);
  }

  Map<String, String> toDisplayNamePatch() => {'display_name': displayName};
}
