import 'dart:math' as math;

/// The privacy gate of a crash report. Everything the phone sends about an error (its class, its message, its trace)
/// passes through [clean] first, so that nothing that could name a person, a business or a secret leaves the phone.
///
/// It masks, in this order:
///  - addresses that carry credentials or a query (`https://user:pw@host/x?a=b` keeps the host and the path only), and
///    absolute file paths (cut to the `lib/...` part, or `[path]` when they are not ours);
///  - credentials by shape (`Bearer ...`, `Authorization:`, `Cookie:`, tokens, long hex or base64 runs) and by name
///    (`password=`, `token=`, `secret=`, `key=`, and a name, an e-mail or a phone given as a value);
///  - people and accounts: e-mail addresses, ids (UUIDs, runs of 9 or more digits), phone numbers, card numbers and
///    network addresses, written with Arabic-Indic, Persian, full-width or ASCII digits;
///  - whatever Dart prints between double quotes, because that is where it prints a value (`Receiver: "Layla"`).
///
/// What it leaves alone, so that a trace stays readable: the library, the file and the line (`package:qistas/x.dart:12:3`),
/// class names, versions, dates and the words of the message.
///
/// A text is cut AFTER masking, on a character (never inside a pair of UTF-16 units), and never leaves half of a mask.
/// The server cleans every report again with its own, stricter scrubber; this is the first line of defence, and the one
/// that decides what leaves the phone.
///
/// Every pattern here reads a text in a time that grows with its length, and a text is first cut to a bounded size, so a
/// text built to be slow cannot hold the app up while it crashes.
abstract final class TraceScrubber {
  /// The longest trace the server takes, in characters.
  static const int traceMax = 8000;

  /// The longest message the server takes, in characters.
  static const int messageMax = 500;

  /// The longest class name the server takes, in characters.
  static const int classMax = 160;

  /// [text] cleaned and cut to [max] characters.
  static String clean(String text, {int max = traceMax}) {
    var work = _prepare(_bounded(text, max));

    // A pattern that needs a character the text does not have is not run at all: most traces have none of the rarer ones,
    // every pass over a long text costs milliseconds (a phone's several times that), and a text outside Latin-1 pays more
    // for every pass it is given (and the first time it meets a pattern, for compiling it again for the wider alphabet).
    if (work.contains('://')) {
      work = work.replaceAllMapped(_urlUserInfo, (m) => m[1]!);
      work = work.replaceAllMapped(_urlQuery, (m) => m[1]!);
    }
    if (work.contains('?')) work = work.replaceAll(_queryString, '');
    work = _paths(work);

    final lower = work.toLowerCase();
    if (lower.contains('authorization')) work = work.replaceAllMapped(_authorization, (m) => '${m[1]}: [secret]');
    if (lower.contains('cookie')) work = work.replaceAllMapped(_cookie, (m) => '${m[1]}: [secret]');
    if (lower.contains('bearer')) work = work.replaceAll(_bearer, 'Bearer [token]');
    if (work.contains('|')) work = work.replaceAll(_sanctum, '[token]');
    if (work.contains('.')) work = work.replaceAll(_jwt, '[token]');
    if (work.contains('-')) work = work.replaceAll(_uuid, '[id]');
    work = _keyed(work);

    // An address needs its @ (or the written forms of it).
    if (work.contains('@') || work.contains('%40') || work.contains('\uFF20')) work = work.replaceAll(_email, '[email]');
    if (work.contains('.')) work = work.replaceAll(_ipv4, '[ip]');

    work = work.replaceAll(_hex, '[token]');
    work = work.replaceAllMapped(_slashed, (m) => _slashedIsToken(m[1]!) ? '[token]' : m[0]!);
    work = work.replaceAllMapped(_run, (m) => _looksRandom(m[1]!) ? '[token]' : m[0]!);
    work = work.replaceAll(_padded, '[token]');

    for (final (pattern, mask) in _numbers) {
      work = work.replaceAll(pattern, mask);
    }

    if (work.contains('"')) work = work.replaceAll(_quoted, '"?"');

    return cut(work.trim(), max);
  }

  /// [text] cut to [max] characters: never inside a pair of UTF-16 units, and never leaving the front half of a mask.
  static String cut(String text, int max) {
    if (text.length <= max) return text;

    var end = 0;
    var count = 0;
    while (end < text.length && count < max) {
      final unit = text.codeUnitAt(end);
      end += _isHigh(unit) && end + 1 < text.length && _isLow(text.codeUnitAt(end + 1)) ? 2 : 1;
      count++;
    }

    return text.substring(0, end).replaceFirst(_tornMask, '').trimRight();
  }

