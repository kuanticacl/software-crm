<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes persona o empresa; servicios con modalidad (solo honorarios u honorarios + obra) y dirección del proyecto;
 * cobros con tipo de documento (boleta de honorarios con retención, factura u otro) y tipo de cobro (honorarios u obra).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('kind', 10)->default('person')->after('id'); // person | company
        });

        Schema::table('client_services', function (Blueprint $table) {
            $table->string('modality', 20)->default('honorarios')->after('billing_cycle'); // honorarios | honorarios_obra
            $table->decimal('works_budget', 14, 2)->nullable()->after('price'); // presupuesto de obra (informativo)
            $table->string('project_address', 255)->nullable()->after('description');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('document_type', 10)->default('boleta')->after('client_service_id'); // boleta | factura | otro
            $table->string('kind', 12)->default('honorarios')->after('document_type'); // honorarios | obra
            $table->decimal('retention_rate', 5, 2)->default(0)->after('tax_rate');
            $table->decimal('retention_amount', 14, 2)->default(0)->after('retention_rate');
            $table->boolean('retention_by_client')->default(true)->after('retention_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['document_type', 'kind', 'retention_rate', 'retention_amount', 'retention_by_client']));
        Schema::table('client_services', fn (Blueprint $t) => $t->dropColumn(['modality', 'works_budget', 'project_address']));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('kind'));
    }
};
