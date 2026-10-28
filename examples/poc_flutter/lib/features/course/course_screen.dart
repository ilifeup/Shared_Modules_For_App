import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/providers.dart';

final myCourseEnrollmentsProvider = FutureProvider.autoDispose<List<dynamic>>((
  ref,
) async {
  final response = await ref.watch(dioProvider).get('/me/course-enrollments');
  return response.data['data'] as List<dynamic>;
});

class CourseScreen extends ConsumerStatefulWidget {
  const CourseScreen({super.key});

  @override
  ConsumerState<CourseScreen> createState() => _CourseScreenState();
}

class _CourseScreenState extends ConsumerState<CourseScreen> {
  final _term = TextEditingController();
  final _member = TextEditingController();
  bool _saving = false;
  String? _message;

  @override
  void dispose() {
    _term.dispose();
    _member.dispose();
    super.dispose();
  }

  Future<void> _enroll() async {
    final termId = int.tryParse(_term.text.trim());
    final memberText = _member.text.trim();
    final memberId = memberText.isEmpty ? null : int.tryParse(memberText);
    if (termId == null ||
        termId <= 0 ||
        (memberText.isNotEmpty && (memberId == null || memberId <= 0))) {
      setState(() => _message = '請輸入有效的課程及家庭會員 ID');
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
            '/course-terms/$termId/enrollments',
            data: {if (memberId != null) 'member_user_id': memberId},
          );
      ref.invalidate(myCourseEnrollmentsProvider);
      if (mounted) setState(() => _message = '已提交報名');
    } catch (error) {
      if (mounted) setState(() => _message = '報名失敗：$error');
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
          TextField(
            controller: _term,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: '課程 ID'),
          ),
          TextField(
            controller: _member,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: '家庭會員 ID（留空為本人）'),
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
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
