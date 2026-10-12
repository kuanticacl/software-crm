<?php

use App\Http\Controllers\Ai\AiEmailController;
use App\Http\Controllers\Ai\AiProposalController;
use App\Http\Controllers\Ai\AiSettingsController;
use App\Http\Controllers\Ai\AiUsageController;
use App\Http\Controllers\Ai\LeadAiController;
use App\Http\Controllers\Ai\McpController;
use App\Http\Controllers\AgencyController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\DemoDataController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\Email\ApiKeyController;
use App\Http\Controllers\Email\AutomationController;
use App\Http\Controllers\Email\CampaignController;
use App\Http\Controllers\Email\ContactListController;
use App\Http\Controllers\Email\EmailMessageController;
use App\Http\Controllers\Email\EmailSettingsController;
use App\Http\Controllers\Email\EmailTemplateController;
use App\Http\Controllers\Email\SuppressionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\LeadActivityController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadNoteController;
use App\Http\Controllers\LeadFieldController;
use App\Http\Controllers\Proposals\ProposalController;
use App\Http\Controllers\Public\ProposalViewController as PublicProposalController;
use App\Http\Controllers\Proposals\ServiceController;
use App\Http\Controllers\Billing\AccountsController;
use App\Http\Controllers\Billing\ContractController;
use App\Http\Controllers\Billing\ExpenseController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\PortalAccessController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\StageController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Inicio: lleva a la primera sección a la que el usuario tiene acceso.
Route::get('/', function (Request $request) {
    $user = $request->user();

    if ($user?->isPortal()) {
        return redirect('/portal');
    }

    foreach (['dashboard.view' => 'dashboard', 'leads.view' => 'leads.index', 'clients.view' => 'clients.index', 'users.view' => 'users.index'] as $permission => $route) {
        if ($user?->hasPermission($permission)) {
            return to_route($route);
        }
    }

    return $user ? to_route('profile.edit') : to_route('login');
})->name('home');

