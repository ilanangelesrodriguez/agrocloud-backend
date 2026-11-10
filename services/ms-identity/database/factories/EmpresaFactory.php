<?php

namespace Database\Factories;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Empresa> */
class EmpresaFactory extends Factory
{
    protected $model = Empresa::class;

    public function definition(): array
    {
        return ['ruc' => fake()->unique()->numerify('20#########'), 'razon_social' => fake()->company(), 'nombre_comercial' => fake()->company(), 'estado' => true];
    }
}
