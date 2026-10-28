import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:qistas/app/providers.dart';
import 'package:qistas/core/storage/token_store.dart';
import 'package:qistas/data/models.dart';
import 'package:qistas/features/glance/glance_refresher.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'fake_api.dart';
import 'fake_glance_store.dart';
import 'glance_samples.dart';
import 'harness.dart';
import 'samples.dart';

/// A phone with the app closed: a token in the secure store, the preferences and the account the app last saw on disk,
/// and a refresher wired to a fake server and a fake widget.
class Phone {
  Phone(this.refresher, this.store, this.server);

  final GlanceRefresher refresher;
  final FakeGlanceStore store;
  final FakeServer server;

  List<RequestOptions> get glanceCalls => server.requestsTo('GET /glance');
}

const String token = 'qst_test-token';

Map<String, dynamic> cachedAccount({bool requireAppLock = false}) {
  final account = accountWithWidget();
  (account['tenant'] as Map<String, dynamic>)['require_app_lock'] = requireAppLock;

  return account;
}

Future<Phone> phone({
  MemoryTokenStore? tokens,
  String? signedInWith = token,
  bool hasCachedAccount = true,
  bool businessRequiresLock = false,
  Map<String, Object> prefs = const {},
  Map<String, Route> routes = const {},
  bool supported = true,
}) async {
  SharedPreferences.setMockInitialValues({
    'language': 'en',
    if (hasCachedAccount) AuthController.accountKey: jsonEncode(Account.fromJson(cachedAccount(requireAppLock: businessRequiresLock)).toJson()),
    ...prefs,
  });
  final server = FakeServer({
    'GET /glance': (options) => json(200, {'data': glanceJson(names: options.queryParameters['names'].toString() == '1')}),
    ...routes,
  });
  final store = FakeGlanceStore();
  final refresher = GlanceRefresher(
    tokens: tokens ?? MemoryTokenStore(signedInWith),
    store: store,
    adapter: server.adapter,
    clock: () => moment,
    supported: supported,
    loadWords: (language) async => wordsFor(language),
  );

  return Phone(refresher, store, server);
}
