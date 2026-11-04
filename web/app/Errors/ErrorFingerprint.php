<?php

declare(strict_types=1);

namespace App\Errors;

use Symfony\Component\HttpFoundation\Response;

/**
 * How failures are grouped (design section 4). Two sightings are ONE issue when they have the same fingerprint:
 * `sha1(source | kind | class | normalised message | top frame)`. The class is pure and has no database in it.
 *
 * The recorder scrubs an event first and fingerprints the scrubbed one, so the text it reads normally carries the
 * scrubber's stable masks ([id] [phone] [email] [token] [secret] [ip] [host] [path] and `?` for SQL values), which are left
 * as they are. A raw uuid, digit run or hex string that reaches here unscrubbed is still folded, so it can never split an
 * issue.
 *
 * What goes into the fingerprint is what a person would call "the same bug" and nothing that changes between sightings:
 * not the version, the time, the person, the device, the method, the status of an exception, or a line number (a deploy
 * must not split an issue). Too loose and two bugs share an issue; too tight and one bug becomes a hundred.
 */
final class ErrorFingerprint
{
    /** The normalised message, in characters. */
    public const MESSAGE_CUT = 200;

    /** A slow query keeps more of its statement: the first 200 characters of many queries are the same list of columns. */
    public const SQL_CUT = 300;

    /** The top frame, in characters. It is only hashed, but one line of a trace can be as long as the trace. */
    private const FRAME_MAX = 200;

    /** More than any message that was scrubbed holds; a guard for a text that was not, so the patterns stay quick. */
    private const INPUT_MAX = 8192;

    /** Where in a text a statement starts, so a double quote after it is read as a name and not as a string. */
    private const SQL_START = '~\b(?:select\s+(?:\*|distinct\b|["`\w.]+\s*(?:,|\bfrom\b|\())|insert\s+into\s+["`\w]|update\s+["`]?[\w.]+["`]?\s+set\b|delete\s+from\s+["`\w]|with\s+["`]?\w+["`]?\s+as\s*\()~iu';

    /** The words of the error pages the site shows and the statuses the API answers. */
    private const STATUS_TEXT = [
        403 => 'Forbidden',
        404 => 'Not Found',
        419 => 'Page Expired',
        429 => 'Too Many Requests',
        500 => 'Server Error',
        503 => 'Service Unavailable',
    ];

    /**
     * The fingerprint of an event that has been through ErrorScrubber::event(). For an error page the class is
     * `http.<status>` and the message is the route pattern; for a slow query the message is the statement, cut at 300.
     */
    public static function of(ErrorEvent $scrubbed): string
    {
        if ($scrubbed->kind === 'http') {
            $class = 'http.'.($scrubbed->status ?? 0);
            $message = self::routePattern($scrubbed->route ?? $scrubbed->message);
        } else {
            $class = self::className($scrubbed->class);
            $message = self::normalise($scrubbed->message, $scrubbed->kind === 'slow_query' ? self::SQL_CUT : self::MESSAGE_CUT);
        }

        return sha1(implode('|', [
            $scrubbed->source,
            $scrubbed->kind,
            $class,
            $message,
            self::topFrame($scrubbed->trace, $scrubbed->platform),
        ]));
    }

    /**
     * What the owner reads at the top of an issue, in plain words: the class and the normalised message
     * (`QueryException: No customer <n> for <id>`), or the status and the route pattern of an error page
     * (`404 Not Found: /app/customers/{id}`). One line, at most 200 characters; the trace is never part of it.
     */
    public static function title(ErrorEvent $scrubbed): string
    {
        if ($scrubbed->kind === 'http') {
            $status = $scrubbed->status;
            $words = $status === null ? 'HTTP error' : $status.' '.(self::STATUS_TEXT[$status] ?? Response::$statusTexts[$status] ?? 'Error');
            $title = $words.': '.self::routePattern($scrubbed->route ?? $scrubbed->message);
        } else {
            $name = self::shortClass($scrubbed->class);
            $message = self::normalise($scrubbed->message);

            $title = match (true) {
                $name !== '' && $message !== '' => $name.': '.$message,
                $name !== '' => $name,
                $message !== '' => $message,
                default => ucfirst(str_replace('_', ' ', $scrubbed->kind)),
            };
        }

        return self::cut($title, ErrorScrubber::TITLE_MAX);
    }

