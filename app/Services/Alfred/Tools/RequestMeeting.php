<?php

namespace App\Services\Alfred\Tools;

use App\Services\Agent\ToolArgs;
use App\Services\Alfred\AlfredLeads;
use App\Services\LeadService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Solicita una reunión: queda como «por confirmar» en el cliente; el equipo la confirma a mano. */
class RequestMeeting extends AlfredTool
{
    /** Horas de inicio disponibles (bloques de 1 hora, de lunes a viernes). */
    public const TIMES = ['09:30', '10:30', '11:30', '12:30', '13:30', '14:30', '15:30', '16:30', '17:30'];

    public const MODALITIES = ['online', 'presencial'];

    public const MAX_DAYS_AHEAD = 30;

    public function name(): string
    {
        return 'request_meeting';
    }

    public function description(): string
    {
        return 'Registra una SOLICITUD de reunión (demo u otra) con el equipo de la empresa. No queda confirmada: el equipo la confirma por correo. Úsala SOLO con consentimiento explícito de la persona y teniendo nombre, correo o teléfono, empresa, día hábil (lunes a viernes, desde mañana), hora de inicio entre 09:30 y 17:30 y modalidad.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'first_name' => $schema->string()->required()->description('Nombre de la persona.'),
            'last_name' => $schema->string()->description('Apellido.'),
            'email' => $schema->string()->description('Correo electrónico.'),
            'phone' => $schema->string()->description('Teléfono.'),
            'company' => $schema->string()->description('Empresa o inmobiliaria.'),
            'job_title' => $schema->string()->description('Cargo.'),
            'date' => $schema->string()->required()->description('Día preferido, formato YYYY-MM-DD (lunes a viernes, desde mañana).'),
            'time' => $schema->string()->required()->enum(self::TIMES)->description('Hora de inicio preferida (HH:MM, hora de Chile).'),
            'modality' => $schema->string()->required()->enum(self::MODALITIES)->description('online o presencial.'),
            'topic' => $schema->string()->description('Tema de la reunión (demo, servicios, etc.).'),
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
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'in:'.implode(',', self::TIMES)],
            'modality' => ['required', 'in:'.implode(',', self::MODALITIES)],
            'topic' => ['nullable', 'string', 'max:300'],
            'consent' => ['required', 'accepted'],
        ], ['consent.accepted' => 'falta el consentimiento explícito de la persona', 'email.required_without' => 'falta un correo o teléfono', 'phone.required_without' => 'falta un correo o teléfono', 'time.in' => 'la hora debe ser una de: '.implode(', ', self::TIMES)])->validate();

        $day = CarbonImmutable::parse($d['date'], 'America/Santiago')->startOfDay();
        $tomorrow = CarbonImmutable::now('America/Santiago')->addDay()->startOfDay();
        if ($day->isWeekend()) {
            throw ValidationException::withMessages(['date' => 'las reuniones son solo de lunes a viernes']);
        }
        if ($day->lt($tomorrow) || $day->gt($tomorrow->addDays(self::MAX_DAYS_AHEAD))) {
            throw ValidationException::withMessages(['date' => 'la fecha debe ser desde mañana y dentro de los próximos '.self::MAX_DAYS_AHEAD.' días']);
        }

        $leads = app(AlfredLeads::class);
        if ($leads->meetingRequested($this->ctx)) {
            return 'ERROR: ya hay una solicitud de reunión registrada en esta conversación. Dile a la persona que el equipo la confirmará y que, si necesita cambiarla, lo converse con ellos.';
        }

        $lead = $leads->upsert($this->ctx, $d);
        $when = $day->locale('es')->isoFormat('dddd D [de] MMMM').' a las '.$d['time'];
        $topic = trim((string) ($d['topic'] ?? ''));

        app(LeadService::class)->log($lead, 'meeting', null, mb_substr("Solicitud de reunión por confirmar: {$when} ({$d['modality']})", 0, 250), [
            'status' => 'pending', 'via' => 'alfred', 'date' => $d['date'], 'time' => $d['time'], 'modality' => $d['modality'], 'topic' => $topic ?: null,
        ]);
        app(LeadService::class)->update($lead, ['next_follow_up_at' => $d['date'].' '.$d['time'].':00'], null);
        $leads->noteTranscript($lead, $this->ctx);
        $leads->markMeeting($this->ctx);

        return "OK: solicitud de reunión registrada para el {$when} ({$d['modality']}). Aún NO está confirmada: dile a la persona que el equipo la confirmará por correo o teléfono.";
    }
}
