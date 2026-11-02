<?php

use App\Actions\RecordPayment;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;

/*
| Go-live basics (spec 2026-11-01 §3.1, §3.3): signing up asks for five things, and the first thing the new owner sees
| on the dashboard is one card that asks for the business's real name and confirms the place that was found. The
| hidden time-zone hint rides on the sign-up and sign-in forms. The web uses the same request and the same action as
| the phone app (PUT /api/v1/workspace/setup); only the answer differs.
*/

/** What a new sign-up leaves behind: the placeholder name, a guess at the place, not confirmed. */
function setupCardUnconfirmed(array $tenant = []): array
{
    return $tenant + [
        'name' => 'Business of Layla', 'country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar',
        'country_source' => 'header', 'setup_confirmed_at' => null,
    ];
}

/** @return array{0: User, 1: Tenant} an owner signed in on the web, whose workspace is not confirmed yet */
function setupCardOwner(array $tenant = [], array $user = []): array
{
    [$owner, $workspace] = makeAccount($user, setupCardUnconfirmed($tenant));
    test()->actingAs($owner);

    return [$owner, $workspace];
}

/** The page's DOM, to ask where things sit rather than whether a string is somewhere. */
function setupCardDom(TestResponse $response): DOMXPath
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
    libxml_clear_errors();

    return new DOMXPath($dom);
}

/** The card itself, or null when the page has none. */
function setupCardNode(DOMXPath $page): ?DOMElement
{
    $node = $page->query('//section[@id="setup-card"]')->item(0);

    return $node instanceof DOMElement ? $node : null;
}

/** The card's text with its spaces collapsed. */
function setupCardText(TestResponse $response): string
{
    $card = setupCardNode(setupCardDom($response));

    return $card === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $card->textContent));
}

/** Nodes inside the card that match an XPath relative to it. */
function setupCardFind(DOMXPath $page, string $path): DOMNodeList
{
    return $page->query('//section[@id="setup-card"]'.$path);
}

/** What a name can get wrong, and the words it is told in. */
function setupCardMistakes(): array
{
    return [
        'nothing typed' => ['', 'Enter the name of your business.'],
        'only spaces' => ['    ', 'Enter the name of your business.'],
        'too long' => [str_repeat('b', 256), 'The business name can be at most 255 characters.'],
    ];
}

/** A workspace that has taken its first contract, so its place can no longer change. */
function setupCardLock(Tenant $tenant): void
{
    openContract($tenant);
}

