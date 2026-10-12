<?php

namespace App\Services;

use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Limpieza de datos de prueba para empezar a usar el CRM «de verdad».
 * Borra DEFINITIVAMENTE (no va a la papelera) solo datos operativos; nunca toca
 * configuración: orígenes y API keys, etapas, campos, plantillas, audiencias,
 * automatizaciones, servicios, datos de mi empresa, proveedores (Resend/IA),
 * usuarios reales ni roles.
 *
 * Modos:
 *  - demo: solo lo que generaron los seeders de muestra (correos @ejemplo.cl / @correo.cl,
 *          clientes «de demostración» y los ejecutivos del DemoSeeder).
 *  - all:  TODOS los leads, clientes y propuestas (incluye los de la papelera).
 */
class DemoPurge
{
    public const MODES = ['demo', 'all'];

    private const DEMO_DOMAINS = ['@ejemplo.cl', '@correo.cl'];

    private const DEMO_USERS = ['camila@quiebre.cl', 'diego@quiebre.cl', 'valentina@quiebre.cl'];

    /** @return array{leads: int, clients: int, proposals: int, emails: int, users: int} */
    public function preview(string $mode): array
    {
        return [
            'leads' => $this->leads($mode)->count(),
            'clients' => $this->clients($mode)->count(),
            'proposals' => $this->proposals($mode)->count(),
            'emails' => $this->messages($mode)->count(),
            'users' => $this->users($mode)->count(),
        ];
    }

    /** @return array{leads: int, clients: int, proposals: int, emails: int, users: int} */
    public function purge(string $mode): array
    {
        abort_unless(in_array($mode, self::MODES, true), 422);

        return DB::transaction(function () use ($mode) {
            $done = ['leads' => 0, 'clients' => 0, 'proposals' => 0, 'emails' => 0, 'users' => 0];

            // Orden: primero lo que referencia (correos, propuestas), luego leads, clientes y usuarios.
            $done['emails'] = $this->messages($mode)->delete();
            $done['proposals'] = $this->proposals($mode)->forceDelete();
            $this->leads($mode)->get()->each(function (Lead $l) use (&$done) {
                $l->forceDelete(); // notas y actividades se van por cascada de la BD
                $done['leads']++;
            });
            $this->clients($mode)->get()->each(function (Client $c) use (&$done) {
                // Servicios, cobros (con sus PDF), gastos y accesos del portal de la empresa.
                \App\Models\Invoice::withTrashed()->where('client_id', $c->id)->get()->each(function ($i) {
                    $i->pdf_path && \Illuminate\Support\Facades\Storage::disk('local')->delete($i->pdf_path);
                    \App\Models\InvoiceReminder::where('invoice_id', $i->id)->delete();
                    $i->forceDelete();
                });
                \App\Models\ClientService::withTrashed()->where('client_id', $c->id)->get()->each(function ($s) {
                    $s->expenses()->delete();
                    $s->forceDelete();
                });
                \App\Models\User::where('client_id', $c->id)->delete();
                $c->forceDelete();
                $done['clients']++;
            });
            $done['users'] = $this->users($mode)->delete();

            return $done;
        });
    }

    private function isDemoEmail(Builder $q, string $column = 'email'): Builder
    {
        return $q->where(fn (Builder $w) => collect(self::DEMO_DOMAINS)->each(fn ($d) => $w->orWhere($column, 'like', '%'.$d)));
    }

    private function leads(string $mode): Builder
    {
        $q = Lead::withTrashed();

        return $mode === 'all' ? $q : $this->isDemoEmail($q)->orWhereIn('client_id', $this->clients($mode)->select('id'));
    }

    private function clients(string $mode): Builder
    {
        $q = Client::withTrashed();

        return $mode === 'all' ? $q : $this->isDemoEmail($q)->orWhere('notes', 'like', '%creado por el seed inicial%');
    }

    private function proposals(string $mode): Builder
    {
        $q = Proposal::withTrashed();

        return $mode === 'all'
            ? $q
            : $q->where(fn (Builder $w) => $w->whereIn('lead_id', $this->leads($mode)->select('id'))->orWhereIn('client_id', $this->clients($mode)->select('id')));
    }

    private function messages(string $mode): Builder
    {
        $q = EmailMessage::query();

        return $q->where(fn (Builder $w) => $w
            ->whereIn('lead_id', $this->leads($mode)->select('id'))
            ->orWhereIn('client_id', $this->clients($mode)->select('id'))
            ->orWhere(fn (Builder $x) => $this->isDemoEmail($x, 'to_email')));
    }

    private function users(string $mode): Builder
    {
        return User::whereIn('email', self::DEMO_USERS);
    }
}
