<?php

namespace Database\Factories;

use App\Models\EvidenciaInspeccion;
use App\Models\HallazgoManual;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HallazgoManual> */
class HallazgoManualFactory extends Factory
{
    protected $model = HallazgoManual::class;

    public function definition(): array
    {
        return ['id_evidencia' => EvidenciaInspeccion::factory(), 'id_inspeccion' => fn (array $attributes) => EvidenciaInspeccion::findOrFail($attributes['id_evidencia'])->id_inspeccion, 'id_inspector' => 1, 'clase' => 'Daño mecánico', 'naturaleza' => 'DEFECTO_FISICO', 'observaciones' => 'Hallazgo observado'];
    }
}
