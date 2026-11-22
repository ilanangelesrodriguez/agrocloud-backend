<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SolicitudAcceso extends Model
{
    use HasFactory;

    protected $table = 'solicitudes_acceso';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sunat_verificacion' => 'array', 'historial' => 'array', 'selected_offer_snapshot' => 'array', 'decidida_en' => 'datetime'];
    }
}
