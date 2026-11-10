<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;

class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('identity.bootstrap_admin.correo');
        $password = config('identity.bootstrap_admin.password');
        if (! $email && ! $password) {
            return;
        }
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ! is_string($password) || strlen($password) < 12 || ! preg_match('/[a-z]/', $password)
            || ! preg_match('/[A-Z]/', $password) || ! preg_match('/[0-9]/', $password)) {
            throw new \RuntimeException('Configure a valid IDENTITY_BOOTSTRAP_EMAIL and a strong 12+ character password.');
        }
        $email = mb_strtolower(trim($email));
        $existing = User::where('correo', $email)->orWhere('pending_email', $email)->first();
        if ($existing) {
            if (! $existing->isSuperAdmin() || $existing->id_empresa !== null || $existing->correo !== $email) {
                throw new \RuntimeException('Bootstrap email belongs to another account; it was not modified.');
            }

            return;
        }
        User::create(['correo' => $email, 'nombre' => 'Administrador de plataforma',
            'id_rol' => Rol::where('codigo', 'SUPERADMIN')->where('estado', true)->firstOrFail()->getKey(),
            'id_empresa' => null, 'password_hash' => $password, 'estado' => true, 'email_verified_at' => now()]);
    }
}