  // ---------------------------------------------------------------- reading a text safely

  /// A text far longer than any report is cut before a pattern reads it. The cut can land inside a value that masking
  /// would have shortened, so what is left of the last word goes too.
  static String _bounded(String text, int max) {
    final limit = math.max(4096, max * 8);
    if (text.length <= limit) return text;

    var end = limit;
    if (_isHigh(text.codeUnitAt(end - 1))) end--;
    final bounded = text.substring(0, end);
    final space = bounded.lastIndexOf(_whitespace);

    return space > 0 ? bounded.substring(0, space) : bounded;
  }

  /// Plain spaces and lines, ASCII digits, and no invisible character: one set of patterns then reads every keyboard,
  /// and a zero-width space cannot split a number to hide it.
  ///
  /// One pass over the code units, not four patterns over the text: a pattern over a letter of another alphabet (Arabic,
  /// an emoji) is compiled again for it and read much more slowly, and the longer the text the more that costs. A text
  /// of plain ASCII is returned as it is.
  static String _prepare(String text) {
    final length = text.length;
    var i = 0;
    // The plain case first: nothing to change in what is printable ASCII, a line break or a tab.
    while (i < length) {
      final unit = text.codeUnitAt(i);
      if (unit > 0x7E || (unit < 0x20 && unit != 0x0A && unit != 0x09)) break;
      i++;
    }
    if (i == length) return text.trim();

    final out = StringBuffer(text.substring(0, i));
    for (; i < length; i++) {
      final unit = text.codeUnitAt(i);

      if (unit < 0x80) {
        if (unit == 0x0D) {
          // \r\n and a lone \r are one line break
          if (i + 1 < length && text.codeUnitAt(i + 1) == 0x0A) i++;
          out.writeCharCode(0x0A);
        } else if (unit == 0x0A || unit == 0x09 || (unit >= 0x20 && unit < 0x7F)) {
          out.writeCharCode(unit);
        }
        // the other controls (and DEL) are dropped
      } else if (unit >= 0xD800 && unit <= 0xDBFF && i + 1 < length && _isLow(text.codeUnitAt(i + 1))) {
        // a pair: kept, unless it is a tag character or from a private-use plane (invisible, and used to hide text)
        final rune = 0x10000 + ((unit - 0xD800) << 10) + (text.codeUnitAt(i + 1) - 0xDC00);
        if (!((rune >= 0xE0000 && rune <= 0xE007F) || rune >= 0xF0000)) {
          out.writeCharCode(unit);
          out.writeCharCode(text.codeUnitAt(i + 1));
        }
        i++;
      } else if (_isHigh(unit) || _isLow(unit)) {
        // half a pair: dropped
      } else if (unit == 0x2028 || unit == 0x2029) {
        out.writeCharCode(0x0A);
      } else if (_isSpace(unit)) {
        out.writeCharCode(0x20);
      } else if (unit >= 0x0660 && unit <= 0x0669) {
        out.writeCharCode(0x30 + unit - 0x0660);
      } else if (unit >= 0x06F0 && unit <= 0x06F9) {
        out.writeCharCode(0x30 + unit - 0x06F0);
      } else if (unit >= 0xFF10 && unit <= 0xFF19) {
        out.writeCharCode(0x30 + unit - 0xFF10);
      } else if (!_isHidden(unit)) {
        out.writeCharCode(unit);
      }
    }

    return out.toString().trim();
  }

  /// A space of another alphabet or typography (no-break, en, em, thin, ideographic ...): the category Zs.
  static bool _isSpace(int unit) => unit == 0x00A0 || unit == 0x1680 || (unit >= 0x2000 && unit <= 0x200A) || unit == 0x202F || unit == 0x205F || unit == 0x3000;

  /// What cannot be seen and can hide a number or a word: controls, format characters (soft hyphen, zero-width and
  /// direction marks, the invisible operators), private use.
  static bool _isHidden(int unit) =>
      unit <= 0x9F ||
      unit == 0x00AD ||
      (unit >= 0x0600 && unit <= 0x0605) ||
      unit == 0x061C ||
      unit == 0x06DD ||
      unit == 0x070F ||
      unit == 0x08E2 ||
      unit == 0x180E ||
      (unit >= 0x200B && unit <= 0x200F) ||
      (unit >= 0x202A && unit <= 0x202E) ||
      (unit >= 0x2060 && unit <= 0x206F) ||
      (unit >= 0xE000 && unit <= 0xF8FF) ||
      unit == 0xFEFF ||
      (unit >= 0xFFF9 && unit <= 0xFFFB);

