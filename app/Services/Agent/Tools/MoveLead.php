<?php

namespace App\Services\Agent\Tools;

use App\Models\Lead;
use App\Models\PipelineStage;
use App\Services\LeadService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class MoveLead extends CrmTool
{
    public function name(): string
    {
        return 'move_lead';
    }

    public function description(): string
    {
        return 'Mueve un prospecto a otra etapa del Kanban (por nombre de etapa). Si la etapa es de descarte, pide el motivo.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'lead_id' => $schema->integer()->required(),
            'stage' => $schema->string()->required()->description('Nombre de la etapa destino (ver crm_overview).'),
            'lost_reason' => $schema->string()->description('Motivo si es una etapa de descarte.'),
        ];
    }

    protected function run(Request $r): array|string
    {
        $user = $this->ctx->user;
        $lead = Lead::visibleTo($user)->find((int) $r['lead_id']);
        if (! $lead || ! $user->can('move', $lead)) {
            return 'ERROR: prospecto no encontrado o sin permiso para moverlo.';
        }
        $stage = PipelineStage::where('name', $r['stage'])->first() ?? PipelineStage::where('name', 'like', '%'.$r['stage'].'%')->first();
        if (! $stage) {
            return 'ERROR: no existe esa etapa. Etapas: '.PipelineStage::orderBy('sort_order')->pluck('name')->implode(', ').'.';
        }
        if ($stage->type === 'lost' && empty($r['lost_reason'])) {
            return 'ERROR: esa etapa es de descarte; pregunta al usuario el motivo y vuelve a llamar con lost_reason.';
        }

        app(LeadService::class)->move($lead, $stage->id, null, $user, ['lost_reason' => $r['lost_reason'] ?? null]);
        $this->ctx->record($this->name(), "Movió a {$lead->full_name} a «{$stage->name}»", url('/leads/'.$lead->id));

        return "OK: {$lead->full_name} ahora está en «{$stage->name}».";
    }
}
