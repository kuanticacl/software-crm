<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Services\Agent\DocumentReader;
use App\Services\Ai\AiGateway;
use App\Support\Agency;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;

/**
 * Lee el PDF de una factura (DTE chilena) con IA y propone los datos del cobro: folio, fechas, montos, receptor y concepto.
 * Nunca guarda nada: el formulario los muestra para que una persona los revise.
 */
class InvoiceExtractor
{
    public function __construct(private DocumentReader $reader, private AiGateway $ai) {}

    /**
     * @return array{fields: array<string, mixed>, client: array{id: int, name: string}|null, warnings: list<string>}
     */
    public function extract(UploadedFile $pdf): array
    {
        $doc = $this->reader->read($pdf); // lanza RuntimeException con un mensaje claro si no hay texto (PDF escaneado) o pesa mucho
        $agency = Agency::profile();

        $r = $this->ai->structured(
            'invoice_extract',
            "Extraes datos de boletas de honorarios y facturas chilenas para un sistema de cobranza. El EMISOR del documento es nuestra cliente ({$agency['legal_name']}, RUT {$agency['tax_id']}); el RECEPTOR es el cliente al que se cobra. Devuelve los datos del documento.\n"
            ."- «document_type»: «boleta» si es una boleta de honorarios (electrónica) y «factura» si es una factura.\n"
            ."- Usa SOLO lo que dice el documento; si un dato no aparece, déjalo vacío o null. No inventes.\n"
            ."- Fechas en formato YYYY-MM-DD (en Chile se escribe día-mes-año: 05-10-2026 es 5 de octubre).\n"
            ."- Montos como números sin separadores (los puntos son miles: 1.234.567 = 1234567; la coma es decimal).\n"
            ."- Boleta de honorarios: «amount_net» son los HONORARIOS BRUTOS (monto bruto), «retention_amount» es el «Impuesto retenido» (retención), «amount_total» el «Total líquido» o líquido a pagar; «tax_amount» es 0.\n"
            ."- Factura: «amount_net» es el monto neto antes de IVA, «tax_amount» el IVA y «amount_total» el total; «retention_amount» es 0.\n"
            ."- «folio» es el N° del documento. «concept» resume en una línea qué se cobra (a partir del detalle).\n"
            ."- La moneda es CLP salvo que el documento indique explícitamente UF.\n"
            .'- «paid_indicated» solo es true si el documento dice expresamente que está pagado/cancelado.',
            "Texto extraído del PDF:\n\n<documento>\n{$doc['text']}\n</documento>",
            fn (JsonSchema $s) => [
                'document_type' => $s->string()->enum(['boleta', 'factura'])->required(),
                'folio' => $s->string()->description('N° del documento')->required(),
                'issue_date' => $s->string()->description('Fecha de emisión YYYY-MM-DD')->required(),
                'due_date' => $s->string()->description('Fecha de vencimiento YYYY-MM-DD (vacío si no aparece)')->required(),
                'receiver_name' => $s->string()->description('Razón social del receptor')->required(),
                'receiver_tax_id' => $s->string()->description('RUT del receptor')->required(),
                'concept' => $s->string()->description('Resumen del detalle cobrado, una línea')->required(),
                'currency' => $s->string()->enum(['CLP', 'UF'])->required(),
                'amount_net' => $s->number()->description('Honorarios brutos (boleta) o monto neto sin IVA (factura)')->required(),
                'retention_amount' => $s->number()->description('Impuesto retenido de la boleta (0 si no aplica)')->required(),
                'tax_amount' => $s->number()->description('Monto del IVA (0 si no aparece)')->required(),
                'amount_total' => $s->number()->description('Total líquido (boleta) o total de la factura')->required(),
                'period_start' => $s->string()->description('Inicio del período facturado YYYY-MM-DD, si se indica')->required(),
                'period_end' => $s->string()->description('Fin del período facturado YYYY-MM-DD, si se indica')->required(),
                'paid_indicated' => $s->boolean()->required(),
            ],
            ['provider' => null],
        );

        return $this->normalize($r, $doc['truncated']);
    }