    /**
     * A message with what changes between sightings folded away, so the same failure always reads the same: a uuid
     * becomes `<id>`, a run of 16 or more hex characters `<hex>`, a run of digits `<n>` (the code in `SQLSTATE[23505]`
     * is kept: it says what kind of database failure it was), a quoted string `?`, a list of the same mark one mark
     * (`in (?, ?, ?)` is `in (?)`), whitespace is collapsed, and the result is cut at $max characters.
     *
     * In a statement a double-quoted word is a table or a column, not a string, and stays: two queries on two tables
     * must stay two issues.
     */
    public static function normalise(string $message, int $max = self::MESSAGE_CUT): string
    {
        $text = mb_strcut(mb_scrub($message, 'UTF-8'), 0, self::INPUT_MAX, 'UTF-8');
        $isStatement = preg_match(self::SQL_START, $text) === 1;

        // A quote opens after something that is not a letter and closes before something that is not a letter, so the
        // apostrophe in "can't" or "user's" is only an apostrophe. A quote written twice is one inside a string.
        $text = self::rx("~(?<![\\p{L}\\p{N}])'(?:[^'\\n]|''|'(?=[\\p{L}\\p{N}]))*'(?![\\p{L}\\p{N}])~u", '?', $text);
        $text = preg_replace_callback(
            '~"(?:[^"\n]|"")*"~u',
            static fn (array $found): string => $isStatement && preg_match('~^"[A-Za-z_][\w$.]*"$~', $found[0]) === 1 ? $found[0] : '?',
            $text,
        ) ?? $text;

        $text = self::rx('~(?<![0-9a-f])[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![0-9a-f])~iu', '<id>', $text);
        $text = self::rx('~(?<![\p{L}\p{N}_])(?:0x)?(?=[0-9]*[a-f])[0-9a-f]{16,}(?![\p{L}\p{N}_])~iu', '<hex>', $text);
        $text = preg_replace_callback(
            '~(SQLSTATE\[[0-9A-Z]{5}\])|\d+~u',
            static fn (array $found): string => ($found[1] ?? '') !== '' ? $found[1] : '<n>',
            $text,
        ) ?? $text;

        $text = trim(self::rx('~\s+~u', ' ', $text));
        $text = self::rx('~(\?|<n>|<id>|<hex>)(?:\s*,\s*\1)+~u', '$1', $text);

        return self::cut($text, $max);
    }

    /**
     * The first frame of our own code, as `file::function` with no line number (so a deploy does not split an issue), or
     * `-` when there is none. The trace is the scrubbed one: PHP frames are `#0 app/Foo.php(12): Foo->bar()`, a script
     * error is a browser stack, a phone crash is a Dart stack. Which one is read depends on the platform.
     *
     * - server (PHP): the first frame whose file is under `app/`, with the function of that frame.
     * - web (JavaScript): the first frame in a script, with the file name without the hash a build puts in it
     *   (`app-Bx9a2c.js` is `app.js`) and the function.
     * - android, ios (Dart): the first frame in `package:qistas/`, as the path under it and the function.
     */
    public static function topFrame(string $trace, string $platform): string
    {
        $trace = mb_strcut(mb_scrub($trace, 'UTF-8'), 0, ErrorScrubber::TRACE_MAX, 'UTF-8');

        $frame = match ($platform) {
            'server' => self::phpFrame($trace),
            'web' => self::scriptFrame($trace),
            'android', 'ios' => self::dartFrame($trace),
            default => null,
        };

        return $frame === null || $frame === '' ? '-' : self::cut($frame, self::FRAME_MAX);
    }

    /**
     * The pattern of a path that no route matched, for an error page and for the scanner check: no query string, no
     * fragment, an id (a uuid, a ulid, a hash, a long token) becomes `{id}`, a number `{n}`, and a leading slash is
     * always there. A parameter such as `{customer}` is kept as it is. Cut to the width of the route column.
     */
    public static function routePattern(string $path): string
    {
        $path = '/'.ltrim(mb_scrub($path, 'UTF-8'), '/');
        $path = self::rx('~\?(?![^{]*\})[\s\S]*$~u', '', $path);
        $path = self::rx('~#[\s\S]*$~u', '', $path);

        $path = implode('/', array_map(
            static fn (string $segment): string => preg_match('~^\d+$~uD', $segment) === 1 ? '{n}' : $segment,
            explode('/', $path),
        ));

        // The scrubber does the rest: ids, masks of personal data, the width of the column.
        $pattern = ErrorScrubber::route($path) ?? '/';

        return str_starts_with($pattern, '/') ? $pattern : '/'.$pattern;
    }

