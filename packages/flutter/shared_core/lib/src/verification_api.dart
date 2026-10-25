import 'member_api.dart';
import 's9_json.dart';

/// S9 M03 verification type configured by the App.
class VerificationType {
  const VerificationType({
    required this.id,
    required this.name,
    required this.requiresEvidence,
    required this.requiredApprovals,
    required this.allowMultiple,
    this.description,
    this.validityDays,
  });

  final String id;
  final String name;
  final String? description;
  final bool requiresEvidence;
  final int requiredApprovals;
  final int? validityDays;
  final bool allowMultiple;

  factory VerificationType.fromJson(Map<String, dynamic> json) {
    final days = json['validity_days'];
    return VerificationType(
      id: s9Str(json, 'id'),
      name: s9Str(json, 'name'),
      description: s9OptStr(json['description']),
      requiresEvidence: s9Bool(json['requires_evidence']),
      requiredApprovals: s9Int(json['required_approvals'], 1),
      validityDays: days is int ? days : null,
      allowMultiple: s9Bool(json['allow_multiple']),
    );
  }
}

class VerificationApplication {
  const VerificationApplication({
    required this.id,
    required this.typeName,
    required this.status,
    required this.approvals,
    required this.requiredApprovals,
    this.rejectionReason,
    this.submittedAt,
  });

  final String id;
  final String typeName;

  /// `pending`, `approved`, `rejected` or `withdrawn`.
  final String status;
  final int approvals;
  final int requiredApprovals;
  final String? rejectionReason;
  final DateTime? submittedAt;

  bool get isPending => status == 'pending';

  factory VerificationApplication.fromJson(Map<String, dynamic> json) =>
      VerificationApplication(
        id: s9Str(json, 'id'),
        typeName: s9OptStr(s9Map(json['type'])['name']) ?? '',
        status: s9Str(json, 'status'),
        approvals: s9Int(json['approvals']),
        requiredApprovals: s9Int(json['required_approvals'], 1),
        rejectionReason: s9OptStr(json['rejection_reason']),
        submittedAt: s9Date(json['submitted_at']),
      );
}

/// A badge; [state] is `valid`, `expired` or `revoked`. Public by default.
class VerificationBadge {
  const VerificationBadge({
    required this.id,
    required this.typeName,
    required this.state,
    required this.isPublic,
    this.grantedAt,
    this.expiresAt,
  });

  final String id;
  final String typeName;
  final String state;
  final bool isPublic;
  final DateTime? grantedAt;
  final DateTime? expiresAt;

  bool get isValid => state == 'valid';

  factory VerificationBadge.fromJson(Map<String, dynamic> json) =>
      VerificationBadge(
        id: s9Str(json, 'id'),
        typeName: s9OptStr(s9Map(json['type'])['name']) ?? '',
        state: s9Str(json, 'state'),
        isPublic: s9Bool(json['is_public']),
        grantedAt: s9Date(json['granted_at']),
        expiresAt: s9Date(json['expires_at']),
      );
}

class MyVerifications {
  const MyVerifications({required this.requests, required this.badges});

  final List<VerificationApplication> requests;
  final List<VerificationBadge> badges;
}

/// S9 M03 member side. The application itself is a multipart upload (evidence
/// files) sent by the App's own HTTP client to [applyPath] with fields
/// `type_id`, `note` and `evidence[]` (JPG / PNG / PDF, 5 MB each, max 5).
class VerificationApi {
  const VerificationApi(this.transport);

  static const applyPath = '/api/v1/me/verification-requests';

  final ApiTransport transport;

  Future<List<VerificationType>> types() async {
    final json = await transport.request('GET', '/api/v1/verification-types');
    return s9List(json['data'], VerificationType.fromJson);
  }

  Future<MyVerifications> mine() async {
    final data = s9Data(
      await transport.request('GET', '/api/v1/me/verifications'),
    );
    return MyVerifications(
      requests: s9List(data['requests'], VerificationApplication.fromJson),
      badges: s9List(data['badges'], VerificationBadge.fromJson),
    );
  }

  /// Applications without evidence can use the JSON transport directly.
  /// 409 REQUEST_PENDING / ALREADY_VERIFIED, 422 when evidence is required.
  Future<String> applyWithoutEvidence(String typeId, {String? note}) async {
    final json = await transport.request(
      'POST',
      applyPath,
      body: {
        'type_id': int.tryParse(typeId) ?? typeId,
        if (note != null) 'note': note,
      },
    );
    return s9Str(s9Data(json), 'id');
  }

  Future<void> withdraw(String requestId) async {
    await transport.request('POST', '$applyPath/${s9Seg(requestId)}/withdraw');
  }

  Future<VerificationBadge> setBadgePublic(String badgeId, bool isPublic) async {
    final json = await transport.request(
      'PATCH',
      '/api/v1/me/verifications/${s9Seg(badgeId)}',
      body: {'is_public': isPublic},
    );
    return VerificationBadge.fromJson(s9Data(json));
  }

  /// Own evidence file (needs the bearer token; never cache it).
  static String evidencePath(String evidenceId) =>
      '/api/v1/me/verification-evidence/${s9Seg(evidenceId)}';
}
