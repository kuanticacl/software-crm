<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Planes con límites configurables, suscripciones (mensual, trimestral, anual), cobros y la clave de cliente (customer_id) para las apps. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limit_types', function (Blueprint $t) {
            $t->id();
            $t->string('key', 60)->unique();
            $t->string('name', 120);
            $t->string('unit', 60)->nullable();
            $t->string('description', 300)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('slug', 60)->unique();
            $t->string('tagline', 200)->nullable();
            $t->text('description')->nullable();
            $t->json('features')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_public')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('plan_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $t->string('period', 12); // monthly | quarterly | annual
            $t->unsignedBigInteger('amount'); // neto en CLP por período
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['plan_id', 'period']);
        });

        Schema::create('plan_limits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $t->foreignId('limit_type_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('value')->nullable(); // null = ilimitado
            $t->timestamps();
            $t->unique(['plan_id', 'limit_type_id']);
        });

        Schema::table('clients', function (Blueprint $t) {
            $t->string('customer_id', 40)->nullable()->unique()->after('id');
        });
        DB::table('clients')->whereNull('customer_id')->orderBy('id')->each(
            fn ($c) => DB::table('clients')->where('id', $c->id)->update(['customer_id' => 'cus_'.Str::lower(Str::random(16))])
        );

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->string('uid', 40)->unique();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained();
            $t->foreignId('proposal_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->string('period', 12);
            $t->unsignedBigInteger('amount'); // neto por período (tras descuento)
            $t->string('currency', 3)->default('CLP');
            $t->decimal('tax_rate', 5, 2)->default(19);
            $t->string('status', 16)->default('active'); // active | past_due | suspended | cancelled | expired
            $t->boolean('auto_renew')->default(true);
            $t->date('started_at');
            $t->date('current_period_start');
            $t->date('current_period_end');
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 300)->nullable();
            $t->timestamp('suspended_at')->nullable();
            $t->json('limit_overrides')->nullable();
            $t->longText('contract_text')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'current_period_end']);
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('concept', 255);
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->string('currency', 3)->default('CLP');
            $t->unsignedBigInteger('amount_net');
            $t->decimal('tax_rate', 5, 2)->default(19);
            $t->unsignedBigInteger('amount_tax')->default(0);
            $t->unsignedBigInteger('amount_total');
            $t->date('issued_at');
            $t->date('due_date');
            $t->string('status', 10)->default('pending'); // pending | paid | void
            $t->timestamp('paid_at')->nullable();
            $t->string('payment_method', 40)->nullable();
            $t->string('payment_reference', 120)->nullable();
            $t->string('payment_link', 500)->nullable();
            $t->string('document_number', 40)->nullable(); // folio de la factura tributaria
            $t->text('notes')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['client_id', 'status']);
            $t->index(['status', 'due_date']);
        });

        Schema::create('automation_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $t->string('dedupe_key', 120);
            $t->timestamp('sent_at')->useCurrent();
            $t->unique(['automation_id', 'dedupe_key']);
        });

        Schema::table('automations', function (Blueprint $t) {
            $t->smallInteger('offset_days')->nullable()->after('delay_minutes');
        });

        Schema::table('proposals', function (Blueprint $t) {
            $t->foreignId('plan_id')->nullable()->after('lead_id')->constrained()->nullOnDelete();
            $t->string('plan_period', 12)->nullable()->after('plan_id');
            $t->boolean('auto_renew')->default(true)->after('plan_period');
            $t->date('start_date')->nullable()->after('auto_renew');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('plan_id');
            $t->dropColumn(['plan_period', 'auto_renew', 'start_date']);
        });
        Schema::table('automations', fn (Blueprint $t) => $t->dropColumn('offset_days'));
        Schema::dropIfExists('automation_deliveries');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('customer_id'));
        Schema::dropIfExists('plan_limits');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('limit_types');
    }
};
