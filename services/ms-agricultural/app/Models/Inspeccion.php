<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inspeccion extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'inspeccion';

    protected $primaryKey = 'id_inspeccion';

    protected $guarded = ['id_inspeccion', 'id_empresa'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer', 'validada_en' => 'datetime', 'id_inspector' => 'integer'];
    }

    public function muestreo(): BelongsTo
    {
        return $this->belongsTo(Muestreo::class, 'id_muestreo', 'id_muestreo');
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(EvidenciaInspeccion::class, 'id_inspeccion', 'id_inspeccion');
    }

    public function hallazgos(): HasMany
    {
        return $this->hasMany(HallazgoManual::class, 'id_inspeccion', 'id_inspeccion');
    }

    public function dictamenes(): HasMany
    {
        return $this->hasMany(DictamenCalidad::class, 'id_inspeccion', 'id_inspeccion');
    }
}
