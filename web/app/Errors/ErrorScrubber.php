<?php

declare(strict_types=1);

namespace App\Errors;

use App\Models\ErrorIssue;
use App\Support\Digits;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * The privacy gate of the error center (design section 6). Every string an error record keeps (message, trace, route,
 * class) goes through here first, so nothing that could name a person, a business or a secret is ever written down.
 *
 * What it does to a text, in this order:
 *   1. repairs invalid UTF-8, cuts a text built to be huge at a word, drops control and invisible characters (they hide
 *      values and split numbers), turns every Unicode space into a plain one and Arabic-Indic, Persian and full-width
 *      digits into ASCII, so one set of patterns covers every keyboard;
 *   2. runs PATTERNS in their fixed order. The order matters (an address loses its credentials before an e-mail pattern
 *      can see them; an IBAN is masked before the digit runs inside it are), so change it with a test;
 *   3. cuts to the length of the column, AFTER masking, so a cut can never leave half of an address behind.
 *
 * The masks are [email] [phone] [id] [token] [secret] [ip] [host] [path]. Anything that cannot be scrubbed (a pattern
 * that fails, for instance on a text built to make it run forever) is dropped whole as [removed]: it fails closed,
 * never open. Scrubbing a scrubbed text changes nothing.
 *
 * What it must NOT touch, or issues become unreadable and the fingerprint loses its signal: line numbers
 * (`Foo.php:12`), class names, `SQLSTATE[23505]`, an HTTP status, versions, dates, and SQL structure.
 */
final class ErrorScrubber
{
    public const MESSAGE_MAX = 500;

    public const TRACE_MAX = 8192;

    public const TITLE_MAX = 200;

    public const CLASS_MAX = 160;

    public const ROUTE_MAX = 160;

    public const VERSION_MAX = 16;

    public const METHOD_MAX = 8;

    public const PLATFORM_MAX = 8;

    public const DEVICE_MAX = 8;

    /** source and kind. */
    public const NAME_MAX = 16;

    /** The vocabulary of the platform and the device of an occurrence (the sources and the kinds are ErrorIssue's). */
    public const PLATFORMS = ['server', 'web', 'android', 'ios'];

    public const DEVICES = ['desktop', 'mobile', 'tablet'];

    /** What a segment of a route becomes when it holds what no plain path does (markup, a quote, a space ...): see route(). */
    public const UNPLAIN = '{x}';

    /** How many times the percent escapes of a route are read: once more than any route of ours needs. */
    private const DECODE_ROUNDS = 3;

    private const REMOVED = '[removed]';

    /** Full-width digits; the Arabic-Indic and Persian ones are App\Support\Digits. */
    private const FULL_WIDTH_DIGITS = [
        '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4', '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
    ];

    /** Folders of a Laravel project: a path is cut to start at one of them. */
    private const PROJECT_FOLDERS = 'app|bootstrap|config|database|lang|public|resources|routes|storage|tests|vendor';

    /** The characters that end a path inside a sentence. */
    private const PATH_END = '\s"\'<>|*?(),;:\[\]{}';

    /** What the patterns put in place of a value (without the brackets), and so what a value must not be masked again as. */
    private const MASK_WORDS = '(?:secret|token|email|phone|id|ip|host|path)';

    private const MASK = '\['.self::MASK_WORDS.'\]';

    /**
     * The key names the secret_value pattern reacts to (secret() decides what each one is). Only these are matched, so an
     * ordinary word before a colon never swallows what comes after it: a secret that follows `and:` is still found.
     */
    private const KEYS = '(?:[\w.-]*?(?:password|passwd|pwd|passphrase|passcode|secret|token|credential|api[_-]?key|private[_-]?key|session|cookie|remember|authorization|key|signature)[\w.-]*'
        .'|(?:[\w.-]*?[_.-])?(?:pass|pw|pin|sid|otp|cvv|cvc|auth|sig)(?![a-z0-9])[\w.-]*'
        .'|(?:(?:customer|user|client|full|first|last|display|company)[_.-]?)?(?:name|address|phone|mobile|email)(?:[_.-]?(?:number|address|line1|line2))?(?![\w.-])'
        .'|(?:national[_-]?id|id[_-]?number|iban|account[_-]?number|card[_-]?number|username|birth[_-]?date|dob)(?![\w.-]))';

