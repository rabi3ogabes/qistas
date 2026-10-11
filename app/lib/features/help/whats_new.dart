import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/config.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../data/help.dart';

/// The app version whose notes this phone last showed (or that it was installed at).
const whatsNewSeenKey = 'whats_new_seen';

/// "What's new" once after an update (Win Plan PP17). A first install only remembers its version: there is nothing new
/// to someone who has just arrived.
Future<void> checkWhatsNew(BuildContext context, WidgetRef ref) async {
  final prefs = ref.read(sharedPreferencesProvider);
  final seen = prefs.getString(whatsNewSeenKey);
  if (seen == AppConfig.appVersion) return;
  await prefs.setString(whatsNewSeenKey, AppConfig.appVersion);
  if (seen == null || !context.mounted) return;

  await showWhatsNew(context, ref, since: seen);
}

/// The notes for the versions after [since], newest first. Shows nothing when there are none or the server is away.
Future<void> showWhatsNew(BuildContext context, WidgetRef ref, {required String since}) async {
  final List<ReleaseNote> notes;
  try {
    notes = await ref.read(apiProvider).releaseNotes(since: since);
  } on ApiException {
    return;
  }
  if (!context.mounted) return;
  if (notes.isEmpty) {
    if (since == '0.0.0') ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Nothing new to show yet.'))));
    return;
  }

  await showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    showDragHandle: true,
    builder: (sheet) => _WhatsNewSheet(notes: notes),
  );
}

class _WhatsNewSheet extends StatelessWidget {
  const _WhatsNewSheet({required this.notes});

  final List<ReleaseNote> notes;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.8),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
              child: Row(
                children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(color: c.tintSand, shape: BoxShape.circle),
                    child: Icon(Icons.auto_awesome_outlined, color: c.accentText),
                  ),
                  const SizedBox(width: 14),
                  Expanded(child: Text(context.t('What’s new'), style: text.headlineSmall)),
                ],
              ),
            ),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                padding: const EdgeInsets.symmetric(horizontal: 24),
                children: [
                  for (final note in notes) ...[
                    Text(context.t('Version :version', {'version': note.version}), style: text.labelLarge?.copyWith(color: c.accentText)),
                    const SizedBox(height: 6),
                    Text(note.body, style: text.bodyLarge?.copyWith(height: 1.5)),
                    const SizedBox(height: 20),
                  ],
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 4, 24, 16),
              child: QButton(label: context.t('Got it'), kind: QButtonKind.gold, onPressed: () => Navigator.of(context).pop()),
            ),
          ],
        ),
      ),
    );
  }
}