  static bool _isHigh(int unit) => unit >= 0xD800 && unit <= 0xDBFF;

  static bool _isLow(int unit) => unit >= 0xDC00 && unit <= 0xDFFF;

  static final RegExp _whitespace = RegExp(r'\s');
  static final RegExp _tornMask = RegExp(r'\[[a-z]{0,7}$');

  // ---------------------------------------------------------------- addresses and paths

  // A scheme and a host are short, and an address is bounded too: a long run is not read again from every place in it that could start one.
  static final RegExp _urlUserInfo = RegExp(r'''\b([a-z][a-z0-9+.-]{0,31}://)[^\s/?#"'<>]{0,256}@''', caseSensitive: false, unicode: true);
  static final RegExp _urlQuery = RegExp(r'''\b([a-z][a-z0-9+.-]{0,31}://[^\s?#"'<>]{0,1024})[?#][^\s"'<>)\],]*''', caseSensitive: false, unicode: true);
  static final RegExp _queryString = RegExp(r'''(?<=[\w/}\]])\?[\w%.\[\]-]+=[^\s"'<>)\],&]*(?:&[\w%.\[\]-]+=[^\s"'<>)\],&]*)*''', unicode: true);

  static final RegExp _fileScheme = RegExp(r'\bfile://', caseSensitive: false);
  static final RegExp _quotedPath = RegExp(r'''(["'])((?:/?[A-Za-z]:[/\\]|[/\\])[^\n]*?)\1''', unicode: true);
  static final RegExp _drivePath = RegExp(r'''(?<![\w:/\\.-])/?[A-Za-z]:[/\\][^\s"'<>|*?(),;:\[\]{}]*''', unicode: true);
  static final RegExp _slashPath = RegExp(r'''(?<![\w:/\\.@%~-])[/\\]{1,2}[^\s"'<>|*?(),;:\[\]{}]+''', unicode: true);
  static final RegExp _separator = RegExp(r'[/\\]');

  /// Every absolute path in the text becomes the part under the project's `lib/` folder, or `[path]`.
  static String _paths(String text) {
    var work = text.toLowerCase().contains('file://') ? text.replaceAll(_fileScheme, '') : text;
    if (work.contains('"') || work.contains("'")) work = work.replaceAllMapped(_quotedPath, (m) => '${m[1]}${_relative(m[2]!)}${m[1]}');
    if (work.contains(':/') || work.contains(':\\')) work = work.replaceAllMapped(_drivePath, (m) => _relative(m[0]!));
    if (!work.contains('/') && !work.contains('\\')) return work;

    // A slash that starts a word and has another after it is a path (`/data/user/0/...`); `/x` alone is not.
    return work.replaceAllMapped(_slashPath, (m) => m[0]!.substring(1).contains(_separator) ? _relative(m[0]!) : m[0]!);
  }

  static String _relative(String raw) {
    var path = raw.replaceAll('\\', '/');
    if (path.length > 2 && path.codeUnitAt(0) == 0x2F && path.codeUnitAt(2) == 0x3A) path = path.substring(1);

    final at = path.lastIndexOf('/lib/');

    return at >= 0 && at + 5 < path.length ? path.substring(at + 1) : '[path]';
  }

  // ---------------------------------------------------------------- credentials

  static final RegExp _authorization = RegExp(r'\b((?:proxy-)?authorization)\s*:\s*(?:(?:bearer|basic|token|digest|negotiate)\s+)?[^\s,;)]+', caseSensitive: false, unicode: true);
  static final RegExp _cookie = RegExp(r'\b((?:set-)?cookie)\s*:[^\n]*', caseSensitive: false, unicode: true);
  static final RegExp _bearer = RegExp(r'''\bBearer\s+[^\s,;)"']+''', caseSensitive: false, unicode: true);
  static final RegExp _sanctum = RegExp(r'(?<![\w|])\d+\|[A-Za-z0-9]{40,}(?![A-Za-z0-9])', unicode: true);
  static final RegExp _jwt = RegExp(r'(?<![\w.-])(?:eyJ[\w-]{5,}\.[\w-]{5,}\.[\w-]*|[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,})(?![\w-])', unicode: true);
  static final RegExp _uuid = RegExp(r'(?<![\w-])[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![\w-])', caseSensitive: false, unicode: true);

