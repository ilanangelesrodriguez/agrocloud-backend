<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tenant = function (Blueprint $table, string $key = 'id'): void {
            $table->unsignedBigInteger('id_empresa')->index();
            $table->unique(['id_empresa', $key]);
        };
        $ref = function (Blueprint $table, string $column, string $target, string $key = 'id', bool $nullable = false): void {
            $table->unsignedBigInteger($column)->nullable($nullable);
            $table->foreign(['id_empresa', $column])->references(['id_empresa', $key])->on($target)->restrictOnDelete();
        };
        // Quarantine scaffold authentication data without copying it into the domain.
        foreach (['users', 'password_reset_tokens', 'sessions'] as $table) {
            Schema::rename($table, 'legacy_auth_'.$table);
        }
        Schema::create('maestro_valores', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('id_empresa')->nullable()->index();
            $t->string('tipo', 40);
            $t->string('nombre', 200);
            $t->string('codigo', 40)->nullable();
            $t->foreignId('parent_id')->nullable()->constrained('maestro_valores')->restrictOnDelete();
            $t->text('descripcion')->nullable();
            $t->boolean('estado')->default(true);
            $t->string('clave', 64)->unique();
            $t->timestamps();
        });
        Schema::create('maestro_empresa_config', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('id_empresa')->index();
            $t->foreignId('maestro_id')->constrained('maestro_valores')->cascadeOnDelete();
            $t->boolean('estado')->default(true);
            $t->unique(['id_empresa', 'maestro_id']);
        });
        Schema::create('productor', function (Blueprint $t) use ($tenant): void {
            $t->bigIncrements('id_productor');
            $tenant($t, 'id_productor');
            $t->string('ruc', 11);
            $t->string('nombre_razon_social', 200);
            foreach (['fundo', 'ubicacion', 'departamento', 'provincia', 'distrito', 'ubigeo'] as $field) {
                $t->string($field)->nullable();
            }
            $t->json('sunat_verificacion')->nullable();
            $t->timestamps();
            $t->unique(['id_empresa', 'ruc']);
        });
        Schema::create('lote', function (Blueprint $t) use ($tenant, $ref): void {
            $t->bigIncrements('id_lote');
            $tenant($t, 'id_lote');
            $ref($t, 'id_productor', 'productor', 'id_productor');
            $t->string('codigo_lote', 50);
            $t->string('producto', 150);
            $t->string('variedad', 150)->nullable();
            $t->decimal('peso_kg', 10, 2);
            $t->string('unidad_medida', 40)->default('Kilogramo');
            $t->timestamp('fecha_llegada_prevista');
            $t->timestamp('fecha_recepcion')->nullable();
            $t->string('estado', 40)->default('PENDIENTE_RECEPCION');
            $t->timestamps();
            $t->unique(['id_empresa', 'codigo_lote']);
        });
        Schema::create('recepciones', function (Blueprint $t) use ($tenant, $ref): void {
            $t->id();
            $tenant($t);
            $ref($t, 'id_lote', 'lote', 'id_lote');
            $t->unique('id_lote');
            $t->unsignedBigInteger('id_usuario');
            $t->string('tipo_documento', 50);
            $t->string('documento', 100);
            $t->string('transportista', 150)->nullable();
            $t->string('placa', 20)->nullable();
            $t->decimal('peso_recibido_kg', 10, 2);
            $t->text('observaciones')->nullable();
            $t->timestamp('recibido_en');
            $t->timestamps();
        });
        Schema::create('sampling_procedures', function (Blueprint $t) use ($tenant): void {
            $t->id();
            $tenant($t);
            $t->string('nombre', 150);
            $t->string('version', 30);
            $t->string('metodo', 80);
            $t->string('producto', 150)->nullable();
            $t->text('criterio_muestra');
            $t->text('seleccion_frutos');
            $t->unsignedInteger('tamano_recomendado')->nullable();
            $t->unsignedInteger('max_frutos');
            $t->boolean('requiere_poblacion')->default(false);
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['id_empresa', 'nombre', 'version']);
        });
        Schema::create('muestreo', function (Blueprint $t) use ($tenant, $ref): void {
            $t->bigIncrements('id_muestreo');
            $tenant($t, 'id_muestreo');
            $ref($t, 'id_lote', 'lote', 'id_lote');
            $t->unique('id_lote');
            $ref($t, 'procedure_id', 'sampling_procedures', nullable: true);
            $t->unsignedBigInteger('id_inspector');
            $t->unsignedInteger('tamano_muestra');
            $t->string('metodo', 80);
            $t->json('protocolo');
            $t->text('observaciones')->nullable();
            $t->string('estado', 40)->default('REGISTRADO');
            $t->timestamps();
        });
        Schema::create('frutos_muestra', function (Blueprint $t) use ($tenant, $ref): void {
            $t->id();
            $tenant($t);
            $ref($t, 'id_muestreo', 'muestreo', 'id_muestreo');
            $t->unsignedInteger('numero');
            $t->string('codigo', 60)->unique();
            $t->timestamps();
            $t->unique(['id_muestreo', 'numero']);
        });
        Schema::create('inspeccion', function (Blueprint $t) use ($tenant, $ref): void {
            $t->bigIncrements('id_inspeccion');
            $tenant($t, 'id_inspeccion');
            $ref($t, 'id_muestreo', 'muestreo', 'id_muestreo');
            $t->unsignedBigInteger('id_inspector')->nullable();
            $t->unsignedBigInteger('solicitada_por')->nullable();
            $t->string('estado', 40)->default('EN_CAPTURA');
            $t->text('observaciones')->nullable();
            $t->timestamp('validada_en')->nullable();
            $t->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX inspection_one_open ON inspeccion (id_muestreo) WHERE estado = 'EN_CAPTURA'");
        }
        Schema::create('evidencia_inspeccion', function (Blueprint $t) use ($tenant, $ref): void {
            $t->bigIncrements('id_evidencia');
            $tenant($t, 'id_evidencia');
            $ref($t, 'id_inspeccion', 'inspeccion', 'id_inspeccion');
            $ref($t, 'id_fruto', 'frutos_muestra');
            $t->string('file_path');
            $t->string('mime', 40);
            $t->string('image_sha256', 64);
            $t->text('imagen_detectada_url')->nullable();
            $t->uuid('ia_request_id')->nullable();
            $t->string('version_modelo', 255)->nullable();
            $t->string('estado_ia', 30)->default('PENDIENTE');
            $t->json('resultado_ia')->nullable();
            $t->text('error_ia')->nullable();
            $t->string('pertinencia', 20)->default('PENDIENTE');
            $t->string('review_fingerprint', 64)->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamps();
        });
        Schema::create('validacion_deteccion', function (Blueprint $t) use ($tenant, $ref): void {
            $t->bigIncrements('id_validacion');
            $tenant($t, 'id_validacion');
            $ref($t, 'id_evidencia', 'evidencia_inspeccion', 'id_evidencia');
            $t->unsignedBigInteger('id_inspector');
            $t->unsignedInteger('indice_deteccion');
            $t->string('detection_id', 100);
            $t->boolean('confirmada');
            $t->text('observaciones')->nullable();
            $t->timestamps();
            $t->unique(['id_evidencia', 'indice_deteccion']);
        });
        Schema::create('hallazgo_manual', function (Blueprint $t) use ($tenant, $ref): void {
            $t->bigIncrements('id_hallazgo');
            $tenant($t, 'id_hallazgo');
            $ref($t, 'id_inspeccion', 'inspeccion', 'id_inspeccion');
            $ref($t, 'id_evidencia', 'evidencia_inspeccion', 'id_evidencia');
            $t->unsignedBigInteger('id_inspector');
            $t->string('clase', 150);
            $t->string('naturaleza', 40);
            $t->text('observaciones');
            $t->timestamps();
        });
        Schema::create('dictamen_calidad', function (Blueprint $t) use ($tenant, $ref): void {
            $t->bigIncrements('id_dictamen');
            $tenant($t, 'id_dictamen');
            $ref($t, 'id_lote', 'lote', 'id_lote');
            $ref($t, 'id_inspeccion', 'inspeccion', 'id_inspeccion');
            $ref($t, 'reemplaza_dictamen', 'dictamen_calidad', 'id_dictamen', true);
            $t->unsignedBigInteger('id_supervisor');
            $t->string('decision', 25);
            $t->string('tipo_observacion', 40)->nullable();
            $t->string('categoria', 100)->nullable();
            $t->string('motivo', 200)->nullable();
            $t->text('observaciones');
            $t->text('respuesta_observacion')->nullable();
            $t->string('protocolo_calidad', 200);
            $t->timestamps();
            $t->index(['id_empresa', 'id_inspeccion']);
        });
        Schema::create('incidencias', function (Blueprint $t) use ($tenant, $ref): void {
            $t->id();
            $tenant($t);
            $ref($t, 'id_lote', 'lote', 'id_lote');
            $t->string('titulo', 150);
            $t->text('descripcion');
            $t->string('gravedad', 20);
            $t->string('estado', 20)->default('ABIERTA');
            $t->unsignedBigInteger('responsable');
            $t->timestamps();
        });
        Schema::create('eventos_trazabilidad', function (Blueprint $t) use ($ref): void {
            $t->id();
            $t->unsignedBigInteger('id_empresa')->index();
            $ref($t, 'id_lote', 'lote', 'id_lote', true);
            $t->unsignedBigInteger('id_usuario')->nullable();
            $t->string('entidad', 80);
            $t->unsignedBigInteger('registro');
            $t->string('evento', 80);
            $t->json('cambios');
            $t->timestamp('created_at');
            $t->index(['id_empresa', 'id_lote', 'created_at']);
        });
        Schema::create('agricultural_operations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('id_empresa')->index();
            $t->uuid('request_id');
            $t->unsignedBigInteger('actor_id');
            $t->string('resource', 30);
            $t->string('fingerprint', 64);
            $t->json('payload');
            $t->json('result')->nullable();
            $t->boolean('quota_consumed')->default(false);
            $t->uuid('lock_token')->nullable();
            $t->timestamp('locked_until')->nullable();
            $t->timestamps();
            $t->unique(['id_empresa', 'request_id']);
        });
        Schema::create('agricultural_ai_jobs', function (Blueprint $t) use ($ref): void {
            $t->id();
            $t->unsignedBigInteger('id_empresa')->index();
            $ref($t, 'id_evidencia', 'evidencia_inspeccion', 'id_evidencia');
            $t->unique('id_evidencia');
            $t->unsignedBigInteger('actor_id');
            $t->string('status', 20)->default('PENDING');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at');
            $t->uuid('lock_token')->nullable();
            $t->timestamp('locked_until')->nullable();
            $t->timestamps();
        });
        Schema::create('agricultural_outbox', function (Blueprint $t): void {
            $t->uuid('event_id')->primary();
            $t->unsignedBigInteger('id_empresa')->index();
            $t->string('type', 100);
            $t->text('payload');
            $t->string('status', 20)->default('PENDING');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at');
            $t->uuid('lock_token')->nullable();
            $t->timestamp('locked_until')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['agricultural_outbox', 'agricultural_ai_jobs', 'agricultural_operations', 'eventos_trazabilidad', 'incidencias', 'dictamen_calidad', 'hallazgo_manual', 'validacion_deteccion', 'evidencia_inspeccion', 'inspeccion', 'frutos_muestra', 'muestreo', 'sampling_procedures', 'recepciones', 'lote', 'productor', 'maestro_empresa_config', 'maestro_valores'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['users', 'password_reset_tokens', 'sessions'] as $table) {
            Schema::rename('legacy_auth_'.$table, $table);
        }
    }
};
