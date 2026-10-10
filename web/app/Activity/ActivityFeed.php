<?php

namespace App\Activity;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * One business's activity log (Win Plan PP10): what its people did, newest first, as a line anyone reads ("Payment
 * recorded: SAR 150.00 on C-0001"), filtered by person, kind and day. Written as events rather than "who did", so no
 * language has to guess a person's gender. Platform staff actions, sign-ins and the like are not part of it.
 */
final class ActivityFeed
{
    /** Each kind and the actions it holds (by the start of their name). */
    public const KINDS = [
        'customers' => ['customer.'],
        'contracts' => ['contract.'],
        'payments' => ['payment.', 'charge.'],
        'investors' => ['investor.'],
        'products' => ['product.'],
        'team' => ['team.'],
        'settings' => ['settings.', 'workspace.', 'business.', 'export.', 'account.deletion'],
    ];

    /**
     * @param  array{user?: string|null, kind?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public static function page(Tenant $tenant, array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $prefixes = isset($filters['kind'], self::KINDS[$filters['kind']]) ? self::KINDS[$filters['kind']] : array_merge(...array_values(self::KINDS));
        $zone = $tenant->localTimezone();

        return AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where(function (Builder $query) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $query->orWhere('action', 'like', $prefix.'%');
                }
            })
            ->when(! empty($filters['user']), fn (Builder $query) => $query->where('user_id', $filters['user']))
            ->when(! empty($filters['from']), fn (Builder $query) => $query->where('created_at', '>=', CarbonImmutable::parse((string) $filters['from'], $zone)->startOfDay()->utc()))
            ->when(! empty($filters['to']), fn (Builder $query) => $query->where('created_at', '<=', CarbonImmutable::parse((string) $filters['to'], $zone)->endOfDay()->utc()))
            ->latest('created_at')->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    /** @return list<array{id: string, name: string}> the people of the business, to filter by */
    public static function people(Tenant $tenant): array
    {
        return $tenant->users()->orderBy('name')->get(['users.id', 'users.name'])
            ->map(fn (User $user) => ['id' => (string) $user->id, 'name' => (string) $user->name])->values()->all();
    }

    /**
     * One entry as the API and the page show it.
     *
     * @param  array<string, string>  $names  user id => name
     * @return array{id: string, at: string, action: string, kind: string|null, summary: string, person: array{id: string, name: string}|null}
     */
    public static function present(AuditLog $entry, Tenant $tenant, array $names): array
    {
        return [
            'id' => (string) $entry->id,
            'at' => $tenant->localTime($entry->created_at)->toIso8601String(),
            'action' => $entry->action,
            'kind' => self::kindOf($entry->action),
            'summary' => self::summary($entry, (string) $tenant->currency),
            // Done by Qistas itself (a nightly copy) when nobody is named; by someone who has since left, said so.
            'person' => $entry->user_id === null ? null : ['id' => $entry->user_id, 'name' => $names[$entry->user_id] ?? __('Someone no longer on the team')],
        ];
    }

    public static function kindOf(string $action): ?string
    {
        foreach (self::KINDS as $kind => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($action, $prefix)) {
                    return $kind;
                }
            }
        }

        return null;
    }

    /** What happened, in a line, in the reader's language. */
    public static function summary(AuditLog $entry, string $currency): string
    {
        $c = $entry->changes ?? [];
        $name = (string) ($c['name'] ?? $c['investor'] ?? '');
        $reference = (string) ($c['reference'] ?? '');
        $amount = isset($c['amount']) && is_numeric($c['amount']) ? Format::money(ltrim((string) $c['amount'], '-'), $currency) : '';
        $role = fn (?string $key): string => match ($key) {
            'owner' => __('Owner'), 'manager' => __('Manager'), 'accountant' => __('Accountant'), 'collector' => __('Collector'), 'viewer' => __('Viewer'),
            default => (string) $key,
        };

        return match ($entry->action) {
            'customer.created' => __('Customer added: :name', ['name' => $name]),
            'customer.updated' => __('Customer changed: :name', ['name' => $name]).self::fields($c['fields'] ?? []),
            'customer.deleted' => __('Customer removed: :name', ['name' => $name]),
            'customer.restored' => __('Customer brought back: :name', ['name' => $name]),
            'contract.created' => __('Contract opened: :reference', ['reference' => $reference]),
            'contract.cancelled' => __('Contract cancelled: :reference', ['reference' => $reference]),
            'contract.converted_to_open' => __('Contract made open: :reference', ['reference' => $reference]),
            'payment.recorded' => ($c['type'] ?? '') === 'down_payment'
                ? __('Down payment recorded: :amount on :reference', ['amount' => $amount, 'reference' => $reference])
                : __('Payment recorded: :amount on :reference', ['amount' => $amount, 'reference' => $reference]),
            'payment.voided' => __('Payment voided: :amount on :reference', ['amount' => $amount, 'reference' => $reference]),
            'charge.recorded' => __('Added to the balance: :amount on :reference', ['amount' => $amount, 'reference' => $reference]),
            'charge.voided' => __('Taken back off the balance: :amount on :reference', ['amount' => $amount, 'reference' => $reference]),
            'product.created' => __('Product added: :name', ['name' => $name]),
            'product.updated' => __('Product changed: :name', ['name' => $name]),
            'product.archived' => __('Product archived: :name', ['name' => $name]),
            'investor.created' => __('Investor added: :name', ['name' => $name]),
            'investor.updated' => __('Investor details changed'),
            'investor.deposit' => __('Money put in: :amount (:name)', ['amount' => $amount, 'name' => $name]),
            'investor.withdrawal' => __('Money taken out: :amount (:name)', ['amount' => $amount, 'name' => $name]),
            'investor.entry_reversed' => __('Investor entry reversed: :amount', ['amount' => $amount]),
            'team.member_invited' => __('Invitation sent: :role', ['role' => $role($c['role'] ?? null)]),
            'team.invitation_revoked' => __('Invitation withdrawn'),
            'team.invitation_accepted' => __('Joined the team: :role', ['role' => $role($c['role'] ?? null)]),
            'team.role_changed' => __('Role changed: :from to :to', ['from' => $role($c['role']['from'] ?? null), 'to' => $role($c['role']['to'] ?? null)]),
            'team.member_removed' => __('Removed from the team'),
            'workspace.app_lock_policy_changed' => __('Phone lock rule changed'),
            'business.logo.changed' => __('Logo changed'),
            'business.logo.removed' => __('Logo removed'),
            'business.signature.changed' => __('Signature changed'),
            'business.signature.removed' => __('Signature removed'),
            'export.created' => __('All the data downloaded (:format)', ['format' => strtoupper((string) ($c['format'] ?? 'xlsx'))]),
            'account.deletion_requested' => __('Deleting the business was asked for'),
            'account.deletion_cancelled' => __('Deleting the business was called off'),
            default => str_starts_with($entry->action, 'investor.') ? __('Investor entry: :amount (:name)', ['amount' => $amount, 'name' => $name]) : __('Settings changed'),
        };
    }

    /** @param  mixed  $fields  the names of the fields that changed */
    private static function fields(mixed $fields): string
    {
        $labels = [
            'name' => __('name'), 'phone' => __('phone'), 'phone_secondary' => __('second phone'), 'email' => __('email'),
            'national_id' => __('national ID'), 'address' => __('address'), 'notes' => __('notes'), 'job' => __('job'),
        ];
        $shown = array_map(fn (mixed $field) => $labels[$field] ?? (string) $field, is_array($fields) ? $fields : []);

        return $shown === [] ? '' : ' ('.implode(', ', $shown).')';
    }
}
