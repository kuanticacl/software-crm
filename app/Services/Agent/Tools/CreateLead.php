<?php

namespace App\Services\Agent\Tools;

use App\Models\LeadSource;
use App\Services\LeadService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class CreateLead extends CrmTool
{
    protected ?string $permission = 'leads.create';

    public function name(): string
    {
        return 'create_lead';
    }

    public function description(): string
    {
        return 'Crea un prospecto nuevo (origen «Manual») en la primera etapa del Kanban. Requiere nombre y al menos correo o teléfono. Antes busca con search_leads para no duplicar.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'first_name' => $schema->string()->required(),
            'last_name' => $schema->string(),
            'email' => $schema->string(),
            'phone' => $schema->string(),
            'company' => $schema->string(),
            'job_title' => $schema->string(),
            'message' => $schema->string()->description('Contexto o necesidad del prospecto.'),
            'client_id' => $schema->integer()->description('Cliente (client_id) al que se asocia el prospecto, si ya existe.'),
            'estimated_value' => $schema->number()->description('Valor estimado en pesos (opcional).'),
        ];
    }

    protected function run(Request $r): array|string
    {
        $data = $r->validate([
            'first_name' => ['required', 'string', 'max:120'], 'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'company' => ['nullable', 'string', 'max:255'], 'job_title' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'], 'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
        ]);
        if (empty($data['email']) && empty($data['phone'])) {
            return 'ERROR: indica al menos correo o teléfono.';
        }

        $source = LeadSource::where('slug', LeadSource::MANUAL_SLUG)->first() ?? LeadSource::first();
        $lead = app(LeadService::class)->create([...$data, 'source_id' => $source->id, 'assigned_to' => $this->ctx->user->id], $this->ctx->user);
        $this->ctx->record($this->name(), "Creó el prospecto {$lead->full_name}", url('/leads/'.$lead->id));

        return ['id' => $lead->id, 'name' => $lead->full_name, 'url' => url('/leads/'.$lead->id)];
    }
}
