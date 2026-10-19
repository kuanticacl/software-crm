<?php

namespace App\Services\Email;

use App\Models\EmailMessage;
use App\Support\Agency;

/** Arma el email final de un mensaje: variables, preheader, baja, tracking y cabeceras. */
class EmailComposer
{
    public function __construct(private TemplateRenderer $renderer) {}

    /** @return array{subject: string, html: string, preheader: ?string, marketing: bool, name: ?string} */
    public function source(EmailMessage $m): array
    {
        $m->loadMissing(['campaign', 'template']);

        if ($m->campaign) {
            return [
                'subject' => $m->campaign->subject,
                'html' => (string) $m->campaign->html,
                'preheader' => $m->campaign->preheader,
                'marketing' => true,
                'name' => $m->campaign->name,
            ];
        }

        $t = $m->template;

        return [
            'subject' => $m->subject ?: (string) $t?->subject,
            'html' => (string) $t?->html,
            'preheader' => $t?->preheader,
            'marketing' => ($t?->category ?? 'transactional') === 'marketing',
            'name' => $t?->name,
        ];
    }

    /** @return array<string, mixed> */
    public function systemVariables(EmailMessage $m, ?string $campaignName = null): array
    {
        return [
            'unsubscribe_url' => route('unsubscribe.show', $m->uuid),
            'view_url' => route('email.view', $m->uuid),
            'current_year' => date('Y'),
            'company_name' => MailSettings::companyName(),
            'to_email' => $m->to_email,
            'to_name' => $m->to_name,
            'campaign_name' => $campaignName,
        ];
    }

    /**
     * Renderiza asunto y cuerpo (sin tracking) para una vista previa o "ver en el navegador".
     *
     * @param  array<string, mixed>  $vars
     * @return array{subject: string, html: string, missing: array<int, string>}
     */
    public function renderContent(string $subjectTpl, string $htmlTpl, array $vars, ?string $preheader = null): array
    {
        $subject = trim(html_entity_decode($this->renderer->render($subjectTpl, $vars), ENT_QUOTES | ENT_HTML5));
        $html = $this->renderer->render($htmlTpl, $vars);
        $missing = array_values(array_diff($this->renderer->missing(), TemplateRenderer::SYSTEM_VARIABLES));

        return ['subject' => $subject, 'html' => $this->withHoldingFooter($this->withPreheader($html, $preheader)), 'missing' => $missing];
    }

    public function build(EmailMessage $m): array
    {
        $src = $this->source($m);
        $vars = [...($m->variables ?? []), ...$this->systemVariables($m, $src['name'])];

        $content = $this->renderContent($src['subject'], $src['html'], $vars, $src['preheader']);
        $html = $content['html'];

        if ($src['marketing'] && ! str_contains($src['html'], 'unsubscribe_url')) {
            $html = $this->withUnsubscribeFooter($html, $vars['unsubscribe_url']);
        }

        $html = LinkTracker::apply($html, $m);

        $headers = [];
        if ($src['marketing']) {
            $headers['List-Unsubscribe'] = '<'.$vars['unsubscribe_url'].'>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        $email = new OutgoingEmail(
            from: MailSettings::fromHeader(null, $m->from_email),
            to: $m->to_name ? sprintf('%s <%s>', preg_replace('/[<>",]/', '', $m->to_name), $m->to_email) : $m->to_email,
            subject: $content['subject'],
            html: $html,
            replyTo: MailSettings::replyTo(),
            headers: $headers,
            tags: array_filter(['message_id' => (string) $m->id, 'kind' => $m->kind, 'campaign_id' => $m->campaign_id ? (string) $m->campaign_id : null]),
        );

        return ['email' => $email, 'subject' => $content['subject'], 'html' => $html, 'missing' => $content['missing']];
    }

    public function withPreheader(string $html, ?string $preheader): string
    {
        if (! $preheader || str_contains($html, 'data-preheader')) {
            return $html;
        }

        $block = '<div data-preheader style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">'.e($preheader).str_repeat('&#847;&zwnj;&nbsp;', 40).'</div>';

        return preg_match('/<body[^>]*>/i', $html)
            ? preg_replace('/(<body[^>]*>)/i', '$1'.$block, $html, 1)
            : $block.$html;
    }

    /** Pie común a todos los correos: «Parte del holding» con los logos de las marcas (igual que en la propuesta comercial). */
    public function withHoldingFooter(string $html): string
    {
        if (str_contains($html, 'data-holding') || Agency::holding() === []) {
            return $html;
        }

        $logos = collect(Agency::holding())->map(fn ($h) => '<td style="padding:0 10px"><a href="'.e($h['url']).'" target="_blank" style="text-decoration:none"><img src="'
            .e(url('/brand/partners/'.$h['logo'].'.png')).'" alt="'.e($h['name']).'" height="26" style="display:block;height:26px;width:auto;border:0"></a></td>')->implode('');

        $block = '<table data-holding role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:8px auto 24px"><tr><td align="center" style="font:11px Arial,sans-serif;letter-spacing:.08em;text-transform:uppercase;color:#9A9A9A;padding-bottom:8px">Parte del holding</td></tr>'
            .'<tr><td align="center"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'.$logos.'</tr></table></td></tr></table>';

        return stripos($html, '</body>') !== false
            ? preg_replace('/<\/body>/i', $block.'</body>', $html, 1)
            : $html.$block;
    }

    private function withUnsubscribeFooter(string $html, string $url): string
    {
        $address = MailSettings::footerAddress();
        $footer = '<div style="margin:24px auto;max-width:600px;padding:16px;text-align:center;font:12px/1.5 Arial,sans-serif;color:#8a8a8a">'
            .'Recibes este correo de '.e(MailSettings::companyName()).'.'
            .($address ? '<br>'.e($address) : '')
            .'<br><a href="'.e($url).'" style="color:#8a8a8a;text-decoration:underline">Darme de baja</a></div>';

        return stripos($html, '</body>') !== false
            ? preg_replace('/<\/body>/i', $footer.'</body>', $html, 1)
            : $html.$footer;
    }
}
