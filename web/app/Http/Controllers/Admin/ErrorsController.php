<?php

namespace App\Http\Controllers\Admin;

use App\Errors\ErrorRecorder;
use App\Errors\ErrorTrend;
use App\Errors\ErrorTriage;
use App\Models\ErrorIssue;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Errors screens (error center design, section 7): what is breaking on the website, the API, the database and the phone
 * app, grouped into issues, and what platform staff have decided about each. Anyone on the platform team may look and change
 * a status; the `admin` middleware group lets nobody else in (a customer gets a 404).
 *
 * The tables hold platform-wide records with nothing in them that names a business or a person, so nothing here is read
 * without a scope on purpose. Every stored text is escaped by the views all the same.
 */
final class ErrorsController
{
    /** Issues on a page of the list. */
    private const PER_PAGE = 25;

    /** The days the list's sparkline draws, and the days before them that "growing" compares with. */
    private const SPARK_DAYS = 7;

    /** The days the detail page's timeline draws. */
    private const TIMELINE_DAYS = 30;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $issues = ErrorIssue::query()
            ->when($filters['source'], fn ($query, string $source) => $query->where('source', $source))
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['new'], fn ($query) => $query->newIssues())
            ->when($filters['back'], fn ($query) => $query->cameBack())
            ->orderByDesc('last_seen_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // One query for every issue on the page: the week for each sparkline, and the week before it for "growing".
        $trend = ErrorTrend::days($issues->getCollection()->modelKeys(), self::SPARK_DAYS + 1);
        $weeks = [];
        $growing = [];

        foreach ($issues as $issue) {
            $counts = $trend[$issue->id];
            $weeks[$issue->id] = array_slice($counts, 1);
            // Only an issue that is open can be growing: a resolved one reopens itself when it happens again.
            $growing[$issue->id] = $issue->status === ErrorIssue::STATUS_OPEN
                && ErrorTrend::growing($counts[self::SPARK_DAYS], array_slice($counts, 0, self::SPARK_DAYS));
        }

        $noise = ErrorTrend::noise(self::SPARK_DAYS);

        return view('admin.errors.index', [
            'issues' => $issues,
            'weeks' => $weeks,
            'growing' => $growing,
            'chips' => $this->chips($filters),
            'filtered' => $filters !== $this->defaults(),
            'noise' => $noise,
            'noiseTotals' => ['scanner' => array_sum(array_column($noise, 'scanner')), 'dropped' => array_sum(array_column($noise, 'dropped'))],
        ]);
    }

    public function show(ErrorIssue $issue): View
    {
        $people = array_values(array_filter([$issue->resolved_by, $issue->ignored_by]));

        return view('admin.errors.show', [
            'issue' => $issue,
            'samples' => $issue->samples()->limit(ErrorRecorder::SAMPLES)->get(),
            'days' => ErrorTrend::days([$issue->id], self::TIMELINE_DAYS)[$issue->id],
            'staff' => $people === [] ? collect() : User::query()->whereIn('id', $people)->pluck('name', 'id'),
        ]);
    }

    /**
     * Resolve, ignore or reopen (audited by ErrorTriage). Only the three statuses mean anything: the buttons send nothing
     * else, so anything else is a hand-made request and is refused with a 422 whatever it accepts.
     */
    public function status(Request $request, ErrorIssue $issue, ErrorTriage $triage): RedirectResponse
    {
        $status = $request->input('status');
        abort_unless(is_string($status) && in_array($status, ErrorIssue::STATUSES, true), 422);

        $staff = $request->user();

        if ($status === ErrorIssue::STATUS_RESOLVED) {
            $triage->resolve($issue, $staff);
            $message = __('Marked as resolved.');
        } elseif ($status === ErrorIssue::STATUS_IGNORED) {
            $triage->ignore($issue, $staff);
            $message = __('Ignored. New sightings of it are no longer counted.');
        } else {
            $triage->reopen($issue, $staff);
            $message = __('Reopened.');
        }

        return redirect()->route('admin.errors.show', $issue)->with('status', $message);
    }

    /**
     * What the list was asked for, with anything unknown turned back into the default.
     *
     * @return array{source: string|null, status: string, new: bool, back: bool}
     */
    private function filters(Request $request): array
    {
        $source = $request->query('source');
        $status = $request->query('status');

        return [
            'source' => is_string($source) && in_array($source, ErrorIssue::SOURCES, true) ? $source : null,
            'status' => is_string($status) && in_array($status, [...ErrorIssue::STATUSES, 'all'], true) ? $status : 'open',
            'new' => $request->boolean('new'),
            'back' => $request->boolean('back'),
        ];
    }

    /** @return array{source: string|null, status: string, new: bool, back: bool} */
    private function defaults(): array
    {
        return ['source' => null, 'status' => 'open', 'new' => false, 'back' => false];
    }

    /**
     * The filter links of the page, in three groups. Each link changes one filter and keeps the others; a link for what is
     * already chosen is marked, and the toggles turn themselves off when pressed again.
     *
     * @param  array{source: string|null, status: string, new: bool, back: bool}  $filters
     * @return array{status: list<array{label: string, href: string, on: bool}>, source: list<array{label: string, href: string, on: bool}>, show: list<array{label: string, href: string, on: bool}>}
     */
    private function chips(array $filters): array
    {
        $link = function (array $change) use ($filters): string {
            $query = array_merge($filters, $change);
            $query = [
                'source' => $query['source'],
                'status' => $query['status'] === 'open' ? null : $query['status'],
                'new' => $query['new'] ? 1 : null,
                'back' => $query['back'] ? 1 : null,
            ];

            return route('admin.errors.index', array_filter($query, fn ($value) => $value !== null));
        };

        $statuses = ErrorIssue::statusLabels() + ['all' => __('All')];
        $sources = ErrorIssue::sourceLabels();

        $chips = ['status' => [], 'source' => [['label' => __('All sources'), 'href' => $link(['source' => null]), 'on' => $filters['source'] === null]], 'show' => []];

        foreach ($statuses as $key => $label) {
            $chips['status'][] = ['label' => $label, 'href' => $link(['status' => $key]), 'on' => $filters['status'] === $key];
        }

        foreach ($sources as $key => $label) {
            $chips['source'][] = ['label' => $label, 'href' => $link(['source' => $key]), 'on' => $filters['source'] === $key];
        }

        $chips['show'][] = ['label' => __('New in 24 hours'), 'href' => $link(['new' => ! $filters['new']]), 'on' => $filters['new']];
        $chips['show'][] = ['label' => __('Came back'), 'href' => $link(['back' => ! $filters['back']]), 'on' => $filters['back']];

        return $chips;
    }
}
