<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Servidores MCP remotos que el Agent puede usar como herramientas, y el registro de sus llamadas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 40)->unique();
            $table->string('url', 500);
            $table->string('auth_type', 12)->default('none'); // none | api_key | oauth
            $table->string('api_key_header', 80)->nullable();
            $table->text('api_key')->nullable(); // cifrada
            $table->string('api_key_prefix', 30)->nullable(); // p. ej. "Bearer "
            $table->text('oauth_client_id')->nullable();
            $table->text('oauth_client_secret')->nullable(); // cifrada
            $table->string('oauth_scopes', 500)->nullable();
            $table->json('oauth_meta')->nullable(); // endpoints descubiertos
            $table->text('oauth_access_token')->nullable(); // cifrada
            $table->text('oauth_refresh_token')->nullable(); // cifrada
            $table->timestamp('oauth_expires_at')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('require_confirmation')->default(true); // pedir confirmación en herramientas que modifican
            $table->json('tools')->nullable(); // catálogo descubierto
            $table->json('enabled_tools')->nullable(); // lista blanca (nombres)
            $table->json('write_tools')->nullable(); // nombres que requieren confirmación siempre
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mcp_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcp_server_id')->constrained('mcp_servers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tool', 120);
            $table->text('arguments')->nullable();
            $table->string('status', 12); // ok | error | denied
            $table->text('summary')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
            $table->index(['mcp_server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_calls');
        Schema::dropIfExists('mcp_servers');
    }
};
