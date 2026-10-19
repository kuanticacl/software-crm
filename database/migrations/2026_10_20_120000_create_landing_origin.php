<?php

use App\Models\LeadSource;
use Illuminate\Database\Migrations\Migration;

/**
 * Origen «Landing MecaniQ»: lo usa el sitio (formulario de demo, boletín y Scarlett). Su API key puede fijarse con la
 * variable LANDING_API_KEY (para entregarla a quien integra el sitio); si no existe, se genera una. Defensiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $key = (string) env('LANDING_API_KEY', '');
            $source = LeadSource::where('slug', 'landing-mecaniq')->first();

            if (! $source) {
                LeadSource::create([
                    'name' => 'Landing MecaniQ', 'slug' => 'landing-mecaniq', 'color' => '#027BB9', 'icon' => 'layout-template',
                    'api_key' => strlen($key) >= 32 ? $key : LeadSource::generateKey(), 'sort_order' => 19,
                ]);
            } elseif (strlen($key) >= 32 && $source->api_key !== $key) {
                $source->update(['api_key' => $key]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function down(): void {}
};
