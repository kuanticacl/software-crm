<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use App\Models\PipelineStage;
use Illuminate\Database\Seeder;

class CrmSeeder extends Seeder
{
    /** Etapas y orígenes iniciales; luego se administran desde el CRM. */
    public function run(): void
    {
        if (PipelineStage::count() === 0) {
            $stages = [
                ['Ingreso', '#38BDF8', 'open', false],
                ['Contactado', '#6366F1', 'open', false],
                ['Demo agendada', '#F59E0B', 'open', false],
                ['Propuesta', '#027BB9', 'open', true],
                ['Taller activo', '#0D9F85', 'won', true],
                ['Descartado', '#8A98A8', 'lost', false],
            ];

            foreach ($stages as $i => [$name, $color, $type, $needsProposal]) {
                PipelineStage::create(['name' => $name, 'color' => $color, 'type' => $type, 'sort_order' => $i, 'requires_proposal' => $needsProposal ?? false]);
            }
        }

        $this->call(ServiceSeeder::class);
        \App\Services\Billing\BillingDefaults::install();

        if (LeadSource::count() === 0) {
            $sources = [
                ['Sitio web', '#38BDF8', 'globe', false],
                ['Landing page', '#6366F1', 'layout-template', false],
                ['Meta Ads', '#4A8CFF', 'megaphone', false],
                ['Google Ads', '#0D9F85', 'search', false],
                ['WhatsApp', '#25D366', 'message-circle', false],
                ['Referido', '#F59E0B', 'handshake', false],
                ['Manual', '#707070', 'pencil', true],
            ];

            foreach ($sources as $i => [$name, $color, $icon, $system]) {
                LeadSource::create([
                    'name' => $name,
                    'slug' => LeadSource::uniqueSlug($name),
                    'color' => $color,
                    'icon' => $icon,
                    'api_key' => LeadSource::generateKey(),
                    'is_system' => $system,
                    'sort_order' => $i,
                ]);
            }
        }
    }
}
