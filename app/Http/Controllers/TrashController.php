<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Papelera: lo eliminado con soft delete se puede revisar y restaurar. */
class TrashController extends Controller
{
    /** Los datos eliminados en cascada llevan una marca de tiempo casi igual a la del padre. */
    private const CASCADE_WINDOW_SECONDS = 10;

    private const TYPES = [
        'leads' => ['label' => 'Prospectos', 'model' => Lead::class],
        'clients' => ['label' => 'Clientes', 'model' => Client::class],
        'proposals' => ['label' => 'Propuestas', 'model' => Proposal::class],
        'contracts' => ['label' => 'Servicios', 'model' => \App\Models\ClientService::class],
        'invoices' => ['label' => 'Cobros', 'model' => \App\Models\Invoice::class],
        'sources' => ['label' => 'Orígenes', 'model' => LeadSource::class],
    ];

    public function index(Request $request): Response
    {
        $type = array_key_exists($request->query('type'), self::TYPES) ? $request->query('type') : 'leads';

        $items = $this->query($type)->orderByDesc('deleted_at')->paginate(15)->withQueryString()
            ->through(fn (Model $m) => $this->row($type, $m));

        return Inertia::render('trash/Index', [
            'type' => $type,
            'tabs' => collect(self::TYPES)->map(fn (array $t, string $k) => ['key' => $k, 'label' => $t['label'], 'count' => $this->query($k)->count()])->values(),
            'items' => $items,
        ]);
    }

    public function restore(string $type, int $id): RedirectResponse
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        $record = $this->query($type)->findOrFail($id);
        $restored = [];

        DB::transaction(function () use ($type, $record, &$restored) {
            match ($type) {
                'leads' => $this->restoreLead($record, $restored),
                'proposals', 'invoices' => $record->restore(),
                'contracts' => $this->restoreContract($record, $restored),
                'clients' => $this->restoreClient($record, $restored),
                'sources' => $this->restoreSource($record, $restored),
            };
        });

        $extra = collect($restored)->filter()->map(fn ($n, $k) => $n === 1 ? '1 '.rtrim($k === 'leads' ? 'lead' : 'propuesta') : "$n $k")->implode(' y ');
        $this->toast('Restaurado'.($extra ? " junto con $extra." : '.').($type === 'sources' ? ' Tiene una API key nueva: actualízala donde se use.' : ''));

        return back();
    }

    private function query(string $type): Builder
    {
        return (self::TYPES[$type]['model'])::onlyTrashed();
    }

    /** @return array<string, mixed> */
    private function row(string $type, Model $m): array
    {
        [$title, $subtitle] = match ($type) {
            'leads' => [$m->full_name, collect([$m->company, $m->email])->filter()->implode(' · ')],
            'clients' => [$m->name, collect([$m->tax_id, $m->email])->filter()->implode(' · ')],
            'proposals' => [$m->number.' · '.$m->title, $m->recipient['company'] ?? ''],
            'contracts' => [$m->name, $m->client?->name ?? ''],
            'invoices' => [($m->number ? $m->number.' · ' : '').$m->concept, $m->client?->name ?? ''],
            'sources' => [$m->name, $m->slug],
        };

        return [
            'id' => $m->id,
            'title' => $title,
            'subtitle' => $subtitle,
            'deleted_at' => $m->deleted_at?->toIso8601String(),
            'related' => $this->related($type, $m),
        ];
    }

    /** Datos que acompañan al restaurar (para mostrarlos antes de hacerlo). */
    private function related(string $type, Model $m): array
    {
        return match ($type) {
            'sources' => ['leads' => $this->cascaded(Lead::onlyTrashed()->where('source_id', $m->id), $m->deleted_at)->count()],
            'clients' => [
                'leads' => $this->cascaded(Lead::onlyTrashed()->where('client_id', $m->id), $m->deleted_at)->count(),
                'propuestas' => $this->cascaded(Proposal::onlyTrashed()->where('client_id', $m->id), $m->deleted_at)->count(),
                'servicios' => $this->cascaded(\App\Models\ClientService::onlyTrashed()->where('client_id', $m->id), $m->deleted_at)->count(),
                'cobros' => $this->cascaded(\App\Models\Invoice::onlyTrashed()->where('client_id', $m->id), $m->deleted_at)->count(),
            ],
            'leads' => ['propuestas' => $this->cascaded(Proposal::onlyTrashed()->where('lead_id', $m->id), $m->deleted_at)->count()],
            default => [],
        };
    }

    private function cascaded(Builder $q, ?CarbonInterface $at): Builder
    {
        return $at ? $q->whereBetween('deleted_at', [$at->subSeconds(self::CASCADE_WINDOW_SECONDS), $at->addSeconds(self::CASCADE_WINDOW_SECONDS)]) : $q->whereRaw('1 = 0');
    }

    private function restoreSource(LeadSource $source, array &$restored): void
    {
        $at = $source->deleted_at;
        $source->forceFill([
            'slug' => LeadSource::uniqueSlug($source->name, $source->id),
            'api_key' => LeadSource::generateKey(), // la key anterior se revocó al eliminar
            'is_active' => true,
        ])->save();
        $source->restore();

        $leads = $this->cascaded(Lead::onlyTrashed()->where('source_id', $source->id), $at)->get();
        $restored['leads'] = $this->restoreLeads($leads);
    }

    private function restoreContract(\App\Models\ClientService $service, array &$restored): void
    {
        $at = $service->deleted_at;
        $service->restore();
        $restored['cobros'] = $this->cascaded(\App\Models\Invoice::onlyTrashed()->where('client_service_id', $service->id), $at)->get()->each->restore()->count();
    }

    private function restoreClient(Client $client, array &$restored): void
    {
        $at = $client->deleted_at;
        $client->restore();

        $restored['leads'] = 0;
        foreach ($this->cascaded(Lead::onlyTrashed()->where('client_id', $client->id), $at)->get() as $lead) {
            if ($this->sourceIsAlive($lead)) {
                $lead->restore();
                $restored['leads']++;
            }
        }
        $restored['propuestas'] = $this->cascaded(Proposal::onlyTrashed()->where('client_id', $client->id), $at)->get()->each->restore()->count();
        $restored['servicios'] = $this->cascaded(\App\Models\ClientService::onlyTrashed()->where('client_id', $client->id), $at)->get()->each->restore()->count();
        $restored['cobros'] = $this->cascaded(\App\Models\Invoice::onlyTrashed()->where('client_id', $client->id), $at)->get()->each->restore()->count();
    }

    private function restoreLead(Lead $lead, array &$restored): void
    {
        abort_unless($this->sourceIsAlive($lead), 422, 'El origen de este prospecto está en la papelera: restáuralo primero.');

        $at = $lead->deleted_at;
        $lead->restore();
        $restored['propuestas'] = $this->cascaded(Proposal::onlyTrashed()->where('lead_id', $lead->id), $at)->get()->each->restore()->count();
    }

    /** @param  iterable<Lead>  $leads */
    private function restoreLeads(iterable $leads): int
    {
        $n = 0;
        foreach ($leads as $lead) {
            $at = $lead->deleted_at;
            $lead->restore();
            Proposal::onlyTrashed()->where('lead_id', $lead->id)->get()
                ->filter(fn (Proposal $p) => $p->deleted_at && $at && abs($p->deleted_at->diffInSeconds($at)) <= self::CASCADE_WINDOW_SECONDS)
                ->each->restore();
            $n++;
        }

        return $n;
    }

    private function sourceIsAlive(Lead $lead): bool
    {
        return LeadSource::whereKey($lead->source_id)->exists();
    }
}
