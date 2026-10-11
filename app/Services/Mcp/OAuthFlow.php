<?php

namespace App\Services\Mcp;

use App\Models\McpServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** OAuth 2.1 para servidores MCP: descubrimiento (RFC 9728 / 8414), registro dinámico (RFC 7591), PKCE S256 y refresco de tokens. */
class OAuthFlow
{
    public static function redirectUri(): string
    {
        return route('ai.mcp.oauth.callback');
    }

    /** @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, registration_endpoint: string|null, scopes: list<string>} */
    public function discover(McpServer $server): array
    {
        $origin = $this->origin($server->url);
        $issuer = $origin;
        $scopes = [];

        // 1. Recurso protegido → servidor de autorización
        foreach ([$origin.'/.well-known/oauth-protected-resource'.(parse_url($server->url, PHP_URL_PATH) ?: ''), $origin.'/.well-known/oauth-protected-resource'] as $u) {
            $meta = $this->getJson($u);
            if ($meta && ! empty($meta['authorization_servers'][0])) {
                $issuer = rtrim((string) $meta['authorization_servers'][0], '/');
                $scopes = (array) ($meta['scopes_supported'] ?? []);
                break;
            }
        }

        // 2. Metadatos del servidor de autorización
        $host = $this->origin($issuer);
        $path = parse_url($issuer, PHP_URL_PATH) ?: '';
        $meta = null;
        foreach ([$host.'/.well-known/oauth-authorization-server'.$path, $host.'/.well-known/openid-configuration'.$path, $issuer.'/.well-known/openid-configuration'] as $u) {
            $meta = $this->getJson($u);
            if ($meta && ! empty($meta['authorization_endpoint']) && ! empty($meta['token_endpoint'])) {
                break;
            }
            $meta = null;
        }
        if (! $meta) {
            throw new McpException('No se encontró la configuración OAuth del servidor. Si el servicio usa API key, cambia el tipo de autenticación.');
        }
        if (! in_array('S256', (array) ($meta['code_challenge_methods_supported'] ?? ['S256']), true)) {
            throw new McpException('El servidor OAuth no soporta PKCE S256; por seguridad no se puede conectar.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'registration_endpoint'] as $k) {
            if (! empty($meta[$k])) {
                UrlGuard::check((string) $meta[$k]); // los endpoints descubiertos también pasan por la protección SSRF
            }
        }

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => (string) $meta['authorization_endpoint'],
            'token_endpoint' => (string) $meta['token_endpoint'],
            'registration_endpoint' => $meta['registration_endpoint'] ?? null,
            'scopes' => array_values(array_map('strval', $scopes ?: (array) ($meta['scopes_supported'] ?? []))),
        ];
    }

    /** Descubre y, si hace falta, se registra dinámicamente como cliente. Deja los datos guardados. */
    public function prepare(McpServer $server): void
    {
        $meta = $this->discover($server);
        $server->oauth_meta = $meta;

        if (blank($server->oauth_client_id)) {
            if (! $meta['registration_endpoint']) {
                $server->save();
                throw new McpException('Este servidor no permite registro automático. Ingresa el Client ID (y secreto) que te dio el proveedor.');
            }
            $res = McpHttp::send('POST', $meta['registration_endpoint'], ['Accept' => 'application/json'], [
                'client_name' => config('app.name').' (Agent)',
                'redirect_uris' => [self::redirectUri()],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'token_endpoint_auth_method' => 'none',
            ]);
            $j = $res->json();
            if (! $res->successful() || empty($j['client_id'])) {
                $server->save();
                throw new McpException('El servidor rechazó el registro automático. Ingresa el Client ID manualmente.');
            }
            $server->oauth_client_id = $j['client_id'];
            $server->oauth_client_secret = $j['client_secret'] ?? null;
        }
        $server->save();
    }

