<?php

namespace App\Entitlements;

/** Where a feature sits in the admin's cockpit: the epics of the features programme, and the original core. */
enum FeatureGroup: string
{
    case Core = 'core';
    case ContractTerms = 'a';
    case Collecting = 'b';
    case LateCustomers = 'c';
    case Autopilot = 'd';
    case Intelligence = 'e';
    case Documents = 'f';
    case Team = 'g';
    case Membership = 'h';
    case Compliance = 'i';

    public function label(): string
    {
        return match ($this) {
            self::Core => __('Core'),
            self::ContractTerms => __('Contract terms'),
            self::Collecting => __('Collecting money'),
            self::LateCustomers => __('Late customers'),
            self::Autopilot => __('Autopilot and communication'),
            self::Intelligence => __('Intelligence'),
            self::Documents => __('Documents and reports'),
            self::Team => __('Team and operations'),
            self::Membership => __('Membership'),
            self::Compliance => __('Compliance'),
        };
    }

    /** The epic's letter as the features brief names it (A to I); the core has none. */
    public function letter(): ?string
    {
        return $this === self::Core ? null : strtoupper($this->value);
    }
}
