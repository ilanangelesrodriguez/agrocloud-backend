<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa', function (Blueprint $table): void {
            $table->bigIncrements('id_empresa');
            $table->string('ruc', 11)->unique();
            $table->string('razon_social', 200);
            $table->string('nombre_comercial', 150);
            foreach (['grupo_empresarial', 'tipo_empresa', 'rubro', 'direccion', 'ubigeo', 'departamento', 'provincia', 'distrito', 'telefono', 'correo', 'sitio_web', 'logo_url', 'logo_public_id'] as $field) {
                $table->string($field)->nullable();
            }
            $table->text('descripcion')->nullable();
            $table->json('sunat_verificacion')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });
        Schema::create('rol', function (Blueprint $table): void {
            $table->bigIncrements('id_rol');
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 100);
            $table->text('descripcion')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });
        Schema::create('permiso', function (Blueprint $table): void {
            $table->bigIncrements('id_permiso');
            $table->string('codigo', 120)->unique();
            $table->string('nombre', 150);
            $table->string('modulo', 80)->index();
            $table->text('descripcion')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });
        Schema::create('rol_permiso', function (Blueprint $table): void {
            $table->foreignId('id_rol')->constrained('rol', 'id_rol')->cascadeOnDelete();
            $table->foreignId('id_permiso')->constrained('permiso', 'id_permiso')->cascadeOnDelete();
            $table->primary(['id_rol', 'id_permiso']);
        });
        // Preserve existing scaffold accounts and numeric IDs; use one identity table.
        Schema::rename('users', 'usuario');
        Schema::table('usuario', function (Blueprint $table): void {
            $table->renameColumn('id', 'id_usuario');
            $table->renameColumn('name', 'nombre');
            $table->renameColumn('email', 'correo');
            $table->renameColumn('password', 'password_hash');
        });
        Schema::table('usuario', function (Blueprint $table): void {
            $table->foreignId('id_empresa')->nullable()->constrained('empresa', 'id_empresa')->restrictOnDelete();
            $table->foreignId('id_rol')->nullable()->constrained('rol', 'id_rol')->restrictOnDelete();
            $table->string('apellido', 150)->nullable();
            foreach (['telefono', 'cargo', 'foto_perfil_url', 'foto_perfil_public_id', 'invitation_delivery_status'] as $field) {
                $table->string($field)->nullable();
            }
            $table->boolean('estado')->default(true);
            $table->timestamp('ultimo_acceso')->nullable();
            $table->unsignedInteger('auth_version')->default(0);
            $table->string('invitation_hash', 64)->nullable();
            $table->string('pending_email')->nullable()->unique();
            $table->string('email_change_hash', 64)->nullable();
            foreach (['invitation_expires_at', 'invitation_attempted_at', 'invitation_sent_at', 'email_change_expires_at'] as $field) {
                $table->timestamp($field)->nullable();
            }
        });
        DB::table('usuario')->update(['correo' => DB::raw('LOWER(TRIM(correo))')]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX usuario_correo_normalized ON usuario (LOWER(TRIM(correo)))');
            DB::statement('CREATE UNIQUE INDEX usuario_pending_email_normalized ON usuario (LOWER(TRIM(pending_email)))');
        }
        Schema::create('solicitudes_acceso', function (Blueprint $table): void {
            $table->id();
            $table->string('ruc', 11)->unique();
            foreach (['razon_social', 'nombre_comercial', 'tipo_empresa', 'nombre', 'apellido', 'correo', 'telefono'] as $field) {
                $table->string($field);
            }
            $table->text('uso');
            $table->string('estado', 20)->default('PENDIENTE')->index();
            $table->foreignId('id_empresa')->nullable()->constrained('empresa', 'id_empresa')->restrictOnDelete();
            $table->foreignId('id_usuario')->nullable()->constrained('usuario', 'id_usuario')->restrictOnDelete();
            $table->foreignId('revisada_por')->nullable()->constrained('usuario', 'id_usuario')->nullOnDelete();
            $table->text('motivo')->nullable();
            $table->timestamp('decidida_en')->nullable();
            $table->json('historial');
            $table->json('sunat_verificacion')->nullable();
            $table->timestamps();
        });
        Schema::create('identity_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('id_usuario')->constrained('usuario', 'id_usuario')->cascadeOnDelete();
            $table->unsignedInteger('auth_version');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('identity_refresh_tokens', function (Blueprint $table): void {
            $table->string('hash', 64)->primary();
            $table->foreignUuid('session_id')->constrained('identity_sessions')->cascadeOnDelete();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('account_security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('id_empresa')->nullable()->constrained('empresa', 'id_empresa')->nullOnDelete();
            $table->foreignId('id_usuario')->constrained('usuario', 'id_usuario')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('usuario', 'id_usuario')->nullOnDelete();
            $table->string('event', 60);
            $table->json('changes');
            $table->timestamp('created_at');
        });
        Schema::create('security_mail_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('id_usuario')->nullable()->constrained('usuario', 'id_usuario')->nullOnDelete();
            $table->string('kind', 60);
            $table->string('status', 30)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });
        Schema::create('identity_billing_orders', function (Blueprint $table): void {
            $table->string('reference', 36)->primary();
            $table->foreignId('company_id')->constrained('empresa', 'id_empresa')->restrictOnDelete();
            $table->string('offer_key');
            $table->string('customer_email');
            $table->string('kind', 20);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('PEN');
            $table->json('offer');
            $table->string('status', 20)->default('PENDING');
            $table->string('provider_charge_id')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
        Schema::create('suscripciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('id_empresa')->unique()->constrained('empresa', 'id_empresa')->restrictOnDelete();
            $table->string('plan');
            $table->string('estado', 20)->default('ACTIVA');
            $table->timestamp('inicia_en');
            $table->timestamp('vence_en')->index();
            $table->timestamps();
        });
        Schema::create('derechos_acceso', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('id_empresa')->constrained('empresa', 'id_empresa')->cascadeOnDelete();
            $table->string('recurso', 60);
            $table->unsignedBigInteger('limite');
            $table->unsignedBigInteger('consumido')->default(0);
            $table->timestamps();
            $table->unique(['id_empresa', 'recurso']);
        });
        Schema::create('identity_processed_events', function (Blueprint $table): void {
            $table->string('event_id', 150)->primary();
            $table->string('payload_hash', 64);
            $table->timestamp('processed_at');
        });
        Schema::create('identity_usage_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('id_empresa')->constrained('empresa', 'id_empresa')->cascadeOnDelete();
            $table->string('request_id', 100);
            $table->string('recurso', 60);
            $table->unsignedInteger('cantidad');
            $table->timestamp('created_at');
            $table->unique(['id_empresa', 'request_id']);
        });
    }

    public function down(): void
    {
        foreach (['identity_usage_requests', 'identity_processed_events', 'derechos_acceso', 'suscripciones', 'identity_billing_orders', 'security_mail_attempts', 'account_security_events', 'identity_refresh_tokens', 'identity_sessions', 'solicitudes_acceso'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS usuario_correo_normalized');
            DB::statement('DROP INDEX IF EXISTS usuario_pending_email_normalized');
        }
        Schema::table('usuario', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('id_empresa');
            $table->dropConstrainedForeignId('id_rol');
            $table->dropUnique(['pending_email']);
            $table->dropColumn(['apellido', 'telefono', 'cargo', 'foto_perfil_url', 'foto_perfil_public_id', 'invitation_delivery_status', 'estado', 'ultimo_acceso', 'auth_version', 'invitation_hash', 'pending_email', 'email_change_hash', 'invitation_expires_at', 'invitation_attempted_at', 'invitation_sent_at', 'email_change_expires_at']);
            $table->renameColumn('id_usuario', 'id');
            $table->renameColumn('nombre', 'name');
            $table->renameColumn('correo', 'email');
            $table->renameColumn('password_hash', 'password');
        });
        Schema::rename('usuario', 'users');
        foreach (['rol_permiso', 'permiso', 'rol', 'empresa'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
