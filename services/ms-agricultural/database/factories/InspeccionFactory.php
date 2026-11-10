<?php

namespace Database\Factories;

use App\Models\Inspeccion;
use App\Models\Muestreo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inspeccion> */
class InspeccionFactory extends Factory
{
    protected $model = Inspeccion::class;

    public function definition(): array
    {
        return ['id_muestreo' => Muestreo::factory(), 'id_inspector' => 1, 'estado' => 'EN_CAPTURA'];
    }
}
