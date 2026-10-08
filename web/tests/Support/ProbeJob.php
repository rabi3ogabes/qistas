<?php

namespace Tests\Support;

use App\Entitlements\Feature;
use App\Jobs\FeatureJob;
use App\Tenancy\CurrentTenant;

/** A throw-away job for `advanced_reports`, used only to prove that queued work re-checks its feature switch. */
final class ProbeJob extends FeatureJob
{
    public static int $ran = 0;

    public static ?string $tenantSeen = null;

    public function __construct(private readonly string $tenant) {}

    public function feature(): Feature
    {
        return Feature::AdvancedReports;
    }

    public function tenantId(): string
    {
        return $this->tenant;
    }

    protected function work(): void
    {
        self::$ran++;
        self::$tenantSeen = app(CurrentTenant::class)->id();
    }

    public static function reset(): void
    {
        self::$ran = 0;
        self::$tenantSeen = null;
    }
}
