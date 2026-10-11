<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpCall extends Model
{
    protected $guarded = [];

    public function server(): BelongsTo
    {
        return $this->belongsTo(McpServer::class, 'mcp_server_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
