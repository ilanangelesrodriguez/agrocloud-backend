<?php

namespace Database\Factories;

use App\Models\SolicitudAcceso;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SolicitudAcceso> */
class SolicitudAccesoFactory extends Factory
{
    protected $model = SolicitudAcceso::class;

    public function definition(): array
    {
        return ['ruc' => fake()->unique()->numerify('20#########'), 'razon_social' => fake()->company(), 'nombre_comercial' => fake()->company(), 'tipo_empresa' => 'AGROEXPORTADORA', 'nombre' => fake()->firstName(), 'apellido' => fake()->lastName(), 'correo' => fake()->unique()->safeEmail(), 'telefono' => '999999999', 'uso' => 'Control de calidad', 'estado' => 'PENDIENTE', 'historial' => []];
    }
}
