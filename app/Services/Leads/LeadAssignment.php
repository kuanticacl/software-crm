<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A quién se asigna un cliente nuevo y a quién se le avisa.
 *  - Asignación: los responsables del origen (si son varios, se reparten parejo); si el origen no tiene, el
 *    responsable por defecto de «Orígenes». Así ningún cliente queda sin asignar.
 *  - Aviso: el responsable asignado, todos los responsables del origen y los correos por defecto.
 */
class LeadAssignment
{
    public const DEFAULT_ASSIGNEE = 'leads.default_assignee_id';

    public const NOTIFY_EMAILS = 'leads.notify_emails';

    /** Responsables activos del origen. @return Collection<int, User> */
    public function owners(?int $sourceId): Collection
    {
        if (! $sourceId) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->whereIn('id', DB::table('lead_source_user')->where('lead_source_id', $sourceId)->pluck('user_id'))
            ->orderBy('id')
            ->get();
    }

    public function defaultAssignee(): ?User
    {
        $id = (int) Setting::get(self::DEFAULT_ASSIGNEE, 0);

        return $id > 0 ? User::where('is_active', true)->find($id) : null;
    }

    /** Quién recibe el cliente nuevo de este origen. */
    public function assigneeFor(?int $sourceId): ?User
    {
        $owners = $this->owners($sourceId);

        if ($owners->count() === 1) {
            return $owners->first();
        }

        if ($owners->count() > 1) {
            // Reparto parejo: el responsable con menos clientes de este origen (a igualdad, el más antiguo).
            $counts = Lead::query()->where('source_id', $sourceId)->whereIn('assigned_to', $owners->pluck('id'))
                ->selectRaw('assigned_to, count(*) as total')->groupBy('assigned_to')->pluck('total', 'assigned_to');

            return $owners->sort(fn (User $a, User $b) => [$counts[$a->id] ?? 0, $a->id] <=> [$counts[$b->id] ?? 0, $b->id])->first();
        }

        return $this->defaultAssignee();
    }

    /** Correos por defecto que reciben la alerta de todos los clientes nuevos. @return array<int, string> */
    public function notifyEmails(): array
    {
        return self::parseEmails((string) Setting::get(self::NOTIFY_EMAILS, ''));
    }

    /**
     * Destinatarios de la alerta de un cliente nuevo, sin repetir correos: [correo => nombre|null].
     *
     * @return array<string, string|null>
     */
    public function recipients(Lead $lead): array
    {
        $out = [];
        $add = function (?string $email, ?string $name) use (&$out) {
            $email = mb_strtolower(trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && ! array_key_exists($email, $out)) {
                $out[$email] = $name ?: null;
            }
        };

        $assignee = $lead->assigned_to ? User::where('is_active', true)->find($lead->assigned_to) : null;
        $add($assignee?->email, $assignee?->name);
        foreach ($this->owners($lead->source_id) as $owner) {
            $add($owner->email, $owner->name);
        }
        foreach ($this->notifyEmails() as $email) {
            $add($email, null);
        }

        return $out;
    }

    /** @return array<int, string> */
    public static function parseEmails(string $raw): array
    {
        return collect(preg_split('/[\s,;]+/', mb_strtolower($raw), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
    }
}
