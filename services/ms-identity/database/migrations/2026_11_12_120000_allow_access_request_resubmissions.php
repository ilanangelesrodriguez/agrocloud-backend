<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX solicitudes_acceso_ruc_open_unique ON solicitudes_acceso (ruc) WHERE estado <> 'RECHAZADA'");

        Schema::table('solicitudes_acceso', function (Blueprint $table): void {
            $table->dropUnique(['ruc']);
        });
    }

    public function down(): void
    {
        $hasDuplicateRuc = DB::table('solicitudes_acceso')
            ->select('ruc')
            ->groupBy('ruc')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateRuc) {
            throw new \RuntimeException(
                'Cannot restore the unique RUC index while multiple access requests share a RUC. Preserve the request history before attempting this rollback.',
            );
        }

        Schema::table('solicitudes_acceso', function (Blueprint $table): void {
            $table->unique('ruc');
        });

        DB::statement('DROP INDEX solicitudes_acceso_ruc_open_unique');
    }
};
