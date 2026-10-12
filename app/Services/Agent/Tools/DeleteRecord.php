<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\AgentActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class DeleteRecord extends CrmTool
{
    public function name(): string
    {
        return 'delete_record';
    }

    public function description(): string
    {
        return 'Propone ELIMINAR un registro (entity: lead=prospecto, client=cliente (persona o empresa), proposal, contract=proyecto/servicio contratado, invoice=cobro/boleta no pagada, expense=gasto de un servicio). '
            .'NO elimina: muestra al usuario qué se va a eliminar (con lo que arrastra) y botones Confirmar/Cancelar; solo se elimina si él confirma. Casi todo va a la Papelera y se puede restaurar. '
            .'Úsalo solo cuando el usuario pida eliminar de forma explícita; busca primero el registro para tener su id.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity' => $schema->string()->enum(['lead', 'client', 'proposal', 'contract', 'invoice', 'expense'])->required(),
            'id' => $schema->integer()->required()->description('Id del registro (de las búsquedas).'),
        ];
    }

    protected function run(Request $r): array|string
    {
        $proposal = (new AgentActions($this->ctx->user))->propose('delete', (string) $r['entity'], (int) $r['id']);

        $this->ctx->propose($proposal);
        $this->ctx->record($this->name(), 'Propuso: '.$proposal['title'].' (espera confirmación)');

        return 'PENDIENTE DE CONFIRMACIÓN: el usuario ve un recuadro con lo que se eliminaría y los botones Confirmar/Cancelar. Aún NO se eliminó nada. Avísale brevemente que confirme en el recuadro; no afirmes que ya se eliminó.';
    }
}
