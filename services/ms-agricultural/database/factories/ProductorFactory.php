<?php

namespace Database\Factories;

use App\Models\Productor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Productor> */
class ProductorFactory extends Factory
{
    protected $model = Productor::class;

    public function definition(): array
    {
        return ['ruc' => fake()->unique()->numerify('20#########'), 'nombre_razon_social' => fake()->company()];
    }
}
