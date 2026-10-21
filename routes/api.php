<?php

use App\Http\Controllers\Api\AgentWebChatController;
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
});

/*
| AgentWeb, el ejecutivo virtual del sitio web. Misma API key por origen; límites propios para controlar el gasto de IA.
*/
Route::prefix('v1')->middleware([AuthenticateLeadSource::class, 'throttle:agentweb-chat'])->group(function () {
    Route::post('agentweb/chat', [AgentWebChatController::class, 'chat'])->name('api.agentweb.chat');
});

/*
| API de email: autenticada con API keys del CRM (Email → API de envío).
*/
Route::prefix('v1')->middleware('throttle:email-api')->group(function () {
    Route::post('emails/send', [EmailSendController::class, 'send'])->middleware(AuthenticateApiKey::class.':emails.send')->name('api.emails.send');
    Route::get('emails/{uuid}', [EmailSendController::class, 'show'])->middleware(AuthenticateApiKey::class.':emails.read')->name('api.emails.show');
});