  /// A name, an `=` or `:` and what follows it. Only the name and the separator are matched here: whether the value
  /// goes is decided from the name, and a name that is not one of ours is passed over, so a secret after it is still found.
  static final RegExp _keyStart = RegExp(r'''(?<![\w.-])([A-Za-z_][\w-]*)(["']?)(\s*[:=]\s*)(?=\S)''', unicode: true);
  static final RegExp _keyValue = RegExp(r'''"(?:[^"\\\n]|\\.)*"|'(?:[^'\\\n]|\\.)*'|(?:\[(?:secret|token|email|phone|id|ip|path)\]|[^\s,;&)\]}"'])+''', unicode: true);
  static final RegExp _secretKey = RegExp(
    r'password|passwd|pwd|passphrase|passcode|secret|token|credential|api[_-]?key|private[_-]?key|session|cookie|remember|authorization|signature|key|otp|cvv|cvc|(?<![a-z0-9])(?:pass|pw|pin|sid|auth|sig)(?![a-z0-9])',
  );
  static final RegExp _personalKey = RegExp(
    r'^(?:(?:customer|user|client|full|first|last|display|company)[_.-]?)?(?:name|address|phone|mobile|email)(?:[_.-]?(?:number|address|line1|line2))?$|^(?:national[_-]?id|id[_-]?number|iban|account[_-]?number|card[_-]?number|username|birth[_-]?date|dob)$',
  );

  static String _keyed(String text) {
    final out = StringBuffer();
    var cursor = 0;

    while (cursor < text.length) {
      final found = _keyStart.allMatches(text, cursor).iterator;
      if (!found.moveNext()) break;

      final match = found.current;
      final key = match[1]!;
      final quote = match[2]!;
      final separator = match[3]!;
      final lower = key.toLowerCase();
      // A name of ours always loses its value; a name of personal data only when it is clearly given one (`name=x`, `"name": x`).
      final mask = _secretKey.hasMatch(lower) || (_personalKey.hasMatch(lower) && (separator.contains('=') || quote.isNotEmpty));
      final value = mask ? _keyValue.matchAsPrefix(text, match.end) : null;

      out.write(text.substring(cursor, match.end));
      if (value == null) {
        cursor = match.end;
        continue;
      }

      // The match already holds the name and the separator: write the mask where the value was.
      out.write('[secret]');
      cursor = value.end;
    }

    out.write(text.substring(cursor));

    return out.toString();
  }

  // ---------------------------------------------------------------- people and accounts

  static final RegExp _email = RegExp(
    r'''(?<![\p{L}\p{N}])(?:"[^"\n]{1,64}"|[\p{L}\p{N}._%+'~-]{1,64})(?:@|%40|＠)(?:\[[0-9a-f:.]{2,45}\]|localhost\b|(?:[\p{L}\p{N}](?:[\p{L}\p{N}_-]*[\p{L}\p{N}])?\.)+(?:xn--[a-z0-9-]{2,}|\p{L}{2,}))''',
    caseSensitive: false,
    unicode: true,
  );
  static final RegExp _ipv4 = RegExp(r'(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?!\d|\.\d)', unicode: true);

  // ---------------------------------------------------------------- long runs that are random on purpose

  // ASCII only (no `\p{L}`): a pattern for every script would be compiled again the first time the text has a letter outside Latin-1.
  static final RegExp _hex = RegExp(r'(?<![0-9A-Za-z])(?=[0-9]*[a-fA-F])[0-9a-fA-F]{24,}(?![0-9A-Za-z])');
  static final RegExp _slashed = RegExp(r'(?<![\w+/-])((?=[A-Za-z0-9+]*/)[A-Za-z0-9+/]{24,})(={0,2})(?![\w+/-])', unicode: true);
  static final RegExp _run = RegExp(r'(?<![\w+/-])([A-Za-z0-9+_-]{24,})(={0,2})(?![\w+-])', unicode: true);
  static final RegExp _padded = RegExp(r'(?<![\w+/-])[A-Za-z0-9+/]{24,}={1,2}(?![\w=])', unicode: true);
  static final RegExp _plusOffset = RegExp(r'\+(?!0x[0-9a-f]+$)');
  static final RegExp _underscoreOrDash = RegExp(r'[_-]');
  static final RegExp _oneCase = RegExp(r'^(?:[a-z0-9]+|[A-Z0-9]+)$');
  static final RegExp _hasDigit = RegExp(r'\d');
  static final RegExp _hasLetter = RegExp(r'[A-Za-z]');

