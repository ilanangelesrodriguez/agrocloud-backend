<?php

namespace App\Modules\Lots;

use App\Models\Lote;
use App\Models\Productor;
use App\Shared\Events\EventOutbox;
use App\Shared\Events\Traceability;

class LotService
{
    public function create(array $data): array
    {
        Productor::findOrFail($data['id_productor']);
        abort_if(Lote::where('codigo_lote', $data['codigo_lote'])->exists(), 409, 'Lot code is already registered.');
        $lot = Lote::create([...$data, 'estado' => 'PENDIENTE_RECEPCION']);
        Traceability::record($lot, $lot->getKey(), 'LOTE_CREADO', $data);
        EventOutbox::add('lot.created', ['lotId' => $lot->getKey()]);

        return ['id_lote' => $lot->getKey()];
    }
}
