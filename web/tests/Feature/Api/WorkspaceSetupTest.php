<?php

use App\Actions\RecordPayment;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
| Go-live basics, the first-run card (spec 2026-11-01 §3.3): the owner confirms the business name once, and the place
| the sign-up found (country, currency, time zone) or changes it. The place can change only while the workspace has no
| contract and no transaction, because it decides the currency and every due date. PUT /api/v1/workspace/setup, and the
| `setup` block of the account (GET /api/v1/me) the screens read.
*/

const WORKSPACE_SETUP_URL = '/api/v1/workspace/setup';

/**
 * The owner of a workspace a new sign-up leaves behind: a placeholder name, Saudi Arabia as a guess, not confirmed.
 *
 * @param  array<string, mixed>  $tenant
 * @return array{0: User, 1: Tenant}
 */
function workspaceSetupOwner(array $tenant = []): array
{
    return apiOwner($tenant + [
        'name' => 'Business of Layla', 'country' => 'SA', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh',
        'country_source' => 'default', 'setup_confirmed_at' => null,
    ]);
}

/** Everything the card can change, as the database holds it. */
function workspaceSetupState(Tenant $tenant): array
{
    $fresh = $tenant->fresh();

    return [
        'name' => $fresh->name, 'country' => $fresh->country, 'currency' => $fresh->currency, 'timezone' => $fresh->timezone,
        'country_source' => $fresh->country_source, 'confirmed' => $fresh->setup_confirmed_at?->toIso8601String(),
    ];
}

/** @return Collection<int, AuditLog> */
function workspaceSetupAudits(Tenant $tenant): Collection
{
    return AuditLog::query()->where('tenant_id', $tenant->id)->where('action', 'workspace.setup_confirmed')->get();
}

/** A contract and nothing else. */
function workspaceSetupWithContract(Tenant $tenant): void
{
    openContract($tenant);
}

/**
 * A payment and nothing else: the contract it was taken on now belongs to another workspace, the way a contract moved
 * by hand or by an old clean-up could leave the money behind. The lock is about money, so a transaction alone must hold
 * it. A plain UPDATE of the contract's workspace (the ledger's foreign keys are simple, with no composite key and no
 * trigger on them), so it behaves the same on SQLite and on PostgreSQL.
 */
