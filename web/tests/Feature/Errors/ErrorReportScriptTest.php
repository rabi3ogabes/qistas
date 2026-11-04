<?php

use App\Errors\ErrorFingerprint;
use App\Models\ErrorIssue;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
| The website's reporter (resources/js/error-report.js, design section 9): the script that listens for the page's own
| errors and tells the error center about them. It is plain JavaScript in the site's bundle, so Pest cannot run a browser.
| Its pieces are pure functions and one function that takes the window it works on, so they are run through Node here,
| the way the payday rule is (tests/Feature/App/PayPatternWebTest.php), with a stand-in for the window; the tests skip,
| saying so, when Node is not installed.
|
| What is pinned: the page's route is sent as a pattern, with its ids and its query string already gone; only errors in the
| site's own scripts are reported (not a browser extension's, not another site's, not the opaque "Script error."); what is
| sent holds only the allowed fields; at most five reports leave a page view; nothing the reporter does can throw or report
| itself; and what it sends is what the endpoint takes (ClientErrorsTest holds the endpoint's own rules).
*/

function errorsScriptNodeIsInstalled(): bool
{
    try {
        $probe = new Process(['node', '--version']);
        $probe->run();

        return $probe->isSuccessful();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Runs $program, the body of an async function, in Node with the reporter imported as `reporter` and $input (read from
 * standard input as JSON) as `input`; returns what the program returned, as JSON read back.
 */
function errorsScriptRun(string $program, mixed $input = null): mixed
{
    $source = "import { pathToFileURL } from 'node:url';\n"
        ."const reporter = await import(pathToFileURL(process.argv[2]).href);\n"
        ."let text = '';\n"
        ."for await (const chunk of process.stdin) text += chunk;\n"
        ."const input = text === '' ? null : JSON.parse(text);\n"
        ."const output = await (async () => {\n{$program}\n})();\n"
        ."console.log(JSON.stringify(output === undefined ? null : output));\n";

    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qistas-script-'.bin2hex(random_bytes(6)).'.mjs';
    file_put_contents($file, $source);

    try {
        $run = new Process(['node', $file, base_path('resources/js/error-report.js')]);
        $run->setInput(json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $run->mustRun();
    } finally {
        unlink($file);
    }

    return json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/** The JavaScript every scenario shares: the page the script runs on, and a stand-in for its window. */
const ERRORS_SCRIPT_PAGE = <<<'JS'
    const ORIGIN = 'https://www.qistasme.com';
    const OWN = ORIGIN + '/build/assets/app-Bx9a2c1d.js';
    const v8 = (message, frames) => `TypeError: ${message}\n` + frames.map((f) => `    at ${f}`).join('\n');
    const ownError = (message, extra = {}) => ({
        message: `Uncaught TypeError: ${message}`,
        filename: OWN, lineno: 12, colno: 34,
        error: Object.assign(new TypeError(message), { stack: v8(message, [`pick (${OWN}:12:34)`, `HTMLSelectElement.<anonymous> (${OWN}:56:7)`]) }),
        ...extra,
    });
    const windowOf = (log, options = {}) => {
        const listeners = {};
        const win = {
            location: { origin: ORIGIN, pathname: '/app/customers/0b8a7c1e-6a0e-4d4f-9a52-3f1d1b2c4e5f', search: '?q=sara.ali@example.com', hash: '#top', href: ORIGIN + '/app/customers/0b8a7c1e?q=sara.ali@example.com' },
            navigator: { sendBeacon(url, blob) { log.push({ via: 'beacon', url, type: blob.type, blob }); return options.beacon ?? true; } },
            fetch(url, init) { log.push({ via: 'fetch', url, init }); return options.fetch ? options.fetch() : Promise.resolve({ ok: true }); },
            addEventListener(name, fn) { listeners[name] = fn; },
        };
        return { win, listeners };
    };
    const bodies = async (log) => Promise.all(log.map(async (entry) => (entry.via === 'beacon' ? JSON.parse(await entry.blob.text()) : JSON.parse(entry.init.body))));
    JS;

it('has Node to run it', function () {
    expect(file_exists(base_path('resources/js/error-report.js')))->toBeTrue();
})->skip(fn () => ! errorsScriptNodeIsInstalled(), 'Node is not installed here, so resources/js/error-report.js was not run.');

describe('the route pattern', function () {
    it('strips ids and the query string', function () {
        $cases = [
            ['/', '/'],
            ['', '/'],
            ['/pricing', '/pricing'],
            ['/ar/pricing', '/ar/pricing'],
            ['/installment-calculator', '/installment-calculator'],
            ['/app/customers/0b8a7c1e-6a0e-4d4f-9a52-3f1d1b2c4e5f', '/app/customers/{id}'],
            ['/app/customers/0B8A7C1E-6A0E-4D4F-9A52-3F1D1B2C4E5F/edit', '/app/customers/{id}/edit'],
            ['/app/customers/12345678-1234-1234-1234-123456789012', '/app/customers/{id}'],
            ['/app/customers/abcdefab-abcd-abcd-abcd-abcdefabcdef', '/app/customers/{id}'],
            ['/app/contracts/12/payments', '/app/contracts/{id}/payments'],
            ['/app/contracts/12/payments?x=1&email=sara.ali@example.com', '/app/contracts/{id}/payments'],
            ['/app/customers?search=sara.ali@example.com#top', '/app/customers'],
            ['/?lang=ar', '/'],
            ['/#section', '/'],
            ['/app/customers/01HZX3K9G2M4N5P6Q7R8S9T0VW', '/app/customers/{id}'],
            ['/verify/ab12cd34ef56', '/verify/{id}'],
            ['/sign/AbCdEfGhIj1234567890KlMnOpQrStUv', '/sign/{id}'],
            ['/sign/abcdefghijklmnopqrstuvwxyzabcdefghij', '/sign/{id}'],
            ['/files/blob/tenants/0b8a7c1e', '/files/blob/tenants/{id}'],
            ['/admin/errors/0b8a7c1e-6a0e-4d4f-9a52-3f1d1b2c4e5f', '/admin/errors/{id}'],
            ['/app/customers/', '/app/customers/'],
            ['app/customers/7', '/app/customers/{id}'],
            ['//app//customers//7', '//app//customers//{id}'],
            ['/%D8%A7%D9%84%D8%B9%D9%85%D9%84%D8%A7%D8%A1', '/%D8%A7%D9%84%D8%B9%D9%85%D9%84%D8%A7%D8%A1'],
        ];

        $got = errorsScriptRun('return input.map((c) => reporter.routePattern(c[0]));', $cases);

        $wrong = [];
        foreach ($cases as $i => [$path, $expected]) {
            if ($got[$i] !== $expected) {
                $wrong[] = "{$path} -> expected {$expected}, got ".json_encode($got[$i]);
            }
        }

        expect($wrong)->toBe([]);
    });

    it('never throws, never leaves a query string and is at most 160 characters', function () {
        $got = errorsScriptRun(<<<'JS'
            const hostile = [null, undefined, 42, {}, [], true, '/a?b'.repeat(500), '/'.repeat(500), '/' + 'ab'.repeat(300), '\u0000'];
            return hostile.map((h) => { try { return reporter.routePattern(h); } catch (error) { return 'THREW'; } });
            JS);

        expect($got)->toHaveCount(10);
        foreach ($got as $pattern) {
            expect($pattern)->toBeString()->not->toBe('THREW')
                ->and(str_contains($pattern, '?'))->toBeFalse()
                ->and(strlen($pattern))->toBeLessThanOrEqual(160);
        }
    });
})->skip(fn () => ! errorsScriptNodeIsInstalled(), 'Node is not installed here, so resources/js/error-report.js was not run.');

describe('which errors are reported', function () {
    it('takes the site\'s own scripts and nothing else', function () {
        $cases = [
            'an error in our bundle' => [true, ['message' => 'x', 'filename' => 'https://www.qistasme.com/build/assets/app-Bx9a2c1d.js']],
            'an error in a split chunk' => [true, ['message' => 'x', 'filename' => 'https://www.qistasme.com/build/assets/card-checkout-DfG3h8Zk.js']],
            'a module script' => [true, ['message' => 'x', 'filename' => 'https://www.qistasme.com/build/assets/x.mjs']],
            'a script with a query string' => [true, ['message' => 'x', 'filename' => 'https://www.qistasme.com/build/assets/app.js?v=3']],
            'a rejection, found by the first frame of its stack' => [true, ['reason' => ['message' => 'x', 'stack' => "TypeError: x\n    at pick (https://www.qistasme.com/build/assets/app-Bx9a2c1d.js:1:2)"]]],
            'a rejection in the Firefox form' => [true, ['reason' => ['message' => 'x', 'stack' => "pick@https://www.qistasme.com/build/assets/app-Bx9a2c1d.js:1:2\n@https://www.qistasme.com/build/assets/app-Bx9a2c1d.js:5:6"]]],
            'the opaque Script error.' => [false, ['message' => 'Script error.', 'filename' => '', 'lineno' => 0]],
            'the opaque Script error with a file' => [false, ['message' => 'Script error.', 'filename' => 'https://www.qistasme.com/build/assets/app.js']],
            'the opaque Script error, in capitals and without the dot' => [false, ['message' => 'SCRIPT ERROR', 'filename' => 'https://www.qistasme.com/build/assets/app.js']],
            'a Chrome extension' => [false, ['message' => 'x', 'filename' => 'chrome-extension://abcdefghijklmnop/content.js']],
            'a Firefox extension' => [false, ['message' => 'x', 'filename' => 'moz-extension://1234-5678/content.js']],
            'a Safari extension' => [false, ['message' => 'x', 'filename' => 'safari-web-extension://1234-5678/content.js']],
            'an old Safari extension' => [false, ['message' => 'x', 'filename' => 'safari-extension://com.example/content.js']],
            'an Edge extension' => [false, ['message' => 'x', 'filename' => 'ms-browser-extension://abc/content.js']],
            'another site' => [false, ['message' => 'x', 'filename' => 'https://cdn.other.example/lib.js']],
            'a look-alike host' => [false, ['message' => 'x', 'filename' => 'https://www.qistasme.com.evil.example/build/app.js']],
            'our name as the user name of another host' => [false, ['message' => 'x', 'filename' => 'https://www.qistasme.com@evil.example/app.js']],
            'a sibling host' => [false, ['message' => 'x', 'filename' => 'https://qistasme.com/build/assets/app.js']],
            'our host on plain http' => [false, ['message' => 'x', 'filename' => 'http://www.qistasme.com/build/assets/app.js']],
            'our host on another port' => [false, ['message' => 'x', 'filename' => 'https://www.qistasme.com:8443/build/assets/app.js']],
            'the page itself (inline code)' => [false, ['message' => 'x', 'filename' => 'https://www.qistasme.com/app/customers']],
            'a blob' => [false, ['message' => 'x', 'filename' => 'blob:https://www.qistasme.com/1234-5678']],
            'a data url' => [false, ['message' => 'x', 'filename' => 'data:text/javascript,throw 1']],
            'an eval' => [false, ['message' => 'x', 'filename' => 'eval']],
            'nothing says where' => [false, ['message' => 'x']],
            'a rejection with a reason that says nothing' => [false, ['reason' => 'just a string']],
            'a rejection from an extension' => [false, ['reason' => ['message' => 'x', 'stack' => "Error: x\n    at run (chrome-extension://abcdefghijklmnop/content.js:1:2)"]]],
            'a rejection from another site' => [false, ['reason' => ['message' => 'x', 'stack' => "Error: x\n    at run (https://cdn.other.example/lib.js:1:2)"]]],
            'a message that holds an address is not a frame' => [false, ['reason' => ['message' => 'x', 'stack' => 'Error: see https://www.qistasme.com/build/assets/app.js:1:2 now']]],
        ];

        $got = errorsScriptRun("return Object.fromEntries(Object.entries(input).map(([name, c]) => [name, reporter.shouldReport(c[1], 'https://www.qistasme.com')]));", $cases);

        $wrong = [];
        foreach ($cases as $name => [$expected]) {
            if ($got[$name] !== $expected) {
                $wrong[] = "{$name}: expected ".json_encode($expected).', got '.json_encode($got[$name]);
            }
        }

        expect($wrong)->toBe([]);
    });

    it('is a plain yes or no for anything it is given, and no for a page with no origin', function () {
        $got = errorsScriptRun(<<<'JS'
            const garbage = [null, undefined, 0, 1, 'text', true, [], {}, { filename: 5 }, { filename: {}, message: {} }, { error: null }, { error: { stack: 12 } }, { reason: { stack: {} } }];
            const verdicts = garbage.map((g) => { try { return reporter.shouldReport(g, 'https://www.qistasme.com'); } catch (error) { return 'THREW'; } });
            const own = { message: 'x', filename: 'https://www.qistasme.com/build/assets/app.js' };
            const origins = [null, undefined, '', 'null', 'about:blank', 'file://', 42, {}].map((o) => { try { return reporter.shouldReport(own, o); } catch (error) { return 'THREW'; } });
            return { verdicts, origins };
            JS);

        expect($got['verdicts'])->each->toBeFalse()
            ->and($got['origins'])->each->toBeFalse();
    });
})->skip(fn () => ! errorsScriptNodeIsInstalled(), 'Node is not installed here, so resources/js/error-report.js was not run.');

describe('what is sent', function () {
    it('holds only the allowed fields, with the route as a pattern and nothing of the address', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const { win } = windowOf([]);
            const payload = reporter.buildPayload(ownError("Cannot read properties of undefined (reading 'value')"), win.location);
            return { payload, text: JSON.stringify(payload) };
            JS);

        expect(array_diff(array_keys($got['payload']), ['class', 'message', 'trace', 'route']))->toBe([])
            ->and($got['payload']['class'])->toBe('TypeError')
            ->and($got['payload']['message'])->toBe("Cannot read properties of undefined (reading 'value')")
            ->and($got['payload']['route'])->toBe('/app/customers/{id}');

        foreach (['sara.ali', 'example.com', '0b8a7c1e', '?q=', '#top', 'qistasme.com', 'https://'] as $leak) {
            expect($got['text'])->not->toContain($leak);
        }
    });

    it('sends a trace of the site\'s own frames only, without the host', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const { win } = windowOf([]);
            const error = ownError('boom');
            error.error.stack = v8('boom', [
                `pick (${OWN}:12:34)`,
                'run (chrome-extension://abcdefghijklmnop/content.js:1:2)',
                'lib (https://cdn.other.example/lib.js:9:9)',
                `HTMLSelectElement.<anonymous> (${OWN}:56:7)`,
                'new Promise (<anonymous>)',
                `async load (${ORIGIN}/build/assets/card-checkout-DfG3h8Zk.js:3:4)`,
            ]);
            const gecko = ownError('boom');
            gecko.error.stack = [`pick@${OWN}:12:34`, 'run@moz-extension://1234-5678/content.js:1:2', `@${OWN}:56:7`].join('\n');
            return { v8: reporter.buildPayload(error, win.location).trace, gecko: reporter.buildPayload(gecko, win.location).trace };
            JS);

        expect($got['v8'])->toBe("at pick (/build/assets/app-Bx9a2c1d.js:12:34)\nat HTMLSelectElement.<anonymous> (/build/assets/app-Bx9a2c1d.js:56:7)\nat async load (/build/assets/card-checkout-DfG3h8Zk.js:3:4)")
            ->and($got['gecko'])->toBe("pick@/build/assets/app-Bx9a2c1d.js:12:34\n@/build/assets/app-Bx9a2c1d.js:56:7");
    });

    it('sends the error\'s own message and name, and a rejection that is not an error as one', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const { win } = windowOf([]);
            const text = { reason: 'plain words', filename: '' };
            const reasoned = { reason: Object.assign(new RangeError('too far'), { stack: `RangeError: too far\n    at go (${OWN}:1:2)` }) };
            const noError = { message: 'Uncaught SyntaxError: nope', filename: OWN };
            const empty = { reason: Object.assign(new Error(''), { stack: `Error\n    at go (${OWN}:1:2)` }) };
            const plain = { message: 'ResizeObserver loop limit exceeded', filename: OWN };
            return [text, reasoned, noError, empty, plain].map((e) => reporter.buildPayload(e, win.location));
            JS);

        expect($got[0])->toMatchArray(['message' => 'plain words', 'class' => 'UnhandledRejection'])
            ->and($got[1])->toMatchArray(['message' => 'too far', 'class' => 'RangeError'])
            ->and($got[2])->toMatchArray(['class' => 'SyntaxError', 'message' => 'nope'])
            ->and($got[3]['message'])->not->toBe('')
            ->and($got[3]['class'])->toBe('Error')
            ->and($got[4])->toMatchArray(['class' => 'Error', 'message' => 'ResizeObserver loop limit exceeded']);
    });

    it('cuts the message, the class and the trace to what the endpoint takes, and the whole to 8 KB, whatever it holds', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const { win } = windowOf([]);
            const frames = Array.from({ length: 400 }, (_, i) => `fn${i} (${OWN}:${i}:${i})`);
            const big = ownError('m'.repeat(5000));
            big.error.name = 'N'.repeat(500);
            big.error.stack = v8('m', frames);
            const arabic = ownError('خطأ'.repeat(800)); // twice as many bytes as characters, in the trace too
            arabic.error.stack = v8('x', Array.from({ length: 400 }, (_, i) => `دالة_طويلة_جدا_${i} (${OWN}:${i}:${i})`));
            const emoji = ownError('😀'.repeat(400));   // a cut must not split a pair
            const cjk = ownError('漢'.repeat(800));     // three bytes a character, and a trace of names three times as long
            cjk.error.stack = v8('x', Array.from({ length: 400 }, (_, i) => `${'函'.repeat(90)}${i} (${OWN}:${i}:${i})`));
            const results = [big, arabic, emoji, cjk].map((e) => reporter.buildPayload(e, win.location));
            return results.map((p) => ({
                payload: p,
                bytes: new TextEncoder().encode(JSON.stringify(p)).length,
                wellFormed: JSON.stringify(p).indexOf('\\ud8') === -1 && JSON.stringify(p).indexOf('\\udc') === -1,
            }));
            JS);

        foreach ($got as $one) {
            expect(mb_strlen($one['payload']['message']))->toBeLessThanOrEqual(500)
                ->and(mb_strlen($one['payload']['class'] ?? ''))->toBeLessThanOrEqual(160)
                ->and(mb_strlen($one['payload']['trace'] ?? ''))->toBeLessThanOrEqual(6000)
                ->and(mb_strlen($one['payload']['route']))->toBeLessThanOrEqual(160)
                ->and($one['bytes'])->toBeLessThanOrEqual(8192)
                ->and($one['wellFormed'])->toBeTrue();
        }
        expect($got[0]['payload']['trace'])->toStartWith('at fn0 (')
            ->and($got[3]['payload']['trace'])->toStartWith('at '.str_repeat('函', 90).'0 (')
            // Too much to send: the last frames went, not the first.
            ->and(substr_count($got[3]['payload']['trace'], "\n"))->toBeLessThan(25);
    });

    it('never ends a text in half of a pair, and never sends half of one', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const { win } = windowOf([]);
            const messages = ['a'.repeat(499) + '😀😀', 'abc\ud83dxyz', 'abc\ude00xyz', 'ends\ud83d', '\ude00starts', '😀'.repeat(300)];
            const run = () => messages.map((m) => JSON.stringify(reporter.buildPayload(ownError(m), win.location)));
            const withNative = run();
            delete String.prototype.toWellFormed;   // an older browser: the script's own clean-up
            const withoutNative = run();
            return { withNative, withoutNative };
            JS);

        foreach (['withNative', 'withoutNative'] as $how) {
            foreach ($got[$how] as $json) {
                expect(preg_match('~\\\\u[dD][89abAB]~', $json))->toBe(0, "{$how}: half of a pair in {$json}")
                    ->and(preg_match('~\\\\u[dD][c-fC-F]~', $json))->toBe(0, "{$how}: half of a pair in {$json}");
            }
        }
    });

    it('is what the endpoint takes, and the site\'s own frame is what the issue is grouped by', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const { win } = windowOf([]);
            const huge = ownError('漢'.repeat(800));
            huge.error.stack = v8('x', Array.from({ length: 400 }, (_, i) => `${'函'.repeat(90)}${i} (${OWN}:${i}:${i})`));
            return [reporter.buildPayload(ownError("Cannot read properties of undefined (reading 'value')"), win.location), reporter.buildPayload(huge, win.location)];
            JS);

        foreach ($got as $payload) {
            test()->call('POST', '/client-errors', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->assertNoContent();
        }

        $issues = ErrorIssue::query()->orderBy('created_at')->get();
        $sample = $issues->first()->samples()->sole();

        expect($issues)->toHaveCount(2)
            ->and($issues->first()->only(['source', 'kind', 'class']))->toBe(['source' => 'site', 'kind' => 'script', 'class' => 'TypeError'])
            ->and($sample->route)->toBe('/app/customers/{id}')
            ->and($sample->platform)->toBe('web')
            ->and(ErrorFingerprint::topFrame($sample->trace, 'web'))->toBe('app.js::pick');
    });
})->skip(fn () => ! errorsScriptNodeIsInstalled(), 'Node is not installed here, so resources/js/error-report.js was not run.');

