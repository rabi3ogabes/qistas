import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/appearance.dart';
import '../../data/models.dart';
import '../billing/upgrade_sheet.dart';

/// The team of the business, fetched each time the page opens.
final teamProvider = FutureProvider.autoDispose<Team>((ref) => ref.watch(apiProvider).team());

String roleName(BuildContext context, String role) => switch (role) {
      'owner' => context.t('Owner'),
      'manager' => context.t('Manager'),
      'accountant' => context.t('Accountant'),
      'collector' => context.t('Collector'),
      _ => context.t('Viewer'),
    };

String roleHelp(BuildContext context, String role) => switch (role) {
      'manager' => context.t('Runs the business with you: customers, contracts, payments, reversals and the team.'),
      'accountant' => context.t('Records and checks payments and sees every report.'),
      'collector' => context.t('Collects instalments and records payments.'),
      _ => context.t('Can look at everything and change nothing.'),
    };

/// Win Plan PP11: who works in the business and what each may do. The owner and managers invite people with a link,
/// change roles and remove people; everyone else can look.
class TeamScreen extends ConsumerWidget {
  const TeamScreen({super.key});

  Future<void> _invite(BuildContext context, WidgetRef ref, Team team) async {
    if (team.isFull) {
      await showUpgradeSheet(
        context,
        UpgradeRequired(code: 'limit_reached', message: context.t('Your plan has room for :limit people. Upgrade to add more.', {'limit': '${team.limit}'}), feature: 'members', limit: team.limit, used: team.used),
      );
      return;
    }
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => InviteSheet(roles: team.assignableRoles),
    );
    ref.invalidate(teamProvider);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final team = ref.watch(teamProvider);
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final me = ref.watch(accountProvider)?.userId;

    return SectionScaffold(
      title: context.t('Team'),
      showAccount: false,
      body: team.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(teamProvider)),
        data: (team) => RefreshIndicator(
          onRefresh: () => ref.refresh(teamProvider.future),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
            children: [
              Text(
                team.limit == null
                    ? context.t(':count people', {'count': '${team.used}'})
                    : context.t(':used of :limit people on your plan', {'used': '${team.used}', 'limit': '${team.limit}'}),
                key: const ValueKey('team-usage'),
                style: text.bodyMedium?.copyWith(color: c.inkMuted),
              ),
              const SizedBox(height: 12),
              QCard(
                padding: EdgeInsets.zero,
                child: Column(
                  children: [
                    for (final (index, member) in team.members.indexed) ...[
                      if (index > 0) Divider(height: 1, indent: 64, color: c.line),
                      _MemberRow(member: member, isMe: member.id == me, team: team),
                    ],
                    for (final invitation in team.invitations) ...[
                      Divider(height: 1, indent: 64, color: c.line),
                      _InvitationRow(invitation: invitation, canManage: team.canManage),
                    ],
                  ],
                ),
              ),
              if (team.canManage) ...[
                const SizedBox(height: 20),
                QButton(
                  key: const ValueKey('team-invite'),
                  label: context.t('Invite someone'),
                  icon: Icons.person_add_alt_1_rounded,
                  kind: QButtonKind.gold,
                  onPressed: () => _invite(context, ref, team),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar(this.name, {this.waiting = false});

  final String name;
  final bool waiting;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final initial = name.trim().isEmpty ? '?' : name.trim().characters.first.toUpperCase();

    return CircleAvatar(
      radius: 20,
      backgroundColor: waiting ? c.surfaceAlt : c.tintSky,
      child: waiting ? Icon(Icons.schedule_rounded, size: 20, color: c.inkMuted) : Text(initial, style: TextStyle(color: c.ink, fontWeight: FontWeight.w600)),
    );
  }
}

class _MemberRow extends ConsumerWidget {
  const _MemberRow({required this.member, required this.isMe, required this.team});

  final TeamMember member;
  final bool isMe;
  final Team team;

  /// The same rule as the server's: the owner manages everyone but themselves; a manager, the people below managers.
  bool _touchable(String? myRole) => team.canManage && !isMe && member.role != 'owner' && (myRole == 'owner' || member.role != 'manager');

  Future<void> _manage(BuildContext context, WidgetRef ref) async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      useSafeArea: true,
      // Four roles with their sentences are taller than half a phone: let it grow, and scroll if it must.
      isScrollControlled: true,
      builder: (context) => SafeArea(
        child: SingleChildScrollView(
          child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(title: Text(member.name, style: Theme.of(context).textTheme.titleMedium), subtitle: Text(member.email)),
            for (final role in team.assignableRoles)
              ListTile(
                key: ValueKey('role-$role'),
                leading: Icon(role == member.role ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded),
                title: Text(roleName(context, role)),
                subtitle: Text(roleHelp(context, role)),
                onTap: () => Navigator.of(context).pop(role),
              ),
            ListTile(
              key: const ValueKey('member-remove'),
              leading: Icon(Icons.person_remove_alt_1_rounded, color: context.qc.danger),
              title: Text(context.t('Remove from the team'), style: TextStyle(color: context.qc.danger)),
              onTap: () => Navigator.of(context).pop('remove'),
            ),
          ],
          ),
        ),
      ),
    );
    if (choice == null || !context.mounted) return;