describe('the sign-up and sign-in pages', function () {
    it('asks for a name, an email, a password, its confirmation and the terms, and no business name or country', function () {
        $page = $this->get('/register')->assertOk();

        foreach (['name', 'email', 'password', 'password_confirmation', 'terms'] as $field) {
            $page->assertSee('name="'.$field.'"', false);
        }

        $page->assertSee('name="locale"', false)
            ->assertDontSee('name="business_name"', false)
            ->assertDontSee('name="country"', false)
            ->assertDontSee('<option value="SA"', false)
            ->assertDontSee('autocomplete="organization"', false);

        $fields = setupCardDom($page)->query('//form[@action="'.route('register.store').'"]//*[self::input or self::select or self::textarea]');
        $names = [];
        foreach ($fields as $field) {
            $names[] = $field->getAttribute('name');
        }

        expect($names)->toEqualCanonicalizing(['_token', 'locale', 'timezone', 'name', 'email', 'password', 'password_confirmation', 'terms']);
    });

    it('carries the browser time zone in a hidden field, filled by the script, on the sign-up and the sign-in form', function (string $path, string $route) {
        $dom = setupCardDom($this->get($path)->assertOk());

        $form = $dom->query('//form[@action="'.route($route).'"]')->item(0);
        $hidden = $dom->query('.//input[@type="hidden"][@name="timezone"]', $form);

        expect($form)->toBeInstanceOf(DOMElement::class)
            ->and($form->hasAttribute('data-timezone'))->toBeTrue()
            ->and($hidden)->toHaveCount(1)
            ->and($hidden->item(0)->getAttribute('value'))->toBe('');
    })->with([
        'sign up' => ['/register', 'register.store'],
        'sign in' => ['/login', 'login.store'],
    ]);

    it('keeps the demo block and its credentials on the sign-in page (Task 8 removes the public demo, not this)', function () {
        config(['qistas.demo' => true]);

        $this->get('/login')->assertOk()->assertSee('demo-login', false);
    });

    it('creates the account from the five fields and shows the card on its first dashboard', function () {
        $this->withHeaders(['Accept-Language' => '', 'CF-IPCountry' => 'QA']);
        config(['qistas.geo_header' => 'CF-IPCountry']);

        $this->post('/register', [
            'name' => 'Layla Haddad', 'email' => 'layla@example.com', 'password' => TEST_PASSWORD,
            'password_confirmation' => TEST_PASSWORD, 'terms' => '1', 'locale' => 'en', 'timezone' => 'Asia/Qatar',
        ])->assertRedirect(config('fortify.home'));

        $tenant = User::where('email', 'layla@example.com')->sole()->tenants()->sole();
        expect($tenant->name)->toBe('Business of Layla')->and($tenant->isSetUp())->toBeFalse()
            ->and($tenant->only(['country', 'currency', 'timezone', 'country_source']))
            ->toBe(['country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar', 'country_source' => 'header']);

        $dashboard = $this->get('/app')->assertOk();
        $text = setupCardText($dashboard);

        expect($text)->toContain('Name your business')->toContain('We think you are in Qatar: QAR');
        expect(setupCardFind(setupCardDom($dashboard), '//input[@name="name"]')->item(0)->getAttribute('placeholder'))->toBe('Business of Layla');
    });

    it('loads the time-zone script on every page that has these forms', function () {
        expect(file_get_contents(resource_path('js/app.js')))->toContain("from './timezone'")
            ->and(file_get_contents(resource_path('views/components/layouts/auth.blade.php')))->toContain('resources/js/app.js');
    });
});

/** Runs resources/js/timezone.js through Node against a hand-made document, and returns what it left in the field. */
function setupCardRunScript(string $arrange): array
{
    $script = <<<'JS'
        import { pathToFileURL } from 'node:url';
        const { fillTimezones } = await import(pathToFileURL(process.argv[1]).href);
        const field = { name: 'timezone', value: '' };
        const asked = [];
        const page = { querySelectorAll(selector) { asked.push(selector); return [field]; } };
        ARRANGE
        fillTimezones(page);
        console.log(JSON.stringify({ value: field.value, asked }));
        JS;

    $run = new Process(['node', '--input-type=module', '-e', str_replace('ARRANGE', $arrange, $script), base_path('resources/js/timezone.js')]);
    $run->run();

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput());

    return json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function setupCardNodeIsInstalled(): bool
{
    $probe = new Process(['node', '--version']);
    $probe->run();

    return $probe->isSuccessful();
}

describe('the time-zone script', function () {
    it('writes the browser’s own zone into the hidden field of a form marked data-timezone', function () {
        $result = setupCardRunScript('');

        expect($result['value'])->toMatch('/^[A-Za-z_]+(\/[A-Za-z_+\-0-9]+)*$/')
            ->and($result['asked'])->toHaveCount(1)
            ->and($result['asked'][0])->toContain('data-timezone')->toContain('timezone');
    })->skip(fn () => ! setupCardNodeIsInstalled(), 'Node is not installed here, so resources/js/timezone.js was not run.');

    it('leaves the field empty when the browser cannot say', function () {
        $result = setupCardRunScript('Intl.DateTimeFormat = () => { throw new Error("no Intl"); };');

        expect($result['value'])->toBe('');
    })->skip(fn () => ! setupCardNodeIsInstalled(), 'Node is not installed here, so resources/js/timezone.js was not run.');

    it('leaves the field empty when the zone it gets is not a name', function () {
        $result = setupCardRunScript('Intl.DateTimeFormat = () => ({ resolvedOptions: () => ({ timeZone: undefined }) });');

        expect($result['value'])->toBe('');
    })->skip(fn () => ! setupCardNodeIsInstalled(), 'Node is not installed here, so resources/js/timezone.js was not run.');
});