    /**
     * name => [regular expression, replacement]. A replacement of @random or @secret is a decision made in code (see
     * random() and secret()). A pattern of null runs the method named like the entry (paths() and sql() need code, not
     * just a regular expression).
     */
    private const PATTERNS = [
        // An address keeps its host and path, and loses what rides on it: credentials, query, fragment.
        'url_user_info' => ['~\b([a-z][a-z0-9+.-]*://)[^\s/?#"\'<>]*@~iu', '$1'],
        'url_query' => ['~\b([a-z][a-z0-9+.-]*://[^\s?#"\'<>]*)[?#][^\s"\'<>)\],]*~iu', '$1'],
        'query_string' => ['~(?<=[\w/}\]])\?[\w%.\[\]-]+=[^\s"\'<>)\],&]*(?:&[\w%.\[\]-]+=[^\s"\'<>)\],&]*)*~u', ''],

        // Where it ran: the part under the project, never the server's folders.
        'paths' => [null, ''],

        // The database says what it was doing and to what; the values are not ours to keep.
        'connection' => ['~(\(Connection: [\w.-]+)(?:, (?:Host|Socket|Port|Database): .*?)?(?=, SQL: )~u', '$1'],
        'sql' => [null, ''],
        'pg_key' => ['~\bKey \(([^)\n]*)\)=\(.*?\)(?=\s*(?:already exists|is not present|is still referenced|conflicts with)|\.?\s*$)~imu', 'Key ($1)=(?)'],
        'pg_key_short' => ['~\bKey \(([^)\n]*)\)=\([^)\n]*\)~u', 'Key ($1)=(?)'],
        'pg_row' => ['~\bFailing row contains \(.*?\)(?=\.(?!\w)|\s*\(Connection:|\s*$)~imu', 'Failing row contains (?)'],
        'db_value' => ['~\b(Duplicate entry|(?:Truncated )?incorrect \w+ value:) \'.*?\'(?= for )~iu', '$1 \'?\''],
        'pg_value' => ['~\b(invalid input (?:syntax|value) for [^:"\n]{1,60}|out of range|malformed \w+ literal): *"(?:[^"]++|"")*+"~iu', '$1: "?"'],
        'pg_near' => ['~\bat or near "[^"\n]*"~iu', 'at or near "?"'],
        'pg_login' => ['~\b(for user|role) "[^"\n]*"~iu', '$1 "[secret]"'],
        'pg_host' => ['~\b(connection to server at|host name|on host) "[^"\n]*"(?: \([^)\n]*\))?~iu', '$1 "[host]"'],

        // Credentials, by shape and by name.
        'authorization' => ['~\b((?:proxy-)?authorization)\s*:\s*(?:(?:bearer|basic|token|digest|negotiate)\s+)?[^\s,;)]+~iu', '$1: [secret]'],
        'cookie' => ['~\b((?:set-)?cookie)\s*:[^\n]*~iu', '$1: [secret]'],
        'bearer' => ['~\bBearer\s+(?:(?:token|auth\w*|scheme|header|type|realm)\s+)?(?:[A-Za-z0-9._\~+/=|-]{16,}|(?!(?:token|auth\w*|scheme|header|type|realm)\b)[A-Za-z0-9._\~+/=|-]{4,})~iu', 'Bearer [token]'],
        'sanctum' => ['~(?<![\w|])\d+\|[A-Za-z0-9]{40,}(?![A-Za-z0-9])~u', '[token]'],
        'jwt' => ['~(?<![\w.-])(?:eyJ[\w-]{5,}\.[\w-]{5,}\.[\w-]*|[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,})(?![\w-])~u', '[token]'],
        // Anything that is base64 of a JSON document (Laravel's encrypted cookies and payloads all start `eyJ`).
        'json_b64' => ['~(?<![\w+/-])eyJ[A-Za-z0-9+/_-]{20,}(?:%[0-9A-Fa-f]{2}[A-Za-z0-9+/_-]*)*={0,2}~u', '[token]'],
        'uuid' => ['~(?<![\w-])[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![\w-])~iu', '[id]'],
        'env_assignment' => ['~(?<![\w.-])([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)=("[^"\n]*"|\'[^\'\n]*\'|(?:'.self::MASK.'|[^\s,;&)\]}\'"?]|(?<!\])\?(?=[^\s,;&)\]}\'"]))+)~u', '$1=[secret]'],
        // A key that names a secret (or personal data) and the value after it, in every shape code and logs write one:
        // password=x, "password":"x", 'password' => 'x', [password] => x, ["password"]=> string(1) "x", {"cookie":["a=b"]}.
        'secret_value' => [
            '~(?<![\w.-])('.self::KEYS.')(["\']?)(\]\s*=>\s*|\s*(?:=>|[:=])\s*)(?:string\(\d+\)\s*|(\[)(?!'.self::MASK_WORDS.'\])\s*)?'
            .'("(?:[^"\\\\\n]|\\\\.)*+"|\'(?:[^\'\\\\\n]|\\\\.)*+\'|(?![?{])(?:'.self::MASK.'|[^\s,;&)\]}"\'?]|(?<!\])\?(?=[^\s,;&)\]}"\']))+)~iu',
            '@secret',
        ],

        // People and accounts. An address may start with any character an address can start with, be written with %40,
        // quoted, on localhost, or on a punycode domain.
        'email' => ['~(?<![\p{L}\p{N}])(?:"[^"\n]{1,64}"|[\p{L}\p{N}._%+\'\~-]{1,64})(?:@|%40|＠|&#0*64;|&commat;)(?:\[[0-9a-f:.]{2,45}\]|localhost\b|(?:[\p{L}\p{N}](?:[\p{L}\p{N}_-]*[\p{L}\p{N}])?\.)+(?:xn--[a-z0-9-]{2,}|\p{L}{2,}))~iu', '[email]'],
        'ipv4' => ['~(?<![\d.])(?:::ffff:)?(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?!\d|\.\d)~iu', '[ip]'],
        'ipv6' => ['~(?<![\w:])(?=[0-9a-f:]*\d)(?:(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}|(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?)(?![\w:])~iu', '[ip]'],
        'iban' => ['~(?<![A-Za-z0-9])[A-Za-z]{2}\d{2}(?:(?=(?:[A-Za-z]*\d){4})[A-Za-z0-9]{11,30}|(?:[ -](?:[A-Z0-9]{4}|(?=[a-z0-9]*\d)[a-z0-9]{4})){2,7}(?:[ -](?=[A-Za-z0-9]*\d)[A-Za-z0-9]{1,4})?)(?![A-Za-z0-9])~u', '[id]'],

        // Long runs that are random on purpose: hashes and keys. A run is a token unless it reads like a name (see
        // random()), so a long class or constraint name is left alone.
        'hex_run' => ['~(?<![\p{L}\p{N}])(?=[0-9]*[a-f])[0-9a-f]{24,}(?![\p{L}\p{N}])~iu', '[token]'],
        // Standard base64 has slashes: a run of pieces joined by slashes, all of them random (a path of words is not).
        'token_slash' => ['~(?<![\w+/-])((?=[A-Za-z0-9+]*/)[A-Za-z0-9+/]{24,})(={0,2})(?![\w+/-])~u', '@slashed'],
        'token_run' => ['~(?<![\w+-]|%[0-9A-Fa-f]{2})((?:[A-Za-z0-9+_-]|%[0-9A-Fa-f]{2}){24,})(={0,2})(?![\w+-])~u', '@random'],
        'token_padded' => ['~(?<![\w+/-])[A-Za-z0-9+/]{24,}={1,2}(?![\w=])~u', '[token]'],

        // Numbers. A card goes before the phones (half a card must not be read as a number), and the digit runs last.
        'card' => ['~(?<![\d-])(?:\d{4}(?:[ .-]{1,2}\d{4}){3}(?:[ .-]{1,2}\d{1,3})?|\d{4}[ .-]{1,2}\d{6}[ .-]{1,2}\d{4,5})(?!\d|-\d)~u', '[id]'],
        'uae_id' => ['~(?<!\d)784-?\d{4}-?\d{7}-?\d(?!\d)~u', '[id]'],
        'phone_international' => ['~(?<![\w+.])(?:\+|00)\d{1,3}(?:[ .()/-]*\d){6,12}(?!\d)~u', '[phone]'],
        'phone_country' => ['~(?<![\d.+-])(?:966|971|974|965|973|968)(?:[ -]\d{1,2}[ -]\d{3}[ -]\d{4}|[ -]\d{4}[ -]\d{4}|[ -]\d{8,9})(?!\d)~u', '[phone]'],
        'phone_trunk' => ['~(?<![\d+.-])0\d(?:[ ./-]?\d){8,9}(?!\d)~u', '[phone]'],
        // A Gulf mobile with no zero and no plus: 9 digits (Saudi, Emirati) or 8 (Qatari, Kuwaiti, Bahraini, Omani), with or
        // without a space. Not when it is a size or a time.
        'phone_gulf' => ['~(?<![\d.-])(?:5\d[ -]?\d{3}[ -]?\d{4}|[35679]\d{3}[ -]?\d{4})(?!\d|\.\d)(?!\s?(?:bytes?|ms|seconds?|KB|MB|GB)\b)~u', '[phone]'],
        'phone_grouped' => ['~(?<![\d.-])(?:\(\d{3}\)\s?|\d{3}[ .-])\d{3}[ .-]\d{4}(?!\d|-\d)~u', '[phone]'],
        'id_grouped' => ['~(?<![\d.-])\d{3,5}(?:[ -]\d{3,7}){2,4}(?!\d|-\d)~u', '[id]'],
        // 9 or more digits, except a memory size in bytes (up to 10 digits).
        'id_digits' => ['~(?<![\d.])(?!\d{1,10}\s?bytes?\b)\d{9,}(?!\d)~u', '[id]'],
    ];

