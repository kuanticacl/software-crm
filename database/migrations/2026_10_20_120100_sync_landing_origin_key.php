<?php

use App\Models\LeadSource;
use Illuminate\Database\Migrations\Migration;

/** Aplica LANDING_API_KEY al origen «Landing MecaniQ» si la variable se definió después de crearlo. Defensiva. */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $key = (string) env('LANDING_API_KEY', '');
            if (strlen($key) >= 32) {
                LeadSource::where('slug', 'landing-mecaniq')->where('api_key', '!=', $key)->update(['api_key' => $key]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function down(): void {}
};
