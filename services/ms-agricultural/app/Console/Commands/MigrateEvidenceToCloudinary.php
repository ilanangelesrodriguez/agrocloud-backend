<?php

namespace App\Console\Commands;

use App\Modules\Evidences\EvidenceStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MigrateEvidenceToCloudinary extends Command
{
    protected $signature = 'agricultural:evidences:migrate-cloudinary {--dry-run : Count local evidence records without writing}';

    protected $description = 'Copy local originals to authenticated Cloudinary storage';

    public function handle(EvidenceStorage $storage): int
    {
        $query = DB::table('evidencia_inspeccion')->where('file_path', 'not like', 'cloudinary:%');
        if ($this->option('dry-run')) {
            $this->info('Local records: '.$query->count());

            return self::SUCCESS;
        }

        $failed = 0;
        $migrated = 0;
        foreach ($query->lazyById(100, 'id_evidencia') as $photo) {
            try {
                $bytes = $storage->read($photo->file_path);
                if (! hash_equals($photo->image_sha256, hash('sha256', $bytes))) {
                    throw new RuntimeException('Local evidence hash mismatch.');
                }
                $extension = pathinfo($photo->file_path, PATHINFO_EXTENSION);
                $key = "$photo->id_empresa/$photo->id_inspeccion/legacy-{$photo->id_evidencia}-{$photo->image_sha256}.$extension";
                $reference = $storage->upload($bytes, $key);
                $migrated += DB::table('evidencia_inspeccion')
                    ->where('id_evidencia', $photo->id_evidencia)
                    ->where('file_path', $photo->file_path)
                    ->update(['file_path' => $reference]);
            } catch (\Exception) {
                $failed++;
                $this->error('Could not migrate evidence '.$photo->id_evidencia);
            }
        }

        $this->info("Migrated: $migrated. Failed: $failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