    /** A message or other free text, cleaned and cut to $max characters. Keeps the lines of a multi-line message. */
    public static function text(string $text, int $max = self::MESSAGE_MAX): string
    {
        return self::fit(self::scrub($text, $max, multiline: true), $max);
    }

    /**
     * A stack trace, cleaned and cut to $max bytes (on a character boundary). Besides what text() does, every frame
     * loses its arguments (they can hold anything that was passed to a function) and every file path is cut to the part
     * under the project root, with the project's own folder tried with both kinds of slash.
     */
    public static function trace(string $trace, int $max = self::TRACE_MAX): string
    {
        return self::fitBytes(self::scrub($trace, $max, multiline: true, trace: true), $max);
    }

    /**
     * The pattern of a route, never the address that was asked for: no query string, no fragment, and every segment that
     * looks like an id (a number, a uuid, a ulid, a hash, a token) replaced with {id}. A parameter such as {customer}
     * or {customer?} is kept as it is.
     *
     * Every route passes here, whoever wrote it (a browser's report, the path of the server's own 404, a matched route's
     * pattern), so this is the one place that decides what a route may hold. Its percent escapes are read first, again and
     * again as long as that changes it (at most DECODE_ROUNDS times: `%253C` is `%3C` is `<`), so what is checked is what a
     * page would show. A segment that then holds what no plain path does becomes UNPLAIN (`{x}`): markup, a quote, a
     * backtick, a space, a backslash, a control or invisible character, a brace that is not a lone `{name}`, bytes that are
     * not UTF-8, or escapes still left after the last round. The route keeps its shape (a broken link next to it is still
     * one issue) and markup never reaches storage. Letters of any script survive (`/ar/%D8%A3...` is `/ar/أسعار`).
     */
    public static function route(?string $route): ?string
    {
        if ($route === null) {
            return null;
        }

        try {
            $route = self::prepare(self::plainSegments($route), self::ROUTE_MAX, multiline: false);
            $route = implode('/', array_map(self::segment(...), explode('/', $route)));
            $route = self::mask($route, structured: false);
        } catch (RuntimeException) {
            $route = self::REMOVED;
        }

        $route = self::fit($route, self::ROUTE_MAX);

        return $route === '' ? null : $route;
    }

