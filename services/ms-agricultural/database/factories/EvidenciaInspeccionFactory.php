<?php

namespace Database\Factories;

use App\Models\EvidenciaInspeccion;
use App\Models\FrutoMuestra;
use App\Models\Inspeccion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EvidenciaInspeccion> */
class EvidenciaInspeccionFactory extends Factory
{
    protected $model = EvidenciaInspeccion::class;

    public function definition(): array
    {
        return ['id_inspeccion' => Inspeccion::factory(), 'id_fruto' => fn (array $attributes) => FrutoMuestra::factory()->create(['id_muestreo' => Inspeccion::findOrFail($attributes['id_inspeccion'])->id_muestreo])->getKey(), 'file_path' => 'fixture-only.jpg', 'mime' => 'image/jpeg', 'image_sha256' => str_repeat('a', 64), 'estado_ia' => 'PENDIENTE', 'pertinencia' => 'PENDIENTE'];
    }
}
