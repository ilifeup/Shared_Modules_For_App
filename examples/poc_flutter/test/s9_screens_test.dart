import 'dart:async';

import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:s1_poc_app/core/device/device_services.dart';
import 'package:s1_poc_app/core/network/api_exception.dart';
import 'package:s1_poc_app/core/providers.dart';
import 'package:s1_poc_app/features/s9/checkin_screens.dart';
import 'package:s1_poc_app/features/s9/discovery_screens.dart';
import 'package:s1_poc_app/features/s9/group_booking_screen.dart';
import 'package:s1_poc_app/features/s9/my_calendar_screen.dart';
import 'package:s1_poc_app/features/s9/verification_marketing.dart';
import 'package:s1_poc_app/ui/content_unavailable.dart';

typedef Handler =
    FutureOr<Map<String, dynamic>> Function(Map<String, dynamic>? body);

/// Routes "METHOD path" (exact, or prefix when it ends with *) to handlers.
class S9Backend implements ApiTransport {
  final calls = <String>[];
  final bodies = <Map<String, dynamic>?>[];
  final routes = <String, Handler>{};

  @override
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    final key = '$method $path';
    calls.add(key);
    bodies.add(body);
    for (final entry in routes.entries) {
      final match = entry.key.endsWith('*')
          ? key.startsWith(entry.key.substring(0, entry.key.length - 1))
          : key == entry.key;
      if (match) return await entry.value(body);
    }
    throw ApiException(code: 'NOT_FOUND', message: '找不到 $key');
  }
}

const event = {
  'id': '7',
  'title': '週日共修',
  'starts_at': '2026-12-06T02:00:00Z',
  'ends_at': '2026-12-06T04:00:00Z',
  'status': 'published',
  'audience': 'members',
  'location': '道場',
};

Map<String, dynamic> group(String status, List<String> names) => {
  'id': '3',
  'event_id': '7',
  'status': status,
  'seat_count': names.length,
  'is_guest': false,
  'booked_at': '2026-11-01T00:00:00Z',
  'waitlist_position': status == 'waitlisted' ? 2 : null,
  'cancel_deadline': '2026-12-06T02:00:00Z',
  'can_cancel': true,
  'attendees': [
    for (var i = 0; i < names.length; i++)
      {'id': '${10 + i}', 'name': names[i], 'status': status, 'is_me': i == 0},
  ],
  'event': event,
};

