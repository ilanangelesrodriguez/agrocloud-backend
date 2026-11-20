<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $insert = function (string $type, string $name, ?string $code = null, ?int $parent = null): int {
            $key = hash('sha256', 'global|'.$type.'|'.($parent ?? '').'|'.mb_strtolower($name));
            DB::table('maestro_valores')->insertOrIgnore(['id_empresa' => null, 'tipo' => $type, 'nombre' => $name, 'codigo' => $code,
                'parent_id' => $parent, 'estado' => true, 'clave' => $key, 'created_at' => now(), 'updated_at' => now()]);

            return (int) DB::table('maestro_valores')->where('clave', $key)->value('id');
        };
        foreach (['metodo' => ['Aleatorio simple', 'Sistemático', 'Estratificado', 'ALEATORIO', 'SISTEMATICO', 'DIRIGIDO', 'OTRO'],
            'categoria' => ['Extra', 'Categoría I', 'Categoría II'],
            'motivo' => ['Defectos de calidad', 'Documentación incompleta', 'Madurez fuera de rango', 'Daños o contaminación'],
            'defecto' => ['Daño mecánico', 'Daño por plagas', 'Enfermedad visible', 'Madurez fuera de rango']] as $type => $values) {
            foreach ($values as $value) {
                $insert($type, $value);
            }
        }
        foreach (['Palta' => ['Hass', 'Fuerte'], 'Arándano' => [], 'Uva' => ['Red Globe'], 'Mango' => ['Kent', 'Haden']] as $product => $varieties) {
            $parent = $insert('producto', $product);
            foreach ($varieties as $variety) {
                $insert('variedad', $variety, parent: $parent);
            }
        }
        foreach (['Kilogramo' => 'kg', 'Gramo' => 'g', 'Tonelada' => 't'] as $unit => $code) {
            $insert('unidad', $unit, $code);
        }
    }
}
