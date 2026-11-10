<?php

namespace App\Shared\Events;

use App\Shared\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Traceability
{
    public static function record(Model $model, ?int $lot, string $event, array $changes): void
    {
        $context = app(CompanyContext::class);
        DB::table('eventos_trazabilidad')->insert([
            'id_empresa' => $context->company(), 'id_lote' => $lot, 'id_usuario' => $context->actor(),
            'entidad' => class_basename($model), 'registro' => $model->getKey(), 'evento' => $event,
            'cambios' => json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);
    }
}