Future<void> pumpScreen(
  WidgetTester tester,
  S9Backend backend,
  Widget screen, {
  Future<String?> Function(BuildContext)? qr,
  Future<GpsFix?> Function()? gps,
  Future<bool> Function(Uri)? openUrl,
  DateTime Function()? clock,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      retry: (retryCount, error) => null,
      overrides: [
        apiTransportProvider.overrideWithValue(backend),
        if (qr != null) qrScannerProvider.overrideWithValue(qr),
        if (gps != null) gpsProvider.overrideWithValue(gps),
        if (openUrl != null) openUrlProvider.overrideWithValue(openUrl),
        if (clock != null) clockProvider.overrideWithValue(clock),
      ],
      child: MaterialApp(home: screen),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  test('request ids are random UUID v4', () {
    final a = newRequestId();
    expect(
      a,
      matches(
        RegExp(
          r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
        ),
      ),
    );
    expect(newRequestId(), isNot(a));
  });

  testWidgets('M08 books a group once and shows the waitlist position', (
    tester,
  ) async {
    final backend = S9Backend();
    final gate = Completer<void>();
    backend.routes['GET /api/v1/events/7'] = (_) => {'data': event};
    backend.routes['POST /api/v1/events/7/booking-groups'] = (body) async {
      await gate.future;
      return {
        'data': group('waitlisted', ['我', '家人']),
      };
    };
    await pumpScreen(tester, backend, const GroupBookingScreen(eventId: '7'));

    await tester.tap(find.byKey(const Key('group-add-name')));
    await tester.pump();
    await tester.enterText(find.byKey(const Key('group-name-0')), '家人');
    await tester.tap(find.byKey(const Key('group-submit')));
    await tester.pump();
    expect(find.text('處理中…'), findsOneWidget);
    await tester.tap(
      find.byKey(const Key('group-submit')),
      warnIfMissed: false,
    );
    gate.complete();
    await tester.pumpAndSettle();

    final posts = backend.calls.where((c) => c.startsWith('POST')).toList();
    expect(posts, hasLength(1));
    final body = backend.bodies[backend.calls.indexOf(posts.single)]!;
    expect(body['include_self'], isTrue);
    expect(body['attendees'], [
      {'name': '家人'},
    ]);
    expect(body['client_request_id'], isA<String>());
    expect(find.text('【候補中】第 2 順位'), findsOneWidget);
    expect(find.text('共 2 位：我、家人'), findsOneWidget);
  });

  testWidgets('M11 scans the event QR and checks in the selected attendee', (
    tester,
  ) async {
    final backend = S9Backend();
    backend.routes['GET /api/v1/events/7/checkin'] = (_) => {
      'data': {
        'settings': {
          'enabled': true,
          'open_now': true,
          'checkout_enabled': false,
          'gps_required': false,
          'gps_radius_m': 100,
        },
        'attendees': [
          {
            'id': '10',
            'name': '我',
            'booking_status': 'confirmed',
            'checkin_status': null,
          },
          {
            'id': '11',
            'name': '候補者',
            'booking_status': 'waitlisted',
            'checkin_status': null,
          },
        ],
      },
    };
    backend.routes['POST /api/v1/events/7/checkin'] = (body) => {
      'data': [
        {
          'attendee_id': '10',
          'result': 'checked_in',
          'checkin_status': 'checked_in',
        },
      ],
    };
    await pumpScreen(
      tester,
      backend,
      const CheckinScreen(eventId: '7'),
      qr: (_) async => 'e.7.29999999.0123456789abcdef01234567',
    );

    expect(find.text('【開放簽到中】'), findsOneWidget);
    expect(find.text('【候補中】無法簽到'), findsOneWidget);
    await tester.tap(find.byKey(const Key('checkin-attendee-10')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('checkin-scan')));
    await tester.pumpAndSettle();

    final body =
        backend.bodies[backend.calls.indexOf('POST /api/v1/events/7/checkin')]!;
    expect(body['attendee_ids'], [10]);
    expect(body['event_token'], 'e.7.29999999.0123456789abcdef01234567');
    expect(find.textContaining('#10 簽到成功'), findsOneWidget);
  });

  testWidgets(
    'M11 Location QR needs GPS when required and explains a failure',
    (tester) async {
      final backend = S9Backend();
      backend.routes['GET /api/v1/events/7/checkin'] = (_) => {
        'data': {
          'settings': {
            'enabled': true,
            'open_now': true,
            'gps_required': true,
            'gps_radius_m': 100,
          },
          'attendees': [
            {'id': '10', 'name': '我', 'booking_status': 'confirmed'},
          ],
        },
      };
      await pumpScreen(
        tester,
        backend,
        const CheckinScreen(eventId: '7', locationToken: 'ABCDEFGHIJKLMNOP'),
        gps: () async => null,
      );

      await tester.tap(find.byKey(const Key('checkin-attendee-10')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('checkin-scan')));
      await tester.pumpAndSettle();

      expect(backend.calls, isNot(contains('POST /api/v1/events/7/checkin')));
      expect(find.textContaining('無法取得定位'), findsOneWidget);
    },
  );

  testWidgets('M19 searches with AND keywords and loads the next page', (
    tester,
  ) async {
    final backend = S9Backend();
    backend.routes['GET /api/v1/tags'] = (_) => {
      'data': [
        {'id': '1', 'name': '靜坐', 'color': '#43A047', 'count': 3},
      ],
    };
    Map<String, dynamic> card(String id) => {
      'content_type': 'article',
      'content_id': id,
      'title': '文章 $id',
      'url': 'https://app.example/articles/$id',
      'tags': const [],
    };
    backend.routes['GET /api/v1/search?q=*'] = (_) {
      final page = backend.calls.last.contains('page=2') ? 2 : 1;
      return {
        'data': [card('$page')],
        'meta': {
          'current_page': page,
          'last_page': 2,
          'total': 21,
          'types': ['article', 'event'],
        },
      };
    };
    await pumpScreen(tester, backend, const SearchScreen());

    await tester.enterText(find.byKey(const Key('search-q')), '靜坐 入門');
    await tester.tap(find.byKey(const Key('tag-1')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('search-submit')));
    await tester.pumpAndSettle();

    expect(
      backend.calls.last,
      'GET /api/v1/search?q=%E9%9D%9C%E5%9D%90+%E5%85%A5%E9%96%80&tags[]=1',
    );
    expect(find.text('共 21 筆'), findsOneWidget);
    await tester.tap(find.byKey(const Key('search-more')));
    await tester.pumpAndSettle();
    expect(backend.calls.last, endsWith('&page=2'));
    expect(find.text('【文章】文章 2'), findsOneWidget);
    expect(find.byKey(const Key('search-more')), findsNothing);
  });

  testWidgets(
    'M19 shows placeholders and clears bookmarks after two confirmations',
    (tester) async {
      final backend = S9Backend();
      var cleared = false;
      backend.routes['GET /api/v1/me/bookmarks*'] = (_) => {
        'data': cleared
            ? const []
            : [
                {
                  'content_type': 'article',
                  'content_id': '9',
                  'available': false,
                  'item': null,
                },
              ],
        'meta': {'count': cleared ? 0 : 1, 'limit': 1000},
      };
      backend.routes['DELETE /api/v1/me/bookmarks'] = (_) {
        cleared = true;
        return {
          'data': {'deleted': 1},
        };
      };
      await pumpScreen(tester, backend, const BookmarksScreen());

      expect(find.text('【內容已無法開啟】'), findsOneWidget);
      expect(find.text('1 / 1000'), findsOneWidget);
      // Grouped by content type (M19-D08 / D17).
      await tester.tap(find.byKey(const Key('bookmark-type-article')));
      await tester.pumpAndSettle();
      expect(backend.calls, contains('GET /api/v1/me/bookmarks?type=article'));
      await tester.tap(find.byKey(const Key('bookmarks-clear')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('confirm-yes')));
      await tester.pumpAndSettle();
      expect(backend.calls, isNot(contains('DELETE /api/v1/me/bookmarks')));
      await tester.tap(find.byKey(const Key('confirm-yes')));
      await tester.pumpAndSettle();

      expect(backend.calls, contains('DELETE /api/v1/me/bookmarks'));
      expect(find.text('【尚無收藏】'), findsOneWidget);
    },
  );

  testWidgets('M15 banner renders nothing when the placement fails', (
    tester,
  ) async {
    await pumpScreen(
      tester,
      S9Backend(),
      const Scaffold(body: MarketingBanner()),
    );
    expect(find.byType(Card), findsNothing);
  });

  testWidgets('M15 banner counts the tap and opens the campaign', (
    tester,
  ) async {
    final second = S9Backend();
    second.routes['GET /api/v1/marketing/placements/home_banner'] = (_) => {
      'data': [
        {
          'id': '2',
          'title': '冬令營',
          'button_text': '查看',
          'target': {
            'type': 'url',
            'id': null,
            'url': 'https://example.com/a',
            'app_path': null,
          },
          'utm': {'utm_source': 'app'},
        },
      ],
    };
    second.routes['POST /api/v1/links/clicks'] = (_) => {
      'data': {'click_id': null},
    };
    final opened = <Uri>[];
    await pumpScreen(
      tester,
      second,
      const Scaffold(body: MarketingBanner()),
      openUrl: (uri) async {
        opened.add(uri);
        return true;
      },
    );
    expect(find.text('冬令營'), findsOneWidget);
    await tester.tap(find.text('查看'));
    await tester.pumpAndSettle();
    expect(second.calls, contains('POST /api/v1/links/clicks'));
    expect(opened.single.toString(), 'https://example.com/a');
  });

  testWidgets('M03 applies without evidence and hides a badge', (tester) async {
    final backend = S9Backend();
    var hidden = false;
    backend.routes['GET /api/v1/verification-types'] = (_) => {
      'data': [
        {
          'id': '1',
          'name': '志工',
          'requires_evidence': false,
          'required_approvals': 2,
          'allow_multiple': false,
        },
      ],
    };
    backend.routes['GET /api/v1/me/verifications'] = (_) => {
      'data': {
        'requests': [
          {
            'id': '5',
            'type': {'name': '志工'},
            'status': 'pending',
            'approvals': 1,
            'required_approvals': 2,
          },
        ],
        'badges': [
          {
            'id': '8',
            'type': {'name': '教職'},
            'state': 'valid',
            'is_public': !hidden,
          },
        ],
      },
    };
    backend.routes['POST /api/v1/me/verification-requests'] = (_) => {
      'data': {'id': '6', 'status': 'pending'},
    };
    backend.routes['PATCH /api/v1/me/verifications/8'] = (body) {
      hidden = body!['is_public'] == false;
      return {
        'data': {
          'id': '8',
          'type': {'name': '教職'},
          'state': 'valid',
          'is_public': !hidden,
        },
      };
    };
    await pumpScreen(tester, backend, const VerificationScreen());

    expect(find.text('志工 【審核中】'), findsOneWidget);
    expect(find.text('核准 1 / 2'), findsOneWidget);
    await tester.tap(find.byKey(const Key('apply-1')));
    await tester.pumpAndSettle();
    expect(backend.calls, contains('POST /api/v1/me/verification-requests'));

    await tester.tap(find.byKey(const Key('badge-8')));
    await tester.pumpAndSettle();
    expect(hidden, isTrue);
    expect(find.text('不公開'), findsOneWidget);
  });

  testWidgets('M14 shows share and QR only for shareable content', (
    tester,
  ) async {
    final backend = S9Backend();
    backend.routes['GET /api/v1/content/article/9/share'] = (_) => {
      'data': {
        'content_type': 'article',
        'content_id': '9',
        'shareable': true,
        'is_public': true,
        'url': 'https://app.example/articles/9',
      },
    };
    backend.routes['GET /api/v1/content/event/3/share'] = (_) => {
      'data': {
        'content_type': 'event',
        'content_id': '3',
        'shareable': false,
        'is_public': true,
        'url': null,
      },
    };
    await pumpScreen(
      tester,
      backend,
      Scaffold(
        appBar: AppBar(
          actions: const [
            ContentActions(contentType: 'article', contentId: '9'),
            ContentActions(
              contentType: 'event',
              contentId: '3',
              canBookmark: false,
            ),
          ],
        ),
      ),
    );

    // Only the article may be shared (M14-D23).
    expect(find.byKey(const Key('content-share')), findsOneWidget);
    expect(find.byKey(const Key('content-qr')), findsOneWidget);

    await tester.tap(find.byKey(const Key('content-qr')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('share-qr')), findsOneWidget);
    expect(find.text('https://app.example/articles/9'), findsOneWidget);
  });

  testWidgets('M09 shows my calendar as month grid, week and list', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(800, 3000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final backend = S9Backend();
    backend.routes['GET /api/v1/me/calendar*'] = (_) => {
      'data': {
        'events': [
          {
            ...event,
            'id': '7',
            'title': '週三共修',
            'starts_at': '2026-12-02T10:00:00Z',
            'ends_at': '2026-12-02T11:00:00Z',
            'my_booking_status': 'confirmed',
          },
        ],
        'private_events': const [],
        'vip': false,
      },
      'meta': {'truncated': false},
    };
    await pumpScreen(
      tester,
      backend,
      const MyCalendarScreen(),
      clock: () => DateTime(2026, 12, 2, 9),
    );

    // Month grid: the day shows its count as text; the selected day lists it.
    expect(find.byKey(const Key('my-cell-2026-12-02')), findsOneWidget);
    expect(find.text('1 項'), findsOneWidget);
    expect(find.text('【已報名】週三共修'), findsOneWidget);
    await tester.tap(find.byKey(const Key('my-cell-2026-12-05')));
    await tester.pumpAndSettle();
    expect(find.textContaining('2026-12-05（六）　沒有行程'), findsOneWidget);

    // Week view: Monday to Sunday of the selected day's week, its own range.
    await tester.tap(find.text('週'));
    await tester.pumpAndSettle();
    expect(
      backend.calls.last,
      startsWith('GET /api/v1/me/calendar?from=2026-11-30'),
    );
    expect(find.text('2026-11-30 – 2026-12-06'), findsOneWidget);
    expect(find.byKey(const Key('my-day-2026-12-02')), findsOneWidget);
    expect(find.byKey(const Key('my-day-2026-12-06')), findsOneWidget);
    await tester.tap(find.byKey(const Key('my-calendar-next')));
    await tester.pumpAndSettle();
    expect(find.text('2026-12-07 – 2026-12-13'), findsOneWidget);

    // List view.
    await tester.tap(find.text('列表'));
    await tester.pumpAndSettle();
    expect(find.text('2026 年 12 月'), findsOneWidget);
    expect(find.text('【已報名】週三共修'), findsOneWidget);
  });

  testWidgets('M11 staff check a guest in by hand after the contact check', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(800, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final backend = S9Backend();
    backend.routes['GET /api/v1/events/7/checkin/qr'] = (_) => {
      'data': {
        'event_id': '7',
        'token': 'e.7.1.0123456789abcdef01234567',
        'expires_in': 30,
        'window_seconds': 60,
      },
    };
    var guestIn = false;
    backend.routes['GET /api/v1/events/7/attendance'] = (_) => {
      'data': {
        'summary': {
          'booked': 2,
          'checked_in': guestIn ? 1 : 0,
          'absent': guestIn ? 1 : 2,
          'checked_out': 0,
          'checkout_enabled': false,
        },
        'attendees': [
          {
            'id': '11',
            'name': '會員',
            'booking_status': 'confirmed',
            'is_guest': false,
          },
          {
            'id': '12',
            'name': '訪客',
            'booking_status': 'confirmed',
            'checkin_status': guestIn ? 'checked_in' : null,
            'is_guest': true,
            'contact_name': '訪客',
            'contact_email': 'g***@example.com',
          },
        ],
      },
    };
    backend.routes['POST /api/v1/events/7/checkin/staff'] = (body) {
      guestIn = true;
      return {
        'data': {
          'attendee_id': '12',
          'result': 'checked_in',
          'checkin_status': 'checked_in',
        },
      };
    };
    await pumpScreen(tester, backend, const StaffCheckinScreen(eventId: '7'));

    expect(find.text('訪客（訪客）'), findsOneWidget);
    await tester.tap(find.byKey(const Key('staff-manual-12')));
    await tester.pumpAndSettle();
    expect(find.text('報名資料：g***@example.com'), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('staff-contact')),
      'guest@example.com',
    );
    await tester.tap(find.byKey(const Key('staff-contact-ok')));
    await tester.pumpAndSettle();

    final body = backend
        .bodies[backend.calls.indexOf('POST /api/v1/events/7/checkin/staff')]!;
    expect(body, {
      'attendee_id': 12,
      'contact': 'guest@example.com',
      'action': 'checkin',
    });
    expect(find.textContaining('訪客：簽到成功'), findsOneWidget);
    expect(find.byKey(const Key('staff-manual-12')), findsNothing);

    // Dispose the screen so its refresh timer stops.
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('M19 filters by category and place', (tester) async {
    tester.view.physicalSize = const Size(800, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final backend = S9Backend();
    backend.routes['GET /api/v1/tags'] = (_) => {'data': const []};
    backend.routes['GET /api/v1/event-categories'] = (_) => {
      'data': [
        {'id': '4', 'name': '共修', 'color': '#43A047'},
      ],
    };
    backend.routes['GET /api/v1/search?*'] = (_) => {
      'data': const [],
      'meta': {'current_page': 1, 'last_page': 1, 'total': 0},
    };
    await pumpScreen(tester, backend, const SearchScreen());

    await tester.tap(find.byKey(const Key('search-type-event')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('search-category')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('共修').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('search-location')), '台北');
    await tester.tap(find.byKey(const Key('search-submit')));
    await tester.pumpAndSettle();

    expect(
      backend.calls.last,
      'GET /api/v1/search?type=event&event_category_id=4&location=%E5%8F%B0%E5%8C%97',
    );
    // No results: the filters can be cleared (M19-D28).
    await tester.tap(find.byKey(const Key('search-clear-filters')));
    await tester.pumpAndSettle();
    expect(backend.calls.last, 'GET /api/v1/search');
  });

  testWidgets('M14 explains gone, forbidden and offline links and goes home', (
    tester,
  ) async {
    Future<void> show(ApiException error) async {
      var retried = 0;
      final router = GoRouter(
        initialLocation: '/events/9',
        routes: [
          GoRoute(path: '/', builder: (_, _) => const Text('首頁')),
          GoRoute(
            path: '/events/:id',
            builder: (_, _) => Scaffold(
              body: ContentUnavailable(error: error, onRetry: () => retried++),
            ),
          ),
          GoRoute(path: '/login', builder: (_, _) => const Text('登入頁')),
        ],
      );
      addTearDown(router.dispose);
      await tester.pumpWidget(MaterialApp.router(routerConfig: router));
      await tester.pumpAndSettle();
      if (find.byKey(const Key('content-retry')).evaluate().isNotEmpty) {
        await tester.tap(find.byKey(const Key('content-retry')));
        expect(retried, 1);
      }
    }

    await show(
      const ApiException(code: 'NOT_FOUND', message: 'x', statusCode: 404),
    );
    expect(find.text('【連結已失效】'), findsOneWidget);
    expect(find.byKey(const Key('content-retry')), findsNothing);

    await show(
      const ApiException(code: 'FORBIDDEN', message: 'x', statusCode: 403),
    );
    expect(find.text('【沒有權限】'), findsOneWidget);

    await show(const ApiException(code: 'NETWORK_ERROR', message: 'x'));
    expect(find.text('【目前離線】'), findsOneWidget);
    expect(find.byKey(const Key('content-retry')), findsOneWidget);
    await tester.tap(find.byKey(const Key('content-home')));
    await tester.pumpAndSettle();
    expect(find.text('首頁'), findsOneWidget);

    await show(
      const ApiException(
        code: 'UNAUTHENTICATED',
        message: 'x',
        statusCode: 401,
      ),
    );
    await tester.tap(find.byKey(const Key('content-login')));
    await tester.pumpAndSettle();
    expect(find.text('登入頁'), findsOneWidget);
  });
}
