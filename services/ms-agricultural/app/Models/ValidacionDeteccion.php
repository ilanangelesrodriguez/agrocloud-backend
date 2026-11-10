<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ValidacionDeteccion extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'validacion_deteccion';

    protected $primaryKey = 'id_validacion';

    protected $guarded = ['id_validacion', 'id_empresa'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer', 'confirmada' => 'boolean', 'indice_deteccion' => 'integer'];
    }
}