  /// A long run of letters, digits and `+ _ -` is a secret unless it reads like a name. A name changes case only where
  /// a word starts (`WidgetsFlutterBinding`); a random run changes all the time. A symbol and its offset in a native
  /// trace (`_kDartIsolateSnapshotInstructions+0x1a2b`) is a name.
  static bool _looksRandom(String run) {
    // One letter again and again, or two, is filler, not a key.
    if (run.length >= 12 && run.toLowerCase().split('').toSet().length < 6) return false;
    if (_plusOffset.hasMatch(run)) return true;

    final bare = run.replaceAll(_underscoreOrDash, '');
    if (bare.length < 2) return false;

    var changes = 0;
    for (var i = 1; i < bare.length; i++) {
      if (_kind(bare.codeUnitAt(i)) != _kind(bare.codeUnitAt(i - 1))) changes++;
    }
    if (changes / (bare.length - 1) > 0.42) return true;

    // Letters mixed with digits in one case: no word can be seen in it.
    return bare.length >= 12 && _oneCase.hasMatch(bare) && _hasDigit.hasMatch(bare) && _hasLetter.hasMatch(bare);
  }

  static int _kind(int unit) => unit >= 0x41 && unit <= 0x5A ? 0 : (unit >= 0x61 && unit <= 0x7A ? 1 : 2);

  /// Base64 has slashes: a run of pieces joined by them is a token when most of its characters, or most of its pieces,
  /// are random, and a path of words (`qistas/features/customers`) is not. Pieces under 4 characters say nothing.
  static bool _slashedIsToken(String run) {
    var random = 0;
    var words = 0;
    var randomPieces = 0;
    var wordPieces = 0;

    for (final piece in run.split('/')) {
      if (piece.length < 4) continue;
      if (_looksRandom(piece)) {
        random += piece.length;
        randomPieces++;
      } else {
        words += piece.length;
        wordPieces++;
      }
    }

    return random > words || randomPieces > wordPieces;
  }

  // ---------------------------------------------------------------- numbers

  /// In this order: a card before the phones (half a card must not read as a number), the digit runs last.
  static final List<(RegExp, String)> _numbers = [
    (RegExp(r'(?<![\d-])(?:\d{4}(?:[ .-]{1,2}\d{4}){3}(?:[ .-]{1,2}\d{1,3})?|\d{4}[ .-]{1,2}\d{6}[ .-]{1,2}\d{4,5})(?!\d|-\d)', unicode: true), '[id]'),
    (RegExp(r'(?<!\d)784-?\d{4}-?\d{7}-?\d(?!\d)', unicode: true), '[id]'),
    (RegExp(r'(?<![\w+.])(?:\+|00)\d{1,3}(?:[ .()/-]*\d){6,12}(?!\d)', unicode: true), '[phone]'),
    (RegExp(r'(?<![\d.+-])(?:966|971|974|965|973|968)(?:[ -]\d{1,2}[ -]\d{3}[ -]\d{4}|[ -]\d{4}[ -]\d{4}|[ -]\d{8,9})(?!\d)', unicode: true), '[phone]'),
    (RegExp(r'(?<![\d+.-])0\d(?:[ ./-]?\d){8,9}(?!\d)', unicode: true), '[phone]'),
    // A Gulf mobile with no zero and no plus: 9 digits, or 8, with or without a space. Not a size or a time.
    (RegExp(r'(?<![\d.-])(?:5\d[ -]?\d{3}[ -]?\d{4}|[35679]\d{3}[ -]?\d{4})(?!\d|\.\d)(?!\s?(?:bytes?|ms|seconds?|KB|MB|GB)\b)', unicode: true), '[phone]'),
    (RegExp(r'(?<![\d.-])(?:\(\d{3}\)\s?|\d{3}[ .-])\d{3}[ .-]\d{4}(?!\d|-\d)', unicode: true), '[phone]'),
    (RegExp(r'(?<![\d.-])\d{3,5}(?:[ -]\d{3,7}){2,4}(?!\d|-\d)', unicode: true), '[id]'),
    // 9 or more digits, except a memory size in bytes.
    (RegExp(r'(?<![\d.])(?!\d{1,10}\s?bytes?\b)\d{9,}(?!\d)', unicode: true), '[id]'),
  ];

  /// What Dart prints between double quotes is a value (a string's own text, an argument); one that never closes goes to the end of its line.
  static final RegExp _quoted = RegExp(r'"(?:[^"\n\\]|\\.)*(?:"|$)', multiLine: true, unicode: true);
}
