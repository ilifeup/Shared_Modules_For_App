import 'dart:async';
import 'dart:typed_data';

import 'package:app_links/app_links.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

/// S9 device capabilities behind providers, so screens stay testable and the
/// platform plugins are only touched on a real device.

typedef GpsFix = ({double lat, double lng, double accuracy});

/// Opens the camera and returns the first QR code text (null = cancelled).
final qrScannerProvider = Provider<Future<String?> Function(BuildContext)>(
  (ref) =>
      (context) => Navigator.of(
        context,
      ).push<String>(MaterialPageRoute(builder: (_) => const QrScanPage())),
);

/// Current position for GPS check-in. Null when permission is denied or the
/// location is unavailable — the screen then asks the member to see staff.
final gpsProvider = Provider<Future<GpsFix?> Function()>(
  (ref) => () async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return null;
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        return null;
      }
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 15),
        ),
      );
      return (
        lat: position.latitude,
        lng: position.longitude,
        accuracy: position.accuracy,
      );
    } catch (_) {
      return null;
    }
  },
);

/// System share sheet.
final shareTextProvider = Provider<Future<void> Function(String text)>(
  (ref) => (text) async {
    await SharePlus.instance.share(ShareParams(text: text));
  },
);

/// System share sheet with an image (M14-D27 QR Code image).
final shareImageProvider =
    Provider<Future<void> Function(Uint8List png, String name)>(
      (ref) => (png, name) async {
        await SharePlus.instance.share(
          ShareParams(
            files: [XFile.fromData(png, mimeType: 'image/png', name: name)],
          ),
        );
      },
    );

/// Opens an HTTPS URL outside the App (Google consent, external campaigns).
final openUrlProvider = Provider<Future<bool> Function(Uri uri)>(
  (ref) => (uri) {
    if (uri.scheme != 'https') return Future.value(false);
    return launchUrl(uri, mode: LaunchMode.externalApplication);
  },
);

/// Incoming App Links (cold start and while running).
final deepLinkStreamProvider = Provider<Stream<Uri>>(
  (ref) => AppLinks().uriLinkStream,
);

class QrScanPage extends StatefulWidget {
  const QrScanPage({super.key});

  @override
  State<QrScanPage> createState() => _QrScanPageState();
}

class _QrScanPageState extends State<QrScanPage> {
  final _controller = MobileScannerController(
    formats: const [BarcodeFormat.qrCode],
  );
  bool _done = false;

  @override
  void dispose() {
    unawaited(_controller.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('掃描 QR Code')),
      body: MobileScanner(
        controller: _controller,
        onDetect: (capture) {
          if (_done) return;
          for (final code in capture.barcodes) {
            final value = code.rawValue;
            if (value != null && value.isNotEmpty) {
              _done = true;
              Navigator.of(context).pop(value);
              return;
            }
          }
        },
      ),
    );
  }
}
