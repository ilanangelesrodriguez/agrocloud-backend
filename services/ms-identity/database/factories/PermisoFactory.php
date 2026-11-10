<?php

namespace Database\Factories;

use App\Models\Permiso;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Permiso> */
class PermisoFactory extends Factory
{
    protected $model = Permiso::class;

    public function definition(): array
    {
        return ['codigo' => 'identity.'.fake()->unique()->numerify('########'), 'nombre' => fake()->words(3, true), 'modulo' => 'IDENTITY', 'estado' => true];
    }
}