function workspaceSetupOnlyATransaction(Tenant $tenant, User $by): void
{
    $contract = openContract($tenant);
    app(RecordPayment::class)->handle($contract, '50.00', 'cash', by: $by);

    $elsewhere = Tenant::factory()->create();
    DB::table('contracts')->where('id', $contract->id)->update(['tenant_id' => $elsewhere->id]);

    expect(DB::table('contracts')->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(DB::table('transactions')->where('tenant_id', $tenant->id)->count())->toBe(1);
}

/** Put the locked workspace in the state the data set names. */
function workspaceSetupLock(string $history, Tenant $tenant, User $by): void
{
    $history === 'a contract' ? workspaceSetupWithContract($tenant) : workspaceSetupOnlyATransaction($tenant, $by);
}

function workspaceSetupSend(array $body): TestResponse
{
    return test()->putJson(WORKSPACE_SETUP_URL, $body);
}

describe('the owner confirms', function () {
    it('saves the name, confirms the workspace and answers with the account’s tenant', function () {
        [, $tenant] = workspaceSetupOwner();
        $this->travelTo('2026-11-02 09:30:00');

        $response = workspaceSetupSend(['name' => '  Al-Fares Electronics  '])->assertOk();

        expect(workspaceSetupState($tenant))->toBe([
            'name' => 'Al-Fares Electronics', 'country' => 'SA', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh',
            'country_source' => 'default', 'confirmed' => '2026-11-02T09:30:00+00:00',
        ]);
        expect($tenant->fresh()->isSetUp())->toBeTrue()
            ->and(array_keys($response->json('data')))->toBe(['tenant'])
            ->and($response->json('data.tenant.name'))->toBe('Al-Fares Electronics')
            ->and($response->json('data.tenant.setup.confirmed'))->toBeTrue()
            ->and($response->json('data.tenant'))->toBe($this->getJson('/api/v1/me')->json('data.tenant'));
    });

    it('records who confirmed, with the name, country, currency and time zone and nothing else', function () {
        [$user, $tenant] = workspaceSetupOwner();

        workspaceSetupSend(['name' => 'Al-Fares Electronics', 'country' => 'QA', 'timezone' => 'Asia/Qatar'])->assertOk();

        $audit = workspaceSetupAudits($tenant)->sole();
        expect($audit->user_id)->toBe($user->id)
            ->and($audit->subject_id)->toBe($tenant->id)
            ->and($audit->changes)->toBe(['name' => 'Al-Fares Electronics', 'country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar']);
    });

    it('keeps the place the sign-up found when only the name is sent', function () {
        [, $tenant] = workspaceSetupOwner(['country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar', 'country_source' => 'header']);

        workspaceSetupSend(['name' => 'Al-Fares Electronics'])->assertOk();

        expect(workspaceSetupState($tenant))->toMatchArray(['country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar', 'country_source' => 'header']);
        expect(workspaceSetupAudits($tenant)->sole()->changes)->toBe(['name' => 'Al-Fares Electronics', 'country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar']);
    });

    it('lets a confirmed workspace be renamed without losing the day it was confirmed, and records each save', function () {
        [, $tenant] = workspaceSetupOwner(['name' => 'Business of Layla']);
        $this->travelTo('2026-11-02 09:30:00');
        workspaceSetupSend(['name' => 'Al-Fares Electronics'])->assertOk();

        $this->travelTo('2026-11-05 14:00:00');
        workspaceSetupSend(['name' => 'Al-Fares Trading'])->assertOk()->assertJsonPath('data.tenant.name', 'Al-Fares Trading');

        expect($tenant->fresh()->name)->toBe('Al-Fares Trading')
            ->and($tenant->fresh()->setup_confirmed_at->format('Y-m-d H:i:s'))->toBe('2026-11-02 09:30:00');
        $audits = workspaceSetupAudits($tenant)->sortBy('created_at')->values();
        expect($audits)->toHaveCount(2)
            ->and($audits[0]->changes['name'])->toBe('Al-Fares Electronics')
            ->and($audits[1]->changes)->toBe(['name' => 'Al-Fares Trading', 'country' => 'SA', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh']);
    });

    it('renames a workspace that was confirmed long before the card existed', function () {
        [, $tenant] = apiOwner(['name' => 'Old Name', 'setup_confirmed_at' => '2026-03-04 09:15:00']);

        workspaceSetupSend(['name' => 'New Name'])->assertOk();

        expect($tenant->fresh()->name)->toBe('New Name')
            ->and($tenant->fresh()->setup_confirmed_at->format('Y-m-d H:i:s'))->toBe('2026-03-04 09:15:00');
    });

    it('keeps a name that looks like markup as the text it is', function () {
        [, $tenant] = workspaceSetupOwner();

        workspaceSetupSend(['name' => '<b>x</b>'])->assertOk()->assertJsonPath('data.tenant.name', '<b>x</b>');

        expect($tenant->fresh()->name)->toBe('<b>x</b>')
            ->and(workspaceSetupAudits($tenant)->sole()->changes['name'])->toBe('<b>x</b>');
    });

    it('takes nothing but the name and the place from the request', function () {
        [, $tenant] = workspaceSetupOwner();

        workspaceSetupSend([
            'name' => 'Al-Fares Electronics', 'currency' => 'USD', 'country_source' => 'header', 'status' => 'suspended',
            'is_test' => true, 'setup_confirmed_at' => '2001-01-01 00:00:00', 'slug' => 'taken', 'tenant_id' => 'x',
        ])->assertOk();

        $fresh = $tenant->fresh();
        expect($fresh->currency)->toBe('SAR')->and($fresh->country_source)->toBe('default')->and($fresh->status)->toBe('active')
            ->and($fresh->is_test)->toBeFalse()->and($fresh->slug)->not->toBe('taken')
            ->and($fresh->setup_confirmed_at->year)->not->toBe(2001);
    });

    it('changes only the signed-in workspace', function () {
        [, $mine] = workspaceSetupOwner();
        [, $theirs] = owner(['name' => 'Theirs', 'setup_confirmed_at' => null]);

        workspaceSetupSend(['name' => 'Mine'])->assertOk();

        expect($mine->fresh()->name)->toBe('Mine')->and($theirs->fresh()->name)->toBe('Theirs')->and($theirs->fresh()->isSetUp())->toBeFalse();
    });
});

describe('the place changes while no contract or transaction exists', function () {
    it('applies the country with its currency and zone and marks the country as chosen', function (string $country, ?string $zone, string $currency, string $expectedZone) {
        [, $tenant] = workspaceSetupOwner();

        $response = workspaceSetupSend(['name' => 'Al-Fares Electronics', 'country' => $country] + ($zone === null ? [] : ['timezone' => $zone]))->assertOk();

        expect(workspaceSetupState($tenant))->toMatchArray([
            'country' => strtoupper($country), 'currency' => $currency, 'timezone' => $expectedZone, 'country_source' => 'chosen',
        ])->and($tenant->fresh()->isSetUp())->toBeTrue();
        expect($response->json('data.tenant.setup'))->toMatchArray([
            'confirmed' => true, 'country' => strtoupper($country), 'currency' => $currency, 'timezone' => $expectedZone, 'can_change_region' => true,
        ]);
        expect(workspaceSetupAudits($tenant)->sole()->changes)->toBe(['name' => 'Al-Fares Electronics', 'country' => strtoupper($country), 'currency' => $currency, 'timezone' => $expectedZone]);
    })->with([
        'a zone from the table' => ['QA', null, 'QAR', 'Asia/Qatar'],
        'lower case' => ['ae', null, 'AED', 'Asia/Dubai'],
        'a different zone sent' => ['US', 'America/Chicago', 'USD', 'America/Chicago'],
        'the old zone resent' => ['US', 'Asia/Riyadh', 'USD', 'America/New_York'],
        'a euro country' => ['FR', null, 'EUR', 'Europe/Paris'],
        'a country with a zone of its own' => ['AE', 'Asia/Muscat', 'AED', 'Asia/Muscat'],
    ]);

    it('does not keep the old zone for a new country when the screen resends it, even one the workspace only inherits from its country', function () {
        [, $tenant] = workspaceSetupOwner(['timezone' => null]);

        workspaceSetupSend(['name' => 'Al-Fares Electronics', 'country' => 'AE', 'timezone' => 'Asia/Riyadh'])->assertOk()
            ->assertJsonPath('data.tenant.setup.timezone', 'Asia/Dubai');

        expect(workspaceSetupState($tenant))->toMatchArray(['country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai']);
    });

    it('changes only the time zone when the country is the same', function () {
        [, $tenant] = workspaceSetupOwner();

        workspaceSetupSend(['name' => 'Al-Fares Electronics', 'country' => 'SA', 'timezone' => 'Asia/Dubai'])->assertOk();

        expect(workspaceSetupState($tenant))->toMatchArray(['country' => 'SA', 'currency' => 'SAR', 'timezone' => 'Asia/Dubai', 'country_source' => 'default']);
    });

    it('does not mark the country as chosen when the owner sends the one it already has', function () {
        [, $tenant] = workspaceSetupOwner(['country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar', 'country_source' => 'language']);

        workspaceSetupSend(['name' => 'Al-Fares Electronics', 'country' => 'QA', 'timezone' => 'Asia/Qatar'])->assertOk();

        expect(workspaceSetupState($tenant))->toMatchArray(['country' => 'QA', 'timezone' => 'Asia/Qatar', 'country_source' => 'language']);
    });
});

describe('the place is locked once money exists', function () {
    it('refuses a different country and saves nothing, the name included', function (string $history) {
        [$user, $tenant] = workspaceSetupOwner();
        workspaceSetupLock($history, $tenant, $user);
        $before = workspaceSetupState($tenant);

        workspaceSetupSend(['name' => 'Al-Fares Electronics', 'country' => 'QA'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['country']]]);

        expect(workspaceSetupState($tenant))->toBe($before)->and($before['confirmed'])->toBeNull()
            ->and(workspaceSetupAudits($tenant))->toHaveCount(0);
    })->with(['a contract', 'only a transaction']);

    it('refuses a different time zone and saves nothing, the name included', function (string $history) {
        [$user, $tenant] = workspaceSetupOwner();
        workspaceSetupLock($history, $tenant, $user);
        $before = workspaceSetupState($tenant);

        workspaceSetupSend(['name' => 'Al-Fares Electronics', 'timezone' => 'Asia/Dubai'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['timezone']]]);

        expect(workspaceSetupState($tenant))->toBe($before)->and(workspaceSetupAudits($tenant))->toHaveCount(0);
    })->with(['a contract', 'only a transaction']);

    it('names both fields when both differ, and points to support', function () {
        [$user, $tenant] = workspaceSetupOwner();
        workspaceSetupLock('a contract', $tenant, $user);

        $fields = workspaceSetupSend(['name' => 'Al-Fares Electronics', 'country' => 'QA', 'timezone' => 'Asia/Qatar'])->assertStatus(422)->json('error.fields');

        expect(array_keys($fields))->toEqualCanonicalizing(['country', 'timezone'])
            ->and($fields['country'][0])->toContain('support')->and($fields['timezone'][0])->toContain('support');
    });

    it('accepts the same country and zone it already has, and saves the name', function (string $history, string $country, ?string $zone) {
        [$user, $tenant] = workspaceSetupOwner();
        workspaceSetupLock($history, $tenant, $user);

        workspaceSetupSend(['name' => 'Al-Fares Electronics', 'country' => $country] + ($zone === null ? [] : ['timezone' => $zone]))
            ->assertOk()->assertJsonPath('data.tenant.setup.can_change_region', false);

        expect(workspaceSetupState($tenant))->toMatchArray([
            'name' => 'Al-Fares Electronics', 'country' => 'SA', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'country_source' => 'default',
        ])->and($tenant->fresh()->isSetUp())->toBeTrue();
        expect(workspaceSetupAudits($tenant))->toHaveCount(1);
    })->with([
        'a contract, both' => ['a contract', 'SA', 'Asia/Riyadh'],
        'a contract, lower case' => ['a contract', 'sa', 'Asia/Riyadh'],
        'a contract, only the country' => ['a contract', 'SA', null],
        'a transaction, both' => ['only a transaction', 'SA', 'Asia/Riyadh'],
    ]);

    it('counts the zone a workspace falls back to as its own when none is stored', function () {
        [$user, $tenant] = workspaceSetupOwner(['timezone' => null]);
        workspaceSetupLock('a contract', $tenant, $user);

        workspaceSetupSend(['name' => 'Al-Fares Electronics', 'country' => 'SA', 'timezone' => 'Asia/Riyadh'])->assertOk();

        expect($tenant->fresh()->isSetUp())->toBeTrue()->and($tenant->fresh()->name)->toBe('Al-Fares Electronics');
    });

    it('lets the name be saved on its own', function () {
        [$user, $tenant] = workspaceSetupOwner();
        workspaceSetupLock('a contract', $tenant, $user);

        workspaceSetupSend(['name' => 'Al-Fares Electronics'])->assertOk();

        expect($tenant->fresh()->name)->toBe('Al-Fares Electronics')->and($tenant->fresh()->country)->toBe('SA');
    });
});

describe('who may confirm', function () {
    it('lets only the owner', function (string $role, int $status) {
        [, $tenant] = workspaceSetupOwner();
        apiMember($role, $tenant);

        $response = workspaceSetupSend(['name' => 'Al-Fares Electronics', 'country' => 'QA'])->assertStatus($status);

        if ($status === 403) {
            $response->assertJsonPath('error.code', 'forbidden');
            expect(workspaceSetupState($tenant))->toMatchArray(['name' => 'Business of Layla', 'country' => 'SA', 'confirmed' => null])
                ->and(workspaceSetupAudits($tenant))->toHaveCount(0);
        } else {
            expect($tenant->fresh()->isSetUp())->toBeTrue();
        }
    })->with([['owner', 200], ['manager', 403], ['accountant', 403], ['collector', 403], ['viewer', 403]]);

    it('needs a token', function () {
        $this->putJson(WORKSPACE_SETUP_URL, ['name' => 'Al-Fares Electronics'])->assertUnauthorized();
    });
});

describe('what is refused', function () {
    it('wants a name of one to 255 characters that is not only spaces', function (mixed $name) {
        [, $tenant] = workspaceSetupOwner();
        $before = workspaceSetupState($tenant);

        workspaceSetupSend(['name' => $name])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['name']]]);

        expect(workspaceSetupState($tenant))->toBe($before);
    })->with([
        'nothing' => [''],
        'spaces' => ['     '],
        'tabs and new lines' => ["\t \n "],
        'null' => [null],
        'a list' => [['Al-Fares']],
        'an empty list' => [[]],
        '256 characters' => [str_repeat('a', 256)],
        '256 Arabic letters' => [str_repeat('ش', 256)],
    ]);

    it('wants a name to be sent at all', function () {
        workspaceSetupOwner();

        workspaceSetupSend(['country' => 'QA'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name']]]);
    });

    it('accepts a name of 255 characters, trimmed first', function (string $name) {
        [, $tenant] = workspaceSetupOwner();

        workspaceSetupSend(['name' => '  '.$name.'  '])->assertOk();

        expect($tenant->fresh()->name)->toBe($name);
    })->with([
        'letters' => [str_repeat('a', 255)],
        'Arabic letters' => [str_repeat('ش', 255)],
        'one letter' => ['A'],
    ]);

    it('refuses a country that is not on the list', function (mixed $country) {
        [, $tenant] = workspaceSetupOwner();
        $before = workspaceSetupState($tenant);

        workspaceSetupSend(['name' => 'Al-Fares Electronics', 'country' => $country])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['country']]]);

        expect(workspaceSetupState($tenant))->toBe($before);
    })->with(['Palestine' => ['PS'], 'nowhere' => ['XX'], 'three letters' => ['USA'], 'one letter' => ['S'], 'digits' => ['12'], 'a name' => ['Qatar'], 'a list' => [['QA']]]);

    it('refuses a time zone that is not a real one', function (mixed $zone) {
        [, $tenant] = workspaceSetupOwner();
        $before = workspaceSetupState($tenant);

        workspaceSetupSend(['name' => 'Al-Fares Electronics', 'timezone' => $zone])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['timezone']]]);

        expect(workspaceSetupState($tenant))->toBe($before);
    })->with(['made up' => ['Mars/Olympus'], 'an offset' => ['+03:00'], 'a list' => [['Asia/Qatar']], 'a script' => ['<script>']]);

    it('treats an empty country or zone as not sent', function () {
        [, $tenant] = workspaceSetupOwner();

        workspaceSetupSend(['name' => 'Al-Fares Electronics', 'country' => '', 'timezone' => ''])->assertOk();

        expect(workspaceSetupState($tenant))->toMatchArray(['country' => 'SA', 'timezone' => 'Asia/Riyadh', 'country_source' => 'default']);
    });
});