    final api = ref.read(apiProvider);
    try {
      if (choice == 'remove') {
        final sure = await showDialog<bool>(
          context: context,
          builder: (context) => AlertDialog(
            title: Text(context.t('Remove :name from the team?', {'name': member.name})),
            content: Text(context.t('They are signed out of this business at once. You can invite them again later.')),
            actions: [
              TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.t('Cancel'))),
              TextButton(key: const ValueKey('member-remove-confirm'), onPressed: () => Navigator.of(context).pop(true), child: Text(context.t('Remove'))),
            ],
          ),
        );
        if (sure != true) return;
        await api.removeMember(member.id);
      } else if (choice != member.role) {
        await api.changeRole(member.id, choice);
      }
      ref.invalidate(teamProvider);
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final text = Theme.of(context).textTheme;
    final c = context.qc;
    final touchable = _touchable(ref.watch(accountProvider)?.role);

    return ListTile(
      key: ValueKey('member-${member.id}'),
      leading: _Avatar(member.name),
      title: Text(isMe ? '${member.name} (${context.t('You')})' : member.name, maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: Text(member.email, maxLines: 1, overflow: TextOverflow.ellipsis, textDirection: TextDirection.ltr, style: text.bodySmall?.copyWith(color: c.inkMuted)),
      trailing: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(roleName(context, member.role), style: text.labelLarge?.copyWith(color: member.role == 'owner' ? c.accentText : c.inkMuted)),
          if (touchable) Icon(Icons.chevron_right_rounded, color: c.inkMuted),
        ],
      ),
      onTap: touchable ? () => _manage(context, ref) : null,
    );
  }
}

class _InvitationRow extends ConsumerWidget {
  const _InvitationRow({required this.invitation, required this.canManage});

  final TeamInvitation invitation;
  final bool canManage;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final text = Theme.of(context).textTheme;
    final c = context.qc;
    final day = '${invitation.expiresAt.year}-${invitation.expiresAt.month.toString().padLeft(2, '0')}-${invitation.expiresAt.day.toString().padLeft(2, '0')}';

    return ListTile(
      key: ValueKey('invitation-${invitation.id}'),
      leading: _Avatar(invitation.name ?? '', waiting: true),
      title: Text(invitation.name ?? context.t('Invited'), maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: Text('${roleName(context, invitation.role)} · ${context.t('Link works until :date', {'date': day})}', style: text.bodySmall?.copyWith(color: c.inkMuted)),
      trailing: canManage
          ? TextButton(
              key: ValueKey('withdraw-${invitation.id}'),
              onPressed: () async {
                try {
                  await ref.read(apiProvider).withdrawInvitation(invitation.id);
                  ref.invalidate(teamProvider);
                } on ApiException catch (e) {
                  if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
                }
              },
              child: Text(context.t('Withdraw')),
            )
          : null,
    );
  }
}

/// Choose what the new person may do, then get a link to send them. The link is shown once.
class InviteSheet extends ConsumerStatefulWidget {
  const InviteSheet({super.key, required this.roles});

