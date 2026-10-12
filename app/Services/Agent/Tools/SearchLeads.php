<?php

namespace App\Services\Agent\Tools;

use App\Models\Lead;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class SearchLeads extends CrmTool
{
    protected ?string $permission = 'leads.view';

    public function name(): string
    {
        return 'search_leads';
    }

    public function description(): string
    {
        return 'Busca prospectos por nombre, cliente, correo, teléfono o mensaje. Sin texto devuelve los más recientes. Solo ve los prospectos a los que el usuario tiene acceso.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Texto a buscar (opcional).'),
            'stage' => $schema->string()->description('Nombre de la etapa del Kanban para filtrar (opcional).'),
            'limit' => $schema->integer()->description('Máximo de resultados (1-20, por defecto 8).'),
        ];
    }

    protected function run(Request $r): array|string
    {
        $term = trim((string) ($r['query'] ?? ''));
        $leads = Lead::query()->visibleTo($this->ctx->user)->with(['stage:id,name', 'source:id,name'])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%$term%")->orWhere('last_name', 'like', "%$term%")
                ->orWhereRaw("concat(first_name,' ',coalesce(last_name,'')) like ?", ["%$term%"])
                ->orWhere('company', 'like', "%$term%")->orWhere('email', 'like', "%$term%")
                ->orWhere('phone', 'like', "%$term%")->orWhere('message', 'like', "%$term%")))
            ->when(! empty($r['stage']), fn ($q) => $q->whereHas('stage', fn ($s) => $s->where('name', 'like', '%'.$r['stage'].'%')))
            ->latest('id')->limit($this->limit($r))->get();

        $this->ctx->record($this->name(), "Buscó prospectos{$this->suffix($term)}: {$leads->count()}");

        return $leads->map(fn (Lead $l) => [
            'id' => $l->id, 'name' => $l->full_name, 'company' => $l->company, 'email' => $l->email, 'phone' => $l->phone,
            'stage' => $l->stage?->name, 'source' => $l->source?->name, 'score' => $l->score, 'client_id' => $l->client_id,
            'next_follow_up_at' => $l->next_follow_up_at?->toDateTimeString(), 'created_at' => $l->created_at->toDateString(),
        ])->all() ?: 'Sin resultados.';
    }

    private function suffix(string $t): string
    {
        return $t !== '' ? " «{$t}»" : '';
    }
}
