<?php

namespace App\Services\AgentWeb\Tools;

use App\Services\Agent\ToolArgs;
use App\Services\AgentWeb\AgentWebLeads;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

class SaveContact extends AgentWebTool
{
    public function name(): string
    {
        return 'save_contact';
    }

    public function description(): string
    {
        return 'Guarda los datos de contacto de la persona para que el equipo de la empresa la contacte. Úsala SOLO después de que la persona haya aceptado explícitamente que guardes sus datos (consentimiento) y tengas su nombre y un correo o teléfono.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'first_name' => $schema->string()->required()->description('Nombre de la persona.'),
            'last_name' => $schema->string()->description('Apellido, si lo dio.'),
            'email' => $schema->string()->description('Correo electrónico.'),
            'phone' => $schema->string()->description('Teléfono.'),
            'company' => $schema->string()->description('Empresa o inmobiliaria.'),
            'job_title' => $schema->string()->description('Cargo.'),
            'interest' => $schema->string()->description('Qué necesita o le interesa, en una frase (etapa del proyecto, servicio, etc.).'),
            'consent' => $schema->boolean()->required()->description('true solo si la persona aceptó de forma explícita que guardes sus datos para que el equipo la contacte.'),
        ];
    }

    protected function run(ToolArgs $r): string
    {
        $d = Validator::make($r->all(), [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'company' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'interest' => ['nullable', 'string', 'max:500'],
            'consent' => ['required', 'accepted'],
        ], ['consent.accepted' => 'falta el consentimiento explícito de la persona', 'email.required_without' => 'falta un correo o teléfono', 'phone.required_without' => 'falta un correo o teléfono'])->validate();

        app(AgentWebLeads::class)->upsert($this->ctx, $d);

        return 'OK: datos guardados. Dile a la persona que el equipo de la empresa la contactará.';
    }
}
