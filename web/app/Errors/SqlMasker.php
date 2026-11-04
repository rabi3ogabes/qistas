<?php

declare(strict_types=1);

namespace App\Errors;

use RuntimeException;

/**
 * Keeps the shape of every SQL statement in a text and loses every value in it. Laravel puts the bindings of a failed
 * query into the exception message without quotes (`... where "name" = Fahad Alotaibi and ...`), so a value cannot be told
 * from the statement around it by its quotes. The masker works the other way round: it knows where a value starts, and
 * everything from there is value.
 *
 * - A value starts after a comparison operator, after `like` / `ilike` / `between` / `then` / `else`, and inside the
 *   brackets of `values (...)` and `in (...)`.
 * - A value runs to the next comma, the bracket that closes it, a semicolon or the end of the statement, and to nothing
 *   else: a word that is a keyword, a dot, a line break, an arrow, `<`, `>` are all part of it. A keyword inside a value
 *   never changes where the statement is. The one thing that ends a value early is a quoted name after the words that close
 *   a condition (`... = Fahad and "city" = ...`): the words `and`, `or`, `order by`, `from` and the like at the end of the
 *   value are kept.
 * - A placeholder (`?`, `$1`) is a complete value on its own.
 * - A semicolon ends a value, and what follows starts a statement over: its bare names are believed only when it begins with
 *   a verb we know (select, insert, update, delete, with). The same goes for a statement that starts with anything else after
 *   `SQL: `.
 * - Outside a value, a quoted name stays (`"customers"`, `"c"."id"`), and so does a bare word in the list of columns or
 *   tables (after `select`, `from`, `into`, `order by`); every other bare word, number and string becomes `?`.
 *
 * Whatever is left of a value is one `?`. Masking a masked statement changes nothing.
 */
final class SqlMasker
{
    /** Where a statement starts: a known verb, or anything at all after the `SQL: ` that Laravel writes before it. */
    private const START = '~(?<![A-Za-z_])(?:select\s+(?:\*|\?|distinct\b|case\b|(?:count|sum|min|max|avg|exists|coalesce|lower|upper)\s*\(|["`]|[a-z_][\w.]*\s*(?:,|\bfrom\b))|insert\s+into\s+["`\w]|update\s+["`]?[\w.]+["`]?\s+set\b|delete\s+from\s+["`\w]|with\s+(?:recursive\s+)?["`]?\w+["`]?\s+as\s*\()|(?-i:(?<=SQL: ))(?=\S)~iu';

    /** One piece of a statement. Every character of a text belongs to exactly one piece; a backslash escapes in a string. */
    private const TOKEN = '~\G(?:(?<ws>\s+)|(?<str>[ENen]?\'(?:[^\'\\\\]++|\\\\[\s\S]|\'\')*+(?:\'|$))|(?<bt>`[^`]*+`?)|(?<dq>"(?:[^"\\\\]++|\\\\[\s\S]|"")*+(?:"|$))|(?<ph>\?|\$\d+)|(?<open>\()|(?<close>\))|(?<comma>,)|(?<op>[=<>!]+)|(?<semi>;)|(?<run>[^\s,()=<>!;\'"`?]+))~u';

    /** The verbs a statement we understand starts with; a bare name is believed only after one of them. */
    private const VERBS = ['select', 'insert', 'update', 'delete', 'with'];

    private const TYPES = ['ws', 'str', 'bt', 'dq', 'ph', 'open', 'close', 'comma', 'op', 'semi', 'run'];

