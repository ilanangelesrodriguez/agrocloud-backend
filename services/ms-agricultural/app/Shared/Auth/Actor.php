<?php

namespace App\Shared\Auth;

use Illuminate\Auth\GenericUser;

class Actor extends GenericUser
{
    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissions, true);
    }

    public function requireRole(string $role): void
    {
        abort_unless($this->rol->codigo === $role, 403, 'This operation requires role '.$role.'.');
    }
}
