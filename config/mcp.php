<?php

return [
    // Solo para desarrollo: permite servidores MCP en localhost / redes privadas. En producción debe quedar en false.
    'allow_private' => (bool) env('MCP_ALLOW_PRIVATE', false),
    // Exigir HTTPS (se relaja solo cuando allow_private=true).
    'timeout' => (int) env('MCP_TIMEOUT', 25),
    'max_response_bytes' => 2 * 1024 * 1024,
    'max_tool_output_chars' => 12000,
    'max_tools_per_server' => 60,
    'protocol_version' => '2025-06-18',
];
