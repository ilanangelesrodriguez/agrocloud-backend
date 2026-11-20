<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function latestDecision(): HasOne
    {
        return $this->hasOne(DictamenCalidad::class, 'id_inspeccion', 'id_inspeccion')->ofMany('id_dictamen', 'max');
    }

    public function scopeDecisionStatus(Builder $query, string $status): void
    {
        if ($status === 'COMPLETED') {
            $query->whereHas('latestDecision', fn ($decision) => $decision->whereIn('decision', ['APROBADO', 'RECHAZADO']));

            return;
        }
        $query->where('estado', 'VALIDADA')->whereNotExists(function ($newer): void {
            $newer->selectRaw('1')->from('inspeccion as newer')
                ->whereColumn('newer.id_empresa', 'inspeccion.id_empresa')
                ->whereColumn('newer.id_muestreo', 'inspeccion.id_muestreo')
                ->whereColumn('newer.id_inspeccion', '>', 'inspeccion.id_inspeccion');
        });
        if ($status === 'OBSERVED') {
            $query->whereHas('latestDecision', fn ($decision) => $decision->where('decision', 'OBSERVADO')->whereIn('tipo_observacion', ['DOCUMENTACION', 'CORRECCION_ADMINISTRATIVA']));
        } else {
            $query->whereDoesntHave('dictamenes');
        }
    }

    public function dictamenes(): HasMany
    {
        return $this->hasMany(DictamenCalidad::class, 'id_inspeccion', 'id_inspeccion');
    }
}
