<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_billing_offer_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('offer_key', 60);
            $table->unsignedInteger('version');
            $table->json('terms');
            $table->string('status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('usuario', 'id_usuario')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['offer_key', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_billing_offer_versions');
    }
};
