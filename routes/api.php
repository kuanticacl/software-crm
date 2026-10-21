<?php

use App\Http\Controllers\Api\AgentWebChatController;
use App\Http\Controllers\Api\BillingApiController;
use App\Http\Controllers\Api\EmailSendController;
use App\Http\Controllers\Api\LeadIngestController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateLeadSource;
use Illuminate\Support\Facades\Route;

/*
| API de ingreso de leads. La API key (por origen) se envía en el header X-Api-Key.
*/
Route::prefix('v1')->middleware([AuthenticateLeadSource::class, 'throttle:lead-ingest'])->group(function () {
    Route::post('leads', [LeadIngestController::class, 'store'])->name('api.leads.store');
    Route::get('agentweb/config', [AgentWebChatController::class, 'config'])->name('api.agentweb.config');
    // Alias de las rutas anteriores (sitios ya integrados)
    Route::get('alfred/config', [AgentWebChatController::class, 'config']);
    Route::get('scarlett/config', [AgentWebChatController::class, 'config']);
});

/*
| Agente web, asistente virtual del sitio web. Misma API key por origen; límites propios para controlar el gasto de IA.
*/
Route::prefix('v1')->middleware([AuthenticateLeadSource::class, 'throttle:agentweb-chat'])->group(function () {
    Route::post('agentweb/chat', [AgentWebChatController::class, 'chat'])->name('api.agentweb.chat');
    Route::post('alfred/chat', [AgentWebChatController::class, 'chat']);
    Route::post('scarlett/chat', [AgentWebChatController::class, 'chat']);
});

/*
| API de email: autenticada con API keys del CRM (Email → API de envío).
*/
Route::prefix('v1')->middleware('throttle:email-api')->group(function () {
    Route::post('emails/send', [EmailSendController::class, 'send'])->middleware(AuthenticateApiKey::class.':emails.send')->name('api.emails.send');
    Route::get('emails/{uuid}', [EmailSendController::class, 'show'])->middleware(AuthenticateApiKey::class.':emails.read')->name('api.emails.show');
});

/*
| API de suscripciones y cobros para la app móvil y el sitio. El `customer_id` (cus_…) de la empresa es la llave que
| asocia el tenant en cualquier plataforma. Autenticada con API keys del CRM con el permiso billing.read / billing.write.
*/
Route::prefix('v1')->middleware('throttle:billing-api')->group(function () {
    Route::middleware(AuthenticateApiKey::class.':billing.read')->group(function () {
        Route::get('plans', [BillingApiController::class, 'plans'])->name('api.plans');
        Route::get('customers/{customer}', [BillingApiController::class, 'customer'])->name('api.customers.show');
        Route::get('customers/{customer}/subscription', [BillingApiController::class, 'subscription'])->name('api.customers.subscription');
        Route::get('customers/{customer}/limits', [BillingApiController::class, 'limits'])->name('api.customers.limits');
        Route::get('customers/{customer}/balance', [BillingApiController::class, 'balance'])->name('api.customers.balance');
        Route::get('customers/{customer}/invoices', [BillingApiController::class, 'invoices'])->name('api.customers.invoices');
        Route::get('customers/{customer}/invoices/{number}', [BillingApiController::class, 'invoice'])->name('api.customers.invoice');
    });
    Route::post('customers/{customer}/invoices/{number}/payments', [BillingApiController::class, 'pay'])->middleware(AuthenticateApiKey::class.':billing.write')->name('api.customers.pay');
});
