<?php

namespace App\Services\Subscriptions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfferCatalog
{
    private const TABLE = 'identity_billing_offer_versions';

    public function latest(): array
    {
        $latest = DB::table(self::TABLE)->selectRaw('MAX(id) as id')->groupBy('offer_key');

        return DB::table(self::TABLE)->whereIn('id', $latest)->orderBy('offer_key')->get()
            ->mapWithKeys(fn (object $row): array => [$row->offer_key => $this->serialize($row)])->all();
    }

    public function published(): array
    {
        $latest = DB::table(self::TABLE)->whereIn('status', ['PUBLISHED', 'ARCHIVED'])
            ->selectRaw('MAX(id) as id')->groupBy('offer_key');

        return DB::table(self::TABLE)->whereIn('id', $latest)->where('status', 'PUBLISHED')->get()
            ->mapWithKeys(fn (object $row): array => [$row->offer_key => $this->serialize($row)])->all();
    }

    public function history(string $key): array
    {
        return DB::table(self::TABLE)->where('offer_key', $key)->orderByDesc('version')->get()
            ->map(fn (object $row): array => $this->serialize($row))->all();
    }

    public function find(string $key): ?array
    {
        $row = DB::table(self::TABLE)->where('offer_key', $key)->orderByDesc('version')->first();

        return $row ? $this->serialize($row) : null;
    }

    public function append(string $key, int $expected, array $terms, string $status, int $actor): array
    {
        try {
            $id = DB::transaction(function () use ($key, $expected, $terms, $status, $actor): int {
                $current = $this->find($key);
                abort_unless(($current['version'] ?? 0) === $expected, 409, 'Offer changed; reload before saving.');
                if (! $current && array_key_exists($key, config('identity.billing.offers', []))) {
                    throw ValidationException::withMessages(['offer_key' => 'Esta clave está reservada por la configuración existente.']);
                }
                abort_if($current && ($current['status'] === 'ARCHIVED' ||
                    $current['terms']['kind'] !== $terms['kind'] ||
                    ($current['terms']['plan'] ?? null) !== ($terms['plan'] ?? null)), 422, 'Archived offers and commercial plan identifiers cannot be changed.');

                if ($status === 'PUBLISHED' && (! $current || $current['status'] !== 'DRAFT' || $current['terms'] != $terms)) {
                    $status = 'DRAFT';
                }
                abort_if($status === 'ARCHIVED' && (! $current || $current['terms'] != $terms), 422, 'Archive the current version without changing its terms.');

                return DB::table(self::TABLE)->insertGetId([
                    'offer_key' => $key, 'version' => $expected + 1,
                    'terms' => json_encode($terms, JSON_THROW_ON_ERROR), 'status' => $status,
                    'actor_id' => $actor, 'created_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Offer changed; reload before saving.');
        }

        return $this->serialize(DB::table(self::TABLE)->where('id', $id)->first());
    }

    private function serialize(object $row): array
    {
        return [
            'key' => $row->offer_key, 'version' => $row->version, 'status' => $row->status,
            'terms' => json_decode($row->terms, true, flags: JSON_THROW_ON_ERROR),
            'actor_id' => $row->actor_id, 'created_at' => $row->created_at,
        ];
    }
}
