import 'package:eachcore_shared_ui/eachcore_shared_ui.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

Widget host(Widget child) => MaterialApp(home: Scaffold(body: child));

void main() {
  testWidgets('StatusBox shows label and detail as text', (tester) async {
    await tester.pumpWidget(host(const StatusBox(label: '【空】', detail: '說明')));

    expect(find.text('【空】'), findsOneWidget);
    expect(find.text('說明'), findsOneWidget);
  });

  testWidgets('LoadingView shows a text label', (tester) async {
    await tester.pumpWidget(host(const LoadingView()));

    expect(find.text('【載入中】'), findsOneWidget);
  });

  testWidgets('ErrorRetry shows the error and calls onRetry', (tester) async {
    var retries = 0;
    await tester.pumpWidget(
      host(ErrorRetry(error: const Text('錯誤'), onRetry: () => retries++)),
    );

    expect(find.text('錯誤'), findsOneWidget);
    await tester.tap(find.text('重試'));
    expect(retries, 1);
  });

  testWidgets('LabeledTextField puts the key on the inner field', (
    tester,
  ) async {
    final controller = TextEditingController();
    addTearDown(controller.dispose);
    await tester.pumpWidget(
      host(
        LabeledTextField(
          fieldKey: const Key('name'),
          controller: controller,
          label: '姓名',
        ),
      ),
    );

    await tester.enterText(find.byKey(const Key('name')), '王小明');
    expect(controller.text, '王小明');
    expect(find.text('姓名'), findsOneWidget);
  });

  testWidgets('showFeedback shows a snack bar', (tester) async {
    await tester.pumpWidget(
      host(
        Builder(
          builder: (context) => TextButton(
            onPressed: () =>
                showFeedback(ScaffoldMessenger.of(context), '已儲存'),
            child: const Text('送出'),
          ),
        ),
      ),
    );

    await tester.tap(find.text('送出'));
    await tester.pump();
    expect(find.text('已儲存'), findsOneWidget);
  });
}