    private const KEYWORDS = [
        'select', 'from', 'where', 'and', 'or', 'not', 'in', 'is', 'null', 'like', 'ilike', 'between', 'exists', 'insert',
        'into', 'values', 'update', 'set', 'delete', 'join', 'left', 'right', 'inner', 'outer', 'cross', 'full', 'natural',
        'on', 'as', 'order', 'by', 'group', 'having', 'limit', 'offset', 'asc', 'desc', 'distinct', 'union', 'all',
        'returning', 'case', 'when', 'then', 'else', 'end', 'with', 'recursive', 'over', 'partition', 'using', 'true',
        'false', 'default', 'for', 'filter', 'nulls', 'first', 'last', 'fetch', 'interval', 'cast', 'conflict', 'do',
        'nothing', 'excluded', 'escape', 'similar', 'to', 'any', 'some', 'lateral', 'only', 'if', 'regexp', 'rlike',
        'drop', 'table', 'alter', 'create', 'add', 'column', 'truncate', 'index', 'begin', 'commit', 'rollback', 'unique',
        'primary', 'key', 'references', 'constraint', 'view', 'cascade',
    ];

    private const FUNCTIONS = [
        'count', 'sum', 'min', 'max', 'avg', 'lower', 'upper', 'coalesce', 'now', 'date', 'extract', 'concat', 'length',
        'substring', 'trim', 'abs', 'round', 'floor', 'ceil', 'nullif', 'greatest', 'least', 'row_number', 'rank',
        'dense_rank', 'json_extract', 'date_trunc', 'to_char', 'unnest', 'array_agg', 'string_agg', 'replace',
    ];

    /** What a keyword says about the words after it: where in a statement we are. */
    private const CLAUSES = [
        'select' => 'select', 'returning' => 'select', 'from' => 'from', 'join' => 'from', 'update' => 'from', 'table' => 'from',
        'into' => 'into', 'where' => 'where', 'on' => 'where', 'having' => 'where', 'set' => 'set', 'values' => 'values',
        'order' => 'order', 'group' => 'order', 'limit' => 'other', 'offset' => 'other', 'union' => 'other', 'with' => 'other',
        'drop' => 'other', 'alter' => 'other', 'create' => 'other', 'add' => 'other',
    ];

    /** After these a value starts (a comparison operator does too). */
    private const BEFORE_VALUE = ['like', 'ilike', 'between', 'regexp', 'rlike', 'then', 'else'];

    /** The words that end a condition: kept when a value is followed by a quoted name. */
    private const CLOSING = [
        'and', 'or', 'not', 'by', 'order', 'group', 'where', 'on', 'having', 'union', 'all', 'returning', 'from', 'end',
        'join', 'left', 'right', 'inner', 'outer', 'cross',
    ];

    /**
     * True when the text has a statement in it (so a double quote after it is a name, not a string). A text the pattern
     * cannot read is not one: this only decides how a quote is read, mask() is what keeps values out.
     */
    public static function looksLikeStatement(string $text): bool
    {
        return preg_match(self::START, $text) === 1;
    }

    /**
     * The text with every statement in it masked. A statement that starts at the beginning of the text runs to the end of
     * it; one that starts later ends at the line, or at the bracket that closes the "(Connection: x, SQL: ...)" around it.
     *
     * @throws RuntimeException when a pattern cannot read the text (it is never let through)
     */
    public static function mask(string $text): string
    {
        $out = '';
        $offset = 0;
        $blank = strspn($text, " \t\n\r\0\x0B");

        while (true) {
            $found = [];
            $matched = preg_match(self::START, $text, $found, PREG_OFFSET_CAPTURE, $offset);

            if ($matched === false) {
                throw new RuntimeException('The SQL masker could not read a text: '.preg_last_error_msg());
            }

            if ($matched === 0) {
                break;
            }

            $start = (int) $found[0][1];
            [$statement, $length] = self::statement(substr($text, $start), $start <= $blank);

            if ($length < 1) {
                [$statement, $length] = [substr($text, $start, 1), 1];
            }

            $out .= substr($text, $offset, $start - $offset).$statement;
            $offset = $start + $length;
        }

        return $out.substr($text, $offset);
    }

