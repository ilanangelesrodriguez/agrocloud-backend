<?php

namespace App\Modules\Catalogs;

use App\Shared\Tenancy\CompanyContext;
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

    public function validate(array $data, array $fields): array
    {
        foreach ($fields as $field => $type) {
            if (! isset($data[$field]) || $data[$field] === '') {
                continue;
            }
            $parents = $type === 'variedad' ? $this->options('producto')->keyBy('id') : collect();
            $match = $this->options($type)->first(fn ($option) => mb_strtolower($option->nombre) === mb_strtolower(trim($data[$field]))
                && ($type !== 'variedad' || mb_strtolower($parents->get($option->parent_id)?->nombre ?? '') === mb_strtolower($data['producto'] ?? '')));
            if (! $match) {
                throw ValidationException::withMessages([$field => 'Selecciona una opcion activa del catalogo.']);
            }
            if ($type === 'variedad') {
                $parent = $this->options('producto')->firstWhere('id', $match->parent_id);
                if (! $parent || mb_strtolower($parent->nombre) !== mb_strtolower($data['producto'] ?? '')) {
                    throw ValidationException::withMessages([$field => 'La variedad no pertenece al producto.']);
                }
            }
            $data[$field] = $match->nombre;
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
