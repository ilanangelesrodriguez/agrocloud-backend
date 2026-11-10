<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\ResetAccountPassword;
use App\Services\Access\AccountSecurity;

class IdentityMailChannel
{
    public function send(User $notifiable, ResetAccountPassword $notification): void
    {
        AccountSecurity::mail($notifiable, 'PASSWORD_RESET', 'Restablecer acceso a AgroCloud', $notification->message($notifiable));
    }
}
