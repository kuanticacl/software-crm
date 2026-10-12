<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/leads — recibe un lead desde un formulario, landing, campaña, etc.
 * El origen se determina por la API key con la que se autentica la llamada.
 */
class LeadIngestController extends Controller
{
    private const KNOWN = [
        'first_name', 'last_name', 'name', 'email', 'phone', 'job_title', 'company', 'message',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'ip', 'user_agent', 'referrer', 'landing_url',
        'country', 'region', 'city', 'latitude', 'longitude', 'custom', 'meta', 'email_template', 'email_variables', 'audience',
    ];

    public function store(Request $request, LeadService $leads): JsonResponse
    {
        /** @var LeadSource $source */
        $source = $request->attributes->get('lead_source');

        $data = $request->validate([
            'first_name' => ['required_without:name', 'nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:240'],
            'email' => ['nullable', 'email:rfc', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'ip' => ['nullable', 'ip'],
            'user_agent' => ['nullable', 'string', 'max:1000'],
            'referrer' => ['nullable', 'string', 'max:2000'],
            'landing_url' => ['nullable', 'string', 'max:2000'],
            'country' => ['nullable', 'string', 'max:80'],
            'region' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'custom' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
            'email_template' => ['nullable', 'string', 'max:80'],
            'email_variables' => ['nullable', 'array'],
            'audience' => ['nullable', 'string', 'max:120'],
        ]);

        // "name" completo → nombre y apellido
        if (blank($data['first_name'] ?? null) && filled($data['name'] ?? null)) {
            [$data['first_name'], $data['last_name']] = array_pad(explode(' ', trim($data['name']), 2), 2, null);
        }
        unset($data['name']);

        // Campos personalizados: dentro de "custom" o sueltos en la raíz con la clave del campo.
        $rootExtra = $request->except(self::KNOWN);
        [$custom, $unknownCustom] = app(LeadService::class)->normalizeCustom([...$rootExtra, ...($data['custom'] ?? [])]);

        $meta = [
            ...($data['meta'] ?? []),
            ...($unknownCustom ? ['extra' => $unknownCustom] : []),
        ];

        $capture = $this->captureContext($request, $data);

        $lead = $leads->create([
            ...collect($data)->only([
                'first_name', 'last_name', 'email', 'phone', 'job_title', 'company', 'message',
                ...Lead::UTM_FIELDS, 'user_agent', 'referrer', 'landing_url',
            ])->all(),
            ...$capture,
            'source_id' => $source->id,
            'custom' => $custom ?: null,
            'meta' => $meta ?: null,
        ], null, ['channel' => 'api', 'source' => $source->slug]);

        // Opcional: suscribir al lead a una audiencia (se crea si no existe) para boletines.
        $audience = null;
        if (filled($data['audience'] ?? null) && $lead->email) {
            $audience = $this->subscribe($lead, trim($data['audience']));
        }

        // Opcional: enviar una plantilla al lead recién creado con los datos recibidos.
        $emailStatus = null;
        if (! empty($data['email_template'])) {
            $emailStatus = $this->sendTemplate($lead, $data['email_template'], (array) ($data['email_variables'] ?? []));
        }

        return response()->json([
            'data' => [
                'email' => $emailStatus,
                'audience' => $audience,
                'id' => $lead->id,
                'source' => $source->slug,
                'stage' => $lead->stage?->name,
                'created_at' => $lead->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /** @return array{name: string, status: string} */
    private function subscribe(Lead $lead, string $name): array
    {
        $list = \App\Models\ContactList::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? \App\Models\ContactList::create(['name' => $name, 'description' => 'Creada desde la API de prospectos']);

        // Si el correo ya estaba en la audiencia (p. ej. importado con variables) no se toca su fila.
        $entry = \App\Models\ContactListEntry::firstOrCreate(
            ['contact_list_id' => $list->id, 'email' => mb_strtolower($lead->email)],
            ['name' => $lead->full_name ?: null, 'data' => array_filter(['empresa' => $lead->company, 'lead_id' => $lead->id]) ?: null],
        );

        return ['name' => $list->name, 'status' => $entry->wasRecentlyCreated ? 'subscribed' : 'already_subscribed'];
    }

    /**
     * IP, navegador y ubicación del lead. Si quien llama (p. ej. el backend de un sitio)
     * informa la IP del visitante, se usa esa; si no, se usa la de la petición y,
     * cuando existen, los datos geográficos que entrega el CDN/proxy.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function captureContext(Request $request, array $data): array
    {
        $visitorIpGiven = filled($data['ip'] ?? null);

        $out = [
            'ip_address' => $data['ip'] ?? $request->ip(),
            'user_agent' => $data['user_agent'] ?? $request->userAgent(),
            'referrer' => $data['referrer'] ?? $request->headers->get('referer'),
            'country' => $data['country'] ?? null,
            'region' => $data['region'] ?? null,
            'city' => $data['city'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ];

        if (! $visitorIpGiven) {
            $h = fn (string ...$names) => collect($names)->map(fn ($n) => $request->header($n))->first(fn ($v) => filled($v));

            $out['country'] ??= $h('CF-IPCountry', 'X-Vercel-IP-Country', 'X-AppEngine-Country', 'CloudFront-Viewer-Country');
            $out['region'] ??= $h('CF-Region', 'X-Vercel-IP-Country-Region', 'X-AppEngine-Region', 'CloudFront-Viewer-Country-Region-Name');
            $out['city'] ??= $h('CF-IPCity', 'X-Vercel-IP-City', 'X-AppEngine-City', 'CloudFront-Viewer-City');
            $out['latitude'] ??= $h('CF-IPLatitude', 'X-Vercel-IP-Latitude', 'CloudFront-Viewer-Latitude');
            $out['longitude'] ??= $h('CF-IPLongitude', 'X-Vercel-IP-Longitude', 'CloudFront-Viewer-Longitude');
        }

        return $out;
    }

    /** @param array<string, mixed> $extra @return array{template: string, status: string}|array{template: string, error: string} */
    private function sendTemplate(Lead $lead, string $slug, array $extra): array
    {
        $template = \App\Models\EmailTemplate::where('slug', $slug)->where('is_active', true)->first();

        if (! $template) {
            return ['template' => $slug, 'error' => 'Plantilla no encontrada o inactiva'];
        }
        if (! $lead->email) {
            return ['template' => $slug, 'error' => 'El prospecto no tiene correo'];
        }

        $lead->loadMissing(['source:id,name', 'stage:id,name']);
        $defaults = collect($template->variables ?? [])->filter(fn ($v) => ($v['default'] ?? '') !== '')->mapWithKeys(fn ($v) => [$v['key'] => $v['default']])->all();
        $vars = [...$defaults, ...($lead->meta['extra'] ?? []), ...\App\Services\Email\AudienceBuilder::leadVariables($lead), ...$extra];

        if (\App\Models\EmailSuppression::isSuppressed($lead->email)) {
            return ['template' => $slug, 'status' => 'suppressed'];
        }

        app(\App\Services\Email\EmailService::class)->queueTemplate($template, $lead->email, $lead->full_name, $vars, ['lead_id' => $lead->id, 'track_opens' => $template->category === 'marketing', 'track_clicks' => $template->category === 'marketing']);

        return ['template' => $slug, 'status' => 'queued'];
    }
}
