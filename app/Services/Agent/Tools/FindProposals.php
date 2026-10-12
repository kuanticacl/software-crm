<?php

namespace App\Services\Agent\Tools;

use App\Models\Proposal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class FindProposals extends CrmTool
{
    protected ?string $permission = 'proposals.view';

    public function name(): string
    {
        return 'find_proposals';
    }

    public function description(): string
    {
        return 'Busca propuestas y devuelve su ESTADO (borrador, enviada, vista, ajustes solicitados, aceptada, rechazada, vencida), vistas, respuesta del cliente y totales. '
            .'Filtra por texto (número, título, cliente), por estado, cliente (client_id) o cliente (lead_id).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Número (P-2026-0001), título o nombre de cliente/contacto.'),
            'status' => $schema->string()->enum(array_keys(Proposal::STATUSES)),
            'client_id' => $schema->integer(),
            'lead_id' => $schema->integer(),
            'limit' => $schema->integer(),
        ];
    }

    protected function run(Request $r): array|string
    {
        $t = trim((string) ($r['query'] ?? ''));
        $rows = Proposal::query()->visibleTo($this->ctx->user)->with(['client:id,name', 'owner:id,name'])
            ->when($t !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%$t%")->orWhere('title', 'like', "%$t%")
                ->orWhere('recipient', 'like', "%$t%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$t%"))))
            ->when($r['client_id'], fn ($q, $v) => $q->where('client_id', $v))
            ->when($r['lead_id'], fn ($q, $v) => $q->where('lead_id', $v))
            ->latest('id')->limit($this->limit($r, 6, 15))->get();

        if (! empty($r['status'])) {
            $rows = $rows->filter(fn (Proposal $p) => $p->effectiveStatus() === $r['status'])->values();
        }
        $this->ctx->record($this->name(), "Consultó propuestas{$this->suffix($t)}: {$rows->count()}");

        return $rows->map(fn (Proposal $p) => [
            'id' => $p->id, 'number' => $p->number, 'title' => $p->title, 'company' => $p->recipient['company'] ?? $p->client?->name,
            'status' => $p->effectiveStatus(), 'status_label' => Proposal::STATUSES[$p->effectiveStatus()] ?? $p->status,
            'currency' => $p->currency, 'total_net' => $p->total_net, 'total_gross' => $p->total_gross,
            'sent_at' => $p->sent_at?->toDateTimeString(), 'viewed_at' => $p->viewed_at?->toDateTimeString(), 'view_count' => $p->view_count,
            'valid_until' => $p->valid_until?->toDateString(),
            'client_response' => $p->responded_at ? ['by' => $p->responded_by, 'at' => $p->responded_at->toDateTimeString(), 'note' => $p->response_note, 'signed' => (bool) $p->signature_data] : null,
            'url' => url('/proposals/'.$p->id),
        ])->all() ?: 'Sin resultados.';
    }

    private function suffix(string $t): string
    {
        return $t !== '' ? " «{$t}»" : '';
    }
}
