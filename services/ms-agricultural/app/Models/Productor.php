<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Productor extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'productor';

    protected $primaryKey = 'id_productor';

    protected $guarded = ['id_productor', 'id_empresa'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer', 'sunat_verificacion' => 'array'];
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class, 'id_productor', 'id_productor');
    }
}