    /** URL a la que se redirige al usuario para autorizar. */
    public function start(McpServer $server, int $userId): string
    {
        if (empty($server->oauth_meta['authorization_endpoint']) || blank($server->oauth_client_id)) {
            $this->prepare($server);
        }
        $meta = $server->oauth_meta;

        $state = Str::random(48);
        $verifier = Str::random(96);
        Cache::put("mcp.oauth.{$state}", ['server' => $server->id, 'user' => $userId, 'verifier' => $verifier], now()->addMinutes(10));

        $q = [
            'response_type' => 'code',
            'client_id' => $server->oauth_client_id,
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'resource' => $server->url,
        ];
        $scope = trim((string) ($server->oauth_scopes ?: implode(' ', $meta['scopes'] ?? [])));
        if ($scope !== '') {
            $q['scope'] = $scope;
        }

        $base = $meta['authorization_endpoint'];

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query($q);
    }

    /** Intercambia el código por tokens. Devuelve el servidor conectado. */
    public function callback(string $state, string $code, int $userId): McpServer
    {
        $data = Cache::pull("mcp.oauth.{$state}");
        if (! $data || (int) $data['user'] !== $userId) {
            throw new McpException('La autorización venció o no corresponde a tu sesión. Inténtalo de nuevo.');
        }
        $server = McpServer::findOrFail($data['server']);
        $this->token($server, [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri(),
            'client_id' => $server->oauth_client_id, 'code_verifier' => $data['verifier'], 'resource' => $server->url,
        ]);

        return $server;
    }

    /** Token vigente; lo refresca si está por vencer. */
    public function freshToken(McpServer $server): ?string
    {
        if (blank($server->oauth_access_token) && blank($server->oauth_refresh_token)) {
            return null;
        }
        if ($server->oauth_expires_at && $server->oauth_expires_at->subSeconds(60)->isPast()) {
            if (blank($server->oauth_refresh_token)) {
                throw new McpException('La conexión OAuth venció. Vuelve a conectar la cuenta.', 401);
            }
            $this->token($server, ['grant_type' => 'refresh_token', 'refresh_token' => $server->oauth_refresh_token, 'client_id' => $server->oauth_client_id, 'resource' => $server->url]);
        }

        return $server->oauth_access_token;
    }

    /** @param  array<string, string>  $form */
    private function token(McpServer $server, array $form): void
    {
        $endpoint = $server->oauth_meta['token_endpoint'] ?? throw new McpException('Falta la configuración OAuth.');
        if (filled($server->oauth_client_secret)) {
            $form['client_secret'] = $server->oauth_client_secret;
        }
        $res = McpHttp::send('POST', $endpoint, ['Accept' => 'application/json'], $form, json: false);
        $j = $res->json();
        if (! $res->successful() || empty($j['access_token'])) {
            if (($form['grant_type'] ?? '') === 'refresh_token') {
                $server->forceFill(['oauth_access_token' => null, 'oauth_refresh_token' => null, 'oauth_expires_at' => null])->save();
            }
            throw new McpException('No se pudo obtener el token: '.mb_substr((string) ($j['error_description'] ?? $j['error'] ?? 'HTTP '.$res->status()), 0, 160), 401);
        }
        $server->forceFill([
            'oauth_access_token' => $j['access_token'],
            'oauth_refresh_token' => $j['refresh_token'] ?? $server->oauth_refresh_token,
            'oauth_expires_at' => isset($j['expires_in']) ? now()->addSeconds((int) $j['expires_in']) : null,
        ])->save();
    }

    public function disconnect(McpServer $server): void
    {
        $server->forceFill(['oauth_access_token' => null, 'oauth_refresh_token' => null, 'oauth_expires_at' => null])->save();
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);

        return $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }

    /** @return array<string, mixed>|null */
    private function getJson(string $url): ?array
    {
        try {
            $res = McpHttp::send('GET', $url, ['Accept' => 'application/json']);
        } catch (McpException) {
            return null;
        }

        return $res->successful() && is_array($j = $res->json()) ? $j : null;
    }
}
