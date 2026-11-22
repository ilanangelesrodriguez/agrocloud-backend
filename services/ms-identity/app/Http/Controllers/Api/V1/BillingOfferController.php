<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Subscriptions\OfferCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingOfferController
{
    public function index(Request $request, OfferCatalog $catalog): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $published = $catalog->published();
        $records = array_map(fn (array $record): array => [
            ...$record, 'published_version' => $published[$record['key']]['version'] ?? null,
            'has_unpublished_changes' => $record['status'] === 'DRAFT',
        ], $catalog->latest());

        return response()->json(['data' => array_values($records)]);
    }

    public function show(Request $request, string $offer, OfferCatalog $catalog): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $record = $catalog->find($offer);
        abort_unless($record, 404);

        return response()->json(['data' => $record, 'published' => $catalog->published()[$offer] ?? null]);
    }

    public function history(Request $request, string $offer, OfferCatalog $catalog): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        abort_unless($catalog->find($offer), 404);

        return response()->json(['data' => $catalog->history($offer), 'published' => $catalog->published()[$offer] ?? null]);
    }

    public function store(Request $request, OfferCatalog $catalog): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $key = $request->validate(['key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,60}$/D']])['key'];

        return $this->save($request, $catalog, $key, 0);
    }

    public function update(Request $request, string $offer, OfferCatalog $catalog): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        abort_unless($catalog->find($offer), 404);

        return $this->save($request, $catalog, $offer, (int) $version);
    }

    private function save(Request $request, OfferCatalog $catalog, string $key, int $version): JsonResponse
    {
        $isPlan = in_array($request->input('kind'), ['PLAN', 'RENEWAL'], true);
        $terms = $request->validate([
            'status' => ['required', Rule::in($version ? ['DRAFT', 'PUBLISHED', 'ARCHIVED'] : ['DRAFT', 'PUBLISHED'])],
            'kind' => ['required', Rule::in(['PLAN', 'RENEWAL', 'USER_ADDON', 'LOT_ADDON', 'AI_ADDON'])],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'amount_cents' => ['required', 'integer', 'between:100,999900'],
            'plan' => [$isPlan ? 'required' : 'prohibited', 'string', 'regex:/^[A-Z0-9_]{1,100}$/D', Rule::notIn(['GRATUITO'])],
            'duration_days' => [$isPlan ? 'required' : 'prohibited', 'integer', 'between:1,366'],
            'limits' => [$isPlan ? 'required' : 'prohibited', 'array:usuarios,lotes,analisis_ia'],
            'limits.usuarios' => [Rule::requiredIf($isPlan), 'integer', 'between:1,1000000000'],
            'limits.lotes' => [Rule::requiredIf($isPlan), 'integer', 'between:0,1000000000'],
            'limits.analisis_ia' => [Rule::requiredIf($isPlan), 'integer', 'between:0,1000000000'],
            'quantity' => [$isPlan ? 'prohibited' : 'required', 'integer', 'between:1,1000000'],
            'public' => ['required', 'boolean'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
        ]);
        abort_if(isset($terms['valid_from'], $terms['valid_until']) && $terms['valid_from'] >= $terms['valid_until'], 422, 'Invalid offer window.');
        $status = $terms['status'];
        unset($terms['status']);
        $terms = array_filter($terms, fn (mixed $value): bool => $value !== null);
        $terms['amount_cents'] = (int) $terms['amount_cents'];
        $terms['public'] = $terms['kind'] === 'PLAN' && (bool) $terms['public'];
        if ($isPlan) {
            $terms['duration_days'] = (int) $terms['duration_days'];
            $terms['limits'] = array_map('intval', $terms['limits']);
        } else {
            $terms['quantity'] = (int) $terms['quantity'];
        }

        return response()->json(['data' => $catalog->append($key, $version, $terms, $status, $request->user()->getKey())], $version === 0 ? 201 : 200);
    }
}
