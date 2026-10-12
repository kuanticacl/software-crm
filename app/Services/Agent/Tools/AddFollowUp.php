<?php

namespace App\Services\Agent\Tools;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Services\LeadService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class AddFollowUp extends CrmTool
{
    public function name(): string
    {
        return 'add_follow_up';
    }

    public function description(): string
    {
        return 'Registra un seguimiento realizado en un prospecto (llamada, correo, WhatsApp, reunión, tarea) y/o agenda el próximo seguimiento. También guarda una nota interna si se pide «anota…».';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'lead_id' => $schema->integer()->required(),
            'type' => $schema->string()->enum(array_keys(LeadActivity::MANUAL))->description('call, email, whatsapp, meeting, task u other.'),
            'description' => $schema->string()->required()->description('Qué se hizo o se acordó.'),
            'next_follow_up_at' => $schema->string()->description('Fecha/hora del próximo seguimiento (YYYY-MM-DD o YYYY-MM-DD HH:MM), opcional.'),
            'as_note' => $schema->boolean()->description('true para guardarlo solo como nota interna en vez de seguimiento.'),
        ];
    }

    protected function run(Request $r): array|string
    {
        $user = $this->ctx->user;
        $lead = Lead::visibleTo($user)->find((int) $r['lead_id']);
        if (! $lead || ! $user->can('note', $lead)) {
            return 'ERROR: prospecto no encontrado o sin permiso para registrar seguimientos.';
        }
        $d = $r->validate([
            'type' => ['nullable', 'in:'.implode(',', array_keys(LeadActivity::MANUAL))],
            'description' => ['required', 'string', 'max:1000'],
            'next_follow_up_at' => ['nullable', 'date'],
            'as_note' => ['nullable', 'boolean'],
        ]);

        if (! empty($d['as_note'])) {
            $lead->notes()->create(['body' => e($d['description']), 'is_private' => false, 'user_id' => $user->id]);
            $what = 'Nota guardada';
        } else {
            app(LeadService::class)->log($lead, $d['type'] ?? 'other', $user, $d['description']);
            $what = 'Seguimiento registrado';
        }
        if (! empty($d['next_follow_up_at'])) {
            app(LeadService::class)->update($lead, ['next_follow_up_at' => $d['next_follow_up_at']], $user);
        }
        $this->ctx->record($this->name(), "$what en {$lead->full_name}".(! empty($d['next_follow_up_at']) ? ' (próximo: '.$d['next_follow_up_at'].')' : ''), url('/leads/'.$lead->id));

        return "OK: $what en el prospecto #{$lead->id}.".(! empty($d['next_follow_up_at']) ? ' Próximo seguimiento: '.$d['next_follow_up_at'].'.' : '');
    }
}
