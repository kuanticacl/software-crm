<?php

namespace App\Services\RealEstate;

use App\Models\Quote;
use Dompdf\Dompdf;
use Dompdf\Options;

/** PDF de la cotización (dompdf, sin navegador): portada del proyecto, unidades, forma de pago y plantas. */
class QuotePdf
{
    public function render(Quote $q): string
    {
        $dir = storage_path('app/dompdf');
        @mkdir($dir, 0775, true);

        $options = new Options;
        // Las plantas viven en el disco público (storage/app/public).
        $options->set('chroot', [base_path('public'), base_path('resources'), storage_path('app/public')]);
        $options->set('fontDir', $dir);
        $options->set('fontCache', $dir);
        $options->set('tempDir', $dir);
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'Open Sans');
        $options->set('dpi', 96);

        $html = view('realestate.quote-pdf', QuoteView::data($q) + ['fonts' => resource_path('fonts'), 'public' => public_path()])->render();

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('Open Sans', 'normal');
        $h = $canvas->get_height();
        $canvas->page_text(34, $h - 28, 'Cotización '.$q->number.' · '.$q->project?->name.' · integraleads.cl', $font, 7.5, [0.55, 0.55, 0.55]);
        $canvas->page_text($canvas->get_width() - 92, $h - 28, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7.5, [0.55, 0.55, 0.55]);

        return $pdf->output();
    }
}
