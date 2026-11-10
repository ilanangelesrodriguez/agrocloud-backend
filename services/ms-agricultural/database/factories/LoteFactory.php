<?php

namespace Database\Factories;

use App\Models\Lote;
use App\Models\Productor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Lote> */
class LoteFactory extends Factory
{
    protected $model = Lote::class;

    public function definition(): array
    {
        return ['id_productor' => Productor::factory(), 'codigo_lote' => 'LOT-'.Str::uuid(), 'producto' => 'Palta', 'variedad' => 'Hass', 'peso_kg' => '100.00', 'unidad_medida' => 'Kilogramo', 'fecha_llegada_prevista' => now(), 'estado' => 'PENDIENTE_RECEPCION'];
    }
}
