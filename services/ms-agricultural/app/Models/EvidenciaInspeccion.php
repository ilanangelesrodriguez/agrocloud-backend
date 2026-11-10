<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidenciaInspeccion extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'evidencia_inspeccion';

    protected $primaryKey = 'id_evidencia';

    protected $guarded = ['id_evidencia', 'id_empresa'];

    protected $hidden = ['file_path', 'review_fingerprint'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer', 'resultado_ia' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function inspeccion(): BelongsTo
    {
        return $this->belongsTo(Inspeccion::class, 'id_inspeccion', 'id_inspeccion');
    }

    public function fruto(): BelongsTo
    {
        return $this->belongsTo(FrutoMuestra::class, 'id_fruto', 'id');
    }

    public function validaciones(): HasMany
    {
        return $this->hasMany(ValidacionDeteccion::class, 'id_evidencia', 'id_evidencia');
    }
}
