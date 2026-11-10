<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HallazgoManual extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'hallazgo_manual';

    protected $primaryKey = 'id_hallazgo';

    protected $guarded = ['id_hallazgo', 'id_empresa'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer'];
    }
}
