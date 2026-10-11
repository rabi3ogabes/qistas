<?php

namespace App\Settings;

use App\Entitlements\Feature;
use App\Support\Digits;
use App\Support\Money;
use Closure;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * One choice a feature offers a workspace owner, declared in code: what it is called, what kind of value it holds,
 * what values are allowed and what it is until the owner decides. Both the web page and the app draw their controls
 * from these, so a later feature needs no screen of its own.
 *
 * Text may be given as a Closure that returns the translated sentence, so it is translated when it is shown, not when
 * it was declared.
 */
final readonly class SettingDefinition
{
    public const TYPES = ['switch', 'int', 'select', 'amount'];

    /**
     * @param  string  $type  switch (on/off), int (a whole number), amount (money or a percent, up to two decimals, kept as an
     *                        exact string) or select (one of $options)
     * @param  list<mixed>  $rules  extra Laravel validation rules for the value (for example `min:1`)
     * @param  array<string, string|Closure>  $options  for a select: allowed value => its label
     */
    public function __construct(
        public string $key,
        public Feature $feature,
        public string $type,
        public mixed $default,
        public array $rules,
        public string|Closure $label,
        public string|Closure $help = '',
        public array $options = [],
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Setting [{$key}] has an unknown type [{$type}].");
        }
        if ($type === 'select' && $options === []) {
            throw new InvalidArgumentException("Setting [{$key}] is a select but offers no options.");
        }
    }

    public function label(): string
    {
        return self::text($this->label);
    }

    public function help(): string
    {
        return self::text($this->help);
    }

    /** @return list<array{value: string, label: string}> */
    public function optionList(): array
    {
        $list = [];
        foreach ($this->options as $value => $label) {
            $list[] = ['value' => (string) $value, 'label' => self::text($label)];
        }

        return $list;
    }

    /**
     * What a value must satisfy: the kind's own rule, then the extra rules.
     *
     * @return list<mixed>
     */
    public function validationRules(): array
    {
        $kind = match ($this->type) {
            'int' => ['required', 'integer'],
            'switch' => ['required', 'boolean'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            default => ['required', Rule::in(array_map('strval', array_keys($this->options)))],
        };

        return [...$kind, ...$this->rules];
    }

    /** What a person typed, made readable: an amount typed with Arabic or Urdu digits becomes ASCII. */
    public function normalize(mixed $value): mixed
    {
        return $this->type === 'amount' && is_string($value) ? trim(Digits::toAscii($value)) : $value;
    }

    /** Turn what was stored or submitted into the kind this setting holds. */
    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            'int' => (int) $value,
            'switch' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'amount' => Money::add(Money::parse((string) $value), '0', 2),
            default => (string) $value,
        };
    }

    /**
     * The shape the API sends and the screens draw from.
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $value): array
    {
        $out = [
            'key' => $this->key,
            'feature' => $this->feature->value,
            'type' => $this->type,
            'label' => $this->label(),
            'help' => $this->help(),
            'value' => $value,
        ];

        if ($this->type === 'select') {
            $out['options'] = $this->optionList();
        }

        return $out;
    }

    private static function text(string|Closure $text): string
    {
        return $text instanceof Closure ? (string) $text() : ($text === '' ? '' : __($text));
    }
}