describe('the setup block of the account', function () {
    it('carries the six keys with their types', function () {
        workspaceSetupOwner(['country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar', 'country_source' => 'header']);

        $setup = $this->getJson('/api/v1/me')->assertOk()->json('data.tenant.setup');

        expect(array_keys($setup))->toBe(['confirmed', 'country', 'currency', 'timezone', 'country_detected', 'can_change_region'])
            ->and($setup)->toBe([
                'confirmed' => false, 'country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar', 'country_detected' => true, 'can_change_region' => true,
            ]);
    });

    it('says whether the country was found from the visitor, and only for a listed country', function (?string $source, string $country, string $currency, bool $detected) {
        workspaceSetupOwner(['country' => $country, 'currency' => $currency, 'timezone' => null, 'country_source' => $source]);

        $setup = $this->getJson('/api/v1/me')->assertOk()->json('data.tenant.setup');

        expect($setup['country_detected'])->toBe($detected)->and($setup['country'])->toBe($country);
    })->with([
        'from the network' => ['header', 'QA', 'QAR', true],
        'from the browser language' => ['language', 'QA', 'QAR', true],
        'the default' => ['default', 'SA', 'SAR', false],
        'chosen by the owner' => ['chosen', 'QA', 'QAR', false],
        'never recorded' => [null, 'SA', 'SAR', false],
        'from the network, not listed' => ['header', 'PS', 'USD', false],
        'from the language, not listed' => ['language', 'PS', 'USD', false],
    ]);

    it('reads the zone a workspace uses, the country’s when none is stored', function () {
        workspaceSetupOwner(['country' => 'AE', 'currency' => 'AED', 'timezone' => null]);

        expect($this->getJson('/api/v1/me')->json('data.tenant.setup.timezone'))->toBe('Asia/Dubai');
    });

    it('is confirmed for a workspace the owner has confirmed', function () {
        apiOwner();

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenant.setup.confirmed', true);
    });

    it('lets the region change until a contract exists, and not after', function () {
        [, $tenant] = workspaceSetupOwner();

        $this->getJson('/api/v1/me')->assertJsonPath('data.tenant.setup.can_change_region', true);

        openContract($tenant);

        $this->getJson('/api/v1/me')->assertJsonPath('data.tenant.setup.can_change_region', false);
    });

    it('does not let the region change after a transaction alone', function () {
        [$user, $tenant] = workspaceSetupOwner();

        workspaceSetupOnlyATransaction($tenant, $user);

        $this->getJson('/api/v1/me')->assertJsonPath('data.tenant.setup.can_change_region', false);
    });

    it('looks only at its own workspace’s money', function () {
        [, $mine] = workspaceSetupOwner();
        [, $theirs] = owner();
        openContract($theirs);

        $this->getJson('/api/v1/me')->assertJsonPath('data.tenant.setup.can_change_region', true);
        expect($mine->fresh()->isSetUp())->toBeFalse();
    });
});

describe('throttling', function () {
    it('allows twenty a minute and then asks to wait', function () {
        [, $tenant] = workspaceSetupOwner();

        foreach (range(1, 20) as $attempt) {
            workspaceSetupSend(['name' => "Al-Fares {$attempt}"])->assertOk();
        }

        workspaceSetupSend(['name' => 'Al-Fares 21'])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        expect($tenant->fresh()->name)->toBe('Al-Fares 20');
    });
});
