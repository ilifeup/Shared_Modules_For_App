@Tags(['e2e'])
library;

import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:s1_poc_app/core/network/api_client.dart';
import 'package:s1_poc_app/core/network/api_exception.dart';
import 'package:s1_poc_app/core/network/dio_transport.dart';
import 'package:s1_poc_app/core/storage/token_storage.dart';

/// Flutter client code (Dio transport + shared contracts) against a real
/// Laravel API and MySQL. Only runs when E2E_API_BASE_URL is defined.
const baseUrl = String.fromEnvironment('E2E_API_BASE_URL');
const password = String.fromEnvironment('E2E_MEMBER_PASSWORD');

void main() {
  test('login, profile, notices and logout against the real API', () async {
    final storage = MemoryTokenStorage();
    final transport = DioTransport(
      createApiClient(baseUrl: baseUrl, tokenStorage: storage),
    );
    final session = SessionApi(transport);
    final members = MemberApi(transport);

    final token = await session.login('e2e@example.com', password);
    expect(token, isNotEmpty);
    await storage.write(token);

    final profile = await members.myProfile();
    expect(profile.email, 'e2e@example.com');
    expect(profile.verificationSatisfied, isTrue);

    final policy = await RegistrationApi(transport).policy();
    expect(policy.verificationPolicy, AuthPolicy.emailRequired);

    final updated = await members.updateDisplayName('E2E 會員');
    expect(updated.displayName, 'E2E 會員');

    final notices = await members.notices();
    expect(notices.map((n) => n.title), contains('E2E 已發布公告'));
    expect(notices.map((n) => n.title), isNot(contains('E2E 草稿')));

    final detail = await members.notice(notices.first.id);
    expect(detail.status, 'published');

    await session.logout();

    await expectLater(
      members.myProfile(),
      throwsA(
        isA<ApiException>().having((e) => e.statusCode, 'statusCode', 401),
      ),
    );
  }, skip: baseUrl.isEmpty ? 'E2E_API_BASE_URL not set' : false);
}
