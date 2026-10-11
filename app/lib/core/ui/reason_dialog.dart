import 'package:flutter/material.dart';

import '../design/widgets.dart';
import '../l10n/translations.dart';

/// Asks "are you sure?" and, for things that are recorded in the audit log, for a reason: optional, unless [required]
/// (then the confirm button waits for one). Returns the reason (empty when none was given) or null when the person
/// backed out.
Future<String?> askReason(
  BuildContext context, {
  required String title,
  required String message,
  required String confirmLabel,
  bool required = false,
}) =>
    showDialog<String>(context: context, builder: (context) => _ReasonDialog(title: title, message: message, confirmLabel: confirmLabel, required: required));

class _ReasonDialog extends StatefulWidget {
  const _ReasonDialog({required this.title, required this.message, required this.confirmLabel, required this.required});

  final String title;
  final String message;
  final String confirmLabel;
  final bool required;

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
              QField(
                controller: _reason,
                label: widget.required ? context.t('Reason') : context.t('Reason (optional)'),
                helper: context.t('Kept in the audit log.'),
                maxLines: 2,
                maxLength: 500,
                onChanged: widget.required ? (_) => setState(() {}) : null,
              ),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(context.t('Cancel'))),
          TextButton(
            onPressed: widget.required && _reason.text.trim().isEmpty ? null : () => Navigator.of(context).pop(_reason.text.trim()),
            child: Text(widget.confirmLabel, style: TextStyle(color: Theme.of(context).colorScheme.error)),
          ),
        ],
      );
}
