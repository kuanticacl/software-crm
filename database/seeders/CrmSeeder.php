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
                ['Nuevo contacto', '#B76E79', 'open', false],
                ['Primera conversación', '#8C7B75', 'open', false],
                ['Visita o levantamiento', '#C9A66B', 'open', false],
                ['Propuesta enviada', '#A9606B', 'open', true],
                ['Ganado', '#4F8F7A', 'won', true],
                ['Perdido', '#9A8C88', 'lost', false],
            ];

            foreach ($stages as $i => [$name, $color, $type, $needsProposal]) {
                PipelineStage::create(['name' => $name, 'color' => $color, 'type' => $type, 'sort_order' => $i, 'requires_proposal' => $needsProposal ?? false]);
            }
        }

        $this->call(ServiceSeeder::class);

        if (LeadSource::count() === 0) {
            $sources = [
                ['Sitio web', '#7D8FA3', 'globe', false],
                ['Instagram', '#B76E79', 'camera', false],
                ['Meta Ads', '#8C7B75', 'megaphone', false],
                ['Google Ads', '#4F8F7A', 'search', false],
                ['WhatsApp', '#25D366', 'message-circle', false],
                ['Referido', '#C9A66B', 'handshake', false],
                ['Manual', '#7A6A68', 'pencil', true],
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
