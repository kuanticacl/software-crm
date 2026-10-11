<?php

namespace App\Services\Mcp;

use RuntimeException;

class McpException extends RuntimeException
{
    /** @param  array<string, mixed>  $context */
    public function __construct(string $message, public readonly int $status = 0, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public function needsAuth(): bool
    {
        return $this->status === 401;
    }
}
