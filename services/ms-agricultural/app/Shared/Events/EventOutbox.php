<?php

namespace App\Shared\Events;

use App\Shared\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventOutbox
{
    public static function add(string $type, array $data): void
    {
        $id = (string) Str::uuid();
        $company = app(CompanyContext::class)->company();
        $payload = ['eventId' => $id, 'type' => $type, 'version' => 1, 'companyId' => $company,
            'occurredAt' => now()->toIso8601String(), 'data' => $data];
        DB::table('agricultural_outbox')->insert(['event_id' => $id, 'id_empresa' => $company, 'type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'status' => 'PENDING',
            'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
