<?php

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Officially-reviewed, versioned local reference list; not tenant-editable. */
final class UbigeoCatalog
{
    private const SOURCES = [
        'departamento' => ['1_ubigeo_departamentos.json', 'ubigeo_departamentos'],
        'provincia' => ['2_ubigeo_provincias.json', 'ubigeo_provincias'],
        'distrito' => ['3_ubigeo_distritos.json', 'ubigeo_distritos'],
    ];

    private function rows(string $level): array
    {
        if (! isset(self::SOURCES[$level])) {
            throw new \InvalidArgumentException('Invalid level.');
        }
        [$filename, $key] = self::SOURCES[$level];
        $path = base_path('resources/ubigeo/'.$filename);
        abort_unless(is_readable($path), 503, 'El catálogo UBIGEO no está instalado.');
        $json = json_decode((string) file_get_contents($path), true);
        abort_unless(is_array($json[$key] ?? null), 503, 'El catálogo UBIGEO no es válido.');
        return $json[$key];
    }

    public function options(string $level, ?string $parent): array
    {
        if ($level !== 'departamento') {
            $size = $level === 'provincia' ? 2 : 4;
            abort_unless($parent && preg_match('/^\d{'.$size.'}$/D', $parent), 422, 'Ubicación superior no válida.');
        }
        $options = array_values(array_filter($this->rows($level), static function (array $row) use ($level, $parent): bool {
            $code = str_pad((string) ($row['ubigeo'] ?? ''), $level === 'departamento' ? 2 : ($level === 'provincia' ? 4 : 6), '0', STR_PAD_LEFT);
            return $level === 'departamento' || str_starts_with($code, $parent);
        }));
        $result = array_map(static fn (array $row): array => [
            'code' => str_pad((string) $row['ubigeo'], $level === 'departamento' ? 2 : ($level === 'provincia' ? 4 : 6), '0', STR_PAD_LEFT),
            'name' => (string) $row[$level],
        ], $options);
        usort($result, static fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $result;
    }

    public function normalize(array $data): array
    {
        $keys = ['departamento', 'provincia', 'distrito', 'ubigeo'];
        $values = array_map(static fn (string $key) => trim((string) ($data[$key] ?? '')), $keys);
        if (! array_filter($values, static fn ($value) => $value !== '')) {
            return array_replace($data, array_fill_keys($keys, null));
        }
        if (count(array_filter($values, static fn ($value) => $value !== '')) !== 4 || ! preg_match('/^\d{6}$/D', $values[3])) {
            throw ValidationException::withMessages(['ubigeo' => 'Selecciona departamento, provincia y distrito para calcular un UBIGEO válido.']);
        }
        $code = $values[3];
        $expected = [
            'departamento' => $this->options('departamento', null),
            'provincia' => $this->options('provincia', substr($code, 0, 2)),
            'distrito' => $this->options('distrito', substr($code, 0, 4)),
        ];
        foreach (['departamento' => substr($code, 0, 2), 'provincia' => substr($code, 0, 4), 'distrito' => $code] as $level => $selectedCode) {
            $match = collect($expected[$level])->firstWhere('code', $selectedCode);
            if (! $match || mb_strtoupper(Str::ascii((string) $data[$level])) !== mb_strtoupper(Str::ascii($match['name']))) {
                throw ValidationException::withMessages([$level => 'La ubicación indicada no coincide con el catálogo UBIGEO.']);
            }
            $data[$level] = $match['name'];
        }
        $data['ubigeo'] = $code;
        return $data;
    }
}
