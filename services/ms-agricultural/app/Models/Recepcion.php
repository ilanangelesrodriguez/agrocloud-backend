<?php

namespace App\Models;

use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recepcion extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'recepciones';

    protected $primaryKey = 'id';

    protected $guarded = ['id', 'id_empresa'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer', 'recibido_en' => 'datetime', 'peso_recibido_kg' => 'decimal:2'];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'id_lote', 'id_lote');
    }
}
