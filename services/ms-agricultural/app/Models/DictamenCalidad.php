<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DictamenCalidad extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'dictamen_calidad';

    protected $primaryKey = 'id_dictamen';

    protected $guarded = ['id_dictamen', 'id_empresa'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer'];
    }
}
