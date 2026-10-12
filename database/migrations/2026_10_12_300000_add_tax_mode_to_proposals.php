<?php

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
            $table->string('tax_mode', 12)->default('iva')->after('tax_rate');
        });

        // Las propuestas existentes conservan lo que se calculó: con IVA si lo tenían, sin impuesto si no.
        DB::table('proposals')->update(['tax_mode' => DB::raw("CASE WHEN tax_rate > 0 THEN 'iva' ELSE 'none' END")]);
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('tax_mode');
        });
    }
};
