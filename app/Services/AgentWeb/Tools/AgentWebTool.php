<?php

namespace App\Services\AgentWeb\Tools;

use App\Services\Agent\ToolArgs;
use App\Services\AgentWeb\AgentWebContext;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Base de las herramientas de AgentWeb. Solo ESCRIBEN datos que la propia persona entregó (contacto y solicitud de
 * reunión): ninguna herramienta lee clientes, propuestas ni configuración del CRM. Los errores vuelven como texto
 * para que el modelo los explique; nunca se exponen detalles internos.
 */
abstract class AgentWebTool implements Tool
{
    public function __construct(protected AgentWebContext $ctx) {}

    abstract public function name(): string;

    /** @return string texto para el modelo */
    abstract protected function run(ToolArgs $args): string;

    final public function handle(Request $request): Stringable|string
    {
        try {
            $out = $this->run(ToolArgs::from($request));
            $this->ctx->record($this->name(), ! str_starts_with($out, 'ERROR'));

            return $out;
        } catch (ValidationException $e) {
            $this->ctx->record($this->name(), false);

            return 'ERROR de validación: '.collect($e->errors())->flatten()->implode(' | ').'. Pídele a la persona el dato correcto.';
        } catch (Throwable $e) {
            report($e);
            $this->ctx->record($this->name(), false);

            return 'ERROR: no se pudo completar la acción. Dile a la persona que el equipo la contactará por los datos de contacto oficiales.';
        }
    }
}
