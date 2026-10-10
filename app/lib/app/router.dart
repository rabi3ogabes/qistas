import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../features/auth/login_screen.dart';
import '../features/auth/register_screen.dart';
import '../features/billing/plans_screen.dart';
import '../features/contracts/contract_detail_screen.dart';
import '../features/contracts/contract_form_screen.dart';
import '../features/contracts/contracts_screen.dart';
import '../features/customers/customer_detail_screen.dart';
import '../features/customers/customer_form_screen.dart';
import '../features/customers/customers_screen.dart';
import '../features/customers/tags.dart';
import '../features/dashboard/dashboard_screen.dart';
import '../features/intro/onboarding_screen.dart';
import '../features/intro/splash_screen.dart';
import '../features/investors/investor_detail_screen.dart';
import '../features/investors/investor_form_screen.dart';
import '../features/investors/investors_screen.dart';
import '../features/payments/payments_screen.dart';
import '../features/products/products_screen.dart';
import '../features/search/search_screen.dart';
import '../features/security/app_lock_settings_screen.dart';
import '../features/settings/activity_log_screen.dart';
import '../features/settings/backups_screen.dart';
import '../features/settings/business_profile_screen.dart';
import '../features/settings/delete_account_screen.dart';
import '../features/settings/devices_screen.dart';
import '../features/settings/settings_screen.dart';
import '../features/settings/tools_screen.dart';
import '../features/team/team_screen.dart';
import 'providers.dart';
import 'shell.dart';

/// Tells the router to look again when the session changes.
class _SessionListenable extends ChangeNotifier {
  void changed() => notifyListeners();
}

/// Where each person may be: the intro and sign-in screens before they are signed in, the app after.
final routerProvider = Provider<GoRouter>((ref) {
  final session = _SessionListenable();
  ref.listen(authProvider, (_, _) => session.changed());
  ref.listen(splashDoneProvider, (_, _) => session.changed());
  ref.onDispose(session.dispose);

  const open = {'/welcome', '/login', '/register'};

  return GoRouter(
    initialLocation: '/splash',
    refreshListenable: session,
    redirect: (context, state) {
      final auth = ref.read(authProvider);
      final splash = ref.read(splashDoneProvider);
      final where = state.matchedLocation;

      // Hold the splash until the session is first known and the mark has had its moment. Asking the server again
      // later (Try again after being offline) keeps the last answer on screen, so it never bounces back to the splash.
      if ((auth.isLoading && !auth.hasValue) || splash.isLoading) return where == '/splash' ? null : '/splash';

      final signedIn = auth.valueOrNull?.isSignedIn ?? false;
      if (!signedIn) {
        final onboarded = ref.read(sharedPreferencesProvider).getBool(onboardedKey) ?? false;
        final home = onboarded ? '/login' : '/welcome';

        return open.contains(where) ? null : home;
      }

      return open.contains(where) || where == '/splash' ? '/' : null;
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, _) => const SplashScreen()),
      GoRoute(path: '/welcome', builder: (_, _) => const OnboardingScreen()),
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(path: '/register', builder: (_, _) => const RegisterScreen()),
      GoRoute(path: '/plans', builder: (_, _) => const PlansScreen()),
      GoRoute(path: '/tools', builder: (_, _) => const ToolsScreen()),
      GoRoute(path: '/app-lock', builder: (_, _) => const AppLockSettingsScreen()),
      GoRoute(path: '/delete-account', builder: (_, _) => const DeleteAccountScreen()),
      GoRoute(path: '/team', builder: (_, _) => const TeamScreen()),
      GoRoute(path: '/devices', builder: (_, _) => const DevicesScreen()),
      GoRoute(path: '/products', builder: (_, _) => const ProductsScreen()),
      GoRoute(path: '/settings/business', builder: (_, _) => const BusinessProfileScreen()),
      GoRoute(path: '/settings/backups', builder: (_, _) => const BackupsScreen()),
      GoRoute(path: '/settings/activity', builder: (_, _) => const ActivityLogScreen()),
      GoRoute(path: '/settings/tags', builder: (_, _) => const TagsScreen()),
      GoRoute(
        path: '/investors',
        builder: (_, _) => const InvestorsScreen(),
        routes: [
          GoRoute(path: 'new', builder: (_, _) => const InvestorFormScreen()),
          GoRoute(path: ':id', builder: (_, state) => InvestorDetailScreen(id: state.pathParameters['id']!), routes: [
            GoRoute(path: 'edit', builder: (_, state) => InvestorFormScreen(id: state.pathParameters['id'])),
          ]),
        ],
      ),
      GoRoute(path: '/search', builder: (_, _) => const SearchScreen()),
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => AppShell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [GoRoute(path: '/', builder: (_, _) => const DashboardScreen())]),
          StatefulShellBranch(routes: [
            GoRoute(
              path: '/customers',
              builder: (_, _) => const CustomersScreen(),
              routes: [
                GoRoute(path: 'new', builder: (_, _) => const CustomerFormScreen()),
                GoRoute(path: ':id', builder: (_, state) => CustomerDetailScreen(id: state.pathParameters['id']!), routes: [
                  GoRoute(path: 'edit', builder: (_, state) => CustomerFormScreen(id: state.pathParameters['id'])),
                ]),
              ],
            ),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(
              path: '/contracts',
              builder: (_, _) => const ContractsScreen(),
              routes: [
                GoRoute(path: 'new', builder: (_, state) => ContractFormScreen(customerId: state.uri.queryParameters['customer'])),
                GoRoute(path: ':id', builder: (_, state) => ContractDetailScreen(id: state.pathParameters['id']!, recordPayment: state.uri.queryParameters['pay'] == '1')),
              ],
            ),
          ]),
          StatefulShellBranch(routes: [GoRoute(path: '/payments', builder: (_, _) => const PaymentsScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/more', builder: (_, _) => const SettingsScreen())]),
        ],
      ),
    ],
  );
});