// Propuesta comercial para el cliente: enlace privado (token), sin iniciar sesión.
Route::get('p/{token}', [PublicProposalController::class, 'show'])->name('proposals.public');
Route::get('p/{token}/pdf', [PublicProposalController::class, 'pdf'])->middleware('throttle:20,1')->name('proposals.public.pdf');
Route::post('p/{token}/respond', [PublicProposalController::class, 'answer'])->middleware('throttle:10,1')->name('proposals.public.respond');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->middleware('can:dashboard.view')->name('dashboard');
    Route::get('kanban', [KanbanController::class, 'index'])->middleware('can:dashboard.view')->name('kanban.index');
    Route::get('kanban/stages/{stage}/leads', [KanbanController::class, 'column'])->middleware('can:dashboard.view')->name('kanban.column');

    // Leads
    Route::middleware('can:leads.view')->group(function () {
        Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
        Route::get('leads/create', [LeadController::class, 'create'])->middleware('can:leads.create')->name('leads.create');
        Route::post('leads', [LeadController::class, 'store'])->middleware('can:leads.create')->name('leads.store');
        Route::get('leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
        Route::get('leads/{lead}/edit', [LeadController::class, 'edit'])->name('leads.edit');
        Route::put('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
        Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');
        Route::get('leads/{lead}/panel', [LeadController::class, 'panel'])->name('leads.panel');
        Route::post('leads/bulk', [LeadController::class, 'bulk'])->name('leads.bulk');
        Route::patch('leads/{lead}/quick', [LeadController::class, 'quick'])->name('leads.quick');
        Route::put('leads/{lead}/move', [LeadController::class, 'move'])->name('leads.move');
        Route::put('leads/{lead}/assign', [LeadController::class, 'assign'])->name('leads.assign');
        Route::post('leads/{lead}/notes', [LeadNoteController::class, 'store'])->name('leads.notes.store');
        Route::delete('leads/{lead}/notes/{note}', [LeadNoteController::class, 'destroy'])->name('leads.notes.destroy');
        Route::post('leads/{lead}/activities', [LeadActivityController::class, 'store'])->name('leads.activities.store');
        Route::post('leads/{lead}/score', [LeadAiController::class, 'rescore'])->name('leads.score');
        Route::middleware(['can:ai.use', 'throttle:20,1'])->group(function () {
            Route::post('leads/{lead}/ai/analyze', [LeadAiController::class, 'analyze'])->name('leads.ai.analyze');
            Route::post('leads/{lead}/ai/apply', [LeadAiController::class, 'apply'])->name('leads.ai.apply');
        });
    });

    // Usuarios
    Route::get('users', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->middleware('can:users.create')->name('users.create');
    Route::post('users', [UserController::class, 'store'])->middleware('can:users.create')->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->middleware('can:users.update')->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('can:users.update')->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('can:users.delete')->name('users.destroy');

    // Clientes
    Route::get('clients', [ClientController::class, 'index'])->middleware('can:clients.view')->name('clients.index');
    Route::get('clients/create', [ClientController::class, 'create'])->middleware('can:clients.create')->name('clients.create');
    Route::post('clients', [ClientController::class, 'store'])->middleware('can:clients.create')->name('clients.store');
    Route::get('clients/{client}', [ClientController::class, 'show'])->middleware('can:clients.view')->name('clients.show');
    Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->middleware('can:clients.update')->name('clients.edit');
    Route::put('clients/{client}', [ClientController::class, 'update'])->middleware('can:clients.update')->name('clients.update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->middleware('can:clients.delete')->name('clients.destroy');

    // Dashboard y Kanban de cuentas (empresas con servicios contratados)
    Route::get('accounts', [AccountsController::class, 'dashboard'])->middleware('can:contracts.view')->name('accounts.dashboard');
    Route::get('accounts/kanban', [AccountsController::class, 'kanban'])->middleware('can:contracts.view')->name('accounts.kanban');

    // Servicios contratados (con costos y gastos internos)
    Route::prefix('contracts')->group(function () {
        Route::get('/', [ContractController::class, 'index'])->middleware('can:contracts.view')->name('contracts.index');
        Route::get('create', [ContractController::class, 'create'])->middleware('can:contracts.create')->name('contracts.create');
        Route::post('/', [ContractController::class, 'store'])->middleware('can:contracts.create')->name('contracts.store');
        Route::get('{clientService}', [ContractController::class, 'show'])->middleware('can:contracts.view')->name('contracts.show');
        Route::get('{clientService}/edit', [ContractController::class, 'edit'])->middleware('can:contracts.update')->name('contracts.edit');
        Route::put('{clientService}', [ContractController::class, 'update'])->middleware('can:contracts.update')->name('contracts.update');
        Route::delete('{clientService}', [ContractController::class, 'destroy'])->middleware('can:contracts.delete')->name('contracts.destroy');
        Route::post('{clientService}/expenses', [ExpenseController::class, 'store'])->middleware('can:contracts.costs')->name('contracts.expenses.store');
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('can:contracts.costs')->name('contracts.expenses.destroy');
    });

    // Facturación y cobranza
    Route::prefix('billing')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->middleware('can:billing.view')->name('billing.index');
        Route::put('settings', [InvoiceController::class, 'settings'])->middleware('can:billing.settings')->name('billing.settings');
        Route::put('bank-accounts', [InvoiceController::class, 'bankAccounts'])->middleware('can:billing.settings')->name('billing.bank-accounts');
        Route::get('invoices/{invoice}/download', [InvoiceController::class, 'download'])->middleware('can:billing.view')->name('billing.download');
        Route::middleware('can:billing.manage')->group(function () {
            Route::post('invoices', [InvoiceController::class, 'store'])->name('billing.store');
            Route::post('invoices/extract', [InvoiceController::class, 'extract'])->middleware(['can:ai.use', 'throttle:15,1'])->name('billing.extract');
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('billing.update');
            Route::post('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('billing.pdf');
            Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('billing.issue');
            Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->middleware('throttle:30,1')->name('billing.send');
        });
        Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->middleware('can:billing.mark_paid')->name('billing.pay');
        Route::post('invoices/{invoice}/reopen', [InvoiceController::class, 'reopen'])->middleware('can:billing.mark_paid')->name('billing.reopen');
        Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('can:billing.delete')->name('billing.cancel');
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('can:billing.delete')->name('billing.destroy');
    });

    // Accesos de una empresa al portal de clientes
    Route::middleware('can:portal.manage')->group(function () {
        Route::post('clients/{client}/portal-users', [PortalAccessController::class, 'store'])->name('portal-users.store');
        Route::post('clients/{client}/portal-preview', [PortalAccessController::class, 'preview'])->name('portal-preview.start');
        Route::post('portal-users/{user}/reset', [PortalAccessController::class, 'reset'])->middleware('throttle:20,1')->name('portal-users.reset');
        Route::post('portal-users/{user}/toggle', [PortalAccessController::class, 'toggle'])->name('portal-users.toggle');
        Route::delete('portal-users/{user}', [PortalAccessController::class, 'destroy'])->name('portal-users.destroy');
    });

    // Configuración del CRM: orígenes (con API key), etapas del Kanban y campos personalizados
    Route::prefix('crm')->group(function () {
        Route::middleware('can:sources.manage')->group(function () {
            Route::get('sources', [SourceController::class, 'index'])->name('sources.index');
            Route::post('sources', [SourceController::class, 'store'])->name('sources.store');
            Route::put('sources/{source}', [SourceController::class, 'update'])->name('sources.update');
            Route::post('sources/{source}/regenerate-key', [SourceController::class, 'regenerateKey'])->name('sources.regenerate-key');
            Route::delete('sources/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');
        });

        Route::middleware('can:stages.manage')->group(function () {
            Route::get('stages', [StageController::class, 'index'])->name('stages.index');
            Route::post('stages', [StageController::class, 'store'])->name('stages.store');
            Route::put('stages/reorder', [StageController::class, 'reorder'])->name('stages.reorder');
            Route::put('stages/{stage}', [StageController::class, 'update'])->name('stages.update');
            Route::delete('stages/{stage}', [StageController::class, 'destroy'])->name('stages.destroy');
        });

        Route::middleware('can:fields.manage')->group(function () {
            Route::get('fields', [LeadFieldController::class, 'index'])->name('fields.index');
            Route::post('fields', [LeadFieldController::class, 'store'])->name('fields.store');
            Route::put('fields/reorder', [LeadFieldController::class, 'reorder'])->name('fields.reorder');
            Route::put('fields/{field}', [LeadFieldController::class, 'update'])->name('fields.update');
            Route::delete('fields/{field}', [LeadFieldController::class, 'destroy'])->name('fields.destroy');
        });
    });

    // Propuestas comerciales y catálogo de servicios
    Route::prefix('proposals')->group(function () {
        Route::get('/', [ProposalController::class, 'index'])->middleware('can:proposals.view')->name('proposals.index');
        Route::get('search', [ProposalController::class, 'search'])->middleware('can:proposals.view')->name('proposals.search');
        Route::middleware('can:proposals.create')->group(function () {
            Route::get('create', [ProposalController::class, 'create'])->name('proposals.create');
            Route::post('/', [ProposalController::class, 'store'])->name('proposals.store');
            Route::post('preview', [ProposalController::class, 'preview'])->name('proposals.preview');
            Route::get('{proposal}/edit', [ProposalController::class, 'edit'])->name('proposals.edit');
            Route::put('{proposal}', [ProposalController::class, 'update'])->name('proposals.update');
            Route::post('{proposal}/duplicate', [ProposalController::class, 'duplicate'])->name('proposals.duplicate');
        });
        Route::middleware('can:proposals.send')->group(function () {
            Route::post('{proposal}/send', [ProposalController::class, 'send'])->name('proposals.send');
            Route::post('{proposal}/mark-sent', [ProposalController::class, 'markSentManually'])->name('proposals.mark-sent');
            Route::put('{proposal}/status', [ProposalController::class, 'status'])->name('proposals.status');
        });
        Route::delete('{proposal}', [ProposalController::class, 'destroy'])->middleware('can:proposals.delete')->name('proposals.destroy');
        Route::middleware('can:proposals.view')->group(function () {
            Route::get('{proposal}', [ProposalController::class, 'show'])->name('proposals.show');
            Route::get('{proposal}/document', [ProposalController::class, 'document'])->name('proposals.document');
            Route::get('{proposal}/pdf', [ProposalController::class, 'pdf'])->name('proposals.pdf');
        });
    });
    Route::middleware(['can:ai.use', 'throttle:20,1'])->prefix('proposals/ai')->group(function () {
        Route::post('draft', [AiProposalController::class, 'draft'])->middleware('can:proposals.create')->name('proposals.ai.draft');
        Route::post('improve', [AiProposalController::class, 'improve'])->middleware('can:proposals.create')->name('proposals.ai.improve');
        Route::post('suggest', [AiProposalController::class, 'suggest'])->middleware('can:proposals.create')->name('proposals.ai.suggest');
        Route::post('service', [AiProposalController::class, 'service'])->name('proposals.ai.service');
    });
    Route::get('leads/{lead}/proposals', [ProposalController::class, 'forLead'])->middleware('can:proposals.view')->name('leads.proposals');
    Route::post('leads/{lead}/proposals/attach', [ProposalController::class, 'attach'])->middleware('can:proposals.create')->name('leads.proposals.attach');
    Route::delete('leads/{lead}/proposals/{proposal}/attach', [ProposalController::class, 'detach'])->middleware('can:proposals.create')->name('leads.proposals.detach');
    Route::middleware('can:agent.use')->prefix('agent')->name('agent.')->group(function () {
        Route::get('status', [AgentController::class, 'status'])->name('status');
        Route::post('chat', [AgentController::class, 'chat'])->middleware('throttle:20,1')->name('chat');
        Route::post('attachments', [AgentController::class, 'attachments'])->middleware('throttle:30,1')->name('attachments');
        Route::post('transcribe', [AgentController::class, 'transcribe'])->middleware('throttle:30,1')->name('transcribe');
        Route::post('actions/{id}/confirm', [AgentController::class, 'confirm'])->middleware('throttle:30,1')->name('actions.confirm');
        Route::post('actions/{id}/cancel', [AgentController::class, 'cancel'])->name('actions.cancel');
    });

    Route::middleware('can:demo.purge')->group(function () {
        Route::get('demo-data', [DemoDataController::class, 'index'])->name('demo-data.index');
        Route::post('demo-data/purge', [DemoDataController::class, 'purge'])->name('demo-data.purge');
    });

    Route::middleware('can:trash.manage')->group(function () {
        Route::get('trash', [TrashController::class, 'index'])->name('trash.index');
        Route::post('trash/{type}/{id}/restore', [TrashController::class, 'restore'])->name('trash.restore');
    });

    Route::prefix('identidad-visual')->name('brand.')->middleware('can:brand.manage')->group(function () {
        Route::get('/', [\App\Http\Controllers\BrandController::class, 'index'])->name('index');
        Route::put('/', [\App\Http\Controllers\BrandController::class, 'update'])->name('update');
        Route::post('reset', [\App\Http\Controllers\BrandController::class, 'reset'])->name('reset');
        Route::post('hero', [\App\Http\Controllers\BrandController::class, 'hero'])->middleware('throttle:20,1')->name('hero');
        Route::delete('hero', [\App\Http\Controllers\BrandController::class, 'removeHero'])->name('hero.remove');
    });
    Route::get('mi-empresa', [AgencyController::class, 'edit'])->middleware('can:agency.manage')->name('agency.edit');
    Route::put('mi-empresa', [AgencyController::class, 'update'])->middleware('can:agency.manage')->name('agency.update');
    Route::redirect('agency', '/mi-empresa', 301); // dirección anterior
    Route::prefix('services')->group(function () {
        Route::get('/', [ServiceController::class, 'index'])->middleware('can:services.view')->name('services.index');
        Route::middleware('can:services.manage')->group(function () {
            Route::post('/', [ServiceController::class, 'store'])->name('services.store');
            Route::put('{service}', [ServiceController::class, 'update'])->name('services.update');
            Route::delete('{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
        });
    });

    // Email marketing
    Route::prefix('email')->group(function () {
        Route::middleware('can:email_settings.manage')->group(function () {
            Route::get('settings', [EmailSettingsController::class, 'edit'])->name('email.settings');
            Route::put('settings', [EmailSettingsController::class, 'update'])->name('email.settings.update');
            Route::post('settings/test', [EmailSettingsController::class, 'test'])->name('email.settings.test');
            Route::get('suppressions', [SuppressionController::class, 'index'])->name('suppressions.index');
            Route::post('suppressions', [SuppressionController::class, 'store'])->name('suppressions.store');
            Route::delete('suppressions/{suppression}', [SuppressionController::class, 'destroy'])->name('suppressions.destroy');
            Route::get('api', [ApiKeyController::class, 'index'])->name('api-keys.index');
            Route::post('api', [ApiKeyController::class, 'store'])->name('api-keys.store');
            Route::put('api/{apiKey}', [ApiKeyController::class, 'update'])->name('api-keys.update');
            Route::delete('api/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
        });

        // Asistente de IA para mailings (opcional)
        Route::middleware(['can:ai.use', 'can:templates.manage', 'throttle:20,1'])->prefix('ai')->group(function () {
            Route::post('design', [AiEmailController::class, 'design'])->name('email.ai.design');
            Route::post('subjects', [AiEmailController::class, 'subjects'])->name('email.ai.subjects');
        });

        // Audiencias (listas)
        Route::middleware('can:lists.manage')->group(function () {
            Route::get('lists', [ContactListController::class, 'index'])->name('lists.index');
            Route::post('lists', [ContactListController::class, 'store'])->name('lists.store');
            Route::get('lists/{list}', [ContactListController::class, 'show'])->name('lists.show');
            Route::put('lists/{list}', [ContactListController::class, 'update'])->name('lists.update');
            Route::delete('lists/{list}', [ContactListController::class, 'destroy'])->name('lists.destroy');
            Route::post('lists/{list}/import', [ContactListController::class, 'import'])->name('lists.import');
            Route::delete('lists/{list}/entries/{entry}', [ContactListController::class, 'destroyEntry'])->name('lists.entries.destroy');
        });

        // Boletines
        Route::get('campaigns', [CampaignController::class, 'index'])->middleware('can:campaigns.view')->name('campaigns.index');
        Route::middleware('can:campaigns.create')->group(function () {
            Route::get('campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
            Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
            Route::post('campaigns/audience-count', [CampaignController::class, 'audienceCount'])->name('campaigns.audience-count');
            Route::post('campaigns/preview', [CampaignController::class, 'preview'])->name('campaigns.preview');
            Route::post('campaigns/test', [CampaignController::class, 'test'])->name('campaigns.test');
            Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('campaigns.edit');
            Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
            Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate'])->name('campaigns.duplicate');
            Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
        });
        Route::middleware('can:campaigns.send')->group(function () {
            Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send'])->name('campaigns.send');
            Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
            Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->name('campaigns.resume');
            Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('campaigns.cancel');
        });
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->middleware('can:campaigns.view')->name('campaigns.show');

        // Automatizaciones
        Route::middleware('can:automations.manage')->group(function () {
            Route::get('automations', [AutomationController::class, 'index'])->name('automations.index');
            Route::post('automations', [AutomationController::class, 'store'])->name('automations.store');
            Route::put('automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
            Route::delete('automations/{automation}', [AutomationController::class, 'destroy'])->name('automations.destroy');
        });

        // Historial de mensajes
        Route::middleware('can:email_logs.view')->group(function () {
            Route::get('messages', [EmailMessageController::class, 'index'])->name('messages.index');
            Route::get('messages/{message}', [EmailMessageController::class, 'show'])->name('messages.show');
        });

        Route::get('templates', [EmailTemplateController::class, 'index'])->middleware('can:templates.view')->name('templates.index');
        Route::post('templates/preview', [EmailTemplateController::class, 'preview'])->middleware('can:templates.view')->name('templates.preview');
        Route::middleware('can:templates.manage')->group(function () {
            Route::get('templates/create', [EmailTemplateController::class, 'create'])->name('templates.create');
            Route::post('templates', [EmailTemplateController::class, 'store'])->name('templates.store');
            Route::post('templates/test', [EmailTemplateController::class, 'test'])->name('templates.test');
            Route::post('templates/media', [EmailTemplateController::class, 'upload'])->name('templates.media');
            Route::put('templates/{template}', [EmailTemplateController::class, 'update'])->name('templates.update');
            Route::post('templates/{template}/duplicate', [EmailTemplateController::class, 'duplicate'])->name('templates.duplicate');
            Route::delete('templates/{template}', [EmailTemplateController::class, 'destroy'])->name('templates.destroy');
        });
        Route::get('templates/{template}/edit', [EmailTemplateController::class, 'edit'])->middleware('can:templates.view')->name('templates.edit');
    });

    // Inteligencia artificial: proveedores y API keys
    Route::prefix('ai')->middleware('can:ai.manage')->group(function () {
        Route::get('/', [AiSettingsController::class, 'index'])->name('ai.index');
        Route::get('usage', [AiUsageController::class, 'index'])->name('ai.usage');
        Route::put('usage/prices', [AiUsageController::class, 'prices'])->name('ai.usage.prices');
        Route::put('usage/budget', [AiUsageController::class, 'budget'])->name('ai.usage.budget');
        Route::put('settings', [AiSettingsController::class, 'settings'])->name('ai.settings.update');
        Route::put('tuning', [AiSettingsController::class, 'tuning'])->name('ai.tuning.update');
        Route::put('providers/{slug}', [AiSettingsController::class, 'update'])->name('ai.providers.update');
        Route::delete('providers/{slug}', [AiSettingsController::class, 'destroy'])->name('ai.providers.destroy');
        Route::post('providers/{slug}/default', [AiSettingsController::class, 'makeDefault'])->name('ai.providers.default');
        Route::post('providers/{slug}/test', [AiSettingsController::class, 'test'])->middleware('throttle:20,1')->name('ai.providers.test');
        Route::post('providers/{slug}/test-voice', [AiSettingsController::class, 'testVoice'])->middleware('throttle:20,1')->name('ai.providers.test-voice');
        Route::get('providers/{slug}/models', [AiSettingsController::class, 'models'])->middleware('throttle:20,1')->name('ai.providers.models');
    });

    // Inteligencia artificial: servidores MCP (herramientas externas del Agent)
    Route::prefix('ai/mcp')->name('ai.mcp.')->middleware('can:mcp.manage')->group(function () {
        Route::get('/', [McpController::class, 'index'])->name('index');
        Route::post('/', [McpController::class, 'store'])->name('store');
        Route::get('oauth/callback', [McpController::class, 'oauthCallback'])->name('oauth.callback');
        Route::put('{server}', [McpController::class, 'update'])->name('update');
        Route::delete('{server}', [McpController::class, 'destroy'])->name('destroy');
        Route::post('{server}/test', [McpController::class, 'test'])->middleware('throttle:20,1')->name('test');
        Route::put('{server}/tools', [McpController::class, 'tools'])->name('tools');
        Route::post('{server}/oauth/start', [McpController::class, 'oauthStart'])->middleware('throttle:20,1')->name('oauth.start');
        Route::post('{server}/oauth/disconnect', [McpController::class, 'oauthDisconnect'])->name('oauth.disconnect');
    });

    // Roles y permisos
    Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
    Route::get('roles/create', [RoleController::class, 'create'])->middleware('can:roles.create')->name('roles.create');
    Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.create')->name('roles.store');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->middleware('can:roles.view')->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.update')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.delete')->name('roles.destroy');
});

require __DIR__.'/settings.php';

Route::post('portal-preview/exit', [PortalAccessController::class, 'exitPreview'])->middleware('auth')->name('portal-preview.exit');

// Portal de clientes (host propio opcional: clientes.ecortes.cl). Solo accesos con rol «cliente» y empresa asignada.
Route::prefix('portal')->middleware(['auth', 'auth.session', 'portal'])->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'home'])->name('home');
    Route::get('propuestas', [PortalController::class, 'proposals_index'])->name('proposals');
    Route::get('servicios', [PortalController::class, 'services_index'])->name('services');
    Route::get('facturas', [PortalController::class, 'invoices_index'])->name('invoices');
    Route::get('facturas/{invoice}/pdf', [PortalController::class, 'invoicePdf'])->middleware('throttle:60,1')->name('invoices.pdf');
    Route::get('cuenta', [PortalController::class, 'account'])->name('account');
    Route::put('cuenta', [PortalController::class, 'updateProfile'])->name('account.update');
    Route::put('cuenta/password', [PortalController::class, 'updatePassword'])->middleware('throttle:6,1')->name('account.password');
});
