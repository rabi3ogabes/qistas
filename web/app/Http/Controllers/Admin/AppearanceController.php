<?php

namespace App\Http\Controllers\Admin;

use App\Models\AppearanceAsset;
use App\Models\AppearanceVersion;
use App\Models\User;
use App\Support\Locale;
use App\Theme\Appearance;
use App\Theme\AppearanceRefused;
use App\Theme\BrandImages;
use App\Theme\Color;
use App\Theme\PaletteReport;
use App\Theme\Presets;
use App\Theme\ThemeEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The Appearance page: the colours, the pictures and the welcome banners of the website, the web app and the Android
 * app. Anyone on the platform team may look; only a super admin changes (the 'manage-appearance' gate). Every write goes
 * through App\Theme\Appearance, which checks it; this controller only turns the form into its input and back.
 */
final class AppearanceController
{
    public function __construct(
        private readonly Appearance $appearance,
        private readonly BrandImages $images,
    ) {}

    public function index(Request $request): View
    {
        $draft = $this->appearance->draft();
        $chosen = $draft->pins()['light'] ?? [];
        $live = $this->appearance->live();
        $history = $this->appearance->history()->load('publishedBy:id,name');

        // Only what the page shows of each picture; the stored bytes stay in the database.
        $pictures = AppearanceAsset::query()->whereKey(array_values($draft->images()))->get(['id', 'slot', 'width', 'height'])->keyBy('slot');

        return view('admin.appearance.index', [
            'canChange' => Gate::allows('manage-appearance'),
            'chosen' => $chosen,
            'report' => PaletteReport::for($chosen),
            'factory' => ThemeEngine::BASE['light'],
            'pictures' => $pictures,
            'banners' => $draft->banners(),
            'languages' => Locale::options(),
            'live' => $live,
            'liveVersion' => $history->first(),
            'history' => $history,
            'unpublished' => $this->appearance->hasUnpublishedChanges(),
        ]);
    }

    /** The palette four colours would give, for the page's live preview and contrast readings. */
    public function palette(Request $request): JsonResponse
    {
        $hex = ['nullable', 'string', 'regex:/\A#[0-9A-Fa-f]{6}\z/'];
        $data = $request->validate(array_fill_keys(Appearance::COLOURS, $hex));
        $colours = array_map(fn (string $value): string => Color::normalise($value), array_filter($data, fn ($value) => is_string($value) && $value !== ''));

        if (isset($colours['bg']) && Color::luminance($colours['bg']) < 0.5) {
            throw ValidationException::withMessages(['bg' => __('Choose a light canvas: the dark mode is made from your main colour.')]);
        }

        return response()->json(['data' => PaletteReport::for($colours)]);
    }

    public function save(Request $request): RedirectResponse
    {
        Gate::authorize('manage-appearance');
        $this->saveForm($request);

        return redirect()->route('admin.appearance.index')->with('status', __('Draft saved. Publish when you are ready.'));
    }

    public function publish(Request $request): RedirectResponse
    {
        Gate::authorize('manage-appearance');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:200']])['note'] ?? null;
        $this->saveForm($request);

        try {
            $version = $this->appearance->publish($this->admin($request), $note);
        } catch (AppearanceRefused $e) {
            $pairs = implode(', ', array_map(fn (array $check): string => __($check['label']), $e->failing));

            return redirect()->route('admin.appearance.index')
                ->withErrors(['publish' => __('Not published: some words would still be hard to read (:pairs). Your changes are saved in the draft.', ['pairs' => $pairs])]);
        }

        return redirect()->route('admin.appearance.index')
            ->with('status', __('Published. Everyone sees version :version now.', ['version' => $version->version]))
            ->with('repaired', $version->repaired());
    }

    /** One picture, sent by the page's script as soon as it is chosen, so a large form never carries several at once. */
    public function picture(Request $request, string $slot): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-appearance');
        abort_unless(array_key_exists($slot, BrandImages::SLOTS), 404);
        $request->validate(['file' => ['required', 'file']]);
        $file = $request->file('file');
        $admin = $this->admin($request);

        $asset = DB::transaction(function () use ($file, $slot, $admin): AppearanceAsset {
            $asset = $this->images->store($file instanceof UploadedFile ? $file : throw ValidationException::withMessages(['file' => __('Choose one picture.')]), $slot, $admin);
            $this->appearance->saveDraft(['images' => [$slot => $asset->id]], $admin);

            return $asset;
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => ['id' => $asset->id, 'slot' => $slot, 'url' => $asset->url(), 'width' => $asset->width, 'height' => $asset->height]], 201);
        }

        return redirect()->route('admin.appearance.index')->with('status', __('Picture saved in the draft.'));
    }

    public function restore(Request $request, int $version): RedirectResponse
    {
        Gate::authorize('manage-appearance');
        $older = AppearanceVersion::query()->where('status', 'published')->where('version', $version)->firstOrFail();
        $new = $this->appearance->restore($older, $this->admin($request));

        return redirect()->route('admin.appearance.index')
            ->with('status', __('Version :old is back, published as version :new.', ['old' => $older->version, 'new' => $new->version]));
    }

    public function reset(Request $request): RedirectResponse
    {
        Gate::authorize('manage-appearance');
        $new = $this->appearance->resetLook($this->admin($request));

        return redirect()->route('admin.appearance.index')
            ->with('status', __('The Qistas look is back, published as version :version.', ['version' => $new->version]));
    }

    public function discard(Request $request): RedirectResponse
    {
        Gate::authorize('manage-appearance');
        $this->appearance->discardDraft($this->admin($request));

        return redirect()->route('admin.appearance.index')->with('status', __('Unpublished changes were thrown away.'));
    }

    /**
     * Saves the form into the draft: the words and colours first (so their mistakes are shown first), then any picture
     * sent with it. All or nothing: a refused picture leaves the draft as it was.
     *
     * @throws ValidationException
     */
    private function saveForm(Request $request): void
    {
        $admin = $this->admin($request);
        $input = array_intersect_key($request->only(['colours', 'banners']), array_flip(['colours', 'banners']));
        $preset = $request->input('preset');

        if (is_string($preset) && $preset !== '') {
            $input['colours'] = array_key_exists($preset, Presets::LIST)
                ? Presets::LIST[$preset]['colours']
                : throw ValidationException::withMessages(['preset' => __('Choose one of the presets offered.')]);
        }

        DB::transaction(function () use ($request, $input, $admin): void {
            $this->appearance->saveDraft($input, $admin);
            $images = [];

            foreach (array_keys(BrandImages::SLOTS) as $slot) {
                if ($request->boolean("remove.{$slot}")) {
                    $images[$slot] = null;
                }

                $file = $request->file("pictures.{$slot}");

                if ($file instanceof UploadedFile) {
                    try {
                        $images[$slot] = $this->images->store($file, $slot, $admin)->id;
                    } catch (ValidationException $e) {
                        throw ValidationException::withMessages(["pictures.{$slot}" => $e->errors()['file'] ?? [__('That picture could not be used.')]]);
                    }
                }
            }

            if ($images !== []) {
                $this->appearance->saveDraft(['images' => $images], $admin);
            }
        });
    }

    private function admin(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(403);
    }
}
