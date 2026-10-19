<?php

namespace App\Services\Agent\Tools;

use App\Models\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class SearchClients extends CrmTool
{
    protected ?string $permission = 'clients.view';

    public function name(): string
    {
        return 'search_clients';
    }

    public function description(): string
    {
        return 'Busca EMPRESAS (organizaciones/talleres; no personas) por nombre, razón social, RUT, correo o contacto. Úsalo SIEMPRE antes de crear una empresa para evitar duplicados. Para buscar personas usa search_leads.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('Nombre, RUT, correo, etc.'),
            'limit' => $schema->integer(),
        ];
    }

    protected function run(Request $r): array|string
    {
        $t = trim((string) $r['query']);
        $rut = preg_replace('/[^0-9kK]/', '', $t);
        $clients = Client::query()->where(fn ($w) => $w
            ->where('name', 'like', "%$t%")->orWhere('legal_name', 'like', "%$t%")->orWhere('email', 'like', "%$t%")
            ->orWhere('contact_name', 'like', "%$t%")->orWhere('tax_id', 'like', "%$t%")
            ->when(strlen($rut) >= 7, fn ($q) => $q->orWhereRaw("replace(replace(tax_id,'.',''),'-','') like ?", ["%$rut%"])))
            ->limit($this->limit($r))->get();
        $this->ctx->record($this->name(), "Buscó empresas «{$t}»: {$clients->count()}");

        return $clients->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name, 'legal_name' => $c->legal_name, 'rut' => $c->tax_id, 'contact' => $c->contact_name, 'email' => $c->email, 'phone' => $c->phone, 'url' => url('/clients/'.$c->id)])->all() ?: 'Sin resultados.';
    }
}
