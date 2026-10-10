<?php

namespace App\Console\Commands;

use App\Exports\ExportService;
use Illuminate\Console\Command;

/**
 * The nightly copies of every business's books (Win Plan PP10), a few businesses per run so a host's time limit is never
 * reached: the scheduler runs it every hour, and each business is copied once in every twenty hours or so. Downloads
 * past their day are let go on the way.
 */
final class ExportWorkspacesCommand extends Command
{
    protected $signature = 'qistas:export-workspaces {--limit=25 : how many businesses to copy in this run}';

    protected $description = 'Make the nightly copy of the businesses that are due one, keeping seven each';

    public function handle(ExportService $exports): int
    {
        $made = $exports->runNightly(max(1, (int) $this->option('limit')));
        $this->components->info("Copied {$made} business(es).");

        return self::SUCCESS;
    }
}
