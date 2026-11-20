<?php

use App\Services\Access\NotificationOutbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('identity:dispatch-notifications {--limit=100}', function (): void {
    $limit = max(1, min(1000, (int) $this->option('limit')));
    $outbox = app(NotificationOutbox::class);
    for ($count = 0; $count < $limit; $count++) {
        if (! $outbox->dispatchOne()) {
            break;
        }
    }
    $this->info('Dispatched '.$count.' notification event(s).');
})->purpose('Deliver pending Identity notification events');

Schedule::command('identity:dispatch-notifications')->everyMinute()->withoutOverlapping();
