<?php

namespace Database\Factories;

use App\Models\EvidenciaInspeccion;
use App\Models\ValidacionDeteccion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ValidacionDeteccion> */
class ValidacionDeteccionFactory extends Factory
{
    protected $model = ValidacionDeteccion::class;

    public function definition(): array
    {
        return ['id_evidencia' => EvidenciaInspeccion::factory(), 'id_inspector' => 1, 'indice_deteccion' => 0, 'detection_id' => 'fixture-detection', 'confirmada' => false];
    }
}
