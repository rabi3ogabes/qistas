<?php

namespace App\Console\Commands;

use App\Actions\Account\PurgeDeletedAccounts;
use Illuminate\Console\Command;

/** Erases the businesses whose owners deleted them more than 30 days ago (and did not restore them). Runs daily. */
final class PurgeDeletedAccountsCommand extends Command
{
    protected $signature = 'qistas:purge-deleted-accounts';

    protected $description = 'Erase businesses whose 30 days to restore after a deletion request are over';

    public function handle(PurgeDeletedAccounts $purge): int
    {
        $total = 0;

        do {
            $erased = $purge->handle(20);
            $total += $erased;
        } while ($erased === 20);

        $this->components->info("Erased {$total} deleted ".($total === 1 ? 'business' : 'businesses').'.');

        return self::SUCCESS;
    }
}
