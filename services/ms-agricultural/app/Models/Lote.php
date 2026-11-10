<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lote extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'lote';

    protected $primaryKey = 'id_lote';

    protected $guarded = ['id_lote', 'id_empresa'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer', 'peso_kg' => 'decimal:2', 'fecha_llegada_prevista' => 'datetime', 'fecha_recepcion' => 'datetime'];
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class, 'id_productor', 'id_productor');
    }

    public function recepcion(): HasOne
    {
        return $this->hasOne(Recepcion::class, 'id_lote', 'id_lote');
    }

    public function muestreos(): HasMany
    {
        return $this->hasMany(Muestreo::class, 'id_lote', 'id_lote');
    }
}
