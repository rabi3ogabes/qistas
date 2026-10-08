<?php

namespace App\Entitlements;

use RuntimeException;

/**
 * A change to a switch that is not allowed, with a stable code for programs and a sentence for people:
 * `core_feature`, `reason_required`, `invalid_limit`, `unknown_preset`, `no_snapshot`, `unknown_workspace`.
 */
final class FeatureControlException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function coreFeature(Feature $feature): self
    {
        return new self('core_feature', __(':feature is a core feature: it is always on, and the plans decide who gets it.', ['feature' => $feature->label()]));
    }

    public static function reasonRequired(): self
    {
        return new self('reason_required', __('Give a reason (a few words are enough).'));
    }

    public static function invalidLimit(): self
    {
        return new self('invalid_limit', __('That limit is not allowed for this feature.'));
    }

    public static function unknownPreset(): self
    {
        return new self('unknown_preset', __('There is no such preset.'));
    }

    public static function noSnapshot(): self
    {
        return new self('no_snapshot', __('There is nothing to go back to yet.'));
    }

    public static function unknownWorkspace(): self
    {
        return new self('unknown_workspace', __('No workspace matches that id or e-mail address.'));
    }
}
