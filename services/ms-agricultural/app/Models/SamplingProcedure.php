<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SamplingProcedure extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'sampling_procedures';

    protected $primaryKey = 'id';

    protected $guarded = ['id', 'id_empresa'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer', 'activo' => 'boolean', 'requiere_poblacion' => 'boolean', 'max_frutos' => 'integer', 'tamano_recomendado' => 'integer'];
    }
}
