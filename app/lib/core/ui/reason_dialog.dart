import 'package:flutter/material.dart';

import '../design/widgets.dart';
import '../l10n/translations.dart';

/// Asks "are you sure?" and, for things that are recorded in the audit log, for an optional reason.
/// Returns the reason (empty when none was given) or null when the person backed out.
Future<String?> askReason(
  BuildContext context, {
  required String title,
  required String message,
  required String confirmLabel,
}) =>
    showDialog<String>(context: context, builder: (context) => _ReasonDialog(title: title, message: message, confirmLabel: confirmLabel));

class _ReasonDialog extends StatefulWidget {
  const _ReasonDialog({required this.title, required this.message, required this.confirmLabel});

  final String title;
  final String message;
  final String confirmLabel;

  @override
  State<_ReasonDialog> createState() => _ReasonDialogState();
}

class _ReasonDialogState extends State<_ReasonDialog> {
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: Text(widget.title),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(widget.message),
              const SizedBox(height: 16),
              QField(controller: _reason, label: context.t('Reason (optional)'), helper: context.t('Kept in the audit log.'), maxLines: 2, maxLength: 500),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(context.t('Cancel'))),
          TextButton(
            onPressed: () => Navigator.of(context).pop(_reason.text.trim()),
            child: Text(widget.confirmLabel, style: TextStyle(color: Theme.of(context).colorScheme.error)),
          ),
        ],
      );
}
