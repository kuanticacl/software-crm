<?php

namespace App\Services\Mcp;

use App\Models\McpServer;
use App\Services\Agent\AgentContext;
use App\Services\Agent\Tools\CrmTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/** Herramienta del Agent respaldada por un servidor MCP externo. Las que modifican datos esperan la confirmación de la persona. */
class McpTool extends CrmTool
{
    protected ?string $permission = 'mcp.use';

    /** @param  array{name: string, description: string, schema: array<string, mixed>}  $def */
    public function __construct(AgentContext $ctx, private McpServer $server, private array $def, private string $toolName)
    {
        parent::__construct($ctx);
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        $confirm = McpRegistry::needsConfirmation($this->server, $this->def['name']) ? ' Requiere la confirmación del usuario antes de ejecutarse.' : '';

        return '['.$this->server->name.' · herramienta externa MCP] '.($this->def['description'] ?: $this->def['name']).$confirm;
    }

    public function schema(JsonSchema $schema): array
    {
        return SchemaMapper::map($schema, $this->def['schema']);
    }

    protected function run(Request $r): array|string
    {
        $args = $this->arguments($r->all());

        if (McpRegistry::needsConfirmation($this->server, $this->def['name'])) {
            $lines = collect($args)->map(fn ($v, $k) => $k.': '.mb_substr(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), 0, 200))->values()->take(12)->all();
            $this->ctx->propose([
                'op' => 'mcp', 'entity' => 'mcp', 'id' => 0, 'server_id' => $this->server->id, 'tool' => $this->def['name'], 'args' => $args,
                'title' => $this->server->name.' → '.$this->def['name'], 'lines' => $lines ?: ['(sin parámetros)'], 'destructive' => ($this->def['destructive'] ?? false) === true, 'changes' => [],
            ]);
            $this->ctx->record($this->name(), 'Propuso: '.$this->server->name.' → '.$this->def['name'].' (espera confirmación)');

            return 'PENDIENTE DE CONFIRMACIÓN: el usuario ve un recuadro con la acción y los botones Confirmar/Cancelar. Aún NO se ejecutó. Avísale brevemente; no afirmes que ya se hizo.';
        }

        $res = McpRegistry::execute($this->server, $this->def['name'], $args, $this->ctx->user);
        $this->ctx->record($this->name(), $this->server->name.' → '.$this->def['name'], null, ! $res['isError']);

        return McpRegistry::wrapOutput($this->server, $this->def['name'], $res['text'], $res['isError']);
    }

    /** Los parámetros objeto/arreglo complejos llegan como texto JSON: se decodifican según el schema original. @param  array<string, mixed>  $args */
    private function arguments(array $args): array
    {
        $props = (array) ($this->def['schema']['properties'] ?? []);
        foreach ($args as $k => $v) {
            if ($v === null || $v === '') {
                unset($args[$k]);

                continue;
            }
            $type = $props[$k]['type'] ?? null;
            if (is_string($v) && (in_array($type, ['object', 'array'], true) || $type === null || is_array($type)) && preg_match('/^\s*[\[{]/', $v)) {
                $j = json_decode($v, true);
                if ($j !== null) {
                    $args[$k] = $j;
                }
            }
        }

        return $args;
    }
}
