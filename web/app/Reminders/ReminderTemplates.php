<?php

namespace App\Reminders;

use App\Models\MessageTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\CurrentTenant;

/**
 * The words of the reminders a business sends its customers (Win Plan PP9): a friendly one before an instalment is late
 * and a firmer one after. Each business may write its own in each of the five languages; where it has not, the default
 * wording in that language is used. Placeholders: :name, :amount, :date, :reference and :business.
 */
final class ReminderTemplates
{
    public const KEYS = ['reminder_due', 'reminder_late'];

    public const PLACEHOLDERS = [':name', ':amount', ':date', ':reference', ':business'];

    public const MAX_LENGTH = 1000;

    public function __construct(private readonly CurrentTenant $current) {}

    /** The default wording of [$key] in [$language], placeholders left in. */
    public static function default(string $key, string $language): string
    {
        return match ($key) {
            'reminder_late' => __('Hello :name, :amount for contract :reference has been overdue since :date. Could you settle it soon? Thank you. :business', [], $language),
            default => __('Hello :name, a friendly reminder that :amount is due on :date for contract :reference. Thank you. :business', [], $language),
        };
    }

    /** What the business says for [$key] in [$language]: its own words, or the default. */
    public function body(Tenant $tenant, string $key, string $language): string
    {
        return $this->own($tenant)[$key][$language] ?? self::default($key, $language);
    }

    /**
     * Every wording in every language, each with its default and whether the business wrote its own.
     *
     * @return list<array{key: string, language: string, body: string, default_body: string, custom: bool}>
     */
    public function all(Tenant $tenant): array
    {
        $own = $this->own($tenant);
        $list = [];
        foreach (self::KEYS as $key) {
            foreach ((array) config('qistas.locales') as $language) {
                $default = self::default($key, $language);
                $list[] = ['key' => $key, 'language' => $language, 'body' => $own[$key][$language] ?? $default, 'default_body' => $default, 'custom' => isset($own[$key][$language])];
            }
        }

        return $list;
    }

    /** Saves the business's own words for [$key] in [$language]; empty words bring the default back. */
    public function set(Tenant $tenant, string $key, string $language, string $body, User $by): void
    {
        $body = trim($body);

        $this->current->use($tenant, function () use ($tenant, $key, $language, $body, $by): void {
            $existing = MessageTemplate::query()->where(['key' => $key, 'language' => $language])->first();
            if ($body === '' || $body === self::default($key, $language)) {
                $existing?->delete();
            } else {
                MessageTemplate::query()->updateOrCreate(['key' => $key, 'language' => $language], ['body' => $body]);
            }
            Audit::record('settings.message_template', null, ['key' => $key, 'language' => $language, 'custom' => $body !== ''], $tenant->id, $by->id);
        });
    }

    /**
     * The words filled in: every placeholder replaced by its value.
     *
     * @param  array<string, string|int|null>  $values  by placeholder name, without its colon
     */
    public static function fill(string $body, array $values): string
    {
        $pairs = [];
        foreach (self::PLACEHOLDERS as $placeholder) {
            $pairs[$placeholder] = (string) ($values[ltrim($placeholder, ':')] ?? '');
        }

        return strtr($body, $pairs);
    }

    /** @return array<string, array<string, string>> the business's own words, by key then language */
    private function own(Tenant $tenant): array
    {
        $own = [];
        $rows = $this->current->use($tenant, fn () => MessageTemplate::query()->get(['key', 'language', 'body']));
        foreach ($rows as $row) {
            $own[$row->key][$row->language] = $row->body;
        }

        return $own;
    }
}