describe('the card', function () {
    it('is shown to the owner while the business is not confirmed', function () {
        setupCardOwner();

        $page = $this->get('/app')->assertOk();
        $dom = setupCardDom($page);
        $name = setupCardFind($dom, '//input[@name="name"]')->item(0);

        expect(setupCardNode($dom))->toBeInstanceOf(DOMElement::class)
            ->and(setupCardText($page))->toContain('Name your business')->toContain('Save and continue')
            ->and(setupCardFind($dom, '//form[@method="POST"][@action="'.route('app.setup.update').'"]'))->toHaveCount(1)
            ->and(setupCardFind($dom, '//form//input[@name="_method"][@value="PUT"]'))->toHaveCount(1);

        // The placeholder name is not the business's name: the field starts empty and only shows it as a hint.
        expect($name->getAttribute('value'))->toBe('')->and($name->getAttribute('placeholder'))->toBe('Business of Layla')
            ->and($name->hasAttribute('required'))->toBeTrue()->and($name->getAttribute('maxlength'))->toBe('255')
            ->and($name->getAttribute('autocomplete'))->toBe('organization');
    });

    it('is not shown once the business is confirmed', function () {
        setupCardOwner(['setup_confirmed_at' => now()]);

        $page = $this->get('/app')->assertOk();

        expect(setupCardNode(setupCardDom($page)))->toBeNull();
        $page->assertDontSee('Name your business')->assertDontSee(route('app.setup.update'), false);
    });

    it('is not shown to a member the owner invited, who reads a quiet line in its place', function (string $role) {
        [, $tenant] = setupCardOwner();
        $member = memberAs($role, $tenant);

        $page = $this->actingAs($member)->get('/app')->assertOk();

        expect(setupCardNode(setupCardDom($page)))->toBeNull();
        $page->assertDontSee(route('app.setup.update'), false)
            ->assertDontSee('Save and continue')
            ->assertSee('Your business owner needs to name the business before documents can be printed.');
    })->with(['manager', 'accountant', 'collector', 'viewer']);

    it('does not say anything to a member of a business that is confirmed', function () {
        [, $tenant] = setupCardOwner(['setup_confirmed_at' => now()]);
        $member = memberAs('manager', $tenant);

        $this->actingAs($member)->get('/app')->assertOk()->assertDontSee('Your business owner needs to name the business');
    });

    describe('the place', function () {
        it('is told as a sentence with the country’s name, its currency and its time zone, and can be changed, when the network found it', function () {
            setupCardOwner();

            $page = $this->get('/app')->assertOk();
            $dom = setupCardDom($page);

            expect(setupCardText($page))->toContain('We think you are in Qatar: QAR, Qatar Time.')->toContain('Change');

            // The chooser is behind "Change", in a disclosure, with the found country chosen.
            $select = setupCardFind($dom, '//select[@name="country"]')->item(0);
            $disclosure = setupCardFind($dom, '//select[@name="country"]/ancestor::details');

            expect($disclosure)->toHaveCount(1)
                ->and($disclosure->item(0)->hasAttribute('open'))->toBeFalse()
                ->and($dom->query('./summary', $disclosure->item(0)))->toHaveCount(1)
                ->and(trim($dom->query('./summary', $disclosure->item(0))->item(0)->textContent))->toContain('Change')
                ->and($dom->query('.//option[@selected]', $select)->item(0)->getAttribute('value'))->toBe('QA');
        });

        it('lists only the countries a business can be opened in, in the reader’s language', function () {
            setupCardOwner();

            $dom = setupCardDom($this->get('/app')->assertOk());
            $codes = [];
            foreach (setupCardFind($dom, '//select[@name="country"]/option') as $option) {
                $codes[] = $option->getAttribute('value');
            }

            expect($codes)->toEqualCanonicalizing(array_keys(config('qistas.countries')));
        });

        it('asks the owner to check it when it was only guessed from the browser’s language, and says so', function () {
            setupCardOwner(['country' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York', 'country_source' => 'language']);

            $page = $this->get('/app')->assertOk();
            $dom = setupCardDom($page);

            expect(setupCardText($page))->toContain('We think you are in United States: USD')
                ->toContain('Guessed from your browser’s language, so it may be wrong.')
                ->toContain('Change');
            // The control is the stronger one, not the quiet link of a place the network gave.
            expect(setupCardFind($dom, '//details[@data-source="language"]'))->toHaveCount(1);
        });

        it('does not call a place a guess when the network gave it', function () {
            setupCardOwner();

            $page = $this->get('/app')->assertOk();

            expect(setupCardText($page))->not->toContain('Guessed from your browser')
                ->and(setupCardFind(setupCardDom($page), '//details[@data-source="header"]'))->toHaveCount(1);
        });

        it('shows the chooser at once, with the current country chosen, when nothing could be found', function (string $source) {
            setupCardOwner(['country' => 'SA', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'country_source' => $source]);

            $page = $this->get('/app')->assertOk();
            $dom = setupCardDom($page);
            $select = setupCardFind($dom, '//select[@name="country"]')->item(0);

            expect(setupCardText($page))->not->toContain('We think you are in')
                ->toContain('We could not tell where you are. Choose your country: its currency and time zone follow.')
                ->and(setupCardFind($dom, '//select[@name="country"]/ancestor::details'))->toHaveCount(0)
                ->and($dom->query('.//option[@selected]', $select)->item(0)->getAttribute('value'))->toBe('SA')
                ->and($select->hasAttribute('required'))->toBeFalse();
        })->with(['default', 'chosen']);

        it('makes the owner pick a country when the one found is not one a business can be opened in', function () {
            setupCardOwner(['country' => 'PS', 'currency' => 'USD', 'timezone' => 'Asia/Gaza', 'country_source' => 'language']);

            $page = $this->get('/app')->assertOk();
            $dom = setupCardDom($page);
            $select = setupCardFind($dom, '//select[@name="country"]')->item(0);
            $chosen = $dom->query('.//option[@selected]', $select)->item(0);

            expect(setupCardText($page))->not->toContain('We think you are in')
                ->and(setupCardFind($dom, '//select[@name="country"]/ancestor::details'))->toHaveCount(0)
                ->and($select->hasAttribute('required'))->toBeTrue()
                ->and($chosen->getAttribute('value'))->toBe('')->and($chosen->hasAttribute('disabled'))->toBeTrue()
                ->and(trim($chosen->textContent))->toBe('Select your country');
        });

        it('asks for the name only, and says why the country stays, once there is a contract', function () {
            [, $tenant] = setupCardOwner();
            setupCardLock($tenant);

            $page = $this->get('/app')->assertOk();
            $dom = setupCardDom($page);

            expect(setupCardNode($dom))->toBeInstanceOf(DOMElement::class)
                ->and(setupCardFind($dom, '//input[@name="name"]'))->toHaveCount(1)
                ->and(setupCardFind($dom, '//select'))->toHaveCount(0)
                ->and(setupCardFind($dom, '//details'))->toHaveCount(0)
                ->and(setupCardFind($dom, '//input[@name="country"]'))->toHaveCount(0)
                ->and(setupCardText($page))->not->toContain('We think you are in')->not->toContain('Change')
                ->toContain('The country can no longer be changed because this business already has contracts or payments. Contact support to change it.');
        });

        it('keeps a country that is not on the list out of the chooser’s way once the place is locked', function () {
            [, $tenant] = setupCardOwner(['country' => 'PS', 'currency' => 'USD', 'timezone' => 'Asia/Gaza', 'country_source' => 'language']);
            setupCardLock($tenant);

            $page = $this->get('/app')->assertOk();

            expect(setupCardFind(setupCardDom($page), '//select'))->toHaveCount(0);
        });

        it('names the country in the reader’s language', function () {
            setupCardOwner(user: ['locale' => 'ar']);

            $page = $this->get('/app')->assertOk();

            expect(setupCardText($page))->toContain(Locale::getDisplayRegion('-QA', 'ar'))->not->toContain('Qatar');
        });
    });

    it('shows the card above the figures and the checklist', function () {
        setupCardOwner();

        $html = $this->get('/app')->assertOk()->getContent();

        expect(strpos($html, 'id="setup-card"'))->toBeInt()
            ->toBeLessThan(strpos($html, 'id="start-title"'))
            ->toBeLessThan(strpos($html, 'aria-label="Overview"'));
    });

    it('escapes the name it shows', function () {
        setupCardOwner(['name' => '<script>alert(1)</script>']);

        $page = $this->get('/app')->assertOk();

        $page->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        expect(setupCardFind(setupCardDom($page), '//input[@name="name"]')->item(0)->getAttribute('placeholder'))->toBe('<script>alert(1)</script>');
    });
});

describe('saving it', function () {
    it('names the business, confirms it, goes back to the dashboard with a message and takes the card away', function () {
        [, $tenant] = setupCardOwner();

        $this->put('/app/setup', ['name' => '  Al-Fares Electronics  ', 'country' => 'QA'])
            ->assertRedirect(route('app.dashboard'))
            ->assertSessionHas('status', 'Saved. Receipts and statements will carry this name.')
            ->assertSessionHasNoErrors();

        $fresh = $tenant->fresh();
        expect($fresh->name)->toBe('Al-Fares Electronics')->and($fresh->isSetUp())->toBeTrue()
            ->and($fresh->only(['country', 'currency', 'timezone', 'country_source']))
            ->toBe(['country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar', 'country_source' => 'header']);

        $page = $this->get('/app')->assertOk()->assertSee('Saved. Receipts and statements will carry this name.');
        expect(setupCardNode(setupCardDom($page)))->toBeNull();
        $page->assertDontSee(route('app.setup.update'), false);
    });

    it('writes the one audit row, with the name and the place', function () {
        [$owner, $tenant] = setupCardOwner();

        $this->put('/app/setup', ['name' => 'Al-Fares Electronics', 'country' => 'QA']);

        $audit = AuditLog::query()->where('tenant_id', $tenant->id)->where('action', 'workspace.setup_confirmed')->sole();
        expect($audit->user_id)->toBe($owner->id)
            ->and($audit->changes)->toBe(['name' => 'Al-Fares Electronics', 'country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar']);
    });

    it('changes the place when the owner picked another country while it is still open', function () {
        [, $tenant] = setupCardOwner(['country' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York', 'country_source' => 'language']);

        $this->put('/app/setup', ['name' => 'Al-Fares Electronics', 'country' => 'AE'])->assertRedirect(route('app.dashboard'));

        expect($tenant->fresh()->only(['country', 'currency', 'timezone', 'country_source', 'name']))
            ->toBe(['country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'country_source' => 'chosen', 'name' => 'Al-Fares Electronics']);
    });

    it('saves a name alone, as the card sends it once the place is locked', function () {
        [, $tenant] = setupCardOwner();
        setupCardLock($tenant);

        $this->put('/app/setup', ['name' => 'Al-Fares Electronics'])->assertRedirect(route('app.dashboard'))->assertSessionHasNoErrors();

        expect($tenant->fresh()->name)->toBe('Al-Fares Electronics')->and($tenant->fresh()->isSetUp())->toBeTrue()
            ->and($tenant->fresh()->country)->toBe('QA');
    });

    it('refuses the name with the reason and saves nothing', function (mixed $typed, string $message) {
        [, $tenant] = setupCardOwner();

        $this->from('/app')->put('/app/setup', ['name' => $typed, 'country' => 'QA'])
            ->assertRedirect('/app')->assertSessionHasErrors(['name' => $message]);

        expect($tenant->fresh()->isSetUp())->toBeFalse()->and($tenant->fresh()->name)->toBe('Business of Layla');
    })->with(setupCardMistakes());

    it('shows the mistake next to the name, in words, and marks the field', function (mixed $typed, string $message) {
        setupCardOwner();

        // (Reading the session in a test ends the flash, so the page is looked at on its own.)
        $this->from('/app')->put('/app/setup', ['name' => $typed, 'country' => 'QA'])->assertRedirect('/app');

        $dom = setupCardDom($this->get('/app')->assertOk());
        $error = setupCardFind($dom, '//input[@name="name"]/following-sibling::p[contains(@class, "field-error")]');
        $name = setupCardFind($dom, '//input[@name="name"]')->item(0);

        expect($error)->toHaveCount(1)->and(trim($error->item(0)->textContent))->toBe($message)
            ->and($name->getAttribute('aria-invalid'))->toBe('true')
            ->and($name->getAttribute('aria-describedby'))->toBe($error->item(0)->getAttribute('id'));
    })->with(setupCardMistakes());

    it('keeps what the owner typed, escaped, when the name is refused', function () {
        setupCardOwner();
        $typed = '<b>'.str_repeat('x', 260).'</b>';

        $this->from('/app')->put('/app/setup', ['name' => $typed]);
        $page = $this->get('/app')->assertOk();

        $page->assertDontSee('<b>'.str_repeat('x', 260), false)->assertSee('&lt;b&gt;'.str_repeat('x', 260), false);
        expect(setupCardFind(setupCardDom($page), '//input[@name="name"]')->item(0)->getAttribute('value'))->toBe($typed);
    });

    it('stores a name with markup as text, and shows it escaped everywhere', function () {
        [, $tenant] = setupCardOwner();

        $this->put('/app/setup', ['name' => '<b>Al-Fares</b> & Sons']);
        $page = $this->get('/app')->assertOk();

        expect($tenant->fresh()->name)->toBe('<b>Al-Fares</b> & Sons');
        $page->assertDontSee('<b>Al-Fares</b>', false)->assertSee('&lt;b&gt;Al-Fares&lt;/b&gt; &amp; Sons', false);
    });

    it('refuses a country that is not on the list, and saves nothing', function () {
        [, $tenant] = setupCardOwner();

        $this->from('/app')->put('/app/setup', ['name' => 'Al-Fares Electronics', 'country' => 'Wakanda'])
            ->assertRedirect('/app')->assertSessionHasErrors(['country' => 'Choose one of the countries in the list.']);

        expect($tenant->fresh()->isSetUp())->toBeFalse();
    });

    it('shows a mistake about the country next to the chooser, which opens to show it', function () {
        setupCardOwner();

        $this->from('/app')->put('/app/setup', ['name' => 'Al-Fares Electronics', 'country' => 'Wakanda'])->assertRedirect('/app');

        $dom = setupCardDom($this->get('/app')->assertOk());
        $disclosure = setupCardFind($dom, '//select[@name="country"]/ancestor::details')->item(0);
        $error = setupCardFind($dom, '//select[@name="country"]/following-sibling::p[contains(@class, "field-error")]');

        expect($disclosure->hasAttribute('open'))->toBeTrue()
            ->and($error)->toHaveCount(1)->and(trim($error->item(0)->textContent))->toBe('Choose one of the countries in the list.')
            ->and(setupCardFind($dom, '//select[@name="country"]')->item(0)->getAttribute('aria-invalid'))->toBe('true');
    });

    it('refuses another country once there is a contract, and saves nothing, not even the name', function () {
        [, $tenant] = setupCardOwner();
        setupCardLock($tenant);

        $this->from('/app')->put('/app/setup', ['name' => 'Al-Fares Electronics', 'country' => 'AE'])
            ->assertRedirect('/app')->assertSessionHasErrors('country');

        $fresh = $tenant->fresh();
        expect($fresh->isSetUp())->toBeFalse()->and($fresh->country)->toBe('QA')->and($fresh->name)->toBe('Business of Layla');
    });

    it('says why once, in the card, when a country is refused for a contract', function () {
        [, $tenant] = setupCardOwner();
        setupCardLock($tenant);

        $this->from('/app')->put('/app/setup', ['name' => 'Al-Fares Electronics', 'country' => 'AE'])->assertRedirect('/app');
        $page = $this->get('/app')->assertOk();

        expect(setupCardFind(setupCardDom($page), '//p[contains(@class, "field-error")]'))->toHaveCount(1)
            ->and(substr_count(setupCardText($page), 'The country can no longer be changed'))->toBe(1);
    });

    it('is the owner’s alone', function (string $role) {
        [, $tenant] = setupCardOwner();
        $member = memberAs($role, $tenant);

        $this->actingAs($member)->put('/app/setup', ['name' => 'Al-Fares Electronics', 'country' => 'QA'])->assertForbidden();

        expect($tenant->fresh()->isSetUp())->toBeFalse()->and($tenant->fresh()->name)->toBe('Business of Layla');
    })->with(['manager', 'accountant', 'collector', 'viewer']);

    it('sends a guest to sign in', function () {
        $this->put('/app/setup', ['name' => 'Al-Fares Electronics'])->assertRedirect(route('login'));
    });

    it('never touches another business', function () {
        [, $mine] = setupCardOwner();
        [, $theirs] = makeAccount(tenant: setupCardUnconfirmed(['name' => 'Theirs']));

        $this->put('/app/setup', ['name' => 'Mine', 'country' => 'QA']);

        expect($mine->fresh()->name)->toBe('Mine')->and($theirs->fresh()->name)->toBe('Theirs')->and($theirs->fresh()->isSetUp())->toBeFalse();
    });

    it('reads nothing but the name and the country, whatever else is sent', function () {
        [, $tenant] = setupCardOwner();

        $this->put('/app/setup', [
            'name' => 'Al-Fares Electronics', 'country' => 'QA', 'currency' => 'JPY', 'country_source' => 'header', 'slug' => 'taken',
            'is_test' => true, 'setup_confirmed_at' => '2001-01-01 00:00:00', 'tenant_id' => 'x', 'status' => 'suspended',
        ])->assertRedirect(route('app.dashboard'));

        $fresh = $tenant->fresh();
        expect($fresh->currency)->toBe('QAR')->and($fresh->slug)->toBe($tenant->slug)->and($fresh->is_test)->toBeFalse()
            ->and($fresh->setup_confirmed_at->year)->not->toBe(2001)->and($fresh->status)->not->toBe('suspended');
    });

    it('is throttled to twenty a minute, apart from the other settings', function () {
        [, $tenant] = setupCardOwner();

        foreach (range(1, 20) as $_) {
            $this->from('/app')->put('/app/setup', ['name' => ''])->assertRedirect('/app');
        }

        $this->from('/app')->put('/app/setup', ['name' => ''])->assertStatus(429);
        $this->put('/app/profile', ['name' => 'Layla H.', 'email' => 'layla@example.com'])->assertStatus(302);
        expect($tenant->fresh()->isSetUp())->toBeFalse();
    });

    it('is read right to left in Arabic, with the card’s words in Arabic', function () {
        setupCardOwner(user: ['locale' => 'ar']);

        $page = $this->get('/app')->assertOk();
        $dom = setupCardDom($page);

        $page->assertSee('lang="ar" dir="rtl"', false);
        expect(setupCardText($page))->toContain(__('Name your business', [], 'ar'))->toContain(__('Save and continue', [], 'ar'))
            ->not->toContain('Name your business')->not->toContain('Save and continue');
        // Nothing in the card is placed by left or right.
        expect(setupCardNode($dom)->getElementsByTagName('input')->length)->toBeGreaterThan(0);
    });

    it('answers the same way in Arabic: the message and the mistakes are in Arabic', function () {
        setupCardOwner(user: ['locale' => 'ar']);

        $this->from('/app')->put('/app/setup', ['name' => ''])->assertSessionHasErrors(['name' => __('Enter the name of your business.', [], 'ar')]);

        $this->put('/app/setup', ['name' => 'مؤسسة الفارس', 'country' => 'QA'])
            ->assertSessionHas('status', __('Saved. Receipts and statements will carry this name.', [], 'ar'));
    });
});

describe('a document asked for before the name', function () {
    it('goes back to the dashboard, where the card waits next to the message', function () {
        [$owner, $tenant] = setupCardOwner();
        $contract = openContract($tenant);
        $payment = app(RecordPayment::class)->handle($contract, '100.00', 'cash', by: $owner);

        $page = $this->followingRedirects()->get("/app/payments/{$payment->id}/receipt")->assertOk();

        $page->assertSee('Name your business first. It is printed on every receipt.');
        expect(setupCardNode(setupCardDom($page)))->toBeInstanceOf(DOMElement::class)
            ->and(setupCardText($page))->toContain('Name your business')->toContain('Save and continue');
    });

    it('lets the receipt through once the card is saved', function () {
        [$owner, $tenant] = setupCardOwner();
        $contract = openContract($tenant);
        $payment = app(RecordPayment::class)->handle($contract, '100.00', 'cash', by: $owner);

        $this->get("/app/payments/{$payment->id}/receipt")->assertRedirect(route('app.dashboard'));
        $this->put('/app/setup', ['name' => 'Al-Fares Electronics'])->assertRedirect(route('app.dashboard'));

        $this->get("/app/payments/{$payment->id}/receipt")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    });
});