    /**
     * The same event with every string cleaned and every field cut to the width of its column (PostgreSQL refuses a
     * longer value, and one such row would block the import of the spool for good). Numbers (the status, which must be
     * 100 to 599 or is null) and the user id (not stored, only counted) are left alone. The words that are a fixed list
     * (source, kind, platform, device) are not masked but put into their vocabulary: an unknown source is `site`, an
     * unknown kind `exception`, an unknown platform `server`, an unknown device none. The time is written as ISO 8601
     * (now when it cannot be read). Text is masked first and cut second.
     */
    public static function event(ErrorEvent $event): ErrorEvent
    {
        $source = strtolower(self::word($event->source, self::NAME_MAX) ?? '');
        $kind = strtolower(self::word($event->kind, self::NAME_MAX) ?? '');
        $platform = strtolower(self::word($event->platform, self::PLATFORM_MAX) ?? '');
        $device = strtolower(self::word($event->device, self::DEVICE_MAX) ?? '');

        return new ErrorEvent(
            source: in_array($source, ErrorIssue::SOURCES, true) ? $source : 'site',
            kind: in_array($kind, ErrorIssue::KINDS, true) ? $kind : 'exception',
            class: self::line($event->class, self::CLASS_MAX) ?? '',
            message: self::text($event->message),
            trace: self::trace($event->trace),
            status: $event->status !== null && $event->status >= 100 && $event->status <= 599 ? $event->status : null,
            method: self::line($event->method, self::METHOD_MAX),
            route: self::route($event->route),
            version: self::line($event->version, self::VERSION_MAX),
            platform: in_array($platform, self::PLATFORMS, true) ? $platform : 'server',
            device: in_array($device, self::DEVICES, true) ? $device : null,
            userId: $event->userId,
            at: self::moment($event->at),
        );
    }

    /** A one-line field: cleaned, whitespace folded, cut. Null when nothing is left. */
    private static function line(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }

        $clean = self::fit(self::scrub($text, $max, multiline: false), $max);

