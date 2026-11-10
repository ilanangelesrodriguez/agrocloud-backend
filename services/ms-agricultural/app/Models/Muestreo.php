<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Muestreo extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'muestreo';

    protected $primaryKey = 'id_muestreo';

    protected $guarded = ['id_muestreo', 'id_empresa'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer', 'protocolo' => 'array'];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'id_lote', 'id_lote');
    }

    public function frutos(): HasMany
    {
        return $this->hasMany(FrutoMuestra::class, 'id_muestreo', 'id_muestreo');
    }

    public function inspecciones(): HasMany
    {
        return $this->hasMany(Inspeccion::class, 'id_muestreo', 'id_muestreo');
    }
}
