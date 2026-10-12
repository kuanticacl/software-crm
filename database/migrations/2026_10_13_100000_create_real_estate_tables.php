<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM inmobiliario: proyectos con sus ejecutivos, inventario de unidades (departamentos, casas, estacionamientos,
 * bodegas, quinchos…), cotizaciones que combinan unidades, y reservas con su plan de pagos (reserva, pie en cuotas
 * y saldo con crédito hipotecario).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->string('developer', 160)->nullable();          // inmobiliaria / desarrolladora
            $table->string('commune', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('sales_office', 300)->nullable();       // dirección de la sala de ventas
            $table->text('description')->nullable();
            $table->string('stage', 30)->default('en_blanco');     // en_blanco | en_verde | entrega_inmediata | terminado
            $table->date('delivery_date')->nullable();
            $table->string('delivery_note', 120)->nullable();      // «2° semestre 2027»
            $table->string('cover_path')->nullable();
            $table->string('website_url', 300)->nullable();
            $table->string('brochure_url', 300)->nullable();
            $table->string('color', 9)->default('#6419DB');
            // Condiciones comerciales por defecto (se pueden cambiar en cada cotización)
            $table->decimal('reservation_amount', 12, 2)->default(300000);   // monto de reserva en pesos
            $table->decimal('default_pie_pct', 5, 2)->default(20);
            $table->unsignedSmallInteger('default_pie_installments')->default(24);
            $table->decimal('max_discount_pct', 5, 2)->default(5);
            $table->decimal('mortgage_rate', 5, 2)->default(4.50);
            $table->unsignedTinyInteger('mortgage_years')->default(25);
            $table->unsignedSmallInteger('quote_validity_days')->default(7);
            $table->unsignedSmallInteger('promise_days')->default(30);        // plazo para firmar la promesa tras reservar
            $table->text('quote_conditions')->nullable();                     // texto legal/condiciones que va en la cotización
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('project_user', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_lead')->default(false);            // jefe/a de ventas del proyecto
            $table->primary(['project_id', 'user_id']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->default('departamento');  // departamento | casa | oficina | local | estacionamiento | bodega | quincho | otro
            $table->string('code', 40);                            // número de la unidad: 1204, E-15, B-03
            $table->string('tower', 40)->nullable();
            $table->string('model', 60)->nullable();               // tipología / modelo: A, B2, Casa Roble
            $table->smallInteger('floor')->nullable();
            $table->string('orientation', 20)->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->string('program', 60)->nullable();             // 3D+2B, 2D+1B, Estudio…
            $table->decimal('surface_useful', 8, 2)->nullable();   // m² útiles / interiores
            $table->decimal('surface_terrace', 8, 2)->nullable();
            $table->decimal('surface_total', 8, 2)->nullable();
            $table->decimal('surface_lot', 9, 2)->nullable();      // terreno (casas)
            $table->decimal('list_price', 12, 2)->default(0);      // precio de lista en UF
            $table->string('status', 20)->default('disponible');   // disponible | bloqueada | reservada | promesada | vendida
            $table->string('floor_plan_path')->nullable();
            $table->json('features')->nullable();                  // logia, vista, walk-in closet…
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['project_id', 'type', 'code']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft');        // draft | sent | viewed | reserved | expired | lost
            $table->json('buyer')->nullable();                     // nombre, RUT, correo y teléfono del cotizante (copia)
            $table->decimal('uf_value', 10, 2)->nullable();
            $table->date('uf_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->decimal('subtotal_uf', 12, 2)->default(0);
            $table->decimal('discount_uf', 12, 2)->default(0);
            $table->decimal('total_uf', 12, 2)->default(0);
            $table->decimal('pie_pct', 5, 2)->default(20);
            $table->decimal('pie_uf', 12, 2)->default(0);
            $table->decimal('reservation_amount', 12, 2)->default(0);   // en pesos
            $table->unsignedSmallInteger('pie_installments')->default(0);
            $table->decimal('credit_uf', 12, 2)->default(0);
            $table->decimal('mortgage_rate', 5, 2)->default(4.5);
            $table->unsignedTinyInteger('mortgage_years')->default(25);
            $table->decimal('monthly_payment_uf', 10, 2)->default(0);   // dividendo estimado
            $table->text('notes')->nullable();                          // visibles para el cliente
            $table->text('internal_notes')->nullable();
            $table->string('public_token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->string('lost_reason', 200)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'status']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('code', 40);
            $table->string('model', 60)->nullable();
            $table->string('detail', 300)->nullable();              // programa, superficies, piso (copia al cotizar)
            $table->decimal('list_price_uf', 12, 2)->default(0);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('price_uf', 12, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained();            // comprador
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('reserved');       // reserved | promised | deeded | cancelled
            $table->date('reserved_at');
            $table->date('promise_due_date')->nullable();
            $table->date('promised_at')->nullable();
            $table->date('deeded_at')->nullable();
            $table->date('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->decimal('uf_value', 10, 2)->nullable();
            $table->decimal('subtotal_uf', 12, 2)->default(0);
            $table->decimal('discount_uf', 12, 2)->default(0);
            $table->decimal('total_uf', 12, 2)->default(0);
            $table->decimal('pie_pct', 5, 2)->default(0);
            $table->decimal('pie_uf', 12, 2)->default(0);
            $table->decimal('credit_uf', 12, 2)->default(0);
            $table->string('financing', 30)->default('credito');      // credito | contado | subsidio | mixto
            $table->string('bank', 80)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'status']);
        });

        Schema::create('reservation_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained();
            $table->string('type', 30);
            $table->string('code', 40);
            $table->string('model', 60)->nullable();
            $table->decimal('list_price_uf', 12, 2)->default(0);
            $table->decimal('price_uf', 12, 2)->default(0);
        });

        Schema::create('reservation_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                              // reserva | pie | saldo | otro
            $table->unsignedSmallInteger('number')->default(0);      // n.º de cuota del pie
            $table->string('label', 120);
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('UF');            // UF | CLP
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('status', 20)->default('pending');        // pending | paid | cancelled
            $table->date('paid_at')->nullable();
            $table->decimal('paid_clp', 14, 2)->nullable();
            $table->string('method', 40)->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('notes', 300)->nullable();
            $table->timestamps();
            $table->index(['reservation_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['reservation_payments', 'reservation_units', 'reservations', 'quote_items', 'quotes', 'units', 'project_user', 'projects'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
