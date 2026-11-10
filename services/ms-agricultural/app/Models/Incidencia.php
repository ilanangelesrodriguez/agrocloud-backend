<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Incidencia extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'incidencias';

    protected $primaryKey = 'id';

    protected $guarded = ['id', 'id_empresa'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer'];
    }
}
