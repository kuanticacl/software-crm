<?php

use App\Support\Honorarios;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Propuestas en honorarios: el monto se habla neto y se le suma la retención de la boleta de honorarios
 * (el cliente paga el bruto de la boleta). El IVA queda como opción para casos puntuales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(0)->change();
            $table->string('tax_mode', 12)->default('honorarios')->after('tax_rate');
        });

        // Las ya enviadas o respondidas conservan lo que vio el cliente (con IVA o sin impuesto);
        // los borradores pasan a honorarios y se recalcula su retención.
        DB::table('proposals')->where('status', '!=', 'draft')->update(['tax_mode' => DB::raw("CASE WHEN tax_rate > 0 THEN 'iva' ELSE 'none' END")]);

        $rate = Honorarios::rate();
        $factor = 1 / (1 - $rate / 100);
        DB::table('proposals')->where('status', 'draft')->update([
            'tax_rate' => $rate,
            'total_gross' => DB::raw("ROUND(total_net * {$factor}, CASE WHEN currency = 'UF' THEN 2 ELSE 0 END)"),
        ]);
        DB::table('proposals')->where('status', 'draft')->update(['total_tax' => DB::raw('total_gross - total_net')]);
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('tax_mode');
        });
    }
};
