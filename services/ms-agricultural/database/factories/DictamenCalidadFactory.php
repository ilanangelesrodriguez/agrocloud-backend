<?php

namespace Database\Factories;

use App\Models\DictamenCalidad;
use App\Models\Inspeccion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DictamenCalidad> */
class DictamenCalidadFactory extends Factory
{
    protected $model = DictamenCalidad::class;

    public function definition(): array
    {
        return ['id_inspeccion' => Inspeccion::factory(), 'id_lote' => fn (array $attributes) => Inspeccion::findOrFail($attributes['id_inspeccion'])->muestreo->id_lote, 'id_supervisor' => 2, 'decision' => 'OBSERVADO', 'tipo_observacion' => 'DOCUMENTACION', 'observaciones' => 'Completar expediente', 'protocolo_calidad' => 'Protocolo documentado'];
    }
}