  final List<String> roles;

  @override
  ConsumerState<InviteSheet> createState() => _InviteSheetState();
}

class _InviteSheetState extends ConsumerState<InviteSheet> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  late String _role = widget.roles.contains('collector') ? 'collector' : (widget.roles.isEmpty ? 'viewer' : widget.roles.first);
  bool _busy = false;
  String? _url;

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    super.dispose();
  }

  Future<void> _make() async {
    setState(() => _busy = true);
    try {
      final made = await ref.read(apiProvider).invite(role: _role, name: _name.text, phone: _phone.text);
      if (mounted) setState(() => _url = made.url);
    } on ApiException catch (e) {
      if (!mounted) return;
      if (await showUpgradeIfNeeded(context, e)) return;
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final business = ref.watch(accountProvider)?.businessName ?? '';

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
        child: _url == null
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(context.t('Invite someone'), style: text.titleLarge),
                  const SizedBox(height: 12),
                  Text(context.t('What they may do'), style: text.titleSmall),
                  const SizedBox(height: 6),
                  for (final role in widget.roles)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: _RoleCard(
                        key: ValueKey('invite-role-$role'),
                        title: roleName(context, role),
                        help: roleHelp(context, role),
                        selected: role == _role,
                        onTap: () => setState(() => _role = role),
                      ),
                    ),
                  const SizedBox(height: 8),
                  QField(controller: _name, label: context.t('Their name (optional)')),
                  const SizedBox(height: 12),
                  QField(controller: _phone, label: context.t('Their phone (optional)'), keyboardType: TextInputType.phone, latin: true),
                  const SizedBox(height: 20),
                  QButton(key: const ValueKey('invite-make'), label: context.t('Make an invitation link'), loading: _busy, onPressed: _make),
                ],
              )
            : Column(
                key: const ValueKey('invite-made'),
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.mark_email_read_outlined, size: 40, color: c.accentText),
                  const SizedBox(height: 12),
                  Text(context.t('Share this link with the person you invited'), style: text.titleLarge, textAlign: TextAlign.center),
                  const SizedBox(height: 8),
                  Text(context.t('It works once, for 7 days. Anyone with the link can join, so send it only to them.'), style: text.bodyMedium?.copyWith(color: c.inkMuted), textAlign: TextAlign.center),
                  const SizedBox(height: 16),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(color: c.surfaceAlt, borderRadius: BorderRadius.circular(12)),
                    child: SelectableText(_url!, textDirection: TextDirection.ltr, style: text.bodySmall),
                  ),
                  const SizedBox(height: 16),
                  QButton(
                    key: const ValueKey('invite-whatsapp'),
                    label: context.t('Send on WhatsApp'),
                    icon: Icons.chat_rounded,
                    kind: QButtonKind.gold,
                    onPressed: () => ref.read(openExternalProvider)(
                      Uri.parse('https://wa.me/?text=${Uri.encodeComponent('${context.t('Join :name on Qistas:', {'name': business})} $_url')}'),
                    ),
                  ),
                  const SizedBox(height: 8),
                  QButton(
                    key: const ValueKey('invite-copy'),
                    label: context.t('Copy the link'),
                    icon: Icons.copy_rounded,
                    kind: QButtonKind.quiet,
                    onPressed: () async {
                      await Clipboard.setData(ClipboardData(text: _url!));
                      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Link copied.'))));
                    },
                  ),
                ],
              ),
      ),
    );
  }
}

class _RoleCard extends StatelessWidget {
  const _RoleCard({super.key, required this.title, required this.help, required this.selected, required this.onTap});

  final String title;
  final String help;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Semantics(
      selected: selected,
      button: true,
      child: Material(
        color: selected ? c.tintSand : c.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: selected ? c.accent : c.line, width: selected ? 1.5 : 1)),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Row(
              children: [
                Icon(selected ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded, color: selected ? c.accentText : c.inkMuted),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title, style: text.titleSmall),
                      const SizedBox(height: 2),
                      Text(help, style: text.bodySmall?.copyWith(color: c.inkMuted)),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
