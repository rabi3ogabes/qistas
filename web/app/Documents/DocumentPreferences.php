<?php

namespace App\Documents;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\CurrentTenant;

/**
 * How a workspace likes its documents (Win Plan PP8): the paper, the size of the text, which sections are on, and its
 * own words for a few terms ("Partner" instead of "Investor"). Every document starts from these; a person can change
 * them for one document without changing them for everyone. Kept as one row in tenant_settings.
 */
final class DocumentPreferences
{
    public const KEY = 'document_preferences';

    public const PAPERS = ['a4', 'a5'];

    public const TEXT_SIZES = ['small', 'normal', 'large'];

    /** Each section and whether it is on until someone turns it off. The cost is never on by default: it is the shop's. */
    public const SECTIONS = ['cost' => false, 'overdue' => true, 'schedule' => true, 'signature' => true];

    /** The terms a workspace may rename on its documents, with the word used when it has not. */
    public const WORDS = ['customer' => 'Customer', 'contract' => 'Contract', 'investor' => 'Investor', 'instalment' => 'Instalment'];

    public const WORD_LENGTH = 30;

    /**
     * @param  array<string, bool>  $sections
     * @param  array<string, string>  $wording  only the terms the workspace renamed
     */
    private function __construct(public readonly string $paper, public readonly string $text, public readonly array $sections, public readonly array $wording) {}

    public static function for(Tenant $tenant): self
    {
        $stored = app(CurrentTenant::class)->use($tenant, fn () => TenantSetting::where('key', self::KEY)->value('value'));

        return self::fromArray(is_array($stored) ? $stored : []);
    }

    /** @param  array<string, mixed>  $value */
    private static function fromArray(array $value): self
    {
        $sections = [];
        foreach (self::SECTIONS as $section => $default) {
            $sections[$section] = (bool) ($value['sections'][$section] ?? $default);
        }

        $wording = [];
        foreach (array_keys(self::WORDS) as $term) {
            $word = trim((string) ($value['wording'][$term] ?? ''));
            if ($word !== '') {
                $wording[$term] = mb_substr($word, 0, self::WORD_LENGTH);
            }
        }

        return new self(
            in_array($value['paper'] ?? null, self::PAPERS, true) ? $value['paper'] : 'a4',
            in_array($value['text'] ?? null, self::TEXT_SIZES, true) ? $value['text'] : 'normal',
            $sections,
            $wording,
        );
    }

    /**
     * Saves what a validated request sent (see DocumentPreferencesRequest) and writes the change to the audit log.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function save(Tenant $tenant, array $validated, User $by): self
    {
        $before = self::for($tenant);
        // Whatever was not sent stays as it was; a renamed term sent empty goes back to the usual word.
        $after = self::fromArray([
            'paper' => $validated['paper'] ?? $before->paper,
            'text' => $validated['text'] ?? $before->text,
            'sections' => array_merge($before->sections, (array) ($validated['sections'] ?? [])),
            'wording' => array_merge($before->wording, (array) ($validated['wording'] ?? [])),
        ]);

        if ($after->toArray() !== $before->toArray()) {
            app(CurrentTenant::class)->use($tenant, function () use ($tenant, $before, $after, $by): void {
                $row = TenantSetting::updateOrCreate(['key' => self::KEY], ['value' => $after->toArray()]);
                Audit::record('settings.changed', $row, ['key' => self::KEY, 'before' => $before->toArray(), 'after' => $after->toArray()], $tenant->id, $by->id);
            });
        }

        return $after;
    }

    /** @return array{paper: string, text: string, sections: array<string, bool>, wording: array<string, string>} */
    public function toArray(): array
    {
        return ['paper' => $this->paper, 'text' => $this->text, 'sections' => $this->sections, 'wording' => $this->wording];
    }
}
