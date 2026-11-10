<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FrutoMuestra extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'frutos_muestra';

    protected $primaryKey = 'id';

    protected $guarded = ['id', 'id_empresa'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer'];
    }

    public function muestreo(): BelongsTo
    {
        return $this->belongsTo(Muestreo::class, 'id_muestreo', 'id_muestreo');
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(EvidenciaInspeccion::class, 'id_fruto', 'id');
    }
}
