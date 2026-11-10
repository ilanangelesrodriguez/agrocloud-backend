<?php

namespace Database\Factories;

use App\Models\Incidencia;
use App\Models\Lote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Incidencia> */
class IncidenciaFactory extends Factory
{
    protected $model = Incidencia::class;

    public function definition(): array
    {
        return ['id_lote' => Lote::factory(), 'titulo' => 'Incidencia operativa', 'descripcion' => 'Requiere seguimiento', 'gravedad' => 'MEDIA', 'estado' => 'ABIERTA', 'responsable' => 1];
    }
}