describe('the reporter on a page', function () {
    it('listens for the page\'s errors and unhandled rejections, and sends one as JSON to /client-errors with a beacon', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const log = [];
            const { win, listeners } = windowOf(log);
            reporter.initErrorReporting(win);
            const names = Object.keys(listeners).sort();
            listeners.error(ownError('first'));
            return { names, entry: { via: log[0].via, url: log[0].url, type: log[0].type }, count: log.length, body: (await bodies(log))[0] };
            JS);

        expect($got['names'])->toBe(['error', 'unhandledrejection'])
            ->and($got['count'])->toBe(1)
            ->and($got['entry'])->toBe(['via' => 'beacon', 'url' => '/client-errors', 'type' => 'application/json'])
            ->and($got['body'])->toMatchArray(['class' => 'TypeError', 'message' => 'first', 'route' => '/app/customers/{id}']);
    });

    it('reports an unhandled rejection of the site\'s own code', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const log = [];
            const { win, listeners } = windowOf(log);
            reporter.initErrorReporting(win);
            listeners.unhandledrejection({ reason: Object.assign(new RangeError('too far'), { stack: `RangeError: too far\n    at go (${OWN}:1:2)` }) });
            return await bodies(log);
            JS);

        expect($got)->toHaveCount(1)->and($got[0])->toMatchArray(['class' => 'RangeError', 'message' => 'too far']);
    });

    it('sends at most 5 reports per page view, and the same error once', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const log = [];
            const { win, listeners } = windowOf(log);
            reporter.initErrorReporting(win);
            for (let i = 0; i < 4; i++) listeners.error(ownError('the same'));
            for (let i = 0; i < 12; i++) listeners.error(ownError('different ' + 'x'.repeat(i)));
            listeners.unhandledrejection({ reason: Object.assign(new Error('and a rejection'), { stack: `Error: x\n    at go (${OWN}:1:2)` }) });
            const messages = (await bodies(log)).map((b) => b.message);
            return messages;
            JS);

        expect($got)->toHaveCount(5)->and(array_unique($got))->toHaveCount(5)->and($got[0])->toBe('the same');
    });

    it('a page view that starts again starts counting again', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const first = [];
            const second = [];
            const one = windowOf(first);
            const two = windowOf(second);
            reporter.initErrorReporting(one.win);
            reporter.initErrorReporting(two.win);
            for (let i = 0; i < 9; i++) one.listeners.error(ownError('a ' + 'x'.repeat(i)));
            two.listeners.error(ownError('b'));
            return [first.length, second.length];
            JS);

        expect($got)->toBe([5, 1]);
    });

    it('sends nothing for another site\'s script, an extension, or the opaque Script error', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const log = [];
            const { win, listeners } = windowOf(log);
            reporter.initErrorReporting(win);
            listeners.error({ message: 'Script error.', filename: '', lineno: 0, colno: 0, error: null });
            listeners.error(ownError('from an extension', { filename: 'chrome-extension://abcdefghijklmnop/content.js' }));
            listeners.error(ownError('from a cdn', { filename: 'https://cdn.other.example/lib.js' }));
            listeners.unhandledrejection({ reason: 'a string' });
            listeners.unhandledrejection({ reason: undefined });
            listeners.error(null);
            listeners.error(undefined);
            listeners.error('text');
            const none = log.length;
            listeners.error(ownError('ours'));   // and the ones that were turned away did not use up the five
            return [none, log.length];
            JS);

        expect($got)->toBe([0, 1]);
    });

    it('falls back to fetch with keepalive and no credentials when the beacon is refused or throws', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const refused = [];
            const one = windowOf(refused, { beacon: false });
            reporter.initErrorReporting(one.win);
            one.listeners.error(ownError('refused'));

            const thrown = [];
            const two = windowOf(thrown);
            two.win.navigator.sendBeacon = () => { throw new Error('no beacon'); };
            reporter.initErrorReporting(two.win);
            two.listeners.error(ownError('thrown'));

            const missing = [];
            const three = windowOf(missing);
            delete three.win.navigator;
            reporter.initErrorReporting(three.win);
            three.listeners.error(ownError('missing'));

            const shape = (log) => log.map((l) => l.via + ' ' + (l.via === 'fetch' ? [l.url, l.init.method, l.init.keepalive, l.init.credentials, l.init.headers['Content-Type'], JSON.parse(l.init.body).message].join(' ') : ''));
            return [shape(refused), shape(thrown), shape(missing)];
            JS);

        expect($got[0])->toBe(['beacon ', 'fetch /client-errors POST true omit application/json refused'])
            ->and($got[1])->toBe(['fetch /client-errors POST true omit application/json thrown'])
            ->and($got[2])->toBe(['fetch /client-errors POST true omit application/json missing']);
    });

    it('never throws and never reports itself, whatever fails inside it', function () {
        $got = errorsScriptRun(ERRORS_SCRIPT_PAGE.<<<'JS'

            const outcomes = [];
            const attempt = (name, make) => {
                const log = [];
                const { win, listeners } = windowOf(log);
                make(win, log);
                try {
                    reporter.initErrorReporting(win);
                    outcomes.push([name, 'init ok']);
                    listeners.error?.(ownError('x ' + name));
                    outcomes.push([name, 'sent ' + log.length]);
                } catch (error) {
                    outcomes.push([name, 'THREW ' + error.message]);
                }
            };

            attempt('beacon and fetch both throw', (win) => { win.navigator.sendBeacon = () => { throw new Error('a'); }; win.fetch = () => { throw new Error('b'); }; });
            attempt('fetch rejects', (win) => { win.navigator.sendBeacon = () => false; win.fetch = () => Promise.reject(new Error('rejected')); });
            attempt('fetch returns nothing', (win) => { win.navigator.sendBeacon = () => false; win.fetch = () => undefined; });
            attempt('no way to send at all', (win) => { delete win.navigator; delete win.fetch; });
            attempt('a location that throws', (win) => { Object.defineProperty(win, 'location', { get() { throw new Error('location'); } }); });
            attempt('no location', (win) => { delete win.location; });
            attempt('no addEventListener', (win) => { delete win.addEventListener; });

            // An event whose fields throw when they are read.
            const log = [];
            const { win, listeners } = windowOf(log);
            reporter.initErrorReporting(win);
            const nasty = { get message() { throw new Error('message'); }, get filename() { throw new Error('filename'); }, get error() { throw new Error('error'); }, get reason() { throw new Error('reason'); } };
            let nastyOutcome = 'ok';
            try { listeners.error(nasty); listeners.unhandledrejection(nasty); } catch (error) { nastyOutcome = 'THREW'; }

            // A send that raises an error event of its own while it is sending: it is not reported.
            const inner = [];
            const again = windowOf(inner);
            again.win.navigator.sendBeacon = (url, blob) => { inner.push(url); again.listeners.error(ownError('raised while sending')); return true; };
            reporter.initErrorReporting(again.win);
            again.listeners.error(ownError('the first'));

            // The rejection of a failed fetch is handled, so it cannot become an unhandled rejection of its own.
            await new Promise((resolve) => setTimeout(resolve, 10));

            return { outcomes, nastyOutcome, nastyLog: log.length, inner: inner.length };
            JS);

        foreach ($got['outcomes'] as [$name, $what]) {
            expect($what)->not->toContain('THREW', $name);
        }
        expect($got['nastyOutcome'])->toBe('ok')->and($got['nastyLog'])->toBe(0)->and($got['inner'])->toBe(1);
    });

    it('does nothing in a window that is not a page', function () {
        $got = errorsScriptRun(<<<'JS'
            const results = [];
            for (const win of [undefined, null, 0, 'text', {}, { addEventListener: 5 }, { addEventListener() {}, location: null }]) {
                try { reporter.initErrorReporting(win); results.push('ok'); } catch (error) { results.push('THREW'); }
            }
            return results;
            JS);

        expect($got)->each->toBe('ok');
    });
})->skip(fn () => ! errorsScriptNodeIsInstalled(), 'Node is not installed here, so resources/js/error-report.js was not run.');

describe('the bundle', function () {
    it('starts the reporter from app.js, and puts no script in a page', function () {
        $app = (string) file_get_contents(base_path('resources/js/app.js'));

        expect($app)->toContain("from './error-report'")->and($app)->toContain('initErrorReporting(window)');

        // The strict content-security policy forbids inline script: nothing in the views may bring one for the reporter.
        $views = collect(File::allFiles(resource_path('views')))->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'));
        foreach ($views as $view) {
            expect((string) file_get_contents($view->getPathname()))->not->toContain('client-errors');
        }
    });
});
