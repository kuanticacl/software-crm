<?php

namespace App\Services\Billing;

use App\Models\Setting;

/** Datos bancarios de la empresa para recibir transferencias (se muestran en el portal y en los correos de cobro). */
class BankAccounts
{
    public const FIELDS = ['bank', 'account_type', 'number', 'holder', 'tax_id', 'email'];

    public const TYPES = ['Cuenta corriente', 'Cuenta vista', 'Cuenta RUT', 'Cuenta de ahorro'];

    /** @return list<array{bank: string, account_type: string, number: string, holder: string, tax_id: string, email: string}> */
    public static function all(): array
    {
        $raw = json_decode((string) Setting::get('billing.bank_accounts', '[]'), true);

        return collect(is_array($raw) ? $raw : [])->map(fn ($a) => collect(self::FIELDS)->mapWithKeys(fn ($f) => [$f => (string) ($a[$f] ?? '')])->all())
            ->filter(fn ($a) => $a['bank'] !== '' && $a['number'] !== '')->values()->all();
    }

    public static function note(): string
    {
        return (string) Setting::get('billing.transfer_note', '');
    }

    /** @param  list<array<string, mixed>>  $accounts */
    public static function save(array $accounts, ?string $note): void
    {
        $clean = collect($accounts)->map(fn ($a) => collect(self::FIELDS)->mapWithKeys(fn ($f) => [$f => trim((string) ($a[$f] ?? ''))])->all())
            ->filter(fn ($a) => $a['bank'] !== '' && $a['number'] !== '')->values()->all();

        Setting::put('billing.bank_accounts', json_encode($clean, JSON_UNESCAPED_UNICODE));
        Setting::put('billing.transfer_note', $note);
    }

    /** HTML (ya escapado) para correos: una cuenta por bloque, con la nota de comprobante. Se inserta con {{{ bank_details }}}. */
    public static function html(): string
    {
        $accounts = self::all();
        if (! $accounts) {
            return '';
        }

        $blocks = collect($accounts)->map(fn ($a) => implode('<br>', array_filter([
            '<strong>'.e($a['bank']).'</strong> · '.e($a['account_type']),
            'N° '.e($a['number']),
            $a['holder'] ? 'Titular: '.e($a['holder']) : null,
            $a['tax_id'] ? 'RUT: '.e($a['tax_id']) : null,
            $a['email'] ? 'Enviar comprobante a: '.e($a['email']) : null,
        ])))->implode('<br><br>');

        return $blocks.(self::note() ? '<br><br>'.e(self::note()) : '');
    }
}
