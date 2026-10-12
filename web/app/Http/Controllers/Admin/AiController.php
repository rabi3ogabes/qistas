<?php

namespace App\Http\Controllers\Admin;

use App\Import\Ai\AiSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Reading notebook photos and PDFs (Win Plan K9, decision D8): the Anthropic key the readings use and the model, set by
 * a super admin. The key is never shown back, only that one is saved. Also how many pages were read this month.
 */
final class AiController
{
    public function index(AiSettings $settings): View
    {
        Gate::authorize('manage-plans');

        return view('admin.ai.index', [
            'configured' => $settings->configured(),
            'model' => $settings->model(),
            'pages' => (int) DB::table('usage_counters')->where('feature_key', 'ai_reading')->where('period', now()->format('Y-m'))->sum('used'),
        ]);
    }

    public function update(Request $request, AiSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-plans');
        $data = $request->validate([
            'key' => ['nullable', 'string', 'max:400', 'regex:/^sk-ant-[A-Za-z0-9_\-]+$/'],
            'model' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9.\-]+$/'],
            'remove' => ['nullable', 'boolean'],
        ], ['key.regex' => __('An Anthropic key starts with sk-ant-.')]);

        if ($request->boolean('remove')) {
            $settings->setKey(null, $request->user());
        } elseif (($data['key'] ?? null) !== null) {
            $settings->setKey($data['key'], $request->user());
        }
        if (array_key_exists('model', $data)) {
            $settings->setModel($data['model'], $request->user());
        }

        return back()->with('status', __('AI reading settings saved.'));
    }
}
