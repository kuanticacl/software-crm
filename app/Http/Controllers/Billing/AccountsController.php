<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientService;
use App\Services\Billing\AccountInsights;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Dashboard y Kanban de cuentas: clientes que ya tienen servicios contratados (ingreso recurrente, cobranza y renovaciones). */
class AccountsController extends Controller
{
    public function dashboard(Request $request, AccountInsights $insights): Response
    {
        $user = $request->user();

        return Inertia::render('accounts/Dashboard', [
            ...$insights->dashboard($user->hasPermission('billing.view'), $user->hasPermission('contracts.costs')),
            'can' => ['billing' => $user->hasPermission('billing.view'), 'costs' => $user->hasPermission('contracts.costs')],
        ]);
    }

    public function kanban(Request $request, AccountInsights $insights): Response
    {
        $user = $request->user();
        $q = trim((string) $request->input('q', ''));
        $canBilling = $user->hasPermission('billing.view');
        $view = $request->input('view') === 'invoices' && $canBilling ? 'invoices' : 'accounts';

        return Inertia::render('accounts/Kanban', [
            'view' => $view,
            'q' => $q,
            'accounts' => $view === 'accounts' ? $insights->kanban($q ?: null) : null,
            'pipeline' => $view === 'invoices' ? $insights->invoicePipeline($q ?: null) : null,
            'can' => ['billing' => $canBilling, 'manage' => $user->hasPermission('billing.manage'), 'pay' => $user->hasPermission('billing.mark_paid')],
            'lookups' => $view === 'invoices' ? [
                'clients' => Client::orderBy('name')->get(['id', 'name', 'kind']),
                'services' => ClientService::orderBy('name')->get(['id', 'name', 'client_id', 'currency', 'price']),
                'taxRate' => config('portal.tax_rate'),
            ] : null,
        ]);
    }
}