    /**
     * @return array{0: string, 1: int} the statement with its values masked, and how many bytes of $sql it covered
     */
    private static function statement(string $sql, bool $toEnd): array
    {
        $length = strlen($sql);
        $tokens = [];
        $pos = 0;
        $out = '';
        $covered = 0;
        $depth = 0;
        $outer = [];
        $clause = 'other';
        $inList = false;
        $pending = false;
        $valuesDepth = -1;
        $known = preg_match('~^\s*(?:'.implode('|', self::VERBS).')\b~i', $sql) === 1;
        $i = 0;

        while (self::fetch($sql, $length, $pos, $tokens, $i)) {
            [$type, $text] = $tokens[$i];

            if ($pending) {
                if ($type === 'ws') {
                    $out .= $text;
                    $covered += strlen($text);
                    $i++;

                    continue;
                }

                $pending = false;

                // The thing after the operator is a value, unless there is none (`values ()`), it is a placeholder, or it is
                // a column of another table (`"b"."a_id"`).
                if (! in_array($type, ['close', 'comma', 'semi', 'ph'], true) && ! self::isColumn($sql, $length, $pos, $tokens, $i)) {
                    [$masked, $next, $bytes, $closing] = self::value($sql, $length, $pos, $tokens, $i);

                    foreach ($closing as $word) {
                        $clause = self::CLAUSES[$word] ?? $clause;
                    }

                    $out .= $masked;
                    $covered += $bytes;
                    $i = $next;

                    continue;
                }
            }

            switch ($type) {
                case 'ws':
                    if (! $toEnd && $depth === 0 && ($newline = strpos($text, "\n")) !== false) {
                        $out .= substr($text, 0, $newline);
                        $covered += $newline;

                        break 2;
                    }

                    $out .= $text;

                    break;
                case 'close':
                    if ($depth === 0) {
                        break 2;
                    }

                    $depth--;
                    [$clause, $inList] = array_pop($outer) ?? [$clause, $inList];
                    $out .= $text;

                    break;
                case 'open':
                    $before = strtolower(self::neighbour($tokens, $i, -1)[1]);
                    $after = strtolower(self::after($sql, $length, $pos, $tokens, $i)[1]);
                    // The brackets after `values`, and the ones after a comma that follow them (`values (...), (...)`), are lists of
                    // values wherever in the statement they are; so are the ones after `in`, unless a query is inside.
                    $list = $before === 'values'
                        || ($before === ',' && $clause === 'values' && $depth === $valuesDepth)
                        || (in_array($before, ['in', 'any', 'all', 'some'], true) && ! in_array($after, ['select', 'with', 'values'], true));
                    $outer[] = [$clause, $inList];
                    $depth++;
                    $inList = $list;
                    $pending = $list;
                    $out .= $text;

                    break;
                case 'comma':
                    $pending = $inList;
                    $out .= $text;

                    break;
                case 'op':
                    $pending = true;
                    $out .= $text;

                    break;
                case 'semi':
                    // What follows is another statement: it starts over, and its names are believed only if it starts
                    // with a verb we know.
                    $clause = 'other';
                    $depth = 0;
                    $outer = [];
                    $inList = false;
                    $known = in_array(strtolower(self::after($sql, $length, $pos, $tokens, $i)[1]), self::VERBS, true);
                    $out .= $text;

                    break;
                case 'str':
                    $out .= '?';

                    break;
                case 'bt':
                case 'dq':
                    $out .= self::isName($text) ? $text : '?';

                    break;
                case 'run':
                    $after = self::after($sql, $length, $pos, $tokens, $i);
                    // A list of columns has a `from` after it; only look for one when it would decide something.
                    $columns = $known && $clause === 'select' && $after[0] === 'comma'
                        && self::fromAhead($sql, $length, $pos, $tokens, $i);
                    $piece = self::word($text, $clause, $pending, $after, $depth, $known, $columns);

                    if ($piece === $text && strtolower($text) === 'values') {
                        $valuesDepth = $depth;
                    }

                    $out .= $piece;

                    break;
                default:
                    $out .= $text;
            }

            $covered += strlen($text);
            $i++;
        }

        $masked = preg_replace('~\?(?:\s+\?)+~', '?', $out);

        if ($masked === null) {
            throw new RuntimeException('The SQL masker could not read a statement: '.preg_last_error_msg());
        }

        return [$masked, $covered];
    }

