<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_acceso', function (Blueprint $table): void {
            $table->string('selected_offer_key', 60)->nullable();
            $table->json('selected_offer_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_acceso', function (Blueprint $table): void {
            $table->dropColumn(['selected_offer_key', 'selected_offer_snapshot']);
        });
    }
};
