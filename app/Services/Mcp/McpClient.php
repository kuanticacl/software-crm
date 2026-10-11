<?php

namespace App\Services\Mcp;

use App\Models\McpServer;
use Illuminate\Http\Client\Response;

/** Cliente MCP (transporte «Streamable HTTP», JSON-RPC 2.0). Sin stdio: solo servidores remotos. */
class McpClient
{
    private ?string $sessionId = null;

    private bool $ready = false;

    private int $seq = 0;

    public function __construct(private McpServer $server) {}

    /** @return array{name: string, version: string|null, protocol: string|null} */
    public function initialize(): array
    {
        $res = $this->rpc('initialize', [
            'protocolVersion' => config('mcp.protocol_version'),
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'crm-agent', 'version' => '1.0'],
        ], init: true);
        $this->ready = true;
        $this->notify('notifications/initialized');

        return [
            'name' => (string) ($res['serverInfo']['name'] ?? 'MCP'),
            'version' => $res['serverInfo']['version'] ?? null,
            'protocol' => $res['protocolVersion'] ?? null,
        ];
    }

    /** @return list<array{name: string, description: string, schema: array<string, mixed>, readOnly: bool|null, destructive: bool|null}> */
    public function listTools(): array
    {
        $this->ensure();
        $tools = [];
        $cursor = null;
        for ($i = 0; $i < 5; $i++) {
            $res = $this->rpc('tools/list', $cursor ? ['cursor' => $cursor] : (object) []);
            foreach ($res['tools'] ?? [] as $t) {
                if (! is_array($t) || ! preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', (string) ($t['name'] ?? ''))) {
                    continue;
                }
                $tools[] = [
                    'name' => $t['name'],
                    'description' => mb_substr((string) ($t['description'] ?? ($t['title'] ?? '')), 0, 1500),
                    'schema' => is_array($t['inputSchema'] ?? null) ? $t['inputSchema'] : ['type' => 'object', 'properties' => (object) []],
                    'readOnly' => $t['annotations']['readOnlyHint'] ?? null,
                    'destructive' => $t['annotations']['destructiveHint'] ?? null,
                ];
            }
            $cursor = $res['nextCursor'] ?? null;
            if (! $cursor || count($tools) >= config('mcp.max_tools_per_server')) {
                break;
            }
        }

        return array_slice($tools, 0, (int) config('mcp.max_tools_per_server'));
    }

    /** @param  array<string, mixed>  $args  @return array{text: string, isError: bool} */
    public function callTool(string $name, array $args): array
    {
        $this->ensure();
        $res = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $args]);
        $parts = [];
        foreach ($res['content'] ?? [] as $c) {
            $parts[] = match ($c['type'] ?? '') {
                'text' => (string) ($c['text'] ?? ''),
                'resource' => (string) ($c['resource']['text'] ?? '[recurso '.($c['resource']['uri'] ?? '').']'),
                'image', 'audio' => '['.$c['type'].' omitido]',
                default => '',
            };
        }
        if (! $parts && isset($res['structuredContent'])) {
            $parts[] = json_encode($res['structuredContent'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return ['text' => trim(implode("\n", array_filter($parts))), 'isError' => (bool) ($res['isError'] ?? false)];
    }

    public function close(): void
    {
        if ($this->sessionId) {
            try {
                McpHttp::send('DELETE', $this->server->url, $this->headers());
            } catch (\Throwable) {
            }
        }
    }

    private function ensure(): void
    {
        if (! $this->ready) {
            $this->initialize();
        }
    }

    private function notify(string $method): void
    {
        try {
            McpHttp::send('POST', $this->server->url, $this->headers(), ['jsonrpc' => '2.0', 'method' => $method]);
        } catch (\Throwable) {
        }
    }

    /** @return array<string, mixed> */
    private function rpc(string $method, array|object $params, bool $init = false): array
    {
        $id = ++$this->seq;
        $res = McpHttp::send('POST', $this->server->url, $this->headers(), ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);

        if ($res->status() === 401) {
            throw new McpException('El servidor rechazó las credenciales (401). Reconecta o revisa la API key.', 401, ['www_authenticate' => $res->header('WWW-Authenticate')]);
        }
        if ($res->status() === 403) {
            throw new McpException('El servidor denegó el acceso (403): la cuenta conectada no tiene permiso.', 403);
        }
        if ($res->status() === 404 && $this->sessionId) {
            throw new McpException('La sesión MCP expiró; vuelve a intentarlo.', 404);
        }
        if (! $res->successful()) {
            throw new McpException('El servidor respondió HTTP '.$res->status().': '.mb_substr(trim(strip_tags($res->body())), 0, 160), $res->status());
        }
        if ($init && ($sid = $res->header('Mcp-Session-Id'))) {
            $this->sessionId = $sid;
        }

        $msg = $this->extract($res, $id);
        if (isset($msg['error'])) {
            throw new McpException('Error MCP: '.mb_substr((string) ($msg['error']['message'] ?? 'desconocido'), 0, 300), (int) ($msg['error']['code'] ?? 0));
        }

        return is_array($msg['result'] ?? null) ? $msg['result'] : [];
    }

    /** Respuesta JSON simple o flujo SSE: devuelve el mensaje que responde a la petición. @return array<string, mixed> */
    private function extract(Response $res, int $id): array
    {
        $body = $res->body();
        $type = strtolower($res->header('Content-Type'));
        if (str_contains($type, 'text/event-stream')) {
            $found = null;
            foreach (preg_split('/\R\R/', $body) as $event) {
                $data = '';
                foreach (preg_split('/\R/', $event) as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $data .= ltrim(substr($line, 5), ' ');
                    }
                }
                $j = $data !== '' ? json_decode($data, true) : null;
                if (is_array($j) && ($j['id'] ?? null) === $id && (isset($j['result']) || isset($j['error']))) {
                    $found = $j;
                }
            }
            if ($found) {
                return $found;
            }
            throw new McpException('El servidor no devolvió una respuesta válida.');
        }

        $j = json_decode($body, true);
        if (! is_array($j)) {
            throw new McpException('El servidor no respondió JSON-RPC válido (¿es la URL del endpoint MCP?).');
        }
        if (array_is_list($j)) {
            foreach ($j as $m) {
                if (is_array($m) && ($m['id'] ?? null) === $id) {
                    return $m;
                }
            }
            throw new McpException('Respuesta MCP incompleta.');
        }

        return $j;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $h = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => (string) config('mcp.protocol_version'), 'User-Agent' => 'CRM-Agent-MCP/1.0'];
        if ($this->sessionId) {
            $h['Mcp-Session-Id'] = $this->sessionId;
        }
        if ($this->server->auth_type === 'api_key' && filled($this->server->api_key)) {
            $header = $this->server->api_key_header ?: 'Authorization';
            $h[$header] = ($this->server->api_key_prefix ?? '').$this->server->api_key;
        } elseif ($this->server->auth_type === 'oauth') {
            $token = (new OAuthFlow)->freshToken($this->server);
            if ($token) {
                $h['Authorization'] = 'Bearer '.$token;
            }
        }

        return $h;
    }
}
