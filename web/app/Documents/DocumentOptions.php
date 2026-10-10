<?php

namespace App\Documents;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The choices for one document (Win Plan PP8): the workspace's preferences, with what the person asked for this time on
 * top. The cost section is only ever shown to someone who sees the business's money (not a collector), whatever was
 * asked. Built from input a request has already validated; anything unexpected falls back to the preference.
 */
final class DocumentOptions
{
    /** Receipts also print on till rolls. */
    public const RECEIPT_PAPERS = ['a4', 'a5', '80mm', '58mm'];

    /** The size of the body text, in points. */
    private const FONT_SIZES = ['small' => 9.0, 'normal' => 10.5, 'large' => 12.0];

    /**
     * @param  array<string, bool>  $sections
     * @param  array<string, string>  $wording
     */
    private function __construct(
        public readonly string $paper,
        public readonly string $text,
        public readonly array $sections,
        public readonly bool $summary,
        public readonly bool $compact,
        public readonly string $language,
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
        public readonly ?string $contract,
        public readonly ?string $investor,
        public readonly ?string $product,
        public readonly array $wording,
        private readonly bool $seesMoney,
        public readonly string $timezone,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function from(array $input, Tenant $tenant, ?User $by): self
    {
        $preferences = DocumentPreferences::for($tenant);

        $sections = $preferences->sections;
        foreach ((array) ($input['sections'] ?? []) as $section => $on) {
            if (array_key_exists((string) $section, $sections)) {
                $sections[(string) $section] = filter_var($on, FILTER_VALIDATE_BOOL);
            }
        }

        $date = fn (string $key): ?CarbonImmutable => empty($input[$key]) ? null : CarbonImmutable::parse((string) $input[$key]);
        $languages = (array) config('qistas.locales');

        return new self(
            paper: in_array($input['paper'] ?? null, self::RECEIPT_PAPERS, true) ? $input['paper'] : $preferences->paper,
            text: in_array($input['text'] ?? null, DocumentPreferences::TEXT_SIZES, true) ? $input['text'] : $preferences->text,
            sections: $sections,
            summary: filter_var($input['summary'] ?? false, FILTER_VALIDATE_BOOL),
            compact: filter_var($input['compact'] ?? false, FILTER_VALIDATE_BOOL),
            language: in_array($input['language'] ?? null, $languages, true) ? $input['language'] : app()->getLocale(),
            from: $date('from'),
            to: $date('to'),
            contract: isset($input['contract']) ? (string) $input['contract'] : null,
            investor: isset($input['investor']) ? (string) $input['investor'] : null,
            product: isset($input['product']) ? (string) $input['product'] : null,
            wording: $preferences->wording,
            seesMoney: $by?->roleIn($tenant->id)?->seesInvestors() ?? false,
            timezone: $tenant->localTimezone(),
        );
    }

    /** Whether a section is on. The cost is the shop's: never shown to someone who does not see its money. */
    public function shows(string $section): bool
    {
        if ($section === 'cost' && ! $this->seesMoney) {
            return false;
        }

        return $this->sections[$section] ?? false;
    }

    /** The workspace's own word for a term, else the usual one, in the language being written (call it while rendering). */
    public function word(string $term): string
    {
        return $this->wording[$term] ?? __(DocumentPreferences::WORDS[$term] ?? $term);
    }

    public function fontSize(): float
    {
        $size = self::FONT_SIZES[$this->text];

        // A till roll is narrow: everything one step smaller.
        return $this->isRoll() ? $size - 1.5 : $size;
    }

    public function isRoll(): bool
    {
        return in_array($this->paper, ['80mm', '58mm'], true);
    }

    /**
     * What the PDF engine is told about the page.
     *
     * @return array{format: string|array{0: int, 1: int}, font_size: float, compact: bool, roll: bool}
     */
    public function page(): array
    {
        $format = match ($this->paper) {
            'a5' => 'A5',
            '80mm' => [80, 2000],
            '58mm' => [58, 2000],
            default => 'A4',
        };

        return ['format' => $format, 'font_size' => $this->fontSize(), 'compact' => $this->compact, 'roll' => $this->isRoll()];
    }

    /**
     * Everything that changes what the document says, for telling one document from another.
     *
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        return [
            'paper' => $this->paper, 'text' => $this->text, 'sections' => array_map(fn (string $s) => $this->shows($s), array_keys($this->sections)),
            'summary' => $this->summary, 'compact' => $this->compact, 'language' => $this->language,
            'from' => $this->from?->format('Y-m-d'), 'to' => $this->to?->format('Y-m-d'),
            'contract' => $this->contract, 'investor' => $this->investor, 'product' => $this->product, 'wording' => $this->wording,
        ];
    }
}
