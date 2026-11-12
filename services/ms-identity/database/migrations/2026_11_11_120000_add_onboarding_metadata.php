<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_acceso', function (Blueprint $table): void {
            $table->uuid('public_reference')->nullable()->unique();
            $table->string('privacy_version', 80)->nullable();
            $table->timestampTz('privacy_accepted_at')->nullable();
            $table->string('privacy_purpose', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_acceso', function (Blueprint $table): void {
            $table->dropUnique(['public_reference']);
            $table->dropColumn(['public_reference', 'privacy_version', 'privacy_accepted_at', 'privacy_purpose']);
        });
    }
};
