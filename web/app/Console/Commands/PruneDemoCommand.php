<?php

namespace App\Console\Commands;

use App\Sandbox\DemoAccess;
use Illuminate\Console\Command;

/** Deletes demo accounts whose time is up. (A demo button press does this too, so no scheduler is required.) */
final class PruneDemoCommand extends Command
{
    protected $signature = 'qistas:prune-demo';

    protected $description = 'Delete expired demo accounts and everything they own';

    public function handle(DemoAccess $demo): int
    {
        $total = 0;

        do {
            $removed = $demo->prune(100);
            $total += $removed;
        } while ($removed === 100);

        $this->components->info("Removed {$total} expired demo ".($total === 1 ? 'account' : 'accounts').'.');

        return self::SUCCESS;
    }
}
