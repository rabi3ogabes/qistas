<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\CloseDay;
use App\Actions\ReopenDay;
use App\Documents\DocumentOptions;
use App\Documents\DocumentService;
use App\Documents\Templates\DayCloseSummary;
use App\Domain\DayClose\DayTotals;
use App\Domain\DayClose\SummaryText;
use App\Http\Requests\CloseDayRequest;
use App\Http\Requests\DocumentRequest;
use App\Models\DayClose;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Digits;
use App\Support\Format;
use App\Support\Money;
use App\Support\WorkspaceDay;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * The end-of-day close on the web workspace (daily-habit wave A, H4; ruling C7): the same ritual as the app, on the
 * same actions. Every rule is the actions' (CloseDay, ReopenDay), the figures are DayTotals' and the sentence is
 * SummaryText's; this carries what the person typed to them, puts what they answer where the person is looking, and
 * hands the view only strings to show (money is never worked out here or in the page, and never a float).
 *
 * The page is for the person who closes the day, so a viewer is refused it. All of it sits behind the `day_close`
 * feature; with the feature off the page is the plain "not available" page and the forms go back with the message.
 */
final class DayCloseController
{
    /** How many days of history a page lists. */
    private const HISTORY = 15;

    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * The workspace's today (or an earlier open day it was asked for): the figures to sign when it is open, the close
     * and the sentence to share when it is closed, and the history below.
     */
    public function show(Request $request): View|RedirectResponse
    {
        Gate::authorize('create', DayClose::class);
        $tenant = $this->tenant();
        $user = $this->user($request);

        $today = WorkspaceDay::of($tenant);
        $day = $this->chosen($request, $tenant, $today);
        if ($day === null) {
            // A link that names a day the page cannot show (to come, too long ago, not a date) opens today instead.
            return redirect()->route('app.day-close.show');
        }

        // The day's latest version, as the API reads it: a close that was reopened stands for nothing until it is closed again.
        $latest = DayClose::query()->with(['closedBy', 'reopenedBy'])->where('day', $day->date)->orderByDesc('version')->first();
        // The close that stands for the day: null while the day is open (never closed, or reopened and not yet closed again).
        $signed = $latest?->status === 'closed' ? $latest : null;
        $figures = DayTotals::for($tenant, $day);
        $currency = (string) $tenant->currency;

        $history = DayClose::query()->with('closedBy')->orderByDesc('day')->orderByDesc('version')
            ->paginate(self::HISTORY)->withQueryString()
            ->through(fn (DayClose $close): array => [
                'id' => $close->id,
                'day' => CarbonImmutable::parse($close->day, 'UTC'),
                'status' => $close->status,
                'difference' => $this->difference($close->difference, $currency),
                'closed_by' => $close->closedBy?->name,
                'summary_url' => route('app.day-close.summary', $close),
            ]);

        return view('app.day-close.show', [
            'day' => $day->date,
            'isToday' => $day->date === $today->date,
            'currency' => $currency,
            'figures' => $figures,
            'close' => $latest,
            'closed' => $signed !== null,
            'closedAt' => $signed === null ? null : $tenant->localTime($signed->closed_at),
            'summary' => $signed === null ? null : SummaryText::for($signed, $tenant),
            'difference' => $signed === null ? null : $this->difference($signed->difference, $currency),
            'canReopen' => $signed !== null && $user->can('reopen', $signed),
            'minReason' => ReopenDay::MIN_REASON,
            'maxReason' => ReopenDay::MAX_REASON,
            'maxNote' => CloseDayRequest::NOTE_MAX,
            'history' => $history,
            'earliest' => CarbonImmutable::parse($today->date, 'UTC')->subDays(CloseDay::DAYS_BACK)->format('Y-m-d'),
            'latestDay' => $today->date,
            // What the live difference needs: the cash the day expects as the server worked it out, and the words to say.
            'config' => [
                'expected' => $figures['expected_cash'],
                'currency' => $currency,
                'locale' => app()->getLocale().'-u-nu-latn',
                'initial' => (string) $request->old('counted_cash', ''),
                'messages' => [
                    'difference' => __('Difference'),
                    'balanced' => __('Balanced'),
                    'short' => __('Short by :amount'),
                    'over' => __('Over by :amount'),
                ],
            ],
        ]);
    }

