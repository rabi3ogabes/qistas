<?php

namespace App\Console\Commands;

use App\Entitlements\Feature;
use App\Entitlements\PlatformFeatures;
use Illuminate\Console\Command;

/**
 * Gives every feature the code declares a platform switch row, at its launch state. Run by `qistas:setup` after the
 * migrations, so a feature added in a release is visible to the admin as soon as it is deployed. Never changes a
 * switch that exists.
 */
final class SyncFeaturesCommand extends Command
{
    protected $signature = 'qistas:sync-features';

    protected $description = 'Add a platform switch for every feature the code declares (never changes an existing one)';

    public function handle(): int
    {
        $added = PlatformFeatures::sync();

        $this->line(sprintf('%d platform switches added; %d features declared.', $added, count(Feature::cases())));

        return self::SUCCESS;
    }
}
