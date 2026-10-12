<?php

namespace App\Import;

use App\Domain\Schedule\ScheduleGenerator;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Customer;
use App\Models\Tenant;
use App\Reminders\WhatsApp;
use App\Rules\PhoneNumber;
use App\Support\Digits;
use App\Support\Money;
use App\Support\NationalId;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Validator;

/**
 * What an import would do, row by row, without doing it (Win Plan K9, decision D8): the trial run. Each row is read as a
 * customer and, when it has amounts, a contract. Old balances become opening lines: with a plan (instalments and a
 * first due date) a scheduled contract whose paid part is a payment dated on the import day; without one, an open
 * contract with the balance as its opening line. Every problem is named by its column. A phone already on the books
 * adds to that customer; the same phone twice in the file is a problem; the plan's customer limit is counted in order.
 */
final class ImportPlan
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $cells
     * @param  array<int, string|null>  $mapping  column index => field
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int|string>}
     */
    public static function make(Tenant $tenant, array $headers, array $cells, array $mapping, ?CarbonInterface $today = null): array
    {
        return app(CurrentTenant::class)->use($tenant, fn () => (new self($tenant, $headers, $mapping, $today))->plan($cells));
    }

    /** @var array<string, int> field => column index */
    private array $columns = [];

    private CarbonImmutable $today;

    /**
     * @param  list<string>  $headers
     * @param  array<int, string|null>  $mapping
     */
    private function __construct(private readonly Tenant $tenant, private readonly array $headers, array $mapping, ?CarbonInterface $today)
    {
        foreach ($mapping as $index => $field) {
            if ($field !== null && in_array($field, Fields::keys(), true) && ! isset($this->columns[$field])) {
                $this->columns[$field] = (int) $index;
            }
        }
        $this->today = CarbonImmutable::parse(($today ?? $tenant->localTime(now()))->format('Y-m-d'));
    }

    /**
     * @param  list<list<string>>  $cells
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int|string>}
     */
    private function plan(array $cells): array
    {
        $features = Entitlements::for($this->tenant);
        $customers = $features->check(Feature::Customers);
        $room = $customers->limit() === null ? PHP_INT_MAX : max(0, $customers->limit() - (int) $customers->used());
        $openContracts = $features->check(Feature::OpenContracts)->enabled();
        $maxCount = $features->check(Feature::FlexibleSchedules)->enabled() ? ScheduleGenerator::MAX_COUNT : ScheduleGenerator::BASIC_MAX_COUNT;

        $known = [];
        foreach (Customer::query()->get(['id', 'phone']) as $customer) {
            $key = $this->phoneKey((string) $customer->phone);
            if ($key !== null) {
                $known[$key] ??= $customer->id;
            }
        }

        $seen = [];
        $rows = [];
        foreach ($cells as $index => $cell) {
            $row = $this->row($index + 2, $cell, $openContracts, $maxCount);

            $key = $row['phone'] === null ? null : $this->phoneKey($row['phone']);
            if ($key !== null && isset($seen[$key])) {
                $row['problems'][] = $this->problem('phone', __('Same phone as row :row.', ['row' => $seen[$key]]));
            } elseif ($key !== null) {
                $seen[$key] = $row['row'];
            }

            if ($key !== null && isset($known[$key])) {
                $row['customer_id'] = $known[$key];
                $row['action'] = $row['contract'] === null ? 'skip' : 'existing';
                $row['note'] = $row['contract'] === null ? __('Already a customer, with nothing to add.') : __('Already a customer: the contract is added to them.');
            }

            if ($row['problems'] === [] && $row['action'] === 'new') {
                if ($room <= 0) {
                    $row['problems'][] = $this->problem('name', __('Your plan keeps :count customers. Upgrade to bring the rest.', ['count' => (int) $customers->limit()]));
                } else {
                    $room--;
                }
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'summary' => self::summary($rows)];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int|string>
     */
    public static function summary(array $rows): array
    {
        $ready = array_values(array_filter($rows, fn (array $row) => $row['problems'] === [] && $row['action'] !== 'skip'));

        return [
            'rows' => count($rows),
            'ready' => count($ready),
            'problems' => count(array_filter($rows, fn (array $row) => $row['problems'] !== [])),
            'skipped' => count($rows) - count($ready),
            'customers' => count(array_filter($ready, fn (array $row) => $row['action'] === 'new')),
            'contracts' => count(array_filter($ready, fn (array $row) => $row['contract'] !== null)),
            'balance' => array_reduce($ready, fn (string $sum, array $row) => Money::add($sum, $row['contract']['balance'] ?? '0', 2), '0.00'),
        ];
    }

    /**
     * @param  list<string>  $cell
     * @return array<string, mixed>
     */
    private function row(int $number, array $cell, bool $openContracts, int $maxCount): array
    {
        $raw = [];
        foreach ($this->columns as $field => $index) {
            $value = trim((string) ($cell[$index] ?? ''));
            $raw[$field] = $value === '' ? null : $value;
        }

        $problems = [];
        $name = $raw['name'] ?? null;
        if ($name === null) {
            $problems[] = $this->problem('name', __('Add the customer’s name.'));
        }

        $phone = isset($raw['phone']) ? Digits::toAscii($raw['phone']) : null;
        if ($phone === null) {
            $problems[] = $this->problem('phone', __('Add a phone number.'));
        } elseif (! $this->isPhone($phone)) {
            $problems[] = $this->problem('phone', __('This is not a phone number.'));
        }

        $money = [];
        foreach (Fields::MONEY as $field) {
            if (isset($raw[$field])) {
                $money[$field] = Values::money($raw[$field]);
                if ($money[$field] === null || Money::isNegative($money[$field])) {
                    $problems[] = $this->problem($field, __('Write the amount in figures, such as 1200.'));
                }
            }
        }

        $count = null;
        if (isset($raw['installments'])) {
            $count = Values::count($raw['installments']);
            if ($count === null || $count < 1) {
                $problems[] = $this->problem('installments', __('Write the number of instalments as a whole number.'));
            } elseif ($count > $maxCount) {
                $problems[] = $this->problem('installments', __('Most instalments: :count.', ['count' => $maxCount]));
            }
        }

        $dates = [];
        foreach (Fields::DATES as $field) {
            if (isset($raw[$field])) {
                $dates[$field] = Values::date($raw[$field]);
                if ($dates[$field] === null) {
                    $problems[] = $this->problem($field, __('Write the date as 2026-03-15 or 15/03/2026.'));
                }
            }
        }

        // Only plan a contract from what could be read: an unreadable amount is already a problem.
        $contract = null;
        if ($problems === [] || ! $this->hasValueProblem($problems)) {
            [$contract, $more] = $this->contract($money, $count, $dates, $raw['item'] ?? null, $openContracts);
            $problems = [...$problems, ...$more];
        }

        $nationalId = isset($raw['national_id']) ? Digits::toAscii($raw['national_id']) : null;

        return [
            'row' => $number,
            'name' => $name === null ? null : mb_substr($name, 0, 255),
            'phone' => $phone,
            'national_id' => NationalId::mask($nationalId),
            'customer' => [
                'name' => $name === null ? null : mb_substr($name, 0, 255),
                'phone' => $phone,
                'national_id' => $nationalId === null ? null : mb_substr($nationalId, 0, 40),
                'address' => isset($raw['address']) ? mb_substr($raw['address'], 0, 1000) : null,
                'notes' => isset($raw['notes']) ? mb_substr($raw['notes'], 0, 5000) : null,
            ],
            'customer_id' => null,
            'action' => 'new',
            'contract' => $contract,
            'problems' => $problems,
            'note' => $contract === null && $money !== [] && $problems === [] ? __('Paid in full: no contract is opened.') : null,
        ];
    }

    /**
     * @param  array<string, string|null>  $money
     * @param  array<string, string|null>  $dates
     * @return array{0: array<string, mixed>|null, 1: list<array{column: string, field: string, message: string}>}
     */
    private function contract(array $money, ?int $count, array $dates, ?string $item, bool $openContracts): array
    {
        $total = $money['total'] ?? null;
        $paid = $money['paid'] ?? null;
        $balance = $money['balance'] ?? null;
        $amount = $money['installment_amount'] ?? null;
        $item = $item === null ? null : mb_substr($item, 0, 120);

        if ($count !== null || $amount !== null) {
            // Only reached without a count, so the instalment amount is there.
            $count ??= ($total !== null && Money::isPositive((string) $amount)) ? (int) ceil((float) $total / (float) $amount) : null;
            $total ??= ($amount !== null && $count !== null) ? Money::mul($amount, (string) $count, 2) : null;
            if ($total === null || $count === null) {
                return [null, [$this->problem($total === null ? 'total' : 'installments', __('Add the total and the number of instalments.'))]];
            }
            if (! isset($dates['first_due'])) {
                return [null, [$this->problem('first_due', __('Add the date of the first instalment.'))]];
            }

            $paid ??= $balance === null ? '0.00' : Money::sub($total, $balance, 2);
            if (Money::isNegative($paid)) {
                return [null, [$this->problem('balance', __('The balance is more than the total.'))]];
            }
            if (Money::cmp($paid, $total) > 0) {
                return [null, [$this->problem('paid', __('What was paid is more than the total.'))]];
            }

            $firstDue = CarbonImmutable::parse($dates['first_due']);
            $start = isset($dates['start_date']) ? CarbonImmutable::parse($dates['start_date']) : ($firstDue->greaterThan($this->today) ? $this->today : $firstDue->subMonthNoOverflow());

            return [[
                'type' => 'scheduled',
                'total' => Money::add($total, '0', 2),
                'paid' => Money::add($paid, '0', 2),
                'balance' => Money::sub($total, $paid, 2),
                'installments' => $count,
                'first_due' => $firstDue->format('Y-m-d'),
                'start' => $start->min($firstDue)->format('Y-m-d'),
                'item' => $item,
            ], []];
        }

        $owed = $balance ?? ($total === null ? null : Money::sub($total, $paid ?? '0', 2));
        if ($owed === null || Money::isZero($owed)) {
            return [null, []];
        }
        if (Money::isNegative($owed)) {
            return [null, [$this->problem('paid', __('What was paid is more than the total.'))]];
        }
        if (! $openContracts) {
            return [null, [$this->problem($balance !== null ? 'balance' : 'total', __('This balance has no instalments. Add the number of instalments and the next due date.'))]];
        }

        return [['type' => 'open', 'total' => null, 'paid' => null, 'balance' => Money::add($owed, '0', 2), 'installments' => null, 'first_due' => null, 'start' => $this->today->format('Y-m-d'), 'item' => $item], []];
    }

    /** @param  list<array{column: string, field: string, message: string}>  $problems */
    private function hasValueProblem(array $problems): bool
    {
        foreach ($problems as $problem) {
            if (in_array($problem['field'], [...Fields::MONEY, ...Fields::DATES, 'installments'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{column: string, field: string, message: string} */
    private function problem(string $field, string $message): array
    {
        $column = isset($this->columns[$field]) ? ($this->headers[$this->columns[$field]] ?? Fields::label($field)) : Fields::label($field);

        return ['column' => $column, 'field' => $field, 'message' => $message];
    }

    private function isPhone(string $phone): bool
    {
        return Validator::make(['phone' => $phone], ['phone' => [new PhoneNumber]])->passes();
    }

    /** The same number written two ways ("+966 50…", "050…") is the same phone. */
    private function phoneKey(string $phone): ?string
    {
        return WhatsApp::number(Digits::toAscii($phone), (string) $this->tenant->country);
    }
}
