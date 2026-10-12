<?php

namespace App\Services\Agent\Tools;

use App\Models\ClientService;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class ListContracts extends CrmTool
{
    protected ?string $permission = 'contracts.view';

    public function name(): string
    {
        return 'list_contracts';
    }

    public function description(): string
    {
        return 'Lista los SERVICIOS CONTRATADOS por los clientes (no el catálogo): ciclo de cobro, valor neto, vigencia, renovación, estado y servicios asociados. Filtra por cliente (client_id), texto o estado.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->description('Id del cliente (search_clients).'),
            'query' => $schema->string()->description('Texto del nombre del servicio.'),
            'status' => $schema->string()->enum(['active', 'pending_payment', 'blocked', 'cancelled']),
            'limit' => $schema->integer(),
        ];
    }

    protected function run(Request $r): array|string
    {
        $rows = ClientService::query()->with(['client:id,name', 'parent:id,name'])
            ->when($r['client_id'], fn ($q, $c) => $q->where('client_id', (int) $c))
            ->when($r['query'], fn ($q, $t) => $q->where('name', 'like', "%$t%"))
            ->when($r['status'], fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->limit($this->limit($r, 10, 30))->get();
        $this->ctx->record($this->name(), "Consultó servicios contratados: {$rows->count()}", url('/contracts'));

        return $rows->map(fn (ClientService $s) => [
            'id' => $s->id, 'cliente' => $s->client?->name, 'servicio' => $s->name, 'ciclo' => ClientService::CYCLES[$s->billing_cycle] ?? $s->billing_cycle,
            'valor_neto' => Money::format($s->price, $s->currency), 'inicio' => $s->start_date?->toDateString(), 'termino' => $s->end_date?->toDateString(),
            'renovacion_automatica' => $s->auto_renew, 'estado' => ClientService::STATUSES[$s->effectiveStatus()] ?? $s->status, 'proximo_cobro' => $s->next_charge_on?->toDateString(),
            'asociado_a' => $s->parent?->name, 'url' => url('/contracts/'.$s->id),
        ])->all() ?: 'Sin resultados.';
    }
}
