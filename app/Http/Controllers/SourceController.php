<?php

namespace App\Http\Controllers;

use App\Models\LeadSource;
use App\Models\Setting;
use App\Models\User;
use App\Services\CascadeDelete;
use App\Services\Leads\LeadAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SourceController extends Controller
{
    public function index(CascadeDelete $cascade): Response
    {
        return Inertia::render('crm/Sources', [
            'sources' => LeadSource::withCount('leads')->with('owners')
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn (LeadSource $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                    'color' => $s->color,
                    'icon' => $s->icon,
                    'is_active' => $s->is_active,
                    'score_weight' => (int) $s->score_weight,
                    'is_system' => $s->is_system,
                    'leads_count' => $s->leads_count,
                    'proposals_count' => $s->leads_count ? $cascade->sourceImpact($s)['proposals'] : 0,
                    'api_key' => $s->api_key, // visible solo para quienes gestionan orígenes
                    'owner_ids' => $s->owners->pluck('id')->values()->all(),
                ]),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'email'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->all(),
            'defaults' => [
                'assignee_id' => (int) Setting::get(LeadAssignment::DEFAULT_ASSIGNEE, 0) ?: null,
                'notify_emails' => (string) Setting::get(LeadAssignment::NOTIFY_EMAILS, ''),
            ],
            'endpoint' => url('/api/v1/leads'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $owners = $data['owner_ids'] ?? [];
        unset($data['owner_ids']);

        $source = LeadSource::create([
            ...$data,
            'slug' => LeadSource::uniqueSlug($data['name']),
            'api_key' => LeadSource::generateKey(),
            'sort_order' => (int) LeadSource::max('sort_order') + 1,
        ]);

        $source->owners()->sync($owners);

        $this->toast("Origen «{$source->name}» creado.");

        return back();
    }

    public function update(Request $request, LeadSource $source): RedirectResponse
    {
        $data = $this->validated($request, $source);
        $owners = $data['owner_ids'] ?? null;
        unset($data['owner_ids']);

        if ($source->is_system) {
            unset($data['is_active']); // el origen "Manual" siempre debe estar activo
        }

        $source->update($data);

        if ($request->has('owner_ids')) {
            $source->owners()->sync($owners ?? []);
        }

        if ($source->wasChanged('score_weight')) {
            // La calidad del origen cambió: se refleja en el puntaje de sus leads.
            $scorer = app(\App\Services\Leads\LeadScorer::class);
            \App\Models\Lead::where('source_id', $source->id)->chunkById(200, fn ($leads) => $leads->each(fn ($l) => $scorer->score($l)));
        }

        $this->toast("Origen «{$source->name}» actualizado.");

        return back();
    }

    /** Responsable por defecto y correos que reciben la alerta de TODO cliente nuevo (cualquier origen). */
    public function defaults(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'notify_emails' => ['nullable', 'string', 'max:1000'],
        ]);

        $raw = (string) ($data['notify_emails'] ?? '');
        $emails = LeadAssignment::parseEmails($raw);
        // Cada dirección escrita debe ser válida: si alguna no lo es, se avisa en vez de descartarla en silencio.
        if (count($emails) !== count(preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY))) {
            return back()->withErrors(['notify_emails' => 'Hay una dirección de correo inválida o repetida. Sepáralas con coma o salto de línea.']);
        }

        Setting::put(LeadAssignment::DEFAULT_ASSIGNEE, ! empty($data['assignee_id']) ? (string) $data['assignee_id'] : null);
        Setting::put(LeadAssignment::NOTIFY_EMAILS, $emails ? implode(', ', $emails) : null);

        $this->toast('Asignación y alertas por defecto guardadas.');

        return back();
    }

    public function regenerateKey(LeadSource $source): RedirectResponse
    {
        $source->update(['api_key' => LeadSource::generateKey()]);

        $this->toast('Nueva API key generada. La anterior dejó de funcionar.');

        return back();
    }

    public function destroy(Request $request, LeadSource $source, CascadeDelete $cascade): RedirectResponse
    {
        if ($source->is_system) {
            $this->toast('Este origen es de sistema y no se puede eliminar.', 'error');

            return back();
        }

        $impact = $cascade->sourceImpact($source);

        if ($impact['leads'] > 0 && ! $request->boolean('confirm_related')) {
            $this->toast('El origen tiene datos relacionados: confirma la eliminación para enviarlos a la papelera.', 'error');

            return back();
        }

        $target = $request->filled('transfer_to') && $impact['leads'] > 0
            ? LeadSource::findOrFail($request->validate([
                'transfer_to' => ['integer', Rule::exists('lead_sources', 'id')->whereNull('deleted_at'), Rule::notIn([$source->id])],
            ])['transfer_to'])
            : null;

        $cascade->source($source, $target);

        if ($target) {
            $this->toast("Origen eliminado. {$impact['clientes']} cliente(s) pasaron a «{$target->name}».");

            return back();
        }

        $this->toast($impact['leads'] > 0
            ? "Origen eliminado junto con {$impact['clientes']} cliente(s) y {$impact['proposals']} propuesta(s) relacionados."
            : 'Origen eliminado.');

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?LeadSource $source = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('lead_sources', 'name')->ignore($source?->id)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'is_active' => ['boolean'],
            'score_weight' => ['nullable', 'integer', 'between:0,10'],
            'owner_ids' => ['nullable', 'array'],
            'owner_ids.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)],
        ]);
        $data['score_weight'] = (int) ($data['score_weight'] ?? 0);

        return $data;
    }
}