    /**
     * A value, from the token at $from to where it ends. The value becomes one `?`; the words that close a condition at its
     * end (`and`, `order by`) stay, and so does the space after it.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array{0: string, 1: int, 2: int, 3: list<string>} the masked value, the index of the token that ended it, the
     *                                                           bytes it covered, and the clause words it ended with
     */
    private static function value(string $sql, int $length, int &$pos, array &$tokens, int $from): array
    {
        $nest = 0;
        $at = $from;
        $endedByName = false;

        while (self::fetch($sql, $length, $pos, $tokens, $at)) {
            [$type, $text] = $tokens[$at];

            if ($nest === 0) {
                if ($type === 'close' || $type === 'comma' || $type === 'semi') {
                    break;
                }

                if (($type === 'dq' || $type === 'bt') && self::isName($text) && self::closing($tokens, $from, $at) < $at) {
                    $endedByName = true;

                    break;
                }
            }

            if ($type === 'open') {
                $nest++;
            } elseif ($type === 'close') {
                $nest--;
            }

            $at++;
        }

        $kept = $endedByName ? self::closing($tokens, $from, $at) : $at;

        while ($kept > $from && $tokens[$kept - 1][0] === 'ws') {
            $kept--;
        }

        $rest = '';
        $closing = [];
        $bytes = 0;

        for ($i = $from; $i < $at; $i++) {
            $bytes += strlen($tokens[$i][1]);

            if ($i >= $kept) {
                $rest .= $tokens[$i][1];

                if ($tokens[$i][0] === 'run') {
                    $closing[] = strtolower($tokens[$i][1]);
                }
            }
        }

        return ['?'.$rest, $at, $bytes, $closing];
    }

    /**
     * Where the words that close a condition start, at the end of the tokens from $from up to (not including) $end; $end
     * itself when there are none.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function closing(array $tokens, int $from, int $end): int
    {
        $start = $end;

        for ($i = $end - 1; $i >= $from; $i--) {
            [$type, $text] = $tokens[$i];

            if ($type === 'ws') {
                continue;
            }

            if ($type === 'run' && preg_match('~^(?:[a-z]+|[A-Z]+)$~', $text) === 1 && in_array(strtolower($text), self::CLOSING, true)) {
                $start = $i;

                continue;
            }

            break;
        }

        return $start;
    }

    /**
     * What a word outside a value becomes: a keyword, a function and a bare name in a list of columns or tables stay,
     * anything else is `?`. A keyword says where we are in the statement. A bare name is only believed in a statement that
     * starts with a verb we know, and in a list of columns only when `from` follows it or it is plainly a column (lower
     * case, with a `from` still to come).
     *
     * @param  array{0: string, 1: string}  $after  the next piece that is not space
     * @param  bool  $columns  the word is followed by a comma, and a `from` comes after the list
     */
    private static function word(string $word, string &$clause, bool &$pending, array $after, int $depth, bool $known, bool $columns): string
    {
        // Punctuation, a cast (`::text`), and a value that was masked by an earlier pass (`[secret]`, or `:[secret]`).
        if (preg_match('~^[*+/%|:.-]+$~', $word) === 1 || preg_match('~^(?:::[A-Za-z_]+(?:\[\])?)+$~', $word) === 1
            || preg_match('~^[:*+/%|.-]*\[(?:secret|token|email|phone|id|ip|host|path)\][:*+/%|.-]*$~', $word) === 1) {
            return $word;
        }

        // Keywords are written in one case (lower as Laravel writes them, or UPPER); "Max" or "Order" is a value.
        if (preg_match('~^(?:[a-z_]+|[A-Z_]+)$~', $word) === 1) {
            $lower = strtolower($word);

            if (in_array($lower, self::KEYWORDS, true)) {
                $clause = self::CLAUSES[$lower] ?? $clause;
                $pending = in_array($lower, self::BEFORE_VALUE, true);

                return $word;
            }

            if (in_array($lower, self::FUNCTIONS, true) && $after[0] === 'open') {
                return $word;
            }
        }

        if ($known && preg_match('~^[A-Za-z_][\w$]*(?:\.(?:[A-Za-z_][\w$]*|\*))*$~', $word) === 1) {
            $next = strtolower($after[1]);

            $keep = match ($clause) {
                'select' => ($after[0] === 'run' && ($next === 'from' || $next === 'as' || str_starts_with($next, '.')))
                    || ($after[0] === 'close' && $depth > 0)
                    || ($columns && preg_match('~^[a-z_][a-z0-9_.]*$~', $word) === 1),
                'from', 'into', 'order' => true,
                default => false,
            };

            if ($keep) {
                return $word;
            }
        }

        return '?';
    }

