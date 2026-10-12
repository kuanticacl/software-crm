<?php

namespace App\Services\Agent\Tools;

use App\Models\Lead;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class GetLead extends CrmTool
{
    protected ?string $permission = 'leads.view';

    public function name(): string
    {
        return 'get_lead';
    }

    public function description(): string
    {
        return 'Devuelve la ficha de un lead: datos, etapa, puntaje, últimas actividades, notas públicas y propuestas.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['lead_id' => $schema->integer()->required()->description('ID del prospecto.')];
    }

    protected function run(Request $r): array|string
    {
        $lead = Lead::visibleTo($this->ctx->user)->with(['stage:id,name', 'source:id,name', 'assignee:id,name', 'client:id,name'])->find((int) $r['lead_id']);
        if (! $lead) {
            return 'ERROR: prospecto no encontrado o sin acceso.';
        }
        $this->ctx->record($this->name(), "Consultó el prospecto {$lead->full_name}", url('/leads/'.$lead->id));

        return [
            'id' => $lead->id, 'name' => $lead->full_name, 'company' => $lead->company, 'job_title' => $lead->job_title,
            'email' => $lead->email, 'phone' => $lead->phone, 'message' => $lead->message,
            'stage' => $lead->stage?->name, 'source' => $lead->source?->name, 'assigned_to' => $lead->assignee?->name,
            'client' => $lead->client?->only(['id', 'name']), 'priority' => $lead->priority, 'estimated_value' => $lead->estimated_value,
            'score' => $lead->score, 'next_follow_up_at' => $lead->next_follow_up_at?->toDateTimeString(), 'url' => url('/leads/'.$lead->id),
            'activities' => $lead->activities()->latest('occurred_at')->limit(8)->get()->map(fn ($a) => ['type' => $a->type, 'text' => $a->description, 'at' => ($a->occurred_at ?? $a->created_at)->toDateTimeString()])->all(),
            'notes' => $lead->notes()->where('is_private', false)->latest()->limit(5)->get()->map(fn ($n) => strip_tags((string) $n->body))->all(),
            'proposals' => $lead->proposals()->latest('id')->limit(5)->get()->map(fn ($p) => ['id' => $p->id, 'number' => $p->number, 'title' => $p->title, 'status' => $p->effectiveStatus()])->all(),
        ];
    }
}
