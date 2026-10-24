import 'package:eachcore_shared_core/eachcore_shared_core.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../ui/status_box.dart';
import 'booking_providers.dart';

/// S6 M08 booking block of the event detail: seats, my booking, book / cancel.
/// Buttons are disabled while a request runs, so a double tap sends once.
class BookingSection extends ConsumerStatefulWidget {
  const BookingSection({super.key, required this.eventId});

  final String eventId;

  @override
  ConsumerState<BookingSection> createState() => _BookingSectionState();
}

class _BookingSectionState extends ConsumerState<BookingSection> {
  bool _busy = false;
  Object? _error;
  String? _message;

  Future<void> _run(
    Future<BookingInfo> Function(BookingApi api) action,
    String success,
  ) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
      _message = null;
    });
    try {
      await action(ref.read(bookingApiProvider));
      if (mounted) setState(() => _message = success);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) {
        ref.invalidate(bookingInfoProvider(widget.eventId));
        ref.invalidate(myBookingsProvider);
        setState(() => _busy = false);
      }
    }
  }

  Future<void> _cancel(Booking booking) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('取消預約？'),
        content: const Text('取消後名額會釋出給其他會員。'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('保留預約'),
          ),
          FilledButton(
            key: const Key('booking-cancel-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('確定取消'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    await _run((api) => api.cancel(booking.id), '【已取消】預約已取消。');
  }

  @override
  Widget build(BuildContext context) {
    final info = ref.watch(bookingInfoProvider(widget.eventId));

    return info.when(
      loading: () => const Text('【預約資訊載入中】'),
      error: (error, _) => ErrorRetry(
        error: ErrorBox(error: error),
        onRetry: () => ref.invalidate(bookingInfoProvider(widget.eventId)),
      ),
      data: (value) {
        if (!value.enabled) return const SizedBox.shrink();
        final mine = value.myBooking;
        final reason = value.unavailableReason;
        final seats =
            '名額 ${value.capacity}，已預約 ${value.bookedCount}，'
            '剩餘 ${value.remaining}';
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            StatusBox(
              key: const Key('booking-seats'),
              label: mine != null ? '【已預約】' : '【預約】',
              detail: seats,
            ),
            if (_message != null) StatusBox(label: _message!, detail: ''),
            if (_error != null) ErrorBox(error: _error!),
            if (mine != null)
              OutlinedButton(
                key: const Key('booking-cancel'),
                onPressed: _busy ? null : () => _cancel(mine),
                child: Text(_busy ? '處理中…' : '取消預約'),
              )
            else if (reason == null)
              FilledButton(
                key: const Key('booking-book'),
                onPressed: _busy
                    ? null
                    : () => _run(
                        (api) => api.book(widget.eventId),
                        '【預約成功】已為您保留名額。',
                      ),
                child: Text(_busy ? '處理中…' : '預約'),
              )
            else
              StatusBox(
                key: const Key('booking-unavailable'),
                label: '【無法預約】',
                detail: bookingReasonLabels[reason] ?? reason,
              ),
          ],
        );
      },
    );
  }
}
