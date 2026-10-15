<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Responsables de un origen: reciben la alerta de cada cliente nuevo y se les asignan los clientes (uno o varios). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_source_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['lead_source_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_source_user');
    }
};
