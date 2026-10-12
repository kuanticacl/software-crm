<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Models\EmailMessage;
use App\Models\Setting;
use App\Services\Email\CampaignRunner;
use App\Services\Email\MailSettings;
use App\Services\Email\OutgoingEmail;
use App\Services\Email\ProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailSettingsController extends Controller
{
    public function edit(): Response
    {
        $key = MailSettings::apiKey();

        return Inertia::render('email/Settings', [
            'settings' => [
                'provider' => MailSettings::provider(),
                'forced_log' => Setting::get('mail.provider') === 'log',
                'has_api_key' => (bool) $key,
                'api_key_hint' => $key ? '••••'.substr($key, -4) : null,
                'api_key_source' => Setting::has('mail.resend_api_key') ? 'crm' : ($key ? 'env' : null),
                'has_webhook_secret' => (bool) MailSettings::webhookSecret(),
                'from_name' => MailSettings::fromName(),
                'from_email' => MailSettings::fromEmail(),
                'reply_to' => MailSettings::replyTo(),
                'company_name' => MailSettings::companyName(),
                'footer_address' => MailSettings::footerAddress(),
            ],
            'webhookUrl' => url('/webhooks/resend'),
            'appUrl' => config('app.url'),
            'events' => ['email.sent', 'email.delivered', 'email.delivery_delayed', 'email.bounced', 'email.complained', 'email.opened', 'email.clicked', 'email.failed'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:resend,log'],
            'resend_api_key' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'clear_api_key' => ['boolean'],
            'clear_webhook_secret' => ['boolean'],
            'from_name' => ['nullable', 'string', 'max:100'],
            'from_email' => ['nullable', 'email:rfc', 'max:255'],
            'reply_to' => ['nullable', 'email:rfc', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:100'],
            'footer_address' => ['nullable', 'string', 'max:255'],
        ]);

        Setting::put('mail.provider', $data['mode']);
        Setting::put('mail.from_name', $data['from_name'] ?? null);
        Setting::put('mail.from_email', $data['from_email'] ?? null);
        Setting::put('mail.reply_to', $data['reply_to'] ?? null);
        Setting::put('mail.company_name', $data['company_name'] ?? null);
        Setting::put('mail.footer_address', $data['footer_address'] ?? null);

        if (! empty($data['resend_api_key'])) {
            Setting::put('mail.resend_api_key', trim($data['resend_api_key']), true);
        } elseif (! empty($data['clear_api_key'])) {
            Setting::put('mail.resend_api_key', null, true);
        }

        if (! empty($data['webhook_secret'])) {
            Setting::put('mail.webhook_secret', trim($data['webhook_secret']), true);
        } elseif (! empty($data['clear_webhook_secret'])) {
            Setting::put('mail.webhook_secret', null, true);
        }

        $this->toast('Configuración de email guardada.');

        return back();
    }

    /** Envía un correo de prueba directo (sin plantilla) para validar la conexión con Resend. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'email:rfc']]);

        if (! MailSettings::fromEmail()) {
            return response()->json(['ok' => false, 'error' => 'Define primero el correo remitente.'], 422);
        }

        $provider = CampaignRunner::provider();
        $email = new OutgoingEmail(
            from: MailSettings::fromHeader(),
            to: $data['to'],
            subject: 'Prueba de conexión · '.MailSettings::companyName(),
            html: '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;padding:24px"><h2 style="color:#6419DB">¡Funciona!</h2><p>Este es un correo de prueba enviado desde el CRM de '.e(MailSettings::companyName()).' usando <strong>'.e($provider->name()).'</strong>.</p></div>',
        );

        $message = EmailMessage::create(['kind' => 'test', 'to_email' => $data['to'], 'from_email' => MailSettings::fromEmail(), 'subject' => $email->subject, 'status' => 'sending', 'html' => $email->html]);

        try {
            $id = $provider->send($email);
            $message->update(['status' => 'sent', 'provider' => $provider->name(), 'provider_id' => $id, 'sent_at' => now()]);

            return response()->json(['ok' => true, 'provider' => $provider->name(), 'id' => $id]);
        } catch (ProviderException $e) {
            $message->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