        return $clean === '' ? null : $clean;
    }

    /**
     * A field that is one of a fixed list of words (source, kind, platform, device): not masked, but with no space or
     * control character (a NUL byte is refused by PostgreSQL) and cut. Null when nothing is left.
     */
    private static function word(?string $text, int $max): ?string
    {
        $word = $text === null ? '' : self::fit((string) preg_replace('~[\p{C}\s]+~u', '', mb_scrub($text, 'UTF-8')), $max);

        return $word === '' ? null : $word;
    }

    /** A time as ISO 8601 with its offset; now when it cannot be read. */
    private static function moment(string $at): string
    {
        if (strlen($at) <= 64) {
            try {
                return CarbonImmutable::parse($at)->toIso8601String();
            } catch (Throwable) {
                // not a time: it is now
            }
        }

        return now()->toIso8601String();
    }

    private static function scrub(string $text, int $max, bool $multiline, bool $structured = true, bool $trace = false): string
    {
        try {
            $text = self::prepare($text, $max, $multiline);

            if ($trace) {
                // "#3 /path/Foo.php(12): Foo->bar('Ahmed', Array)": the arguments go, the frame stays.
                // An argument can hold a line break, so the arguments run to the next frame, not to the end of the line.
                $text = self::rx('~^(#\d+ (?:\[internal function\]|[^\n]*?\(\d+\)): [^\s(]+)\([\s\S]*?(?=\n#\d+ |\z)~mu', '$1()', $text);
            }

            return self::mask($text, $structured);
        } catch (RuntimeException) {
            return self::REMOVED;
        }
    }

    /** Valid UTF-8, no control or invisible characters, plain spaces, ASCII digits, a bounded size. */
    private static function prepare(string $text, int $max, bool $multiline): string
    {
        $text = mb_scrub($text, 'UTF-8');

        // A guard against a text built to be slow: far more than any column holds, so the cut that follows masking is
        // the real one. The cut can land inside a value that masking would have shortened, so what is left of the last
        // word goes too.
        $limit = max(4096, $max * 8);
        $cut = strlen($text) > $limit;
        if ($cut) {
            $text = mb_strcut($text, 0, $limit, 'UTF-8');
        }

        // Every kind of space between the groups of a number is a plain space; a line or paragraph separator is a line.
        $text = self::rx('~\p{Zs}~u', ' ', $text);
        $text = self::rx('~[\p{Zl}\p{Zp}]~u', "\n", $text);

        if ($cut) {
            $space = false;
            foreach ([' ', "\n", "\t", "\r", "\v", "\f"] as $character) {
                $at = strrpos($text, $character);
                $space = $at !== false && ($space === false || $at > $space) ? $at : $space;
            }

            if ($space !== false) {
                $text = substr($text, 0, $space);
            }
        }

        $text = strtr(Digits::toAscii($text), self::FULL_WIDTH_DIGITS);
        $text = self::rx('~\r\n?~', "\n", $text);
        $text = self::rx('~[^\P{C}\n\t]+~u', '', $text);

        if (! $multiline) {
            $text = self::rx('~\s+~u', ' ', $text);
        }

        return trim($text);
    }

    private static function mask(string $text, bool $structured): string
    {
        foreach (self::PATTERNS as $name => [$pattern, $replacement]) {
            if ($pattern !== null) {
                $replace = match ($replacement) {
                    '@random' => self::random(...),
                    '@slashed' => self::slashed(...),
                    '@secret' => self::secret(...),
                    default => $replacement,
                };
                $text = self::rx($pattern, $replace, $text);

                continue;
            }

            if (! $structured) {
                continue;
            }

            $text = match ($name) {
                'paths' => self::paths($text),
                'sql' => SqlMasker::mask($text),
                default => throw new RuntimeException("The scrubber has no step called {$name}."),
            };
        }

        return $text;
    }

    /** @throws RuntimeException when the pattern failed (too much work, or a text it cannot read) */
    private static function rx(string $pattern, string|\Closure $replacement, string $subject): string
    {
        $result = $replacement instanceof \Closure
            ? preg_replace_callback($pattern, $replacement, $subject)
            : preg_replace($pattern, $replacement, $subject);

        if ($result === null) {
            throw new RuntimeException('The scrubber could not read a text: '.preg_last_error_msg());
        }

        return $result;
    }

    /**
     * The value after a key that names a secret or personal data (see the secret_value pattern). A key says what kind of
     * value follows: a secret (password, token, cookie, session, authorization ...) is masked wherever it is written; a
     * `key` or a `signature` only when it is assigned (`key=abc`, `"key":"abc"`) or the value looks like a token, so
     * "Cache key: tenant.features.v2" is left alone; a name, an address, a phone or an e-mail only when written the way
     * code writes a field (`"name":"x"`, `'name' => 'x'`, `name=x`), never in a sentence or in a statement.
     *
     * @param  array<int, string>  $found
     */
    private static function secret(array $found): string
    {
        $key = $found[1];
        $quote = $found[2];
        $separator = $found[3];
        $wrap = $found[4] ?? '';
        $value = $found[5] ?? '';
        $lower = strtolower($key);

        if (preg_match('~(?:password|passwd|pwd|passphrase|passcode|secret|token|credential|api[_-]?key|private[_-]?key|session|cookie|remember|authorization)~', $lower) === 1
            || preg_match('~(?<![a-z0-9])(?:pass|pw|pin|sid|otp|cvv|cvc|auth)(?![a-z0-9])~', $lower) === 1) {
            $mask = true;
        } elseif (preg_match('~(?<![a-z])key(?![a-z])|signature|(?<![a-z])sig(?![a-z])~', $lower) === 1) {
            $plain = trim($value, '"\'');
            $mask = str_contains($separator, '=') || $quote !== ''
                || (strlen($plain) >= 16 && preg_match('~^[A-Za-z0-9+/_=%-]+$~', $plain) === 1
                    && (preg_match('~\d~', $plain) === 1 || (preg_match('~[a-z]~', $plain) === 1 && preg_match('~[A-Z]~', $plain) === 1)));
        } elseif (preg_match('~^(?:(?:customer|user|client|full|first|last|display|company)[_.-]?)?(?:name|address|phone|mobile|email)(?:[_.-]?(?:number|address|line1|line2))?$~', $lower) === 1
            || preg_match('~^(?:national[_-]?id|id[_-]?number|iban|account[_-]?number|card[_-]?number|username|birth[_-]?date|dob)$~', $lower) === 1) {
            $mask = ($quote !== '' && str_contains($separator, ':'))
                || str_contains($separator, '=>')
                || (str_starts_with($separator, '=') && $separator !== '=>');
        } else {
            $mask = false;
        }

        if ($mask) {
            return $key.$quote.$separator.$wrap.'[secret]';
        }

        // Not a key we mask. Its value may hold a key that is ("Login failed: password=hunter2"), and the match has
        // consumed it, so the value is read again for keys of its own.
        $before = substr($found[0], 0, strlen($found[0]) - strlen($value));

        return $before.(strpbrk($value, '=:') === false ? $value : self::rx(self::PATTERNS['secret_value'][0], self::secret(...), $value));
    }

    /**
     * A long run of letters, digits and `+ / _ -` is a secret, unless it reads like a name. What a name looks like: its
     * parts (between the separators) are made of words, acronyms and short numbers, most of its letters are inside words
     * of four letters or more, and the case changes only where a word starts. A random run (an application key, a
     * base64 token, `Str::random`) fails that at once: measured over thousands of random runs of 24 to 48 characters,
     * under 1 in 1000 passes, and every class, constraint and path name we know does.
     *
     * @param  array<int, string>  $found
     */
    private static function random(array $found): string
    {
        return self::looksRandom($found[1]) ? '[token]' : $found[0];
    }

    /**
     * A run of base64 characters with slashes in it: a token when most of its characters, or most of its pieces, are random
     * (`kQ9x2LmZp0Vb7TrY/w3NcJ8aDh5sFgHjK`), a path when most are in pieces that are words (`app/customers/12345`,
     * `vendor/laravel/framework`). Pieces under 4 characters say nothing: a short piece of a random run is often a word
     * by chance. A token is masked whole, slashes and all.
     *
     * @param  array<int, string>  $found
     */
    private static function slashed(array $found): string
    {
        $random = 0;
        $words = 0;
        $randomPieces = 0;
        $wordPieces = 0;

        foreach (explode('/', $found[1]) as $piece) {
            if (strlen($piece) < 4) {
                continue;
            }

            if (self::looksRandom($piece, false)) {
                $random += strlen($piece);
                $randomPieces++;
            } else {
                $words += strlen($piece);
                $wordPieces++;
            }
        }

        return $random > $words || $randomPieces > $wordPieces ? '[token]' : $found[0];
    }

    /**
     * @param  bool  $whole  the run is a whole token candidate; a piece of a path (one word of it) is judged without the word count
     */
    private static function looksRandom(string $run, bool $whole = true): bool
    {
        // A run that is one letter again and again, or two, is a filler and not a secret; a key has many different characters.
        if (strlen($run) >= 12 && count(array_unique(str_split(strtolower($run)))) < 6) {
            return false;
        }

        // `+` is a character of standard base64, and of nothing that is a name.
        if (str_contains($run, '+')) {
            return true;
        }

        $parts = preg_split('~%[0-9A-Fa-f]{2}|[_-]~', $run, -1, PREG_SPLIT_NO_EMPTY);

        // Nothing but escapes (`%D8%A3...`: a name written in another script, in an address) is not a name either.
        if ($parts === false || $parts === []) {
            return true;
        }

        foreach ($parts as $part) {
            // One case, letters and digits: no word can be seen in it. Letters only: it must have the vowels of a word.
            if ((preg_match('~^[a-z0-9]+$~', $part) === 1 || preg_match('~^[A-Z0-9]+$~', $part) === 1) && strlen($part) >= 8) {
                if (preg_match('~\d~', $part) === 1 && preg_match('~[A-Za-z]~', $part) === 1) {
                    return true;
                }

                if (strlen($part) >= 12 && preg_match('~^[A-Za-z]+$~', $part) === 1 && ! self::hasVowels($part)) {
                    return true;
                }

                continue;
            }

            if (preg_match('~^(?:[a-z]{1,12}|[A-Z]{1,12}|\d{1,6}|[vV]\d{1,3})$~', $part) === 1) {
                continue;
            }

            if (preg_match('~^(?>[A-Z][a-z]{2,}|To|Of|Is|In|On|By|Or|An|As|At|If|No|Id|Ok|Up|Do|Be|Me|My|We|So|[a-z]{3,}|[A-Z]{2,5}v\d{1,2}|[A-Z]{2,5}(?![a-z])|[A-Z](?=[A-Z][a-z]|\d)|\d{1,3}(?:[a-z]{2,3}(?![a-z]))?)+$~', $part) !== 1) {
                return true;
            }
        }

        $bare = implode('', $parts);
        $length = strlen($bare);

        if ($length < 2) {
            return false;
        }

        // How much of it is in words: a part of letters in one case counts whole (`CERTIFICATE`, `foreign`), any other part
        // by the words of four letters or more that it holds.
        $inWords = 0;
        $long = 0;

        foreach ($parts as $part) {
            if (preg_match('~^(?:[a-z]+|[A-Z]+)$~', $part) === 1 && (strlen($part) < 12 || self::hasVowels($part))) {
                $inWords += strlen($part);
                $long += strlen($part) >= 5 ? 1 : 0;
            } elseif (preg_match_all('~(?:Https?|Html|Json|Smtp|Csrf|Cors|Pdf|Csv|Xml|Sql|Pdo|Php|Url|Uri|Sms|Fcm|Jwt)(?![a-z])|[A-Z](?=[a-z]*[aeiouy])[a-z]{3,}|(?=[a-z]*[aeiouy])[a-z]{4,}|[A-Z]{3,4}(?=v\d|(?![A-Za-z]))~', $part, $words) !== false) {
                // a word has a vowel in it
                $inWords += strlen(implode('', $words[0]));
                $long += (int) preg_match_all('~[A-Z](?=[a-z]*[aeiouy])[a-z]{4,}|(?=[a-z]*[aeiouy])[a-z]{5,}~', $part);
            }
        }

        $class = static fn (string $character): int => ctype_upper($character) ? 0 : (ctype_lower($character) ? 1 : 2);
        $changes = 0;

        for ($i = 1; $i < $length; $i++) {
            $changes += $class($bare[$i]) !== $class($bare[$i - 1]) ? 1 : 0;
        }

        // A name this long has at least two words of five letters or more; a random run hardly ever does.
        return $changes / ($length - 1) > 0.42 || $inWords < $length * 0.75 || ($whole && $long < 2);
    }

    /** Does a run of letters have the vowels of a word: enough of them, and no five consonants in a row? */
    private static function hasVowels(string $letters): bool
    {
        $letters = strtolower($letters);

        return preg_match_all('~[aeiou]~', $letters) / strlen($letters) >= 0.30 && preg_match('~[^aeiou]{5,}~', $letters) !== 1;
    }

    /** Cut to $max characters, never inside a character, and never leaving the front half of a mask. */
    private static function fit(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return self::untorn(mb_substr($text, 0, $max));
    }

    private static function fitBytes(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        return self::untorn(mb_strcut($text, 0, $max, 'UTF-8'));
    }

    private static function untorn(string $cut): string
    {
        return rtrim(preg_replace('~\[[a-z]{0,7}$~', '', $cut) ?? $cut);
    }

    /**
     * The route read from its percent escapes, without its query string and fragment, with every segment that is not a
     * plain path segment replaced by UNPLAIN (see route()). Works on bytes until each segment has been checked for UTF-8,
     * so a text that is not UTF-8 never makes a pattern fail.
     */
    private static function plainSegments(string $route): string
    {
        // A guard against a text built to be slow (the lookahead below is quadratic on a hostile one), as in prepare().
        $route = mb_strcut($route, 0, max(4096, self::ROUTE_MAX * 8), 'UTF-8');
        $stable = false;

        for ($round = 0; $round < self::DECODE_ROUNDS && ! $stable; $round++) {
            $decoded = rawurldecode($route);
            $stable = $decoded === $route;
            $route = $decoded;
        }

        $stable = $stable || rawurldecode($route) === $route;

        // The ? of an optional parameter ({customer?}) is not a query string.
        $route = self::rx('~\?(?![^{]*\})[\s\S]*$~', '', $route);
        $route = self::rx('~#[\s\S]*$~', '', $route);

        $segments = array_map(static function (string $segment) use ($stable): string {
            if ($segment === '' || preg_match('~^\{[A-Za-z_][A-Za-z0-9_]*\??\}$~D', $segment) === 1) {
                return $segment;
            }

            $plain = mb_check_encoding($segment, 'UTF-8')
                && preg_match('~[<>"\'`\\\\{}\s\p{Z}\p{C}]~u', $segment) === 0
                && ($stable || preg_match('~%[0-9A-Fa-f]{2}~', $segment) === 0);

            return $plain ? $segment : self::UNPLAIN;
        }, explode('/', $route));

        return implode('/', $segments);
    }

    /** One segment of a route: an id becomes {id}. */
    private static function segment(string $segment): string
    {
        if ($segment === '' || preg_match('~^\{[^}]*\}$~u', $segment) === 1) {
            return $segment;
        }

        $isId = preg_match('~^\d+$~', $segment) === 1
            || preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $segment) === 1
            || preg_match('~^[0-7][0-9A-HJKMNP-TV-Z]{25}$~i', $segment) === 1
            || preg_match('~^(?=[0-9a-f]*\d)[0-9a-f]{8,}$~i', $segment) === 1
            || preg_match('~^(?=.*\d)(?=.*[A-Za-z])[A-Za-z0-9_-]{16,}$~', $segment) === 1
            // A push token: the id of the phone, a colon, the token.
            || preg_match('~^[A-Za-z0-9_-]{8,}:[A-Za-z0-9_-]{16,}$~', $segment) === 1;

        return $isId ? '{id}' : $segment;
    }

    // ---- paths -------------------------------------------------------------------------------------------------

    /** Every absolute path in the text becomes the part under the project root, or [path] when it is not ours. */
    private static function paths(string $text): string
    {
        $text = self::rx('~\bfile://~i', '', $text);
        $end = self::PATH_END;
        $cut = static fn (array $match): string => self::relative($match[0]);

        $patterns = [
            // Android's storage never holds our project: whatever is in it is a person's file.
            '~(?<![\w:/\\\\.@%\~-])/(?:storage/emulated|sdcard)(?:/[^'.$end.']*)?~u',
            // A drive path may have spaces in a folder name (not in the last part): C:\Users\Rabii Souai\proj\...
            '~(?<![\w:/\\\\.-])/?[A-Za-z]:(?:[\\\\/][^\\\\/:*?"<>|\r\n\s]+(?: [^\\\\/:*?"<>|\r\n\s]+){0,2}(?=[\\\\/]))*[\\\\/][^'.$end.'\\\\/]*~u',
            '~(?<![\w\\\\])\\\\\\\\[\w.$-]+\\\\[^'.$end.']*~u',
            '~(?<![\w:/\\\\.@%\~-])/(?:var|home|usr|opt|srv|tmp|etc|root|mnt|Users|Volumes|private|workspace|www|nix|snap|proc|data|run)/[^'.$end.']*~u',
            '~(?<![\w:/\\\\.@%\~-])/(?:[\w.@+-]+/)+[\w.@+-]+\.(?:php|phtml|dart|so|ini|sqlite|ndjson|sh)(?![\w])~u',
            '~(?<![\w:/\\\\.])\~/[^'.$end.']*~u',
        ];

        foreach (array_reverse(self::rootPatterns()) as $root) {
            array_splice($patterns, 1, 0, [$root]);
        }

        foreach ($patterns as $pattern) {
            $text = self::rx($pattern, $cut, $text);
        }

        return $text;
    }

    /**
     * The project folder (and its real path, when that differs), as patterns, followed by the rest of the path. A drive
     * path takes either kind of slash in any case; a Unix path only `/`: in production the root is `/app`, and a
     * backslash there is the namespace of `\App\Models\Customer`.
     *
     * @return list<string>
     */
    private static function rootPatterns(): array
    {
        $patterns = [];

        foreach (self::roots() as $root) {
            $drive = preg_match('~^[A-Za-z]:~', $root) === 1;
            $parts = array_map(static fn (string $part): string => preg_quote($part, '~'), explode('/', trim($root, '/')));

            $patterns[] = $drive
                ? '~(?<![\w.-])'.implode('[\\\\/]+', $parts).'(?:[\\\\/][^'.self::PATH_END.']*)?~iu'
                : '~(?<![\w.-])/'.implode('/', $parts).'(?:/[^'.self::PATH_END.']*)?~u';
        }

        return $patterns;
    }

    /**
     * The project root with forward slashes and no trailing one, as base_path() says it and as the file system does.
     *
     * @return list<string>
     */
    private static function roots(): array
    {
        static $known = [];

        $base = base_path();

        if (! isset($known[$base])) {
            $roots = [];

            foreach ([$base, realpath($base)] as $root) {
                $root = is_string($root) ? rtrim(str_replace('\\', '/', $root), '/') : '';

                if ($root !== '') {
                    $roots[] = $root;
                }
            }

            $known[$base] = array_values(array_unique($roots));
        }

        return $known[$base];
    }

    /**
     * What a path in a text becomes. Under the project root it is cut to the part after the root, but only when that part
     * starts in a project folder or ends in a file extension: `/app/customers/{id}` is a route on a server whose project is
     * at `/app`, and a bare `/app` is not a path at all, so those stay as they were. Another machine's copy of the project
     * is cut after its web folder or from its first Laravel folder; anything else is [path].
     */
    private static function relative(string $match): string
    {
        $path = str_replace('\\', '/', $match);
        $path = preg_replace('~^/(?=[A-Za-z]:)~', '', $path) ?? $path;

        if (preg_match('~^/(?:storage/emulated|sdcard)(?:/|$)~', $path) === 1) {
            return '[path]';
        }

        $folders = self::PROJECT_FOLDERS;

        foreach (self::roots() as $root) {
            if (strcasecmp($path, $root) === 0 || strcasecmp($path, $root.'/') === 0) {
                return $match;
            }

            if (strncasecmp($path, $root.'/', strlen($root) + 1) === 0) {
                $rest = substr($path, strlen($root) + 1);

                return preg_match('~^(?:'.$folders.')(?:/|$)~i', $rest) === 1 || preg_match('~\.[A-Za-z0-9]{1,8}$~', $rest) === 1
                    ? $rest
                    : $match;
            }
        }

        if (preg_match('~/web/((?:'.$folders.')/.+)$~i', $path, $found) === 1
            || preg_match('~(?:^|/)((?:'.$folders.')/.+)$~i', $path, $found) === 1) {
            return $found[1];
        }

        return '[path]';
    }
}
