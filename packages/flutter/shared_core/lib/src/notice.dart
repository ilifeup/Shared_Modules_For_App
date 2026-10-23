/// Portable notice contract; no application identity or API host is embedded.
class Notice {
  const Notice({
    required this.id,
    required this.title,
    required this.body,
    required this.status,
    required this.publishedAt,
  });

  final String id;
  final String title;
  final String body;
  final String status;
  final DateTime? publishedAt;

  bool isVisibleAt(DateTime nowUtc) =>
      status == 'published' &&
      publishedAt != null &&
      !publishedAt!.toUtc().isAfter(nowUtc.toUtc());
}
