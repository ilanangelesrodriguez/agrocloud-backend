<?php

namespace App\Models;

use App\Modules\Catalogs\CatalogService;
use App\Modules\Inspections\InspectionWorkflow;
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
        $progress = $inspection ? app(InspectionWorkflow::class)->reviewProgress($inspection) : null;
        $evidenceComplete = $progress && $progress['sampled_fruits'] > 0 && $progress['captured_fruits'] === $progress['sampled_fruits'];
        $humanComplete = $progress && $progress['complete'];
        $aiIdle = $progress && $progress['ai_pending'] === 0;
        $final = in_array($decision?->decision, ['APROBADO', 'RECHAZADO'], true);
        $stages = [
            ['key' => 'reception', 'label' => 'Recepción', 'done' => $this->recepcion()->exists()],
            ['key' => 'sampling', 'label' => 'Muestreo', 'done' => $sample !== null],
            ['key' => 'inspection', 'label' => 'Inicio de inspección', 'done' => $inspection !== null],
            ['key' => 'evidence', 'label' => 'Captura de evidencias', 'done' => $evidenceComplete],
            ['key' => 'analysis', 'label' => 'Procesamiento IA (opcional)', 'done' => (bool) ($evidenceComplete && $aiIdle)],
            ['key' => 'review', 'label' => 'Revisión humana', 'done' => (bool) $humanComplete],
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
            'analysis' => ['Esperar el análisis de IA o continuar mediante revisión manual justificada', $inspectionUrl.'/evidences', 'inspecciones.validar'],
            'review' => ['Revisar detecciones y confirmar los hallazgos', $inspectionUrl.'/evidences', 'inspecciones.validar'],
            'validation' => ['Validar inspección', $inspectionUrl, 'inspecciones.validar'],
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
            'reception' => 'RECEPCION', 'sampling', 'inspection', 'evidence', 'analysis', 'review', 'validation' => 'INSPECTOR',
            'decision' => 'SUPERVISOR', default => null,
        };
        $canExecute = $action && $actor->hasPermission($action[2]) && ($expectedRole === null || $actor->rol->codigo === $expectedRole);
        if ($inspection && in_array($stage, ['evidence', 'analysis', 'review', 'validation'], true)
            && $inspection->id_inspector !== null && $inspection->id_inspector !== $actor->id_usuario) {
            $canExecute = false;
            $reasons[] = 'Esta inspección está asignada a otro inspector.';
        }
        if ($stage === 'decision' && $inspection?->id_inspector === $actor->id_usuario) {
            $canExecute = false;
            $reasons[] = 'El dictamen requiere un supervisor distinto del inspector.';
        }
        if ($stage === 'analysis') {
            $canExecute = false;
            $reasons[] = 'Hay análisis en cola o en procesamiento. La revisión estará disponible cuando finalicen o sean cancelados.';
        }
        if ($stage === 'sampling' && ! app(CatalogService::class)->hasProcedureFor($this)) {
            $canExecute = false;
            $reasons[] = 'El administrador debe habilitar un procedimiento y sus maestros para este producto.';
        }

        return ['currentStage' => $stage, 'nextAction' => $action ? ['label' => $action[0], 'href' => $action[1], 'permission' => $action[2], 'canExecute' => $canExecute] : null,
            'blockingReasons' => $reasons, 'evidenceProgress' => $progress, 'samplingId' => $sample?->getKey(), 'inspectionId' => $inspection?->getKey(),
            'latestDecision' => DictamenCalidad::where('id_lote', $this->getKey())->orderByDesc('id_dictamen')->value('decision'), 'stages' => array_map(fn ($item) => [...$item, 'status' => $item['key'] === 'analysis' && $item['done'] && ($progress['ai_completed'] ?? 0) === 0 ? 'manual' : ($item['done'] ? 'completed' : ($item['key'] === $stage ? 'in_progress' : 'pending'))], $stages)];
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
