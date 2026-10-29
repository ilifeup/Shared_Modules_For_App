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

final courseSessionsProvider = FutureProvider.autoDispose
    .family<List<dynamic>, int>((ref, id) async {
      final response = await ref
          .watch(dioProvider)
          .get('/api/v1/me/course-enrollments/$id/sessions');
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
        content: Text(
          isWaitlisted ? '確定退出這筆課程候補？' : '確定取消整期課程報名？已付款的退款須由管理員另外處理。',
        ),
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
      await ref
          .read(dioProvider)
          .post('/api/v1/me/course-enrollments/$enrollmentId/$action');
      ref.invalidate(myCourseEnrollmentsProvider);
      if (mounted) setState(() => _message = isWaitlisted ? '已退出候補' : '已取消報名');
    } catch (error) {
      if (mounted) setState(() => _message = '取消失敗：$error');
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _showSessions(int enrollmentId) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (sheetContext) => Consumer(
        builder: (context, sheetRef, child) => SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: sheetRef
                .watch(courseSessionsProvider(enrollmentId))
                .when(
                  loading: () =>
                      const Center(child: CircularProgressIndicator()),
                  error: (error, _) => Text('課堂讀取失敗：$error'),
                  data: (sessions) => ListView(
                    shrinkWrap: true,
                    children: [
                      const Text('課堂與請假'),
                      for (final session in sessions)
                        ListTile(
                          title: Text(session['title']?.toString() ?? '課堂'),
                          subtitle: Text(
                        '${session['starts_at'] ?? ''}'
                        '${session['makeup_id'] == null ? '' : '　補課：${session['makeup_title'] ?? ''} ${session['makeup_starts_at'] ?? ''}'}',
                      ),
                          trailing: session['leave_id'] != null
                              ? const Text('已請假')
                              : TextButton(
                                  onPressed: () async {
                                    final controller = TextEditingController();
                                    try {
                                      final reason = await showDialog<String>(
                                        context: context,
                                        builder: (dialogContext) => AlertDialog(
                                          title: const Text('單堂請假'),
                                          content: TextField(
                                            controller: controller,
                                            maxLength: 2000,
                                            maxLines: 3,
                                            decoration: const InputDecoration(
                                              labelText: '請假原因',
                                            ),
                                          ),
                                          actions: [
                                            TextButton(
                                              onPressed: () =>
                                                  Navigator.pop(dialogContext),
                                              child: const Text('返回'),
                                            ),
                                            FilledButton(
                                              onPressed: () => Navigator.pop(
                                                dialogContext,
                                                controller.text.trim(),
                                              ),
                                              child: const Text('送出'),
                                            ),
                                          ],
                                        ),
                                      );
                                      if (reason == null) return;
                                      if (reason.length < 2) {
                                        if (mounted)
                                          setState(
                                            () => _message = '請假原因至少 2 個字',
                                          );
                                        return;
                                      }
                                      await ref
                                          .read(dioProvider)
                                          .post(
                                            '/api/v1/me/course-enrollments/$enrollmentId/sessions/${session['id']}/leave',
                                            data: {'reason': reason},
                                          );
                                      sheetRef.invalidate(
                                        courseSessionsProvider(enrollmentId),
                                      );
                                      if (mounted)
                                        setState(() => _message = '單堂請假已記錄');
                                    } catch (error) {
                                      if (mounted)
                                        setState(
                                          () => _message = '請假失敗：$error',
                                        );
                                    } finally {
                                      controller.dispose();
                                    }
                                  },
                                  child: const Text('請假'),
                                ),
                        ),
                    ],
                  ),
                ),
          ),
        ),
      ),
    );
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
                    onTap: () => _showSessions(item['id'] as int),
                    title: Text('${item['course_title'] ?? '課程'}'),
                    subtitle: Text(
                      '會員 ID：${item['user_id']}　狀態：${item['status']}',
                    ),
                    trailing:
                        [
                          'waitlisted',
                          'pending_payment',
                          'confirmed',
                        ].contains(item['status'])
                        ? TextButton(
                            onPressed: _saving
                                ? null
                                : () => _cancelEnrollment(
                                    item['id'] as int,
                                    item['status'] as String,
                                  ),
                            child: Text(
                              item['status'] == 'waitlisted' ? '退出候補' : '取消報名',
                            ),
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
