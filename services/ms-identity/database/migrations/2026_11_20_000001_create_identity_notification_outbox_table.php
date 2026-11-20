<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_notification_outbox', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->text('payload');
            $table->string('status')->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->useCurrent();
            $table->uuid('lock_token')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve notification delivery history; use a reviewed corrective migration.');
    }
};
