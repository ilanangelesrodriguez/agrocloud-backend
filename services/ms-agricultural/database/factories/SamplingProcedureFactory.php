<?php

namespace Database\Factories;

use App\Models\SamplingProcedure;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SamplingProcedure> */
class SamplingProcedureFactory extends Factory
{
    protected $model = SamplingProcedure::class;

    public function definition(): array
    {
        return ['nombre' => 'Procedimiento '.fake()->unique()->numerify('######'), 'version' => '1', 'metodo' => 'Aleatorio simple', 'criterio_muestra' => 'Criterio documentado', 'seleccion_frutos' => 'Seleccion aleatoria', 'max_frutos' => 200, 'tamano_recomendado' => 1, 'activo' => true];
    }
}
