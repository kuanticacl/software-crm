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
                ['Nuevo prospecto', '#2E90FA', 'open', false],
                ['Contactado', '#7A5AF8', 'open', false],
                ['Visita agendada', '#F79009', 'open', false],
                ['Cotizado', '#6419DB', 'open', true],
                ['Reservado', '#12B76A', 'open', true],
                ['Promesado / vendido', '#027A48', 'won', true],
                ['Perdido', '#98A2B3', 'lost', false],
            ];

            foreach ($stages as $i => [$name, $color, $type, $needsProposal]) {
                PipelineStage::create(['name' => $name, 'color' => $color, 'type' => $type, 'sort_order' => $i, 'requires_proposal' => $needsProposal ?? false]);
            }
        }


        if (LeadSource::count() === 0) {
            $sources = [
                ['Sitio web', '#6419DB', 'globe', false],
                ['Portal inmobiliario', '#F79009', 'building', false],
                ['Meta Ads', '#2E90FA', 'megaphone', false],
                ['Google Ads', '#12B76A', 'search', false],
                ['Sala de ventas', '#7A5AF8', 'store', false],
                ['WhatsApp', '#25D366', 'message-circle', false],
                ['Referido', '#565E74', 'handshake', false],
                ['Manual', '#565E74', 'pencil', true],
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
