<?php

namespace App\Services\Proposals;

use App\Models\Proposal;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Genera el PDF de una propuesta en el servidor (sin navegador), con la identidad de Vivienda en Regla. */
class ProposalPdf
{
    public function render(Proposal $p): string
    {
        $dir = storage_path('app/dompdf');
        @mkdir($dir, 0775, true);

        $options = new Options;
        $options->set('chroot', [base_path('public'), base_path('resources')]);
        $options->set('fontDir', $dir);
        $options->set('fontCache', $dir);
        $options->set('tempDir', $dir);
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'Open Sans');
        $options->set('dpi', 96);

        $d = ProposalView::data($p);
        $html = view('proposals.pdf', $d + ['fonts' => resource_path('fonts'), 'public' => public_path()])->render();

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        // Pie de página en todas las hojas.
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('Open Sans', 'normal');
        $w = $canvas->get_width();
        $h = $canvas->get_height();
        $canvas->page_text(36, $h - 28, 'Propuesta '.$p->number.' · '.($d['company']).' · viviendaenregla.cl', $font, 7.5, [0.55, 0.55, 0.55]);
        $canvas->page_text($w - 90, $h - 28, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7.5, [0.55, 0.55, 0.55]);

        return $pdf->output();
    }
}
