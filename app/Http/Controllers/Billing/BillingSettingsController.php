<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Billing\BankAccounts;
use App\Services\Billing\BillingSettings;
use App\Support\ProposalText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('billing/Settings', [
            'settings' => [
                'issue_days_before' => BillingSettings::issueDaysBefore(),
                'first_due_days' => BillingSettings::firstDueDays(),
                'past_due_days' => BillingSettings::pastDueAfterDays(),
                'contract' => BillingSettings::contract(),
                'accounts' => BankAccounts::all(),
                'transfer_note' => BankAccounts::note(),
            ],
            'accountTypes' => BankAccounts::TYPES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'issue_days_before' => ['required', 'integer', 'min:0', 'max:60'],
            'first_due_days' => ['required', 'integer', 'min:0', 'max:60'],
            'past_due_days' => ['required', 'integer', 'min:0', 'max:90'],
            'contract' => ['required', 'string', 'max:60000'],
            'accounts' => ['nullable', 'array', 'max:6'],
            'accounts.*.bank' => ['nullable', 'string', 'max:80'],
            'accounts.*.account_type' => ['nullable', 'string', 'max:40'],
            'accounts.*.number' => ['nullable', 'string', 'max:40'],
            'accounts.*.holder' => ['nullable', 'string', 'max:120'],
            'accounts.*.tax_id' => ['nullable', 'string', 'max:20'],
            'accounts.*.email' => ['nullable', 'email', 'max:120'],
            'transfer_note' => ['nullable', 'string', 'max:500'],
        ]);

        Setting::put('billing.issue_days_before', (string) $data['issue_days_before']);
        Setting::put('billing.first_due_days', (string) $data['first_due_days']);
        Setting::put('billing.past_due_days', (string) $data['past_due_days']);
        Setting::put('billing.contract', ProposalText::sanitize($data['contract']));
        BankAccounts::save($data['accounts'] ?? [], $data['transfer_note'] ?? null);

        $this->toast('Configuración de cobros guardada.');

        return back();
    }
}
