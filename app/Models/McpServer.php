<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Servidor MCP remoto. Credenciales y tokens cifrados; nunca se envían al navegador. */
class McpServer extends Model
{
    protected $guarded = [];

    protected $hidden = ['api_key', 'oauth_client_secret', 'oauth_access_token', 'oauth_refresh_token'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'oauth_client_id' => 'encrypted',
            'oauth_client_secret' => 'encrypted',
            'oauth_access_token' => 'encrypted',
            'oauth_refresh_token' => 'encrypted',
            'oauth_meta' => 'array',
            'oauth_expires_at' => 'datetime',
            'is_enabled' => 'boolean',
            'require_confirmation' => 'boolean',
            'tools' => 'array',
            'enabled_tools' => 'array',
            'write_tools' => 'array',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    public function calls(): HasMany
    {
        return $this->hasMany(McpCall::class);
    }

    public function isConnected(): bool
    {
        return match ($this->auth_type) {
            'oauth' => filled($this->oauth_access_token) || filled($this->oauth_refresh_token),
            'api_key' => filled($this->api_key),
            default => true,
        };
    }

    public function keyHint(): ?string
    {
        return filled($this->api_key) ? '••••'.substr($this->api_key, -4) : null;
    }
}
