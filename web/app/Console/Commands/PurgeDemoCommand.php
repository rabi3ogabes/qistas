<?php

namespace App\Console\Commands;

use App\Sandbox\DemoCleanup;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Lists what the old public demo and the developer seeder left behind, and with --confirm removes exactly that. It is
 * only a wrapper: every rule (who counts as a demo account, what a real person's workspace keeps, what is checked again
 * before anything goes) lives in App\Sandbox\DemoCleanup, and this class neither reads nor deletes anything itself.
 *
 *     php artisan qistas:purge-demo                    the list, nothing deleted
 *     php artisan qistas:purge-demo --confirm          the list worked out again now, then removed
 *     php artisan qistas:purge-demo --include-test     the same, with staff test workspaces
 *
 * What it prints about accounts is demo and seeder addresses and workspace names. Names are typed by people, so they are
 * printed as plain text: no console formatting and no control characters get through.
 */
final class PurgeDemoCommand extends Command
{
    protected $signature = 'qistas:purge-demo
        {--confirm : Remove what the list shows. Without it nothing is deleted}
        {--include-test : Also remove the staff test workspaces}';

    protected $description = 'List the old demo accounts and the developer seeder\'s account, and remove them with --confirm';

    public function handle(DemoCleanup $cleanup): int
    {
        $includeTest = (bool) $this->option('include-test');

        return $this->option('confirm')
            ? $this->remove($cleanup, $includeTest)
            : $this->show($cleanup, $includeTest);
    }

    /** The dry run: the list, and how to remove it. */
    private function show(DemoCleanup $cleanup, bool $includeTest): int
    {
        $plan = $cleanup->plan($includeTest);
        $accounts = $plan['accounts'];
        $tests = $plan['test_workspaces'];

        if ($accounts !== []) {
            $this->heading('Demo accounts that would be removed: '.count($accounts));

            foreach ($accounts as $account) {
                $this->components->twoColumnDetail(
                    '<options=bold>'.$this->clean($account['email']).'</>',
                    ($account['matched_by'] === 'seeder_address'
                        ? 'matched by seeder_address only (no demo expiry: check it is not a real person)'
                        : 'matched by demo_expires_at (the old "Try the demo" button)').', made '.$account['created_at'],
                );

                foreach ($account['workspaces'] as $workspace) {
                    $this->components->twoColumnDetail('workspace "'.$this->clean($workspace['name']).'"', $this->records($workspace['rows']));
                }

                if ($account['workspaces'] === []) {
                    $this->components->twoColumnDetail('workspace', 'none of its own');
                }
            }
        }

        $this->heldBack($plan['held_back'], 'Accounts held back, not removed');

        if ($tests !== []) {
            $this->heading('Staff test workspaces: '.count($tests).($includeTest ? ' (removed with this run)' : ' (only removed with --include-test)'));

            foreach ($tests as $workspace) {
                $this->components->twoColumnDetail('"'.$this->clean($workspace['name']).'"', 'owner '.$this->clean($workspace['owner_email']));
            }
        }

        $this->newLine();
        $removable = count($accounts) + ($includeTest ? count($tests) : 0);

        if ($removable === 0) {
            $this->components->info('Nothing to remove. Nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $includeTest && $tests !== []) {
            $this->line('To remove the staff test workspaces too, add the option --include-test.');
        }

        $this->components->info('Nothing was deleted. This was only the list.');
        $this->line('To remove exactly what is listed above, run: php artisan qistas:purge-demo --confirm'.($includeTest ? ' --include-test' : ''));

        return self::SUCCESS;
    }

    /** The real run: the list worked out again now, then removed. */
    private function remove(DemoCleanup $cleanup, bool $includeTest): int
    {
        $plan = $cleanup->plan($includeTest);
        $report = $cleanup->purgeAndReport($plan);

        $this->newLine();

        if ($report['accounts'] === [] && $report['test_workspaces'] === 0) {
            $this->components->info('Nothing to remove.');
        }

        if ($report['accounts'] !== []) {
            $accounts = count($report['accounts']);
            $this->components->info(sprintf('Removed %d demo %s and %d %s.', $accounts, $accounts === 1 ? 'account' : 'accounts', $report['workspaces'], $report['workspaces'] === 1 ? 'workspace' : 'workspaces'));

            foreach ($report['accounts'] as $email) {
                $this->components->twoColumnDetail($this->clean($email), 'removed');
            }
        }

        if ($report['test_workspaces'] > 0) {
            $this->components->info(sprintf('Removed %d staff test %s.', $report['test_workspaces'], $report['test_workspaces'] === 1 ? 'workspace' : 'workspaces'));
        }

        $this->heldBack($plan['held_back'], 'Accounts held back, left alone');

        if (! $includeTest && $plan['test_workspaces'] !== []) {
            $this->newLine();
            $this->line(sprintf('%d staff test %s left in place. To remove them too, add the option --include-test.', count($plan['test_workspaces']), count($plan['test_workspaces']) === 1 ? 'workspace was' : 'workspaces were'));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{user_id: string, email: string, reason: string}>  $held
     */
    private function heldBack(array $held, string $title): void
    {
        if ($held === []) {
            return;
        }

        $this->heading($title.': '.count($held));

        foreach ($held as $account) {
            $this->components->twoColumnDetail('<options=bold>'.$this->clean($account['email']).'</>', $this->clean($account['reason']));
        }
    }

    private function heading(string $text): void
    {
        $this->newLine();
        $this->line('<options=bold>'.$text.'</>');
        $this->newLine();
    }

    private function records(int $rows): string
    {
        return $rows.' '.($rows === 1 ? 'record' : 'records');
    }

    /** A name or address as plain text: no control characters, and no angle bracket read as console formatting. */
    private function clean(string $text): string
    {
        return OutputFormatter::escape(preg_replace('/[\x00-\x1F\x7F]/', '', $text) ?? '');
    }
}
