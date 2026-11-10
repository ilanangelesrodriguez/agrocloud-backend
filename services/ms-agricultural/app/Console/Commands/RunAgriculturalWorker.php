<?php

namespace App\Console\Commands;

use App\Modules\Evidences\AnalysisWorker;
use App\Shared\Events\OutboxDispatcher;
use Illuminate\Console\Command;

class RunAgriculturalWorker extends Command
{
    protected $signature = 'agricultural:work {--once : Process at most one analysis and one event}';

    protected $description = 'Process persisted AI jobs and deliver the transactional outbox';

    public function handle(AnalysisWorker $ai, OutboxDispatcher $events): int
    {
        $stop = false;
        if (extension_loaded('pcntl')) {
            $this->trap([SIGINT, SIGTERM], function () use (&$stop): void {
                $stop = true;
            });
        }
        do {
            $worked = false;
            try {
                $worked = $ai->processOne();
            } catch (\Throwable) {
                $this->error('AI worker failed; the persistent lease will allow recovery.');
            }
            try {
                $worked = $events->dispatchOne() || $worked;
            } catch (\Throwable) {
                $this->error('Outbox delivery unavailable; pending events are retained.');
            }
            if ($this->option('once')) {
                break;
            }
            if (! $worked) {
                sleep(3);
            }
        } while (! $stop);

        return self::SUCCESS;
    }
}
