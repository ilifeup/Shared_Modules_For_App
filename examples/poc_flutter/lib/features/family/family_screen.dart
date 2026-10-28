import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/providers.dart';

final familyMembersProvider = FutureProvider.autoDispose<List<dynamic>>((ref) async {
  final response = await ref.watch(dioProvider).get('/me/family-memberships');
  return (response.data['data'] as List<dynamic>);
});

class FamilyScreen extends ConsumerWidget {
  const FamilyScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final members = ref.watch(familyMembersProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('家庭會員')),
      body: members.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, _) => Center(child: Text('讀取失敗：$error')),
        data: (items) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(familyMembersProvider),
          child: ListView(
            children: [
              if (items.isEmpty) const ListTile(title: Text('尚無家庭會員')),
              for (final item in items)
                ListTile(
                  title: Text('${item['name'] ?? '家庭會員'}'),
                  subtitle: Text('會員 ID：${item['member_user_id']}'),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
