<?php

namespace App\Services\Email;

use App\Models\Setting;

/** Lectura centralizada de la configuración de correo (ajustes del CRM con respaldo en .env). */
class MailSettings
{
    public static function provider(): string
    {
        $chosen = Setting::get('mail.provider');

        if ($chosen === 'log') {
            return 'log';
        }

        return self::apiKey() ? 'resend' : 'log';
    }

    public static function apiKey(): ?string
    {
        return Setting::get('mail.resend_api_key') ?: config('services.resend.key');
    }

    public static function webhookSecret(): ?string
    {
        return Setting::get('mail.webhook_secret') ?: config('services.resend.webhook_secret');
    }

    public static function fromEmail(): ?string
    {
        return Setting::get('mail.from_email') ?: config('mail.from.address');
    }

    public static function fromName(): string
    {
        return Setting::get('mail.from_name') ?: (config('mail.from.name') ?: 'INTEGRALEADS');
    }

    public static function replyTo(): ?string
    {
        return Setting::get('mail.reply_to') ?: null;
    }

    public static function companyName(): string
    {
        return Setting::get('mail.company_name') ?: 'INTEGRALEADS';
    }

    public static function footerAddress(): ?string
    {
        return Setting::get('mail.footer_address') ?: null;
    }

    /** "Nombre <correo@dominio>" listo para el campo from. */
    public static function fromHeader(?string $name = null, ?string $email = null): string
    {
        $email = $email ?: self::fromEmail();
        $name = $name ?: self::fromName();

        return $email ? "{$name} <{$email}>" : '';
    }
}
