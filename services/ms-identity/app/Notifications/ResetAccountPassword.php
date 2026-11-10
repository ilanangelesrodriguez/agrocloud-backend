<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\IdentityMailChannel;
use App\Services\Access\AccountSecurity;
use Illuminate\Notifications\Notification;

class ResetAccountPassword extends Notification
{
    public function __construct(private string $token) {}

    public function via(User $notifiable): array
    {
        return [IdentityMailChannel::class];
    }

    public function message(User $notifiable): string
    {
        $url = AccountSecurity::url('reset-password', ['token' => $this->token, 'correo' => $notifiable->correo]);

        return 'El enlace vence en 60 minutos: '.$url;
    }
}
