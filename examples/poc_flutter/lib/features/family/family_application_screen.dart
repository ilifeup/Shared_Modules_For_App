import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/providers.dart';
import 'family_screen.dart';

class FamilyApplicationScreen extends ConsumerStatefulWidget {
  const FamilyApplicationScreen({super.key});

  @override
  ConsumerState<FamilyApplicationScreen> createState() =>
      _FamilyApplicationScreenState();
}

class _FamilyApplicationScreenState
    extends ConsumerState<FamilyApplicationScreen> {
  final _name = TextEditingController();
  final _relationship = TextEditingController();
  final _address = TextEditingController();
  final _phone = TextEditingController();
  DateTime? _birthDate;
  XFile? _evidence;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _relationship.dispose();
    _address.dispose();
    _phone.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_name.text.trim().isEmpty ||
        _relationship.text.trim().isEmpty ||
        _address.text.trim().isEmpty ||
        _birthDate == null ||
        _evidence == null) {
      setState(() => _error = '請填寫必填資料並附上關係證明照片');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final date = _birthDate!.toIso8601String().substring(0, 10);
      final form = FormData.fromMap({
        'name': _name.text.trim(),
        'relationship': _relationship.text.trim(),
        'birth_date': date,
        'address': _address.text.trim(),
        if (_phone.text.trim().isNotEmpty) 'contact_phone': _phone.text.trim(),
        'relationship_evidence': MultipartFile.fromBytes(
          await _evidence!.readAsBytes(),
          filename: _evidence!.name,
        ),
      });
      await ref
          .read(dioProvider)
          .post('/api/v1/me/family-applications', data: form);
      ref.invalidate(familyApplicationsProvider);
      if (mounted) Navigator.of(context).pop();
    } catch (error) {
      if (mounted) setState(() => _error = '申請失敗：$error');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('申請家庭會員')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const Text('第一位免費；第二位起須先完成付款，並經管理員審核。'),
          TextField(
            controller: _name,
            decoration: const InputDecoration(labelText: '姓名 *'),
          ),
          TextField(
            controller: _relationship,
            decoration: const InputDecoration(labelText: '關係 *'),
          ),
          TextField(
            controller: _address,
            decoration: const InputDecoration(labelText: '地址 *'),
          ),
          TextField(
            controller: _phone,
            decoration: const InputDecoration(labelText: '聯絡電話'),
          ),
          ListTile(
            title: Text(
              _birthDate == null
                  ? '選擇出生日期 *'
                  : _birthDate!.toIso8601String().substring(0, 10),
            ),
            onTap: _busy
                ? null
                : () async {
                    final date = await showDatePicker(
                      context: context,
                      initialDate: DateTime(2000),
                      firstDate: DateTime(1900),
                      lastDate: DateTime.now(),
                    );
                    if (date != null && mounted) {
                      setState(() => _birthDate = date);
                    }
                  },
          ),
          OutlinedButton(
            onPressed: _busy
                ? null
                : () async {
                    final image = await ImagePicker().pickImage(
                      source: ImageSource.gallery,
                    );
                    if (image != null && mounted) {
                      setState(() => _evidence = image);
                    }
                  },
            child: Text(_evidence?.name ?? '選擇關係證明照片 *'),
          ),
          if (_error != null) Text(_error!),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: const Text('提交申請'),
          ),
        ],
      ),
    );
  }
}
