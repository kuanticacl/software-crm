<?php

namespace App\Services\Agent\Tools;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\Proposal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class CrmOverview extends CrmTool
{
    public function name(): string
    {
        return 'crm_overview';
    }

    public function description(): string
    {
        return 'Resumen del CRM para el usuario: etapas del Kanban con su cantidad de prospectos, orígenes disponibles y propuestas por estado. Úsalo para conocer los nombres de etapas y para responder «cómo vamos».';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $r): array|string
    {
        $u = $this->ctx->user;
        $counts = Lead::visibleTo($u)->selectRaw('stage_id, count(*) as n')->groupBy('stage_id')->pluck('n', 'stage_id');
        $this->ctx->record($this->name(), 'Consultó el resumen del CRM');

        $out = [
            'stages' => PipelineStage::orderBy('sort_order')->get()->map(fn ($s) => ['name' => $s->name, 'type' => $s->type, 'leads' => (int) ($counts[$s->id] ?? 0)])->all(),
            'sources' => LeadSource::where('is_active', true)->pluck('name')->all(),
        ];
        if ($u->hasPermission('proposals.view')) {
            $out['proposals_by_status'] = Proposal::visibleTo($u)->get()->groupBy(fn (Proposal $p) => Proposal::STATUSES[$p->effectiveStatus()] ?? $p->status)->map->count()->all();
        }

        return $out;
    }
}