    /** Closes the day the page showed (the form carries it, so a close after midnight still closes the day that was counted). */
    public function store(CloseDayRequest $request, CloseDay $closeDay): RedirectResponse
    {
        $tenant = $this->tenant();
        $day = $request->day($tenant);

        // The amount goes to the action as it was typed, but an Arabic or Urdu keyboard's digits are read as every other form reads them.
        $closeDay->handle($tenant, $day, Digits::toAscii($request->countedCash()), $request->note(), $this->user($request));

        return redirect()->to($this->page($tenant, $day->date))->with('status', __('The day is closed.'));
    }

    /** Reopens a closed day, with a reason; the action says how long the reason must be and what is wrong, on `reason` or `day`. */
    public function reopen(Request $request, DayClose $close, ReopenDay $reopenDay): RedirectResponse
    {
        Gate::authorize('reopen', $close);
        $data = $request->validate(['reason' => ['nullable', 'string']]);

        $reopenDay->handle($close, (string) ($data['reason'] ?? ''), $this->user($request));

        return redirect()->to($this->page($this->tenant(), $close->day))
            ->with('status', __('The day is open again. Close it again when the figures are right.'));
    }

    /** The close as a signed PDF, opened in the browser like the other documents (and counted against the plan's allowance like them). */
    public function summary(DocumentRequest $request, DayClose $close, DocumentService $documents): Response
    {
        Gate::authorize('view', $close);
        $tenant = $this->tenant();
        $user = $this->user($request);

        $issued = $documents->issue(new DayCloseSummary($close), DocumentOptions::from($request->validated(), $tenant, $user), $tenant, $user);

        return response($issued['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$issued['filename'].'"',
            'X-Document-Code' => $issued['document']->verification_code,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The day the page is for: today, or the `day` asked for when it is a real date from the earliest day that can still
     * be closed up to today. Null for anything else.
     */
    private function chosen(Request $request, Tenant $tenant, WorkspaceDay $today): ?WorkspaceDay
    {
        $asked = $request->query('day');
        if ($asked === null) {
            return $today;
        }

        $earliest = CarbonImmutable::parse($today->date, 'UTC')->subDays(CloseDay::DAYS_BACK)->format('Y-m-d');
        if (! is_string($asked) || Validator::make(['day' => $asked], ['day' => ['date_format:Y-m-d']])->fails() || $asked > $today->date || $asked < $earliest) {
            return null;
        }

        return WorkspaceDay::on($tenant, $asked);
    }

    /** Where the page for [$date] is: the plain address for today, the day in the address for any other. */
    private function page(Tenant $tenant, string $date): string
    {
        return route('app.day-close.show', $date === WorkspaceDay::of($tenant)->date ? [] : ['day' => $date]);
    }

    /**
     * A difference in plain words (balanced, short by, over by) and the tone to show it in, from the string the close kept.
     *
     * @return array{tone: 'ok'|'bad'|'warn', words: string}
     */
    private function difference(string $difference, string $currency): array
    {
        return match (Money::cmp($difference, '0')) {
            0 => ['tone' => 'ok', 'words' => __('Balanced')],
            1 => ['tone' => 'warn', 'words' => __('Over by :amount', ['amount' => Format::money($difference, $currency)])],
            default => ['tone' => 'bad', 'words' => __('Short by :amount', ['amount' => Format::money(ltrim($difference, '-'), $currency)])],
        };
    }

    private function tenant(): Tenant
    {
        return $this->current->get() ?? abort(404);
    }

    private function user(Request $request): User
    {
        return $request->user() ?? abort(401);
    }
}
