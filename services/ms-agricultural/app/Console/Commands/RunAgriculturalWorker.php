<?php

namespace App\Console\Commands;

use App\Modules\Evidences\AnalysisWorker;
use App\Shared\Events\OutboxDispatcher;
use Illuminate\Console\Command;

class RunAgriculturalWorker extends Command
{
    protected $signature = 'agricultural:work
    {--once : Process at most one analysis and one event}
    {--events-only : Dispatch events without processing AI jobs}
    {--ai-only : Process AI jobs without dispatching events}';

    protected $description = 'Process persisted AI jobs and deliver the transactional outbox';

    public function handle(AnalysisWorker $ai, OutboxDispatcher $events): int
    {
        if ($this->option('events-only') && $this->option('ai-only')) {
            $this->error('Choose either --events-only or --ai-only.');

            return self::INVALID;
        }
        $stop = false;
        if (extension_loaded('pcntl')) {
            $this->trap([SIGINT, SIGTERM], function () use (&$stop): void {
                $stop = true;
            });
        }
        do {
            $worked = false;
            try {
                if (! $this->option('events-only')) {
                    $worked = $ai->processOne();
                }
            } catch (\Throwable $error) {
                logger()->error('AI worker iteration failed.', ['exception_type' => $error::class, 'code' => $error->getCode()]);
                $this->error('AI worker failed; the persistent lease will allow recovery.');
            }
            try {
                if (! $this->option('ai-only')) {
                    $worked = $events->dispatchOne() || $worked;
                }
            } catch (\Throwable $error) {
                logger()->error('Outbox iteration failed.', ['exception_type' => $error::class, 'code' => $error->getCode()]);
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
