<?php

namespace App\Shared\Quota;

use App\Integrations\Identity\IdentityClient;
use App\Shared\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UsageService
{
    private function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $data;
    }

    public function run(string $key, string $resource, array $payload, callable $mutation): array
    {
        $key = strtolower($key);
        abort_unless(Str::isUuid($key), 422, 'A UUID Idempotency-Key is required.');
        $context = app(CompanyContext::class);
        $company = $context->company();
        $fingerprint = hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR));
        $token = (string) Str::uuid();
        $operation = DB::transaction(function () use ($key, $resource, $payload, $context, $company, $fingerprint, $token): object {
            DB::table('agricultural_operations')->insertOrIgnore(['id_empresa' => $company, 'request_id' => $key,
                'actor_id' => $context->actor(), 'resource' => $resource, 'fingerprint' => $fingerprint,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('agricultural_operations')->where('id_empresa', $company)->where('request_id', $key)->lockForUpdate()->first();
            abort_unless($row->resource === $resource && $row->fingerprint === $fingerprint && (int) $row->actor_id === $context->actor(), 409, 'Idempotency key reused with other input or actor.');
            if ($row->result !== null) {
                return $row;
            }
            abort_if($row->locked_until && $row->locked_until > now()->toDateTimeString(), 409, 'Operation in progress; retry this same key.');
            DB::table('agricultural_operations')->where('id', $row->id)->update(['lock_token' => $token, 'locked_until' => now()->addMinutes(2), 'updated_at' => now()]);

            return $row;
        }, 3);
        if ($operation->result !== null) {
            return json_decode($operation->result, true, 512, JSON_THROW_ON_ERROR);
        }
        try {
            if (! $operation->quota_consumed) {
                app(IdentityClient::class)->consume($company, 'agri:'.$key, $resource);
                DB::table('agricultural_operations')->where('id', $operation->id)->where('lock_token', $token)->update(['quota_consumed' => true]);
            }

            return DB::transaction(function () use ($company, $operation, $token, $mutation): array {
                $row = DB::table('agricultural_operations')->where('id_empresa', $company)->where('id', $operation->id)->lockForUpdate()->first();
                abort_unless($row->lock_token === $token, 409, 'Operation lease changed. Retry the original key.');
                $result = $mutation();
                DB::table('agricultural_operations')->where('id', $row->id)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR), 'lock_token' => null, 'locked_until' => null, 'updated_at' => now()]);

                return $result;
            }, 3);
        } catch (\Throwable $error) {
            DB::table('agricultural_operations')->where('id', $operation->id)->where('lock_token', $token)->update(['lock_token' => null, 'locked_until' => null, 'updated_at' => now()]);
            throw $error;
        }
    }
}
