import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/errors/trace_scrubber.dart';

/// The privacy gate of a crash report: whatever the phone sends has passed through [TraceScrubber.clean], which masks
/// what could name a person, a business or a secret, keeps what makes a trace readable (the library, the file and the
/// line), and fails closed.
void main() {
  /// What a text becomes. (The server cleans again; the phone cleans first.)
  const masks = <String, ({String input, String output})>{
    'an e-mail address': (input: 'Could not reach layla@example.com just now', output: 'Could not reach [email] just now'),
    'an e-mail address written with Arabic letters': (input: 'sent to ليلى@مثال.كوم failed', output: 'sent to [email] failed'),
    'a phone number with its country code': (input: 'call +974 5555 1234 today', output: 'call [phone] today'),
    'a phone number with a double zero': (input: 'call 00974 5555 1234 today', output: 'call [phone] today'),
    'a phone number written the local way': (input: 'phone 0501234567 failed', output: 'phone [phone] failed'),
    'a phone number in Arabic-Indic digits': (input: 'phone ٠٥٠١٢٣٤٥٦٧ failed', output: 'phone [phone] failed'),
    'a phone number with a country code in Arabic-Indic digits': (input: 'call +٩٧٤ ٥٥٥٥ ١٢٣٤ today', output: 'call [phone] today'),
    'a phone number in Persian digits': (input: 'phone ۰۵۰۱۲۳۴۵۶۷ failed', output: 'phone [phone] failed'),
    'a phone number in full-width digits': (input: 'phone ０５０１２３４５６７ failed', output: 'phone [phone] failed'),
    'a run of ten digits': (input: 'customer 1234567890 not found', output: 'customer [id] not found'),
    'a run of nine digits': (input: 'id 123456789.', output: 'id [id].'),
    'a run of digits in Arabic-Indic digits': (input: 'رقم ١٢٣٤٥٦٧٨٩٠١ غير موجود', output: 'رقم [id] غير موجود'),
    'a card number': (input: 'card 4111 1111 1111 1111 declined', output: 'card [id] declined'),
    'a bearer token': (input: 'sent Bearer abc to the server', output: 'sent Bearer [token] to the server'),
    'an authorization header': (input: 'Authorization: Bearer 12|abcdefghijklmnopqrstuvwxyz', output: 'Authorization: [secret]'),
    'a cookie header': (input: 'Cookie: a=b; c=d', output: 'Cookie: [secret]'),
    'a sanctum token': (input: 'token 12|ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuv done', output: 'token [token] done'),
    'a token in a query': (input: 'failed token=xyz and more', output: 'failed token=[secret] and more'),
    'a password': (input: 'password=hunter2', output: 'password=[secret]'),
    'a secret and a key': (input: 'secret=abc123 key=AKIAEXAMPLE', output: 'secret=[secret] key=[secret]'),
    'a key that names a secret, in JSON': (input: '{"password":"hunter2","note":1}', output: '{"?":[secret],"?":1}'),
    'a key that names a secret, after a colon and a space': (input: 'Login failed: password: hunter2', output: 'Login failed: password: [secret]'),
    'a secret behind a key that is not one': (input: 'Login failed: password=hunter2', output: 'Login failed: password=[secret]'),
    'a name given as a value': (input: 'saving name=Layla now', output: 'saving name=[secret] now'),
    'a uuid, such as an account id': (input: 'account 3f2b8c1e-5d4a-4b7e-9c1d-2a3b4c5d6e7f missing', output: 'account [id] missing'),
    'an address of a phone on a network': (input: 'connect to 192.168.1.20:8080 failed', output: 'connect to [ip]:8080 failed'),
    'a long hex run': (input: 'hash deadbeefdeadbeefdeadbeefdeadbeef end', output: 'hash [token] end'),
    'a long base64 run': (input: 'key kQ9x2LmZp0Vb7TrYw3NcJ8aDh5sFgHjK end', output: 'key [token] end'),
    'a base64 run with slashes': (input: 'blob kQ9x2LmZp0Vb7TrY/w3NcJ8aDh5sFgHjK end', output: 'blob [token] end'),
    'a name inside double quotes (what Dart prints for a value)': (input: 'Receiver: "Layla Haddad" Tried calling: foo("1250.00")', output: 'Receiver: "?" Tried calling: foo("?")'),
    'a double quote that never closes': (input: 'Receiver: "Layla Ha', output: 'Receiver: "?"'),
    'a query string on an address': (input: 'GET https://api.qistasme.com/api/v1/customers?search=Layla&page=2 failed', output: 'GET https://api.qistasme.com/api/v1/customers failed'),
    'an access token on an address': (input: 'GET https://api.qistasme.com/v1/me?access_token=1 failed', output: 'GET https://api.qistasme.com/v1/me failed'),
    'an access token in a query that has no address': (input: 'route /me?access_token=1', output: 'route /me'),
    'the user name and password of an address': (input: 'https://layla:hunter2@qistasme.com/x', output: 'https://qistasme.com/x'),
    'a path on Windows, as a file address': (input: 'at file:///C:/Users/layla/work/app/lib/features/x.dart:12:3', output: 'at lib/features/x.dart:12:3'),
    'a path on Windows, with backslashes': (input: r'at C:\Users\layla\work\app\lib\main.dart:5:1', output: 'at lib/main.dart:5:1'),
    'a path in a build folder': (input: 'at /home/runner/work/qistas/app/lib/main.dart:5:1', output: 'at lib/main.dart:5:1'),
    'a path inside the phone that is not ours': (input: 'open /data/user/0/com.qistas.app/cache/x.pdf failed', output: 'open [path] failed'),
    'a quoted path with a name in it': (input: "path = '/storage/emulated/0/Download/Layla Haddad receipt.pdf' (OS Error)", output: "path = '[path]' (OS Error)"),
  };

  group('what is masked', () {
    for (final entry in masks.entries) {
      test(entry.key, () => expect(TraceScrubber.clean(entry.value.input), entry.value.output));
    }
  });

  group('what is kept, so that a trace can still be read', () {
    const kept = [
      '#0      CustomerScreen.build (package:qistas/features/customers/customer_screen.dart:42:7)',
      '#1      _rootRunUnary (dart:async/zone.dart:1399:13)',
      '#2      State.setState (package:flutter/src/widgets/framework.dart:1239:12)',
      '<asynchronous suspension>',
      'lib/core/storage/token_store.dart:34:12',
      'FormatException: Invalid number (at character 3)',
      'type \'Null\' is not a subtype of type \'String\' in type cast',
      'A RenderFlex overflowed by 24 pixels on the right.',
      'Bad state: No element',
      'RangeError (index): Invalid value: Not in inclusive range 0..4: 7',
      'LateInitializationError: Field \'name\' has not been initialized.',
      '_kDartIsolateSnapshotInstructions+0x1a2b',
      'WidgetsFlutterBindingExtremelyLongClassNameForTesting',
      'at 2026-11-05T10:20:30.123456 on version 1.29.0',
      'GET failed with 502 after 15000 ms',
    ];

    for (final text in kept) {
      test(text, () => expect(TraceScrubber.clean(text), text));
    }
  });

  group('the limits', () {
    test('a trace is cut to 8000 characters by default', () {
      expect(TraceScrubber.clean('frame ' * 5000).length, lessThanOrEqualTo(8000));
      expect(TraceScrubber.clean('frame ' * 5000).length, greaterThan(7900));
    });

    test('a text is cut to the length asked for', () {
      expect(TraceScrubber.clean('é' * 600, max: 500).runes.length, 500);
      expect(TraceScrubber.clean('word ' * 200, max: 160).length, lessThanOrEqualTo(160));
    });

    test('a cut never lands inside a character', () {
      final cut = TraceScrubber.clean('😀' * 400, max: 399);

      expect(cut.runes.length, 399);
      expect(cut.length, 798, reason: 'two UTF-16 units each, none of them alone');
    });

    test('a cut never leaves the front half of a mask behind', () {
      final cut = TraceScrubber.clean('${'x' * 495} layla@example.com', max: 500);

      expect(cut.length, lessThanOrEqualTo(500));
      expect(cut.contains('['), isFalse);
      expect(cut.contains('@'), isFalse);
    });

    test('masking comes before cutting: a secret at the end of a long text never survives half cut', () {
      final cut = TraceScrubber.clean('${'x ' * 300}password=hunter2supersecret', max: 500);

      expect(cut.contains('hunter'), isFalse);
      expect(cut.contains('supersecret'), isFalse);
    });
  });

  group('safety', () {
    test('cleaning a clean text changes nothing', () {
      for (final entry in masks.values) {
        final once = TraceScrubber.clean(entry.input);

        expect(TraceScrubber.clean(once), once, reason: entry.input);
      }
    });

    test('invisible characters do not hide a number', () {
      expect(TraceScrubber.clean('phone 05\u200B01\u200F23\u200C4567 failed'), 'phone [phone] failed');
      expect(TraceScrubber.clean('phone 0501\u00A0234\u2009567 failed'), 'phone [phone] failed');
    });

    test('a text with a broken character is cleaned, not refused', () {
      final broken = String.fromCharCodes([0x61, 0xD800, 0x62]);

      expect(() => TraceScrubber.clean(broken), returnsNormally);
    });

    test('an empty text is an empty text', () {
      expect(TraceScrubber.clean(''), '');
    });

    test('a text built to be slow is read in a bounded time', () {
      final stopwatch = Stopwatch()..start();

      for (final text in [
        'a' * 2000000,
        '1' * 2000000,
        '/a' * 1000000,
        'a@' * 1000000,
        'Bearer ' * 300000,
        'a=' * 1000000,
        'a.' * 1000000,
        '"' * 1000000,
        '0' * 100000 + 'x',
        '${'ab12' * 100000}=',
        'a-' * 1000000,
        'a://' * 600000,
        'http://${'a' * 2000000}',
        'a?b=c&' * 600000,
        'x:y ' * 600000,
        'aB1+' * 600000,
        'a.b' * 700000,
      ]) {
        TraceScrubber.clean(text);
      }

      expect(stopwatch.elapsed, lessThan(const Duration(seconds: 10)));
    });

    test('a secret beyond the cut made for speed is never sent', () {
      final text = '${'x ' * 100000}password=hunter2';

      expect(TraceScrubber.clean(text).contains('hunter2'), isFalse);
    });
  });
}
