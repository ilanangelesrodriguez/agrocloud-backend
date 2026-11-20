<?php

namespace App\Models;

use App\Shared\Auth\Actor;
use App\Shared\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lote extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'lote';

    protected $primaryKey = 'id_lote';

    protected $guarded = ['id_lote', 'id_empresa'];

    protected function casts(): array
    {
        return ['id_empresa' => 'integer', 'peso_kg' => 'decimal:2', 'fecha_llegada_prevista' => 'datetime', 'fecha_recepcion' => 'datetime'];
    }

    public function workflowSummary(Actor $actor): array
    {
        $sample = $this->muestreos()->orderByDesc('id_muestreo')->first();
        $inspection = $sample?->inspecciones()->with('latestDecision')->orderByDesc('id_inspeccion')->first();
        $decision = $inspection?->latestDecision;
        $blocked = Incidencia::where('id_lote', $this->getKey())->whereIn('gravedad', ['ALTA', 'CRITICA'])->where('estado', '<>', 'CERRADA')->count();
        $validated = $inspection?->estado === 'VALIDADA';
        $evidenceComplete = $inspection && $sample->frutos()->exists()
            && ! $sample->frutos()->whereDoesntHave('evidencias', fn ($query) => $query->where('id_inspeccion', $inspection->getKey())
                ->where(fn ($query) => $query->whereNull('pertinencia')->orWhere('pertinencia', '<>', 'INVALIDA')))->exists();
        $final = in_array($decision?->decision, ['APROBADO', 'RECHAZADO'], true);
        $stages = [
            ['key' => 'reception', 'label' => 'Recepción', 'done' => $this->recepcion()->exists()],
            ['key' => 'sampling', 'label' => 'Muestreo', 'done' => $sample !== null],
            ['key' => 'inspection', 'label' => 'Inicio de inspección', 'done' => $inspection !== null],
            ['key' => 'evidence', 'label' => 'Evidencias', 'done' => $evidenceComplete],
            ['key' => 'validation', 'label' => 'Validación', 'done' => $validated],
            ['key' => 'decision', 'label' => 'Dictamen', 'done' => $final],
        ];
        $stage = collect($stages)->firstWhere('done', false)['key'] ?? 'completed';
        $inspectionUrl = $inspection ? '/inspections/'.$inspection->getKey() : '/samplings/'.$sample?->getKey();
        $actions = [
            'reception' => ['Confirmar recepción', '/lots/'.$this->getKey().'/reception', 'recepciones.crear'],
            'sampling' => ['Registrar muestreo', '/lots/'.$this->getKey().'/sampling', 'muestreos.crear'],
            'inspection' => ['Iniciar inspección', $inspectionUrl, 'inspecciones.crear'],
            'evidence' => ['Completar y revisar evidencias', $inspectionUrl.'/evidences', 'inspecciones.capturar'],
            'validation' => ['Revisar evidencias y validar inspección', $inspectionUrl, 'inspecciones.validar'],
            'decision' => [$decision?->decision === 'OBSERVADO' ? 'Resolver observación y emitir dictamen' : 'Emitir dictamen', $inspectionUrl, 'calidad.clasificar'],
        ];
        $reasons = $blocked ? ['Hay '.$blocked.' incidencia(s) alta(s) o crítica(s) pendientes de cierre.'] : [];
        if ($this->estado === 'ANULADO') {
            $stage = 'cancelled';
            $reasons[] = 'El lote está anulado.';
        } elseif ($this->estado === 'RECHAZADO') {
            $stage = 'rejected';
        } elseif ($this->estado === 'RETENIDO') {
            $stage = 'blocked';
            $actions[$stage] = ['Atender incidencias bloqueantes', '/lots/'.$this->getKey(), 'incidencias.ver'];
        }
        $action = $actions[$stage] ?? null;
        $expectedRole = match ($stage) {
            'reception' => 'RECEPCION', 'sampling', 'inspection', 'evidence', 'validation' => 'INSPECTOR',
            'decision' => 'SUPERVISOR', default => null,
        };
        $canExecute = $action && $actor->hasPermission($action[2]) && ($expectedRole === null || $actor->rol->codigo === $expectedRole);

        return ['currentStage' => $stage, 'nextAction' => $action ? ['label' => $action[0], 'href' => $action[1], 'permission' => $action[2], 'canExecute' => $canExecute] : null,
            'blockingReasons' => $reasons, 'samplingId' => $sample?->getKey(), 'inspectionId' => $inspection?->getKey(),
            'latestDecision' => DictamenCalidad::where('id_lote', $this->getKey())->orderByDesc('id_dictamen')->value('decision'), 'stages' => $stages];
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class, 'id_productor', 'id_productor');
    }

    public function recepcion(): HasOne
    {
        return $this->hasOne(Recepcion::class, 'id_lote', 'id_lote');
    }

    public function muestreos(): HasMany
    {
        return $this->hasMany(Muestreo::class, 'id_lote', 'id_lote');
    }
}
