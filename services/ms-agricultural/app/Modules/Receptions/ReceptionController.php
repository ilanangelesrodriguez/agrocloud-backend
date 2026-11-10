<?php

namespace App\Modules\Receptions;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgriculturalResource;
use App\Models\Lote;
use App\Models\Recepcion;
use App\Shared\Events\EventOutbox;
use App\Shared\Events\Traceability;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReceptionController extends Controller
{
    public function store(Request $request, int $lot): AgriculturalResource
    {
        $request->user()->requireRole('RECEPCION');
        $data = $request->validate(['tipo_documento' => ['required', Rule::in(['Guía de remisión', 'Guia de remision', 'Comprobante interno', 'Factura', 'Otro'])],
            'documento' => ['required', 'string', 'max:100'], 'transportista' => ['nullable', 'string', 'max:150'], 'placa' => ['nullable', 'string', 'max:20'],
            'peso_recibido_kg' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'], 'recibido_en' => ['required', 'date', 'before_or_equal:now', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/D'],
            'observaciones' => ['nullable', 'string', 'max:3000']]);
        $data['recibido_en'] = CarbonImmutable::parse($data['recibido_en'])->utc()->toDateTimeString();
        $record = DB::transaction(function () use ($request, $lot, $data): Recepcion {
            $record = Lote::lockForUpdate()->findOrFail($lot);
            abort_unless($record->estado === 'PENDIENTE_RECEPCION' && ! $record->recepcion()->exists(), 409, 'Lot already received or unavailable.');
            if (round((float) $record->peso_kg * 100) !== round((float) $data['peso_recibido_kg'] * 100) && blank($data['observaciones'] ?? null)) {
                throw ValidationException::withMessages(['observaciones' => 'Explica la diferencia de peso.']);
            }
            $reception = Recepcion::create([...$data, 'id_lote' => $lot, 'id_usuario' => $request->user()->id_usuario]);
            $record->update(['estado' => 'RECIBIDO', 'fecha_recepcion' => $data['recibido_en']]);
            Traceability::record($reception, $lot, 'RECEPCION_CONFIRMADA', $data);
            EventOutbox::add('lot.received', ['lotId' => $lot, 'receptionId' => $reception->getKey(), 'recipientPermissions' => ['muestreos.crear']]);

            return $reception;
        }, 3);

        return new AgriculturalResource($record);
    }
}
