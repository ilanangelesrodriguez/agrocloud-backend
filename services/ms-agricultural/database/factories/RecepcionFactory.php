<?php

namespace Database\Factories;

use App\Models\Lote;
use App\Models\Recepcion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Recepcion> */
class RecepcionFactory extends Factory
{
    protected $model = Recepcion::class;

    public function definition(): array
    {
        return ['id_lote' => Lote::factory(), 'id_usuario' => 1, 'tipo_documento' => 'Comprobante interno', 'documento' => fake()->uuid(), 'peso_recibido_kg' => '100.00', 'recibido_en' => now()];
    }
}
