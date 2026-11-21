<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agricultural_ai_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('id_empresa')->index();
            $table->unsignedBigInteger('id_inspeccion');
            $table->uuid('idempotency_key');
            $table->string('input_hash', 64);
            $table->json('evidence_ids');
            $table->timestamps();
            $table->unique(['id_empresa', 'id_inspeccion', 'idempotency_key'], 'ai_batch_idempotency');
        });
        Schema::table('agricultural_ai_jobs', function (Blueprint $table): void {
            $table->string('stage', 40)->default('QUEUED');
            $table->string('last_error_code', 80)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->index(['status', 'next_attempt_at', 'locked_until'], 'ai_jobs_claim');
        });
    }

    public function down(): void
    {
        Schema::table('agricultural_ai_jobs', function (Blueprint $table): void {
            $table->dropIndex('ai_jobs_claim');
            $table->dropColumn(['stage', 'last_error_code', 'last_error_at', 'started_at', 'completed_at']);
        });
        Schema::dropIfExists('agricultural_ai_batches');
    }
};
