<?php

namespace Database\Factories;

use App\Models\Lote;
use App\Models\Muestreo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Muestreo> */
class MuestreoFactory extends Factory
{
    protected $model = Muestreo::class;

    public function definition(): array
    {
        return ['id_lote' => Lote::factory(), 'id_inspector' => 1, 'tamano_muestra' => 1, 'metodo' => 'Aleatorio simple', 'protocolo' => ['modalidad' => 'MANUAL', 'motivo_excepcion' => 'Excepcion documentada'], 'estado' => 'REGISTRADO'];
    }
}
