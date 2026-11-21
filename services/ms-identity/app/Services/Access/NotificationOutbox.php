<?php

namespace App\Services\Access;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class NotificationOutbox
{
    public static function enqueue(int $company, array $recipients, string $code, array $data = [], ?string $deduplicationKey = null): void
    {
        if ($company < 1 || $recipients === []) {
            return;
        }
        $id = $deduplicationKey === null ? (string) Str::uuid() : Uuid::uuid5(Uuid::NAMESPACE_URL, 'agrocloud:'.$company.':'.$code.':'.$deduplicationKey)->toString();
        $type = in_array($code, ['SUBSCRIPTION_UPDATED', 'SUBSCRIPTION_EXPIRING', 'SUBSCRIPTION_EXPIRED', 'QUOTA_THRESHOLD'], true)
            ? 'subscription.notice' : (in_array($code, ['USER_INVITED', 'COMPANY_APPROVED'], true) ? 'account.activity_notice' : 'account.security_notice');
        DB::table('identity_notification_outbox')->insertOrIgnore([
            'event_id' => $id, 'payload' => json_encode([
                'eventId' => $id, 'type' => $type, 'version' => 1,
                'companyId' => $company, 'occurredAt' => now()->toIso8601String(),
                'data' => [...$data, 'recipientIds' => array_values(array_unique($recipients)), 'code' => $code],
            ], JSON_THROW_ON_ERROR), 'status' => 'PENDING', 'attempts' => 0,
            'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function dispatchOne(): bool
    {
        $url = (string) config('identity.notifications.url');
        if ($url === '') {
            return false;
        }
        $secret = (string) config('identity.notifications.secret');
        if (strlen($secret) < 32 || ! filter_var($url, FILTER_VALIDATE_URL)
            || (! str_starts_with($url, 'https://') && ! app()->environment('local', 'testing'))) {
            throw new \RuntimeException('Configure Notifications URL and signing secret.');
        }
        $event = DB::transaction(function (): ?object {
            $event = DB::table('identity_notification_outbox')->where('status', 'PENDING')->where('next_attempt_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<', now()))
                ->orderBy('created_at')->lock('FOR UPDATE SKIP LOCKED')->first();
            if (! $event) {
                return null;
            }
            $event->lock_token = (string) Str::uuid();
            DB::table('identity_notification_outbox')->where('event_id', $event->event_id)->update([
                'lock_token' => $event->lock_token, 'locked_until' => now()->addSeconds(60), 'updated_at' => now()]);

            return $event;
        });
        if (! $event) {
            return false;
        }
        try {
            if (! $this->relevant($event)) {
                DB::table('identity_notification_outbox')->where('event_id', $event->event_id)->where('lock_token', $event->lock_token)
                    ->update(['status' => 'CANCELLED', 'lock_token' => null, 'locked_until' => null, 'updated_at' => now()]);

                return true;
            }
            $request = Http::acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(10);
            $audience = (string) config('identity.notifications.audience');
            if ($audience !== '' || getenv('K_SERVICE') || getenv('CLOUD_RUN_WORKER_POOL')) {
                $audience = $audience !== '' ? $audience : parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST);
                $token = Http::withHeaders(['Metadata-Flavor' => 'Google'])->withoutRedirecting()->connectTimeout(2)->timeout(5)
                    ->get('http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/identity', ['audience' => $audience]);
                if (! $token->successful() || ! preg_match('/^[\w-]+\.[\w-]+\.[\w-]+$/', trim($token->body()))) {
                    throw new \RuntimeException('Cloud Run identity token unavailable.');
                }
                $request = $request->withHeaders(['X-Serverless-Authorization' => 'Bearer '.trim($token->body())]);
            }
            $response = $request->withHeaders(['X-Event-Id' => $event->event_id, 'X-AgroCloud-Signature' => 'sha256='.hash_hmac('sha256', $event->payload, $secret)])
                ->withBody($event->payload, 'application/json')->post($url);
            if (! $response->successful()) {
                throw new \RuntimeException('Receiver rejected event.');
            }
            $changes = ['status' => 'DELIVERED'];
        } catch (\Throwable $error) {
            logger()->log($event->attempts >= 4 ? 'error' : 'warning', 'Identity notification delivery failed.', [
                'event_id' => $event->event_id, 'attempt' => $event->attempts + 1, 'error_type' => $error::class,
            ]);
            $changes = ['next_attempt_at' => now()->addSeconds(min(3600, 15 * (2 ** min(8, $event->attempts + 1))))];
        }
        DB::table('identity_notification_outbox')->where('event_id', $event->event_id)->where('lock_token', $event->lock_token)
            ->update([...$changes, 'attempts' => $event->attempts + 1, 'lock_token' => null, 'locked_until' => null, 'updated_at' => now()]);

        return true;
    }

    private function relevant(object $event): bool
    {
        $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
        $data = $payload['data'] ?? [];
        $code = $data['code'] ?? '';
        if (! in_array($code, ['SUBSCRIPTION_EXPIRING', 'SUBSCRIPTION_EXPIRED', 'QUOTA_THRESHOLD'], true)) {
            return true;
        }
        $subscription = DB::table('suscripciones')->where('id_empresa', $payload['companyId'])->first();
        if (! $subscription || $subscription->estado !== 'ACTIVA') {
            return false;
        }
        $expires = CarbonImmutable::parse($subscription->vence_en);
        if (! $expires->equalTo(CarbonImmutable::parse($data['expiresAt']))) {
            return false;
        }
        if ($code === 'SUBSCRIPTION_EXPIRED') {
            return $expires->isPast();
        }
        if ($expires->isPast()) {
            return false;
        }
        if ($code === 'SUBSCRIPTION_EXPIRING') {
            return (int) CarbonImmutable::now('America/Lima')->startOfDay()->diffInDays($expires->setTimezone('America/Lima')->startOfDay(), false) === $data['daysRemaining'];
        }
        $right = DB::table('derechos_acceso')->where('id_empresa', $payload['companyId'])->where('recurso', $data['resource'])->first();
        $used = $data['resource'] === 'usuarios' ? User::where('id_empresa', $payload['companyId'])->count() : (int) ($right->consumido ?? 0);

        return $right && (int) $right->limite === $data['limit'] && $used >= $data['limit'] * $data['threshold'] / 100;
    }
}
