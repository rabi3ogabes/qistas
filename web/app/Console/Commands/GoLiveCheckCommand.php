<?php

namespace App\Console\Commands;

use App\Support\GoLiveCheck;
use Illuminate\Console\Command;

/**
 * "Is this site ready for real customers?" for the owner, on the server. One line per check: the word PASS, WARN or FAIL
 * (colour only helps), the title, and what it found with what to do next. Exits with 1 when any check fails. It changes
 * no data (rendering the two public pages for the `legal` check can warm a cache entry, nothing else).
 */
final class GoLiveCheckCommand extends Command
{
    protected $signature = 'qistas:go-live-check';

    protected $description = 'Check that the site is ready for real customers (reads only, changes no data)';

    public function handle(): int
    {
        $this->newLine();
        $this->line('<options=bold>Qistas go-live check</>');
        $this->newLine();

        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];

        foreach (GoLiveCheck::run() as $result) {
            $counts[$result['status']]++;

            $colour = ['pass' => 'green', 'warn' => 'yellow', 'fail' => 'red'][$result['status']];
            $this->components->twoColumnDetail(
                sprintf('<fg=%s;options=bold>%s</> %s', $colour, strtoupper($result['status']), $result['title']),
                $result['detail'],
            );
        }

        $this->newLine();
        $summary = sprintf('Summary: %d PASS, %d WARN, %d FAIL.', $counts['pass'], $counts['warn'], $counts['fail']);

        if ($counts['fail'] > 0) {
            $this->components->error($summary.' Fix every FAIL, then run this check again.');

            return self::FAILURE;
        }

        $counts['warn'] > 0
            ? $this->components->warn($summary.' A WARN does not stop the launch; read each one and decide.')
            : $this->components->info($summary.' Ready to go live.');

        return self::SUCCESS;
    }
}
