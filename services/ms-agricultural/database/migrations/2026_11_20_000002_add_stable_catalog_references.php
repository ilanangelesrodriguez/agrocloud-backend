<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['lote' => ['producto', 'variedad'], 'sampling_procedures' => ['producto', 'metodo'], 'muestreo' => ['metodo']] as $table => $fields) {
            foreach ($fields as $field) {
                Schema::table($table, fn (Blueprint $t) => $t->foreignId($field.'_id')->nullable()->constrained('maestro_valores')->restrictOnDelete());
                $parent = $field === 'variedad' ? ' AND t.producto_id IS NOT NULL AND v.parent_id = t.producto_id' : '';
                $where = "v.tipo = ? AND (v.id_empresa IS NULL OR v.id_empresa = t.id_empresa) AND LOWER(TRIM(v.nombre)) = LOWER(TRIM(t.$field))".$parent;
                DB::statement("UPDATE $table t SET {$field}_id = (SELECT MIN(v.id) FROM maestro_valores v WHERE $where) WHERE t.{$field}_id IS NULL AND (SELECT COUNT(*) FROM maestro_valores v WHERE $where) = 1", [$field, $field]);
            }
        }
        Schema::table('evidencia_inspeccion', fn (Blueprint $t) => $t->index(['id_empresa', 'version_modelo', 'created_at'], 'evidence_company_model_date_idx'));
        Schema::table('inspeccion', fn (Blueprint $t) => $t->index(['id_empresa', 'estado', 'id_inspector'], 'inspection_company_work_idx'));
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve stable references and historical snapshots; prepare an explicit corrective migration.');
    }
};