    /**
     * Is this the path of a request only a scanner makes (`/.env`, `/wp-login.php`, `/x.sql`)? The patterns are the
     * config list `qistas.errors.scanner_paths`, written against a path that starts with a slash and has no query
     * string; `Request::path()` has neither, so both are put right here. A pattern that does not compile is skipped.
     */
    public static function isScannerPath(string $path): bool
    {
        $patterns = config('qistas.errors.scanner_paths');

        if (! is_array($patterns)) {
            return false;
        }

        $path = (string) preg_replace('~[?#].*$~s', '', $path);
        $path = '/'.ltrim(rawurldecode($path), '/');

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    // ---- frames ------------------------------------------------------------------------------------------------

    /** `#1 app/Actions/Pay.php(48): App\Actions\Pay->handle()` is `app/Actions/Pay.php::App\Actions\Pay->handle`. */
    private static function phpFrame(string $trace): ?string
    {
        // PHP 8.4 names a closure with the line it is on ({closure:App\Foo::bar():12}), and one inside another inside
        // it: fold them from the inside out, or a deploy that moves a line splits the issue.
        do {
            $before = $trace;
            $trace = self::rx('~\{closure:(?:[^{}]|\{closure\})*\}~u', '{closure}', $trace);
        } while ($trace !== $before);

        foreach (self::lines($trace) as $line) {
            if (preg_match('~^#\d+\s+(app/[^\s(]+?\.php)(?:\(\d+\))?:\s+([^\s(]+)\(~u', $line, $found) === 1) {
                return $found[1].'::'.$found[2];
            }
        }

        return null;
    }

    /** V8 says `at fn (url:1:2)` or `at url:1:2`; Firefox and Safari say `fn@url:1:2`. */
    private static function scriptFrame(string $trace): ?string
    {
        foreach (self::lines($trace) as $line) {
            if (preg_match('~^at\s+(?<function>.+?)\s+\((?<url>[^()]+)\)$~u', $line, $found) === 1) {
                $function = $found['function'];
                $url = $found['url'];
            } elseif (preg_match('~^at\s+(?<url>\S+)$~u', $line, $found) === 1) {
                $function = '';
                $url = $found['url'];
            } elseif (preg_match('~^(?<function>[^@]*)@(?<url>\S+)$~u', $line, $found) === 1) {
                $function = $found['function'];
                $url = $found['url'];
            } else {
                continue;
            }

            // The line and column come last, a query string may sit before them.
            $file = self::rx('~(?::\d+){1,2}$~', '', $url);
            $file = self::rx('~[?#].*$~u', '', $file);
            $file = self::rx('~^.*/~u', '', $file);

            if (preg_match('~\.(?:m?js|cjs|jsx|tsx?)$~i', $file) !== 1) {
                continue;
            }

            return self::unhashed($file).'::'.self::scriptFunction($function);
        }

        return null;
    }

    /** `#0      Foo.bar (package:qistas/core/foo.dart:12:3)` is `core/foo.dart::Foo.bar`. */
    private static function dartFrame(string $trace): ?string
    {
        foreach (self::lines($trace) as $line) {
            if (preg_match('~^#\d+\s+(?:new\s+)?(\S.*?)\s+\(package:qistas/([^\s:)]+)(?::\d+){0,2}\)$~u', $line, $found) === 1) {
                return $found[2].'::'.$found[1];
            }
        }

        return null;
    }

    /** @return list<string> the lines of a trace, trimmed, the empty ones left out */
    private static function lines(string $trace): array
    {
        $lines = preg_split('~\R~u', $trace);

        return $lines === false ? [] : array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    }

    /**
     * A build puts a hash in the name of a file (`app-Bx9a2c.js`, `app.3f2a9c1b.js`). A word before the extension is not a
     * hash (`error-report.js`): a hash has a digit in it, or both kinds of letters.
     */
    private static function unhashed(string $file): string
    {
        return self::rxCallback('~^(.+)[-.]([A-Za-z0-9_-]{6,16})(\.[A-Za-z]+)$~u', static function (array $found): string {
            $hash = $found[2];
            $isHash = preg_match('~\d~', $hash) === 1 || (preg_match('~[a-z]~', $hash) === 1 && preg_match('~[A-Z]~', $hash) === 1);

            return $isHash ? $found[1].$found[3] : $found[0];
        }, $file);
    }

    /** `Object.render`, `async render`, `render/<` and Firefox's plain `render` are one function. */
    private static function scriptFunction(string $function): string
    {
        $function = self::rx('~^(?:async|new)\s+~u', '', trim($function));
        $function = self::rx('~(?:/<)+$~u', '', $function);
        $function = (string) preg_replace('~^.*\.~u', '', $function);

        return $function === '' || $function === '<anonymous>' ? '(anonymous)' : $function;
    }

    // ---- small things ------------------------------------------------------------------------------------------

    /** An anonymous class is named after the file and line it is on (`Foo@anonymous/app/X.php:12$0`): keep the first part. */
    private static function className(string $class): string
    {
        return self::rx('~@anonymous.*$~su', '@anonymous', trim($class));
    }

    private static function shortClass(string $class): string
    {
        $name = self::className($class);

        return self::rx('~\s+~u', ' ', (string) preg_replace('~^.*[\\\\/]~u', '', $name));
    }

    /** Cut to $max characters, never inside a character and never leaving the front half of a mask or a placeholder. */
    private static function cut(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(self::rx('~(?:\[[a-z]{0,7}|<[a-z]{0,3})$~u', '', mb_substr($text, 0, $max)));
    }

    /** preg_replace that hands the text back when a pattern fails (a fingerprint input never throws). */
    private static function rx(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }

    /** @param  callable(array<int|string, string>): string  $callback */
    private static function rxCallback(string $pattern, callable $callback, string $subject): string
    {
        return preg_replace_callback($pattern, $callback, $subject) ?? $subject;
    }
}
