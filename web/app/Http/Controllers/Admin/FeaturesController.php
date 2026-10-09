<?php

namespace App\Http\Controllers\Admin;

use App\Admin\FeatureCards;
use App\Entitlements\Feature;
use App\Entitlements\FeatureCatalogue;
use App\Entitlements\FeatureControl;
use App\Entitlements\FeatureControlException;
use App\Entitlements\PlatformState;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantOverride;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The admin's Feature control: every change to what the platform has on, off or in beta. Anyone on the platform team
 * may look; only a super admin may change (the 'manage-platform-features' gate). Each call answers JSON for the
 * screen's own script and redirects back with a message for a plain form, so the page works without JavaScript.
 */
final class FeaturesController
{
    public function __construct(
        private readonly FeatureControl $control,
        private readonly FeatureCards $cards,
        private readonly FeatureCatalogue $catalogue,
    ) {}

    public function index(Request $request): JsonResponse|View
    {
        $board = $this->cards->build();

        if ($request->expectsJson()) {
            return response()->json($board);
        }

        $switchable = $this->catalogue->switchable();

        return view('admin.features.index', [
            'board' => $board,
            'canChange' => Gate::allows('manage-platform-features'),
            'anySwitchable' => $switchable !== [],
            'anyAutomation' => array_any($switchable, fn (Feature $f) => $this->catalogue->touchesCustomers($f)),
        ]);
    }

    public function state(Request $request, string $key): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-platform-features');
        $feature = $this->feature($key);
        $data = $request->validate([
            'state' => ['required', Rule::enum(PlatformState::class)],
            'reason' => ['nullable', 'string', 'max:500'],
            'undo' => ['nullable', 'boolean'],
        ]);

        try {
            $dependents = $this->control->setState($feature, PlatformState::from($data['state']), $data['reason'] ?? null, $request->user(), (bool) ($data['undo'] ?? false));
        } catch (FeatureControlException $e) {
            return $this->refuse($request, $e, 'state');
        }

        return $this->done($request, __('Saved.'), ['data' => $this->cards->find($feature), 'dependents' => array_map(fn (Feature $f) => $f->value, $dependents)]);
    }

    public function plan(Request $request, string $key, string $plan): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-platform-features');
        $feature = $this->feature($key);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'limit' => ['nullable', 'integer', 'min:0']]);

        try {
            $this->control->setPlan($feature, Plan::where('key', $plan)->firstOrFail(), (bool) $data['enabled'], isset($data['limit']) ? (int) $data['limit'] : null, $request->user());
        } catch (FeatureControlException $e) {
            return $this->refuse($request, $e, 'limit');
        }

        return $this->done($request, __('Saved.'), ['data' => $this->cards->find($feature)]);
    }

    public function betaStore(Request $request, string $key): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-platform-features');
        $feature = $this->feature($key);
        $data = $request->validate(['workspace' => ['required', 'string', 'max:190'], 'reason' => ['nullable', 'string', 'max:500'], 'expires_at' => ['nullable', 'date', 'after:now']]);

        try {
            $tenant = $this->workspace($data['workspace']);
            $override = $this->control->grantBeta($feature, $tenant, (string) ($data['reason'] ?? ''), isset($data['expires_at']) ? now()->parse($data['expires_at']) : null, $request->user());
        } catch (FeatureControlException $e) {
            return $this->refuse($request, $e, 'workspace');
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'id' => $override->id,
                'workspace' => ['id' => $tenant->id, 'name' => $tenant->name],
                'reason' => $override->reason,
                'expires_at' => $override->expires_at?->toIso8601String(),
            ]], 201);
        }

        return back()->with('status', __('Saved.'));
    }

    public function betaDestroy(Request $request, string $key, string $override): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-platform-features');
        $feature = $this->feature($key);
        $grant = TenantOverride::where('feature_key', $feature->value)->whereKey($override)->firstOrFail();

        $this->control->revokeBeta($grant, $request->user());

        return $this->done($request, __('Saved.'), ['data' => $this->cards->find($feature)]);
    }

    public function presetPreview(string $preset): JsonResponse
    {
        try {
            return response()->json(['data' => $this->control->previewPreset($preset)]);
        } catch (FeatureControlException $e) {
            return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], 422);
        }
    }

    public function presetApply(Request $request, string $preset): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-platform-features');

        try {
            $this->control->applyPreset($preset, (string) $request->input('reason'), $request->user());
        } catch (FeatureControlException $e) {
            return $this->refuse($request, $e, 'reason');
        }

        return $this->done($request, __('Saved.'), ['data' => $this->control->previewPreset($preset)]);
    }

    public function pause(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-platform-features');

        try {
            $paused = $this->control->pauseAutomation((string) $request->input('reason'), $request->user());
        } catch (FeatureControlException $e) {
            return $this->refuse($request, $e, 'reason');
        }

        return $this->done($request, __('Saved.'), ['paused' => $paused]);
    }

    private function feature(string $key): Feature
    {
        return Feature::tryFrom($key) ?? abort(404);
    }

    /** A workspace by its id, or by the e-mail address of the person who runs it. */
    private function workspace(string $value): Tenant
    {
        $value = trim($value);

        if (Str::isUuid($value)) {
            return Tenant::find($value) ?? throw FeatureControlException::unknownWorkspace();
        }

        $owner = User::query()->whereRaw('lower(email) = ?', [Str::lower($value)])->first();

        return $owner?->primaryTenant() ?? throw FeatureControlException::unknownWorkspace();
    }

    /** @param  array<string, mixed>  $payload */
    private function done(Request $request, string $message, array $payload): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json($payload) : back()->with('status', $message);
    }

    private function refuse(Request $request, FeatureControlException $e, string $field): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], 422)
            : back()->withErrors([$field => $e->getMessage()])->withInput();
    }
}
