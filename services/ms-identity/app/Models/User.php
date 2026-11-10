<?php

namespace App\Models;

use App\Notifications\ResetAccountPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    protected $table = 'usuario';

    protected $primaryKey = 'id_usuario';

    protected $fillable = [
        'id_empresa',
        'id_rol',
        'nombre',
        'apellido',
        'correo',
        'password_hash',
        'telefono',
        'cargo',
        'foto_perfil_url',
        'foto_perfil_public_id',
        'estado',
        'email_verified_at',
        'ultimo_acceso',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
        'invitation_hash',
        'email_change_hash',
        'pending_email',
        'auth_version',
        'invitation_expires_at',
        'email_change_expires_at',
    ];

    protected $appends = [
        'nombre_completo',
    ];

    protected function casts(): array
    {
        return [
            'id_empresa' => 'integer',
            'id_rol' => 'integer',
            'estado' => 'boolean',
            'invitation_expires_at' => 'datetime',
            'email_change_expires_at' => 'datetime',
            'invitation_attempted_at' => 'datetime',
            'invitation_sent_at' => 'datetime',
            'auth_version' => 'integer',
            'email_verified_at' => 'datetime',
            'ultimo_acceso' => 'datetime',
            'password_hash' => 'hashed',
        ];
    }

    public function setCorreoAttribute(string $value): void
    {
        $this->attributes['correo'] = mb_strtolower(trim($value));
    }

    public function getEmailForPasswordReset(): string
    {
        return $this->correo;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetAccountPassword($token));
    }

    public function routeNotificationForMail($notification = null): string
    {
        return $this->correo;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthIdentifierName(): string
    {
        return 'id_usuario';
    }

    public function getNombreCompletoAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->nombre, $this->apellido])));
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'id_rol', 'id_rol');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa', 'id_empresa');
    }

    public function isSuperAdmin(): bool
    {
        return $this->rol?->estado === true && $this->rol?->codigo === 'SUPERADMIN' && $this->id_empresa === null;
    }

    public function isAvailable(): bool
    {
        return $this->estado && $this->email_verified_at !== null && ! $this->invitation_hash && $this->rol?->estado
            && ($this->isSuperAdmin() || ($this->id_empresa !== null && $this->empresa?->estado));
    }

    public function isCompanyAdmin(): bool
    {
        return $this->rol?->estado === true && $this->rol?->codigo === 'ADMIN';
    }

    public function hasPermission(string $code): bool
    {
        // Solo el SUPERADMIN de plataforma tiene bypass global.
        if ($this->isSuperAdmin()) {
            return true;
        }

        if (! $this->rol || ! $this->rol->estado) {
            return false;
        }

        return $this->rol
            ->permisos()
            ->where('permiso.codigo', $code)
            ->where('permiso.estado', true)
            ->exists();
    }

    /** @param array<int, string> $codes */
    public function hasAnyPermission(array $codes): bool
    {
        foreach ($codes as $code) {
            if ($this->hasPermission($code)) {
                return true;
            }
        }

        return false;
    }
}
