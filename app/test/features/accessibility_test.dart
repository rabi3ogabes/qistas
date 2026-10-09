import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/harness.dart';

/// The app's own controls (the bottom bar, the gold plus, the language button) draw their own look and give screen
/// readers a single, clean label. A label alone is not enough: the control must also answer a screen reader's "tap",
/// or TalkBack and VoiceOver users hear the button and cannot press it.

void main() {
  testWidgets('a screen reader can open every section from the bottom bar', (tester) async {
    final handle = tester.ensureSemantics();
    await pumpApp(tester, workspaceServer());

    for (final (label, title) in [('Customers', 'Customers'), ('Contracts', 'Contracts'), ('Payments', 'Payments')]) {
      tester.semantics.tap(find.semantics.byLabel(label).last);
      await settle(tester);
      expect(find.widgetWithText(AppBar, title), findsOneWidget, reason: 'the screen reader could not open $label');
    }
    handle.dispose();
  });

  testWidgets('a screen reader can open the quick actions and the language cards', (tester) async {
    final handle = tester.ensureSemantics();
    await pumpApp(tester, workspaceServer());

    tester.semantics.tap(find.semantics.byLabel('Quick actions'));
    await settle(tester);
    expect(find.text('What would you like to do?'), findsOneWidget);
    Navigator.of(tester.element(find.text('What would you like to do?'))).pop();
    await settle(tester);

    tester.semantics.tap(find.semantics.byLabel('Language'));
    await settle(tester);
    expect(find.text('Choose your language'), findsOneWidget);
    handle.dispose();
  });
}
