<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\AgentActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class UpdateRecord extends CrmTool
{
    public function name(): string
    {
        return 'update_record';
    }

    public function description(): string
    {
        $fields = collect(['lead', 'client', 'proposal', 'contract', 'invoice'])->map(fn ($e) => "{$e}: ".implode(', ', array_keys(AgentActions::fields($e))))->implode(' | ');

        return 'Propone EDITAR un registro existente (entity: lead=prospecto, client=cliente (persona o empresa), proposal, contract=proyecto/servicio contratado, invoice=cobro/boleta). '
            .'NO aplica el cambio: muestra al usuario un resumen «antes → después» con botones Confirmar/Cancelar y solo se aplica si él confirma. '
            .'Primero busca el registro para obtener su id. Cambia únicamente lo que el usuario pidió. Campos editables — '.$fields.'. '
            .'Fechas YYYY-MM-DD; booleanos true/false; en lead el valor estimado va en estimated_amount + estimated_currency (CLP/UF).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity' => $schema->string()->enum(['lead', 'client', 'proposal', 'contract', 'invoice'])->required(),
            'id' => $schema->integer()->required()->description('Id del registro (de las búsquedas).'),
            'changes' => $schema->array()->items($schema->object([
                'field' => $schema->string()->required()->description('Nombre exacto del campo.'),
                'value' => $schema->string()->required()->description('Nuevo valor (vacío para borrar el dato).'),
            ]))->required()->description('Lista de campos a cambiar.'),
        ];
    }

    protected function run(Request $r): array|string
    {
        $changes = collect((array) $r['changes'])->mapWithKeys(fn ($c) => [(string) ($c['field'] ?? '') => $c['value'] ?? null])->filter(fn ($v, $k) => $k !== '')->all();
        $proposal = (new AgentActions($this->ctx->user))->propose('update', (string) $r['entity'], (int) $r['id'], $changes);

        $this->ctx->propose($proposal);
        $this->ctx->record($this->name(), 'Propuso: '.$proposal['title'].' (espera confirmación)');

        return 'PENDIENTE DE CONFIRMACIÓN: el usuario ve ahora un recuadro con los cambios y los botones Confirmar/Cancelar. Aún NO se aplicó nada. Dile brevemente qué propusiste y que confirme en el recuadro; no repitas la lista completa ni afirmes que ya se cambió.';
    }
}
