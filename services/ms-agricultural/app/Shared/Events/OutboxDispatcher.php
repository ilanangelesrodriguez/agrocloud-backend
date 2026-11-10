<?php

namespace App\Shared\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OutboxDispatcher
{
    public function dispatchOne(): bool
    {
        $url = (string) config('agricultural.notifications_url');
        if ($url === '') {
            return false;
        }
        $secret = (string) config('agricultural.event_secret');
        if (strlen($secret) < 32 || ! filter_var($url, FILTER_VALIDATE_URL)
            || (! str_starts_with($url, 'https://') && ! app()->environment('local', 'testing'))) {
            throw new \RuntimeException('Configure Notifications URL and signing secret.');
        }
        $event = DB::transaction(function (): ?object {
            $event = DB::table('agricultural_outbox')->where('status', 'PENDING')->where('next_attempt_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<', now()))
                ->orderBy('created_at')->lock('FOR UPDATE SKIP LOCKED')->first();
            if (! $event) {
                return null;
            }
            $event->lock_token = (string) Str::uuid();
            DB::table('agricultural_outbox')->where('event_id', $event->event_id)->update([
                'lock_token' => $event->lock_token, 'locked_until' => now()->addSeconds(60), 'updated_at' => now()]);

            return $event;
        });
        if (! $event) {
            return false;
        }
        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(10)
                ->withHeaders(['X-Event-Id' => $event->event_id, 'X-AgroCloud-Signature' => 'sha256='.hash_hmac('sha256', $event->payload, $secret)])
                ->withBody($event->payload, 'application/json')->post($url);
            if (! $response->successful()) {
                throw new \RuntimeException('Receiver rejected event.');
            }
            $changes = ['status' => 'DELIVERED'];
        } catch (\Throwable) {
            $changes = ['next_attempt_at' => now()->addSeconds(min(3600, 15 * (2 ** min(8, $event->attempts + 1))))];
        }
        DB::table('agricultural_outbox')->where('event_id', $event->event_id)->where('lock_token', $event->lock_token)
            ->update([...$changes, 'attempts' => $event->attempts + 1, 'lock_token' => null, 'locked_until' => null, 'updated_at' => now()]);

        return true;
    }
}
