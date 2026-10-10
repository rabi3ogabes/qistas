<?php

namespace App\Documents;

use App\Models\BusinessAsset;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Audit;
use App\Support\ImageSanitizer;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Who the documents come from (Win Plan PP8): the shop's name in Arabic and in English, phone, address, commercial
 * registration and VAT numbers, a footer line, its logo and its signature. The words are one row in tenant_settings; the
 * pictures are re-encoded, scaled down and kept in the database (see business_assets). Every change is audited.
 */
final class BusinessProfile
{
    public const KEY = 'business_profile';

    /** Each field and the longest it may be. */
    public const FIELDS = ['name_ar' => 120, 'name_en' => 120, 'phone' => 40, 'address' => 255, 'cr_number' => 40, 'vat_number' => 40, 'footer' => 255];

    /** slot => [the widest, the tallest] it ever needs to be on a page, and the types it takes. */
    public const SLOTS = [
        'logo' => ['size' => [600, 240], 'mimes' => ['image/jpeg', 'image/png']],
        // A signature is drawn on a screen and keeps its transparent background.
        'signature' => ['size' => [600, 240], 'mimes' => ['image/png']],
    ];

    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @param  array<string, string|null>  $fields */
    private function __construct(private readonly Tenant $tenant, public readonly array $fields) {}

    public static function for(Tenant $tenant): self
    {
        $stored = self::inWorkspace($tenant, fn () => TenantSetting::where('key', self::KEY)->value('value'));

        return new self($tenant, self::clean(is_array($stored) ? $stored : []));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, string|null>
     */
    private static function clean(array $value): array
    {
        $fields = [];
        foreach (self::FIELDS as $field => $length) {
            $text = trim((string) ($value[$field] ?? ''));
            $fields[$field] = $text === '' ? null : mb_substr($text, 0, $length);
        }

        return $fields;
    }

    /** The shop's name for a document in [$language]: its Arabic name for Arabic and Urdu, else its English one. */
    public function name(string $language): string
    {
        $arabic = in_array($language, (array) config('qistas.rtl_locales'), true);
        $first = $arabic ? 'name_ar' : 'name_en';
        $second = $arabic ? 'name_en' : 'name_ar';

        return $this->fields[$first] ?? $this->fields[$second] ?? $this->tenant->name;
    }

    /**
     * Saves the fields a validated request sent; a field not sent keeps its value, one sent empty is cleared.
     *
     * @param  array<string, mixed>  $fields
     */
    public function save(array $fields, User $by): self
    {
        $after = self::clean(array_merge($this->fields, array_intersect_key($fields, self::FIELDS)));

        if ($after !== $this->fields) {
            self::inWorkspace($this->tenant, function () use ($after, $by): void {
                $row = TenantSetting::updateOrCreate(['key' => self::KEY], ['value' => $after]);
                Audit::record('settings.changed', $row, ['key' => self::KEY, 'before' => $this->fields, 'after' => $after], $this->tenant->id, $by->id);
            });
        }

        return new self($this->tenant, $after);
    }

    public function logo(): ?BusinessAsset
    {
        return $this->asset('logo');
    }

    public function signature(): ?BusinessAsset
    {
        return $this->asset('signature');
    }

    private function asset(string $slot): ?BusinessAsset
    {
        return self::inWorkspace($this->tenant, fn () => BusinessAsset::where('slot', $slot)->first());
    }

    /**
     * Keeps a new logo or signature in place of the old one. What the file IS comes from its bytes; it is drawn again
     * without any hidden data and scaled down to what a page needs.
     *
     * @throws ValidationException (field `file`) when it is not an allowed picture
     * @throws InvalidArgumentException when the slot is not one of SLOTS
     */
    public function storeAsset(string $slot, UploadedFile|string $file, User $by): BusinessAsset
    {
        $rules = self::SLOTS[$slot] ?? throw new InvalidArgumentException("There is no picture slot [{$slot}].");
        $bytes = is_string($file) ? $file : $this->uploaded($file);

        if (strlen($bytes) > self::MAX_BYTES) {
            throw $this->refuse(__('This picture is larger than :mb MB.', ['mb' => self::MAX_BYTES / 1024 / 1024]));
        }

        $mime = $bytes === '' ? '' : (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, $rules['mimes'], true)) {
            throw $this->refuse($slot === 'signature' ? __('A signature must be a PNG picture.') : __('This type of file is not allowed here.'));
        }

        [$maxWidth, $maxHeight] = $rules['size'];
        $clean = ImageSanitizer::clean($bytes, $mime, $maxWidth, $maxHeight) ?? throw $this->refuse(__('This file could not be read as a picture.'));
        $size = getimagesizefromstring($clean) ?: throw $this->refuse(__('This file could not be read as a picture.'));

        return self::inWorkspace($this->tenant, function () use ($slot, $mime, $size, $clean, $by): BusinessAsset {
            $asset = BusinessAsset::firstOrNew(['slot' => $slot]);
            $asset->forceFill([
                'mime' => $mime,
                'width' => $size[0],
                'height' => $size[1],
                'size' => strlen($clean),
                'sha256' => hash('sha256', $clean),
                'data' => base64_encode($clean),
                'created_by_user_id' => $by->getKey(),
            ])->save();

            Audit::record('business.'.$slot.'.changed', $asset, ['size' => strlen($clean)], $this->tenant->id, $by->id);

            return $asset;
        });
    }

    public function removeAsset(string $slot, User $by): void
    {
        self::inWorkspace($this->tenant, function () use ($slot, $by): void {
            $asset = BusinessAsset::where('slot', $slot)->first();
            if ($asset !== null) {
                $asset->delete();
                Audit::record('business.'.$slot.'.removed', $asset, [], $this->tenant->id, $by->id);
            }
        });
    }

    /**
     * What the API and the settings page show: the fields, and each picture as a source a page can show directly.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return $this->fields + ['logo' => $this->logo()?->dataUri(), 'signature' => $this->signature()?->dataUri()];
    }

    private function uploaded(UploadedFile $file): string
    {
        if (! $file->isValid() || ($path = $file->getRealPath()) === false) {
            throw $this->refuse(__('The file could not be uploaded. Please try again.'));
        }

        return (string) file_get_contents($path);
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function inWorkspace(Tenant $tenant, callable $callback): mixed
    {
        return app(CurrentTenant::class)->use($tenant, fn () => $callback());
    }
}