    /** @param  array<string, mixed>  $r */
    private function normalize(array $r, bool $truncated): array
    {
        $warnings = $truncated ? ['El PDF es muy largo: la IA leyó solo la primera parte.'] : [];
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
        $num = fn ($v) => is_numeric($v) ? (float) $v : null;

        $net = $num($r['amount_net'] ?? null);
        $tax = $num($r['tax_amount'] ?? null);
        $total = $num($r['amount_total'] ?? null);
        if ($net === null && $total !== null) {
            $net = ($r['document_type'] ?? 'boleta') === 'boleta' ? $total + (float) ($r['retention_amount'] ?? 0) : ($tax !== null ? $total - $tax : round($total / (1 + config('portal.tax_rate') / 100)));
            $warnings[] = 'No aparecía el monto neto: lo calculé a partir del total.';
        }
        $isBoleta = ($r['document_type'] ?? 'boleta') === 'boleta';
        $retention = $num($r['retention_amount'] ?? null) ?? 0.0;
        $retRate = $isBoleta && $net ? round($retention / $net * 100, 2) : null;
        $rate = ! $isBoleta && $net && $tax !== null ? round($tax / $net * 100, 2) : null;
        // Tasas raras = lectura dudosa: se deja la estándar y se avisa.
        if ($rate !== null && ! in_array((int) round($rate), [0, 19], true)) {
            $warnings[] = "El IVA leído ({$rate}%) no es 19% ni exento: revisa los montos.";
        }
        if ($isBoleta && $net !== null && $total !== null && abs($net - $retention - $total) > 2) {
            $warnings[] = 'Honorarios − retención no coincide con el líquido del PDF: revisa los montos.';
        }
        if (! $isBoleta && $net !== null && $total !== null && $tax !== null && abs($net + $tax - $total) > 2) {
            $warnings[] = 'Neto + IVA no coincide con el total del PDF: revisa los montos.';
        }

        $client = $this->matchClient((string) ($r['receiver_tax_id'] ?? ''), (string) ($r['receiver_name'] ?? ''));
        if (! $client && ($r['receiver_name'] ?? '') !== '') {
            $warnings[] = 'No encontré el cliente «'.$r['receiver_name'].'» en el CRM (RUT '.($r['receiver_tax_id'] ?: 'no leído').'): selecciónalo o créalo primero.';
        }

        $currency = ($r['currency'] ?? 'CLP') === 'UF' ? 'UF' : 'CLP';

        return [
            'fields' => array_filter([
                'number' => trim((string) ($r['folio'] ?? '')) ?: null,
                'issue_date' => $date($r['issue_date'] ?? null),
                'due_date' => $date($r['due_date'] ?? null),
                'concept' => trim((string) ($r['concept'] ?? '')) ?: null,
                'currency' => $currency,
                'amount_net' => $net,
                'document_type' => $isBoleta ? 'boleta' : 'factura',
                'tax_rate' => $rate !== null && in_array((int) round($rate), [0, 19], true) ? (int) round($rate) : null,
                'retention_rate' => $retRate !== null && $retRate > 0 ? $retRate : null,
                'period_start' => $date($r['period_start'] ?? null),
                'period_end' => $date($r['period_end'] ?? null),
                'paid_indicated' => ! empty($r['paid_indicated']),
                'receiver_name' => trim((string) ($r['receiver_name'] ?? '')) ?: null,
                'receiver_tax_id' => trim((string) ($r['receiver_tax_id'] ?? '')) ?: null,
            ], fn ($v) => $v !== null),
            'client' => $client ? ['id' => $client->id, 'name' => $client->name] : null,
            'warnings' => $warnings,
        ];
    }

    private function matchClient(string $rut, string $name): ?Client
    {
        $clean = fn (string $v) => strtoupper(preg_replace('/[^0-9kK]/', '', $v));
        $r = $clean($rut);

        if (strlen($r) >= 7) {
            $hit = Client::query()->whereNotNull('tax_id')->get(['id', 'name', 'tax_id'])->first(fn (Client $c) => $clean($c->tax_id) === $r);
            if ($hit) {
                return $hit;
            }
        }

        $name = trim($name);
        if (mb_strlen($name) >= 4) {
            $norm = fn (string $v) => mb_strtolower(preg_replace('/\b(spa|ltda|s\.a\.|sa|limitada|eirl)\b\.?/iu', '', $v));
            $needle = trim($norm($name));
            $matches = Client::query()->get(['id', 'name', 'legal_name'])->filter(fn (Client $c) => $needle !== '' && (trim($norm((string) $c->legal_name)) === $needle || trim($norm($c->name)) === $needle));

            return $matches->count() === 1 ? $matches->first() : null;
        }

        return null;
    }
}
