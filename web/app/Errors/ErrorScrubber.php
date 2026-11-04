<?php

declare(strict_types=1);

namespace App\Errors;

use App\Support\Digits;
use RuntimeException;

/**
 * The privacy gate of the error center (design section 6). Every string an error record keeps (message, trace, route,
 * class) goes through here first, so nothing that could name a person, a business or a secret is ever written down.
 *
 * What it does to a text, in this order:
 *   1. repairs invalid UTF-8, drops control and invisible characters (they hide values and split numbers), turns
 *      Arabic-Indic, Persian and full-width digits into ASCII so one set of patterns covers every keyboard;
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

    private const REMOVED = '[removed]';

    /** Full-width digits; the Arabic-Indic and Persian ones are App\Support\Digits. */
    private const FULL_WIDTH_DIGITS = [
        '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4', '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
    ];

    /** Folders of a Laravel project: a path is cut to start at one of them. */
    private const PROJECT_FOLDERS = 'app|bootstrap|config|database|lang|public|resources|routes|storage|tests|vendor';

    /** The characters that end a path inside a sentence. */
    private const PATH_END = '\s"\'<>|*?(),;:\[\]{}';

    /**
     * name => [regular expression, replacement]. A replacement of @random is a decision made in code (see random()).
     * A pattern of null runs the method named like the entry (paths() and sql() need code, not just a regular
     * expression).
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
        'pg_row' => ['~\bFailing row contains \(.*?\)(?=\.?\s*(?:\(Connection:|$))~imu', 'Failing row contains (?)'],
        'db_value' => ['~\b(Duplicate entry|(?:Truncated )?incorrect \w+ value:) \'.*?\'(?= for )~iu', '$1 \'?\''],
        'pg_value' => ['~\b(invalid input (?:syntax|value) for [^:"\n]{1,60}|out of range|malformed \w+ literal): *"(?:[^"]++|"")*+"~iu', '$1: "?"'],
        'pg_near' => ['~\bat or near "[^"\n]*"~iu', 'at or near "?"'],
        'pg_login' => ['~\b(for user|role) "[^"\n]*"~iu', '$1 "[secret]"'],
        'pg_host' => ['~\b(connection to server at|host name|on host) "[^"\n]*"(?: \([^)\n]*\))?~iu', '$1 "[host]"'],

        // Credentials, by shape and by name.
        'authorization' => ['~\b((?:proxy-)?authorization)\s*:\s*(?:(?:bearer|basic|token|digest|negotiate)\s+)?[^\s,;)]+~iu', '$1: [secret]'],
        'cookie' => ['~\b((?:set-)?cookie)\s*:[^\n]*~iu', '$1: [secret]'],
        'bearer' => ['~\bBearer\s+(?!(?:token|auth\w*|scheme|header|type|realm)\b)[A-Za-z0-9._\~+/=|-]{4,}~iu', 'Bearer [token]'],
        'sanctum' => ['~(?<![\w|])\d+\|[A-Za-z0-9]{40,}(?![A-Za-z0-9])~u', '[token]'],
        'jwt' => ['~(?<![\w.-])(?:eyJ[\w-]{5,}\.[\w-]{5,}\.[\w-]*|[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,})(?![\w-])~u', '[token]'],
        'uuid' => ['~(?<![\w-])[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![\w-])~iu', '[id]'],
        'env_assignment' => ['~(?<![\w.-])([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)=("[^"\n]*"|\'[^\'\n]*\'|\[(?:secret|token|email|phone|id|ip|host|path)\]|[^\s,;&)\]}\'"]+)~u', '$1=[secret]'],
        'secret_value' => ['~(?<![\w.-])((?:[\w.-]*?)(?:password|passwd|pwd|passcode|secret|token|api[_-]?key|key|credentials?|session[_-]?id|signature|otp|cvv|cvc)[\w.-]*)(["\']?\s*(?:=>|[:=])\s*)("[^"\n]*"|\'[^\'\n]*\'|\[(?:secret|token|email|phone|id|ip|host|path)\]|[^\s,;&)\]}"\']+)~iu', '$1$2[secret]'],

        // People and accounts.
        'email' => ['~(?<![\p{L}\p{N}._%+-])[\p{L}\p{N}][\p{L}\p{N}._%+\'-]*@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.\p{L}{2,}~u', '[email]'],
        'ipv4' => ['~(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?!\d|\.\d)~u', '[ip]'],
        'iban' => ['~(?<![A-Za-z0-9])[A-Z]{2}\d{2}(?:[A-Z0-9]{11,30}|(?: [A-Z0-9]{4}){2,7}(?: [A-Z0-9]{1,4})?)(?![A-Za-z0-9])~u', '[id]'],

        // Long runs that are random on purpose: hashes and keys. A run needs a digit, a lower and an upper case letter and
        // must not be made of words (see random()), so a long class or constraint name is left alone. (A base64 run with a
        // slash is only taken when it is padded.)
        'hex_run' => ['~(?<![\p{L}\p{N}])(?=[0-9]*[a-f])[0-9a-f]{24,}(?![\p{L}\p{N}])~iu', '[token]'],
        'token_run' => ['~(?<![\w+=-])(?=[A-Za-z0-9+_-]*\d)(?=[A-Za-z0-9+_-]*[a-z])(?=[A-Za-z0-9+_-]*[A-Z])[A-Za-z0-9+_-]{24,}={0,2}(?![\w+-])~u', '@random'],
        'token_padded' => ['~(?<![\w+/=-])[A-Za-z0-9+/]{24,}={1,2}(?![\w=])~u', '[token]'],

        // Numbers. A card goes before the phones (half a card must not be read as a number), and the digit runs last.
        'card' => ['~(?<![\d-])(?:\d{4}(?:[ -]\d{4}){3}(?:[ -]\d{1,3})?|\d{4}[ -]\d{6}[ -]\d{5}|\d{4}[ -]\d{6}[ -]\d{4})(?!\d|-\d)~u', '[id]'],
        'uae_id' => ['~(?<!\d)784-?\d{4}-?\d{7}-?\d(?!\d)~u', '[id]'],
        'phone_international' => ['~(?<![\w+.])(?:\+|00)\d{1,3}(?:[ .()-]*\d){6,12}(?!\d)~u', '[phone]'],
        'phone_trunk' => ['~(?<![\d+.-])0\d(?:[ .-]?\d){8,9}(?!\d)~u', '[phone]'],
        'phone_grouped' => ['~(?<![\d.-])(?:\(\d{3}\)\s?|\d{3}[ .-])\d{3}[ .-]\d{4}(?!\d|-\d)|(?<![\d.-])[3567]\d{3}[ -]\d{4}(?!\d|\.\d)~u', '[phone]'],
        'id_grouped' => ['~(?<![\d.-])\d{3,5}(?:[ -]\d{3,7}){2,4}(?!\d|-\d)~u', '[id]'],
        'id_digits' => ['~(?<![\d.])\d{9,}(?!\d)(?!\s?(?:bytes?|ms|seconds?|KB|MB|GB)\b)~u', '[id]'],
    ];

    /** The statements a text can contain: the start of one, so the words after it are read as SQL. */
    private const SQL_START = '~\b(?:select\s+(?:\*|\?|distinct\b|(?:count|sum|min|max|avg|exists|coalesce|lower|upper)\s*\(|["`]|[a-z_][\w.]*\s*(?:,|\bfrom\b))|insert\s+into\s+["`\w]|update\s+["`]?[\w.]+["`]?\s+set\b|delete\s+from\s+["`\w]|with\s+(?:recursive\s+)?["`]?\w+["`]?\s+as\s*\()~iu';

    /** One piece of a statement. Every character of a text belongs to exactly one piece. */
    private const SQL_TOKEN = '~\G(?:(?<ws>\s+)|(?<str>[ENen]?\'(?:[^\']++|\'\')*+(?:\'|$))|(?<bt>`[^`]*+`?)|(?<dq>"(?:[^"]++|"")*+(?:"|$))|(?<ph>\?|\$\d+)|(?<open>\()|(?<close>\))|(?<comma>,)|(?<op>[=<>!]+)|(?<semi>;)|(?<run>[^\s,()=<>!;\'"`?]+))~u';

    private const SQL_KEYWORDS = [
        'select', 'from', 'where', 'and', 'or', 'not', 'in', 'is', 'null', 'like', 'ilike', 'between', 'exists', 'insert',
        'into', 'values', 'update', 'set', 'delete', 'join', 'left', 'right', 'inner', 'outer', 'cross', 'full', 'natural',
        'on', 'as', 'order', 'by', 'group', 'having', 'limit', 'offset', 'asc', 'desc', 'distinct', 'union', 'all',
        'returning', 'case', 'when', 'then', 'else', 'end', 'with', 'recursive', 'over', 'partition', 'using', 'true',
        'false', 'default', 'for', 'filter', 'nulls', 'fetch', 'interval', 'cast', 'conflict', 'do', 'nothing', 'excluded',
        'escape', 'similar', 'to', 'any', 'some', 'lateral', 'only', 'if',
    ];

    private const SQL_FUNCTIONS = [
        'count', 'sum', 'min', 'max', 'avg', 'lower', 'upper', 'coalesce', 'now', 'date', 'extract', 'concat', 'length',
        'substring', 'trim', 'abs', 'round', 'floor', 'ceil', 'nullif', 'greatest', 'least', 'row_number', 'rank',
        'dense_rank', 'json_extract', 'date_trunc', 'to_char', 'unnest', 'array_agg', 'string_agg', 'replace',
    ];

    /** What a keyword says about the words after it: where in a statement we are. */
    private const SQL_CLAUSES = [
        'select' => 'select', 'returning' => 'select', 'from' => 'from', 'join' => 'from', 'update' => 'from',
        'into' => 'into', 'where' => 'where', 'on' => 'where', 'having' => 'where', 'set' => 'set', 'values' => 'values',
        'order' => 'order', 'group' => 'order', 'limit' => 'other', 'offset' => 'other', 'union' => 'other', 'with' => 'other',
    ];

    /** Before these a word is a name (a column or a table), not a value. */
    private const SQL_AFTER_NAME = ['like', 'ilike', 'in', 'is', 'between', 'not', 'as'];

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
     */
    public static function route(?string $route): ?string
    {
        if ($route === null) {
            return null;
        }

        try {
            $route = self::prepare(rawurldecode($route), self::ROUTE_MAX, multiline: false);
            $route = self::rx('~\?(?![^{]*\})[\s\S]*$~u', '', $route);
            $route = self::rx('~#[\s\S]*$~u', '', $route);
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
     * longer value, and one such row would block the import of the spool for good). Numbers (the status), the user id
     * (not stored, only counted) and the time are left alone; the words that are a fixed list (source, kind, platform,
     * device) are not masked, only stripped of spaces and control characters and cut. Text is masked first and cut
     * second.
     */
    public static function event(ErrorEvent $event): ErrorEvent
    {
        return new ErrorEvent(
            source: self::word($event->source, self::NAME_MAX) ?? '',
            kind: self::word($event->kind, self::NAME_MAX) ?? '',
            class: self::line($event->class, self::CLASS_MAX) ?? '',
            message: self::text($event->message),
            trace: self::trace($event->trace),
            status: $event->status,
            method: self::line($event->method, self::METHOD_MAX),
            route: self::route($event->route),
            version: self::line($event->version, self::VERSION_MAX),
            platform: self::word($event->platform, self::PLATFORM_MAX) ?? '',
            device: self::word($event->device, self::DEVICE_MAX),
            userId: $event->userId,
            at: $event->at,
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

    private static function scrub(string $text, int $max, bool $multiline, bool $structured = true, bool $trace = false): string
    {
        try {
            $text = self::prepare($text, $max, $multiline);

            if ($trace) {
                // "#3 /path/Foo.php(12): Foo->bar('Ahmed', Array)": the arguments go, the frame stays.
                $text = self::rx('~^(#\d+ (?:\[internal function\]|[^\n]*?\(\d+\)): [^\s(]+)\(.*\)[ \t]*$~mu', '$1()', $text);
            }

            return self::mask($text, $structured);
        } catch (RuntimeException) {
            return self::REMOVED;
        }
    }

    /** Valid UTF-8, no control or invisible characters, ASCII digits, a bounded size. */
    private static function prepare(string $text, int $max, bool $multiline): string
    {
        $text = mb_scrub($text, 'UTF-8');

        // A guard against a text built to be slow: far more than any column holds, so the cut that follows masking is
        // the real one.
        $limit = max(4096, $max * 8);
        if (strlen($text) > $limit) {
            $text = mb_strcut($text, 0, $limit, 'UTF-8');
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
                $text = self::rx($pattern, $replacement === '@random' ? self::random(...) : $replacement, $text);
            } elseif ($structured) {
                $text = $name === 'paths' ? self::paths($text) : self::sql($text);
            }
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
     * A long run of letters and digits is a secret, unless it is made of words: `Stripe3DSecureChallengeHandler` is a
     * class name, `kQ9x2LmZp0Vb7TrYw3NcJ8aDh5` is a key. Words are capitalised or lower-case stretches of three or more
     * letters; at least 6 in 10 characters of a name are inside one, and in a random run it is about 2 in 10.
     *
     * @param  array<int, string>  $match
     */
    private static function random(array $match): string
    {
        $run = rtrim($match[0], '=');
        $inWords = preg_match_all('~[A-Z]?[a-z]{3,}|[A-Z]{2,}(?![a-z])~', $run, $words);

        return $inWords !== false && strlen(implode('', $words[0])) >= strlen($run) * 0.6 ? $match[0] : '[token]';
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
            || preg_match('~^(?=.*\d)(?=.*[A-Za-z])[A-Za-z0-9_-]{16,}$~', $segment) === 1;

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
            // A drive path may have spaces in a folder name (not in the last part): C:\Users\Rabii Souai\proj\...
            '~(?<![\w:/\\\\.-])/?[A-Za-z]:(?:[\\\\/][^\\\\/:*?"<>|\r\n\s]+(?: [^\\\\/:*?"<>|\r\n\s]+){0,2}(?=[\\\\/]))*[\\\\/][^'.$end.'\\\\/]*~u',
            '~(?<![\w\\\\])\\\\\\\\[\w.$-]+\\\\[^'.$end.']*~u',
            '~(?<![\w:/\\\\.@%\~-])/(?:var|home|usr|opt|srv|tmp|etc|root|mnt|Users|Volumes|private|workspace|www|nix|snap|proc|data|run)/[^'.$end.']*~u',
            '~(?<![\w:/\\\\.@%\~-])/(?:[\w.@+-]+/)+[\w.@+-]+\.(?:php|phtml|dart|so|ini|sqlite|ndjson|sh)(?![\w])~u',
            '~(?<![\w:/\\\\.])\~/[^'.$end.']*~u',
        ];

        foreach (self::rootPatterns() as $root) {
            array_unshift($patterns, $root);
        }

        foreach ($patterns as $pattern) {
            $text = self::rx($pattern, $cut, $text);
        }

        return $text;
    }

    /**
     * The project folder (and its real path, when that differs), as patterns that accept either kind of slash and any
     * case, followed by the rest of the path.
     *
     * @return list<string>
     */
    private static function rootPatterns(): array
    {
        $patterns = [];

        foreach (self::roots() as $root) {
            $parts = array_map(static fn (string $part): string => preg_quote($part, '~'), explode('/', trim($root, '/')));
            $lead = str_starts_with($root, '/') ? '[\\\\/]+' : '';
            $patterns[] = '~(?<![\w.-])'.$lead.implode('[\\\\/]+', $parts).'(?:[\\\\/][^'.self::PATH_END.']*)?~iu';
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

    private static function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('~^/(?=[A-Za-z]:)~', '', $path) ?? $path;

        foreach (self::roots() as $root) {
            if (strcasecmp($path, $root) === 0 || strcasecmp($path, $root.'/') === 0) {
                return '.';
            }

            if (strncasecmp($path, $root.'/', strlen($root) + 1) === 0) {
                return substr($path, strlen($root) + 1);
            }
        }

        $folders = self::PROJECT_FOLDERS;

        // Another machine's copy of the project: after its web folder, or from the first folder of a Laravel project.
        if (preg_match('~/web/((?:'.$folders.')/.+)$~i', $path, $found) === 1
            || preg_match('~(?:^|/)((?:'.$folders.')/.+)$~i', $path, $found) === 1) {
            return $found[1];
        }

        return '[path]';
    }

    // ---- SQL ---------------------------------------------------------------------------------------------------

    /**
     * Every SQL statement in the text keeps its structure and loses every value: identifiers in quotes, keywords,
     * functions and `?` stay; strings, numbers and any other word become `?`. Laravel puts the bindings into the
     * message of a QueryException without quotes, so a value cannot be told from its quotes: a word is kept only when
     * its place in the statement says it is a name.
     */
    private static function sql(string $text): string
    {
        $out = '';
        $offset = 0;

        while (preg_match(self::SQL_START, $text, $found, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = (int) $found[0][1];
            [$statement, $length] = self::statement(substr($text, $start), trim(substr($text, 0, $start)) === '');

            $out .= substr($text, $offset, $start - $offset).$statement;
            $offset = $start + max($length, 1);
        }

        return $out.substr($text, $offset);
    }

    /**
     * @param  bool  $toEnd  the whole text is the statement, so it runs over lines; otherwise it ends at the line
     *                       or at the bracket that closes the "(Connection: x, SQL: ...)" around it
     * @return array{0: string, 1: int} the statement with its values masked, and how many bytes of $sql it covered
     */
    private static function statement(string $sql, bool $toEnd): array
    {
        if (preg_match_all(self::SQL_TOKEN, $sql, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
            throw new RuntimeException('The scrubber could not read a statement: '.preg_last_error_msg());
        }

        $tokens = [];
        $covered = 0;
        $depth = 0;

        foreach ($matches as $match) {
            $type = 'run';
            foreach (['ws', 'str', 'bt', 'dq', 'ph', 'open', 'close', 'comma', 'op', 'semi', 'run'] as $name) {
                if (($match[$name] ?? null) !== null) {
                    $type = $name;
                    break;
                }
            }
            $piece = (string) $match[0];

            if ($type === 'close' && $depth === 0) {
                break;
            }
            if ($type === 'ws' && ! $toEnd && ($newline = strpos($piece, "\n")) !== false) {
                if ($newline > 0) {
                    $tokens[] = ['ws', substr($piece, 0, $newline)];
                    $covered += $newline;
                }
                break;
            }

            $depth += $type === 'open' ? 1 : ($type === 'close' ? -1 : 0);
            $tokens[] = [$type, $piece];
            $covered += strlen($piece);
        }

        $clause = 'other';
        $outer = [];
        $masked = '';

        foreach ($tokens as $at => [$type, $piece]) {
            // A bracket opens a clause of its own (a sub-select, a list of columns) and gives the outer one back.
            if ($type === 'open') {
                $outer[] = $clause;
            } elseif ($type === 'close') {
                $clause = array_pop($outer) ?? $clause;
            }

            $masked .= match ($type) {
                'str' => '?',
                'bt' => preg_match('~^`[A-Za-z_][\w$.]*`$~', $piece) === 1 && self::isName($tokens, $at, $clause) ? $piece : '?',
                'dq' => preg_match('~^"[A-Za-z_][\w$.]*"$~', $piece) === 1 && self::isName($tokens, $at, $clause) ? $piece : '?',
                'run' => self::sqlWord($piece, $tokens, $at, $clause),
                default => $piece,
            };
        }

        // A value that is several words ("Ahmed Ali") became several marks.
        return [self::rx('~\?(?:\s+\?)+~', '?', $masked), $covered];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function sqlWord(string $word, array $tokens, int $at, string &$clause): string
    {
        if (preg_match('~^[*+/%|:.-]+$~', $word) === 1 || preg_match('~^(?:::[A-Za-z_]+(?:\[\])?)+$~', $word) === 1) {
            return $word;
        }

        // Keywords are written in one case (lower as Laravel writes them, or UPPER); "Max" or "Order" is a value.
        if (preg_match('~^(?:[a-z_]+|[A-Z_]+)$~', $word) === 1) {
            $lower = strtolower($word);

            if (in_array($lower, self::SQL_KEYWORDS, true)) {
                $clause = self::SQL_CLAUSES[$lower] ?? $clause;

                return $word;
            }

            if (in_array($lower, self::SQL_FUNCTIONS, true) && self::neighbour($tokens, $at, 1)[0] === 'open') {
                return $word;
            }
        }

        if (preg_match('~^[A-Za-z_][\w$]*(?:\.(?:[A-Za-z_][\w$]*|\*))*$~', $word) === 1 && self::isName($tokens, $at, $clause)) {
            return $word;
        }

        return '?';
    }

    /**
     * Is the piece at $at a name (a table, a column, an alias) rather than a value? Only its place says so: in the
     * list of columns or tables, after `as`, next to a dot (`"a"."id"`), or on the left of a comparison.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function isName(array $tokens, int $at, string $clause): bool
    {
        [$beforeType, $before] = self::neighbour($tokens, $at, -1);
        [$nextType, $next] = self::neighbour($tokens, $at, 1);

        if (($beforeType === 'run' && str_ends_with($before, '.')) || ($nextType === 'run' && str_starts_with($next, '.'))) {
            return true;
        }

        // What follows a comparison is what it is compared with: a value.
        if ($beforeType === 'op') {
            return false;
        }

        // The argument of a function (`lower("name")`, `count("id")`), but not of `values (...)` or `in (...)`.
        if ($beforeType === 'open' && ($open = self::reach($tokens, $at, -1)) !== null) {
            [$callType, $call] = self::neighbour($tokens, $open, -1);

            if ($callType === 'run' && in_array(strtolower($call), self::SQL_FUNCTIONS, true)) {
                return true;
            }
        }

        return in_array($clause, ['select', 'from', 'into', 'order'], true)
            || strtolower($before) === 'as'
            || $nextType === 'op'
            || ($nextType === 'run' && in_array(strtolower($next), self::SQL_AFTER_NAME, true));
    }

    /**
     * The nearest piece that is not whitespace, before ($step -1) or after (1) a token.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array{0: string, 1: string}
     */
    private static function neighbour(array $tokens, int $at, int $step): array
    {
        $found = self::reach($tokens, $at, $step);

        return $found === null ? ['end', ''] : $tokens[$found];
    }

    /**
     * Where the nearest piece that is not whitespace is, before ($step -1) or after (1) a token.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function reach(array $tokens, int $at, int $step): ?int
    {
        for ($i = $at + $step; isset($tokens[$i]); $i += $step) {
            if ($tokens[$i][0] !== 'ws') {
                return $i;
            }
        }

        return null;
    }
}
