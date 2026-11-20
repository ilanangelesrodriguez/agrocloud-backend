<?php

namespace App\Modules\Catalogs;

use App\Models\Lote;
use App\Models\SamplingProcedure;
use App\Shared\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    public function options(string $type): Collection
    {
        $company = app(CompanyContext::class)->company();

        return DB::table('maestro_valores')->where('tipo', $type)->where('estado', true)
            ->where(fn ($query) => $query->whereNull('id_empresa')->orWhere('id_empresa', $company))
            ->whereNotIn('id', DB::table('maestro_empresa_config')->where('id_empresa', $company)->where('estado', false)->select('maestro_id'))
            ->orderBy('nombre')->get();
    }

    public function availableProcedures(): Builder
    {
        $methods = $this->options('metodo');
        $products = $this->options('producto');

        return SamplingProcedure::where('activo', true)
            ->where(fn ($q) => $q->whereIn('metodo_id', $methods->pluck('id'))->orWhere(fn ($q) => $q->whereNull('metodo_id')->whereIn('metodo', $methods->pluck('nombre'))))
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('producto_id')->whereNull('producto'))
                ->orWhereIn('producto_id', $products->pluck('id'))->orWhere(fn ($q) => $q->whereNull('producto_id')->whereIn('producto', $products->pluck('nombre'))));
    }

    public function applicableProcedures(?int $productId, ?string $product): Builder
    {
        return $this->availableProcedures()->where(fn ($query) => $query->where(fn ($q) => $q->whereNull('producto_id')->whereNull('producto'))
            ->when($productId, fn ($q) => $q->orWhere('producto_id', $productId))
            ->orWhere(fn ($q) => $q->whereNull('producto_id')->where('producto', $product)));
    }

    public function hasProcedureFor(Lote $lot): bool
    {
        return $this->applicableProcedures($lot->producto_id, $lot->producto)->exists();
    }

    public function validate(array $data, array $fields): array
    {
        foreach ($fields as $field => $type) {
            if (! isset($data[$field]) && ! isset($data[$field.'_id'])) {
                continue;
            }
            if (DB::transactionLevel() > 0) {
                DB::table('maestro_valores')->where('tipo', $type)->where(fn ($q) => $q->whereNull('id_empresa')->orWhere('id_empresa', app(CompanyContext::class)->company()))
                    ->when(isset($data[$field.'_id']), fn ($q) => $q->where('id', $data[$field.'_id']), fn ($q) => $q->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim($data[$field] ?? ''))]))->sharedLock()->get();
            }
            $options = $this->options($type);
            $stable = in_array($field, ['producto', 'variedad', 'metodo'], true);
            $matches = isset($data[$field.'_id'])
                ? $options->where('id', (int) $data[$field.'_id'])
                : $options->filter(fn ($item) => mb_strtolower(trim($item->nombre)) === mb_strtolower(trim($data[$field] ?? '')));
            if ($field === 'variedad') {
                $matches = $matches->where('parent_id', $data['producto_id'] ?? null);
            }
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages([$field => 'Selecciona una opción activa y no ambigua del catálogo.']);
            }
            $match = $matches->first();
            $data[$field] = $match->nombre;
            if ($stable) {
                $data[$field.'_id'] = (int) $match->id;
            }
        }

        return $data;
    }

    public function weight(string $amount, string $unit): string
    {
        $selected = $this->options('unidad')->firstWhere('nombre', $unit);
        $factor = match ($selected?->codigo) {
            'kg' => 1, 'g' => 0.001, 't' => 1000, default => 0
        };
        $kg = round((float) $amount * $factor, 2);
        if ($kg < 0.01 || $kg > 99999999.99) {
            throw ValidationException::withMessages(['peso_kg' => 'Peso fuera del rango 0.01..99999999.99 kg.']);
        }

        return number_format($kg, 2, '.', '');
    }
}
