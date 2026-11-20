<?php

namespace App\Services\Access;

use App\Jobs\SendIdentityMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountSecurity
{
    public static function record(User $user, string $event, array $changes = [], ?User $actor = null): void
    {
        if ($user->id_empresa && in_array($event, ['USER_UPDATED', 'PASSWORD_RESET', 'EMAIL_CHANGE_REQUESTED', 'EMAIL_CHANGE_CONFIRMED', 'LOGOUT_ALL'], true)) {
            NotificationOutbox::enqueue($user->id_empresa, [(int) $user->getKey()], $event === 'USER_UPDATED' ? 'USER_ACCESS_CHANGED' : 'SECURITY_NOTICE');
        }
        DB::table('account_security_events')->insert([
            'id_empresa' => $user->id_empresa, 'id_usuario' => $user->getKey(),
            'actor_id' => $actor?->getKey(), 'event' => $event,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public static function mail(User $user, string $kind, string $subject, string $message, ?string $address = null): void
    {
        self::mailTo($address ?? $user->correo, $kind, $subject, $message, $user->getKey());
    }

    public static function mailTo(string $address, string $kind, string $subject, string $message, ?int $userId = null): void
    {
        $id = DB::table('security_mail_attempts')->insertGetId([
            'id_usuario' => $userId, 'kind' => $kind, 'status' => 'queued', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::afterCommit(function () use ($id, $address, $subject, $message, $kind): void {
            try {
                SendIdentityMail::dispatch($id, $address, $subject, $message, $kind);
            } catch (\Throwable $error) {
                DB::table('security_mail_attempts')->where('id', $id)->update(['status' => 'failed', 'updated_at' => now()]);
                report($error);
            }
        });
    }

    public static function url(string $path, array $parameters): string
    {
        $origin = rtrim((string) config('identity.frontend_url'), '/');
        abort_unless(filter_var($origin, FILTER_VALIDATE_URL) &&
            (str_starts_with($origin, 'https://') || (app()->environment('local', 'testing') && parse_url($origin, PHP_URL_SCHEME) === 'http' && in_array(parse_url($origin, PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true))), 503, 'Configure IDENTITY_FRONTEND_URL.');

        return $origin.'/'.$path.'?'.http_build_query($parameters);
    }
}