    /**
     * Is there a `from` among the next pieces of the statement (a list of columns is followed by one)?
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function fromAhead(string $sql, int $length, int &$pos, array &$tokens, int $at): bool
    {
        for ($i = $at + 1; $i <= $at + 200 && self::fetch($sql, $length, $pos, $tokens, $i); $i++) {
            if ($tokens[$i][0] === 'run' && strtolower($tokens[$i][1]) === 'from') {
                return true;
            }
        }

        return false;
    }

    private static function isName(string $quoted): bool
    {
        return preg_match('~^(?:"[A-Za-z_][\w$.]*"|`[A-Za-z_][\w$.]*`)$~', $quoted) === 1;
    }

    /**
     * Is the token at $at a quoted name that continues with a dot (`"b"."a_id"`)? That is a column, not a value.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function isColumn(string $sql, int $length, int &$pos, array &$tokens, int $at): bool
    {
        [$type, $text] = $tokens[$at];

        if (($type !== 'dq' && $type !== 'bt') || ! self::isName($text)) {
            return false;
        }

        $after = self::after($sql, $length, $pos, $tokens, $at);

        return $after[0] === 'run' && str_starts_with($after[1], '.');
    }

    /**
     * The next piece after the one at $at that is not space; ['end', ''] at the end of the statement.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array{0: string, 1: string}
     */
    private static function after(string $sql, int $length, int &$pos, array &$tokens, int $at): array
    {
        for ($i = $at + 1; self::fetch($sql, $length, $pos, $tokens, $i); $i++) {
            if ($tokens[$i][0] !== 'ws') {
                return $tokens[$i];
            }
        }

        return ['end', ''];
    }

    /**
     * The nearest piece that is not space before ($step -1) a token that has already been read.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array{0: string, 1: string}
     */
    private static function neighbour(array $tokens, int $at, int $step): array
    {
        for ($i = $at + $step; isset($tokens[$i]); $i += $step) {
            if ($tokens[$i][0] !== 'ws') {
                return $tokens[$i];
            }
        }

        return ['end', ''];
    }

    /**
     * Reads the statement piece by piece, only as far as it is asked for, so a text of many statements is read once.
     * False when there is no piece at $index (the statement is over).
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function fetch(string $sql, int $length, int &$pos, array &$tokens, int $index): bool
    {
        while (! isset($tokens[$index])) {
            if ($pos >= $length) {
                return false;
            }

            $found = [];
            $matched = preg_match(self::TOKEN, $sql, $found, PREG_UNMATCHED_AS_NULL, $pos);

            if ($matched === false) {
                throw new RuntimeException('The SQL masker could not read a statement: '.preg_last_error_msg());
            }

            if ($matched === 0) {
                // Every character belongs to a piece, so this cannot happen; if it ever does, one character is a word.
                $tokens[] = ['run', $sql[$pos]];
                $pos++;

                continue;
            }

            $type = 'run';

            foreach (self::TYPES as $name) {
                if (($found[$name] ?? null) !== null) {
                    $type = $name;

                    break;
                }
            }

            $piece = (string) $found[0];
            $tokens[] = [$type, $piece];
            $pos += strlen($piece);
        }

        return true;
    }
}
