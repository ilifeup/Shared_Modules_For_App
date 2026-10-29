import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/providers.dart';
import '../family/family_screen.dart';

final courseTermsProvider = FutureProvider.autoDispose<List<dynamic>>((
  ref,
) async {
  final response = await ref.watch(dioProvider).get('/api/v1/course-terms');
  return response.data['data'] as List<dynamic>;
});

final myCourseEnrollmentsProvider = FutureProvider.autoDispose<List<dynamic>>((
  ref,
) async {
  final response = await ref
      .watch(dioProvider)
      .get('/api/v1/me/course-enrollments');
  return response.data['data'] as List<dynamic>;
});

class CourseScreen extends ConsumerStatefulWidget {
  const CourseScreen({super.key});

  @override
  ConsumerState<CourseScreen> createState() => _CourseScreenState();
}

class _CourseScreenState extends ConsumerState<CourseScreen> {
  int? _selectedTermId;
  int? _selectedMemberId;
  bool _saving = false;
  String? _message;

  @override
  void dispose() {
    super.dispose();
  }

  Future<void> _enroll() async {
    final termId = _selectedTermId;
    final memberId = _selectedMemberId;
    if (termId == null || termId <= 0) {
      setState(() => _message = '請選擇課程');
      return;
    }
    setState(() {
      _saving = true;
      _message = null;
    });
    try {
      await ref
          .read(dioProvider)
          .post(
            '/api/v1/course-terms/$termId/enrollments',
            data: {'member_user_id': ?memberId},
          );
      ref.invalidate(myCourseEnrollmentsProvider);
      if (mounted) setState(() => _message = '已提交報名');
    } catch (error) {
      if (mounted) setState(() => _message = '報名失敗：$error');
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _cancelEnrollment(int enrollmentId, String status) async {
    final isWaitlisted = status == 'waitlisted';
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(isWaitlisted ? '退出候補' : '取消整期課程報名'),
        content: Text(isWaitlisted
            ? '確定退出這筆課程候補？'
            : '確定取消整期課程報名？已付款的退款須由管理員另外處理。'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('返回'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('確認'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    setState(() {
      _saving = true;
      _message = null;
    });
    try {
      final action = isWaitlisted ? 'cancel-waitlist' : 'cancel';
      await ref.read(dioProvider).post(
        '/api/v1/me/course-enrollments/$enrollmentId/$action',
      );
      ref.invalidate(myCourseEnrollmentsProvider);
      if (mounted) setState(() => _message = isWaitlisted ? '已退出候補' : '已取消報名');
    } catch (error) {
      if (mounted) setState(() => _message = '取消失敗：$error');
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final entries = ref.watch(myCourseEnrollmentsProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('整期課程報名')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          ref
              .watch(courseTermsProvider)
              .when(
                loading: () => const LinearProgressIndicator(),
                error: (error, _) => Text('課程讀取失敗：$error'),
                data: (terms) => DropdownButtonFormField<int>(
                  initialValue:
                      terms.any((item) => item['id'] == _selectedTermId)
                      ? _selectedTermId
                      : null,
                  decoration: const InputDecoration(labelText: '選擇整期課程'),
                  items: [
                    for (final term in terms)
                      DropdownMenuItem<int>(
                        value: term['id'] as int,
                        child: Text(term['title']?.toString() ?? '課程'),
                      ),
                  ],
                  onChanged: (value) => setState(() => _selectedTermId = value),
                ),
              ),
          ref
              .watch(familyMembersProvider)
              .when(
                loading: () => const LinearProgressIndicator(),
                error: (error, _) => Text('家庭會員讀取失敗：$error'),
                data: (members) => DropdownButtonFormField<int>(
                  initialValue:
                      members.any(
                        (item) => item['member_user_id'] == _selectedMemberId,
                      )
                      ? _selectedMemberId
                      : null,
                  decoration: const InputDecoration(labelText: '報名對象'),
                  items: [
                    const DropdownMenuItem<int>(value: null, child: Text('本人')),
                    for (final member in members)
                      DropdownMenuItem<int>(
                        value: member['member_user_id'] as int,
                        child: Text(member['name']?.toString() ?? '家庭會員'),
                      ),
                  ],
                  onChanged: (value) =>
                      setState(() => _selectedMemberId = value),
                ),
              ),
          const SizedBox(height: 8),
          FilledButton(
            onPressed: _saving ? null : _enroll,
            child: const Text('報名整期課程'),
          ),
          if (_message != null) Text(_message!),
          const Divider(),
          const Text('我的課程報名'),
          entries.when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (error, _) => Text('讀取失敗：$error'),
            data: (items) => Column(
              children: [
                if (items.isEmpty) const ListTile(title: Text('尚無報名紀錄')),
                for (final item in items)
                  ListTile(
                    title: Text('${item['course_title'] ?? '課程'}'),
                    subtitle: Text(
                      '會員 ID：${item['user_id']}　狀態：${item['status']}',
                    ),
                    trailing: ['waitlisted', 'pending_payment', 'confirmed']
                            .contains(item['status'])
                        ? TextButton(
                            onPressed: _saving
                                ? null
                                : () => _cancelEnrollment(
                                      item['id'] as int,
                                      item['status'] as String,
                                    ),
                            child: Text(item['status'] == 'waitlisted'
                                ? '退出候補'
                                : '取消報名'),
                          )
                        : null,
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
