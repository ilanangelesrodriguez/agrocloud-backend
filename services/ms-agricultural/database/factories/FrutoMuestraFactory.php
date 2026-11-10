<?php

namespace Database\Factories;

use App\Models\FrutoMuestra;
use App\Models\Muestreo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FrutoMuestra> */
class FrutoMuestraFactory extends Factory
{
    protected $model = FrutoMuestra::class;

    public function definition(): array
    {
        return ['id_muestreo' => Muestreo::factory(), 'numero' => 1, 'codigo' => 'FRUIT-'.Str::uuid()];
    }
}
