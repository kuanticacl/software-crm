<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\McpCall;
use App\Models\McpServer;
use App\Services\Mcp\McpClient;
use App\Services\Mcp\McpException;
use App\Services\Mcp\OAuthFlow;
use App\Services\Mcp\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Configuración de servidores MCP (herramientas externas del Agent) con API key u OAuth. */
class McpController extends Controller
{
    public function index(): Response
    {
        $servers = McpServer::orderBy('name')->get()->map(fn (McpServer $s) => [
            'id' => $s->id, 'name' => $s->name, 'url' => $s->url, 'auth_type' => $s->auth_type,
            'api_key_header' => $s->api_key_header, 'api_key_prefix' => $s->api_key_prefix, 'has_key' => filled($s->api_key), 'key_masked' => $s->keyHint(),
            'oauth_client_id' => $s->oauth_client_id, 'has_client_secret' => filled($s->oauth_client_secret), 'oauth_scopes' => $s->oauth_scopes,
            'oauth_connected' => filled($s->oauth_access_token) || filled($s->oauth_refresh_token), 'oauth_expires_at' => $s->oauth_expires_at?->toIso8601String(),
            'is_enabled' => $s->is_enabled, 'require_confirmation' => $s->require_confirmation,
            'tools' => collect($s->tools ?? [])->map(fn ($t) => [
                'name' => $t['name'], 'description' => $t['description'], 'read_only' => $t['readOnly'], 'destructive' => $t['destructive'],
                'enabled' => in_array($t['name'], (array) $s->enabled_tools, true), 'confirm' => in_array($t['name'], (array) $s->write_tools, true),
            ])->values(),
            'connected' => $s->isConnected(),
            'last_tested_at' => $s->last_tested_at?->toIso8601String(), 'last_test_ok' => $s->last_test_ok, 'last_test_message' => $s->last_test_message,
        ]);

        return Inertia::render('ai/Mcp', [
            'servers' => $servers,
            'redirectUri' => OAuthFlow::redirectUri(),
            'calls' => McpCall::with(['server:id,name', 'user:id,name'])->latest()->limit(40)->get()->map(fn (McpCall $c) => [
                'id' => $c->id, 'server' => $c->server?->name, 'user' => $c->user?->name, 'tool' => $c->tool, 'status' => $c->status,
                'summary' => $c->summary, 'arguments' => $c->arguments, 'ms' => $c->duration_ms, 'at' => $c->created_at->toIso8601String(),
            ]),
            'allowPrivate' => (bool) config('mcp.allow_private'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $server = new McpServer($this->fill($data));
        $server->slug = $this->uniqueSlug($data['name']);
        $server->created_by = $request->user()->id;
        $server->is_enabled = true;
        $server->require_confirmation = true;
        $server->save();

        $this->toast('Servidor agregado. Ahora conéctalo y prueba para descubrir sus herramientas.');

        return back();
    }

    public function update(Request $request, McpServer $server): RedirectResponse
    {
        $data = $this->validated($request, $server);
        $urlChanged = $data['url'] !== $server->url;
        $server->fill($this->fill($data, $server));
        if ($urlChanged || $data['auth_type'] !== $server->getOriginal('auth_type')) {
            // otro destino o método: no se reutilizan credenciales ni catálogo anteriores
            $server->fill(['oauth_meta' => null, 'oauth_access_token' => null, 'oauth_refresh_token' => null, 'oauth_expires_at' => null, 'tools' => null, 'enabled_tools' => [], 'write_tools' => [], 'last_test_ok' => null, 'last_test_message' => null]);
            if ($urlChanged && $data['auth_type'] === 'oauth' && blank($data['oauth_client_id'] ?? null)) {
                $server->oauth_client_id = null;
                $server->oauth_client_secret = null;
            }
        }
        if (isset($data['is_enabled'])) {
            $server->is_enabled = (bool) $data['is_enabled'];
        }
        if (isset($data['require_confirmation'])) {
            $server->require_confirmation = (bool) $data['require_confirmation'];
        }
        $server->save();

        $this->toast('Servidor actualizado.');

        return back();
    }

    public function destroy(McpServer $server): RedirectResponse
    {
        $server->delete();
        $this->toast('Servidor eliminado junto con sus credenciales.');

        return back();
    }

    /** Conecta, inicializa y descubre las herramientas del servidor. */
    public function test(McpServer $server): JsonResponse
    {
        $t0 = microtime(true);
        $client = new McpClient($server);
        try {
            $info = $client->initialize();
            $tools = $client->listTools();
        } catch (McpException $e) {
            $server->forceFill(['last_tested_at' => now(), 'last_test_ok' => false, 'last_test_message' => mb_substr($e->getMessage(), 0, 480)])->save();

            return response()->json(['ok' => false, 'message' => $e->getMessage(), 'needs_auth' => $e->needsAuth()], 422);
        } finally {
            $client->close();
        }

        $known = collect($server->tools ?? [])->pluck('name')->all();
        $enabled = (array) $server->enabled_tools;
        $confirm = (array) $server->write_tools;
        foreach ($tools as $t) {
            if (! in_array($t['name'], $known, true)) {
                // Valores seguros por defecto: solo lectura queda habilitada; el resto la decide una persona.
                if ($t['readOnly'] === true) {
                    $enabled[] = $t['name'];
                } else {
                    $confirm[] = $t['name'];
                }
            }
        }
        $names = collect($tools)->pluck('name')->all();
        $msg = "{$info['name']}".($info['version'] ? " v{$info['version']}" : '').' · '.count($tools).' herramientas';
        $server->forceFill([
            'tools' => $tools,
            'enabled_tools' => array_values(array_intersect($enabled, $names)),
            'write_tools' => array_values(array_intersect($confirm, $names)),
            'last_tested_at' => now(), 'last_test_ok' => true, 'last_test_message' => $msg,
        ])->save();

        return response()->json(['ok' => true, 'message' => $msg, 'ms' => (int) ((microtime(true) - $t0) * 1000)]);
    }

    /** Qué herramientas puede usar el Agent y cuáles piden confirmación. */
    public function tools(Request $request, McpServer $server): RedirectResponse
    {
        $names = collect($server->tools ?? [])->pluck('name')->all();
        $data = $request->validate([
            'enabled' => ['array'], 'enabled.*' => ['string', 'in:'.implode(',', $names ?: ['_'])],
            'confirm' => ['array'], 'confirm.*' => ['string', 'in:'.implode(',', $names ?: ['_'])],
        ]);
        $server->update(['enabled_tools' => array_values($data['enabled'] ?? []), 'write_tools' => array_values($data['confirm'] ?? [])]);
        $this->toast('Herramientas actualizadas.');

        return back();
    }

    // ---------------------------------------------------------------- OAuth

    public function oauthStart(Request $request, McpServer $server, OAuthFlow $oauth): JsonResponse
    {
        abort_unless($server->auth_type === 'oauth', 422, 'Este servidor no usa OAuth.');
        try {
            return response()->json(['url' => $oauth->start($server->fresh(), $request->user()->id)]);
        } catch (McpException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function oauthCallback(Request $request, OAuthFlow $oauth): RedirectResponse
    {
        if ($request->filled('error')) {
            $this->toast('El proveedor canceló o rechazó la autorización: '.Str::limit((string) $request->input('error_description', $request->input('error')), 120), 'error');

            return redirect()->route('ai.mcp.index');
        }
        try {
            $server = $oauth->callback((string) $request->query('state'), (string) $request->query('code'), $request->user()->id);
            $this->toast("«{$server->name}» conectado. Pulsa «Probar» para descubrir sus herramientas.");
        } catch (McpException $e) {
            $this->toast($e->getMessage(), 'error');
        }

        return redirect()->route('ai.mcp.index');
    }

    public function oauthDisconnect(McpServer $server, OAuthFlow $oauth): RedirectResponse
    {
        $oauth->disconnect($server);
        $this->toast('Cuenta desconectada; se borraron los tokens.');

        return back();
    }

    // ---------------------------------------------------------------- utilidades

    /** @return array<string, mixed> */
    private function validated(Request $request, ?McpServer $server = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'url' => ['required', 'url:http,https', 'max:500', function ($a, $v, $fail) {
                try {
                    UrlGuard::check($v);
                } catch (McpException $e) {
                    $fail($e->getMessage());
                }
            }],
            'auth_type' => ['required', 'in:none,api_key,oauth'],
            'api_key_header' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9\-_]+$/'],
            'api_key_prefix' => ['nullable', 'string', 'max:30', 'regex:/^[\x20-\x7E]*$/'],
            'api_key' => ['nullable', 'string', 'max:2000', 'regex:/^[\x21-\x7E ]+$/'],
            'oauth_client_id' => ['nullable', 'string', 'max:500'],
            'oauth_client_secret' => ['nullable', 'string', 'max:1000'],
            'oauth_scopes' => ['nullable', 'string', 'max:500'],
            'is_enabled' => ['boolean'],
            'require_confirmation' => ['boolean'],
        ]);
        if ($data['auth_type'] === 'api_key' && blank($data['api_key'] ?? null) && ! filled($server?->api_key)) {
            throw ValidationException::withMessages(['api_key' => 'Ingresa la API key.']);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $d  @return array<string, mixed> */
    private function fill(array $d, ?McpServer $server = null): array
    {
        $out = ['name' => $d['name'], 'url' => $d['url'], 'auth_type' => $d['auth_type']];
        if ($d['auth_type'] === 'api_key') {
            $out['api_key_header'] = $d['api_key_header'] ?: 'Authorization';
            $out['api_key_prefix'] = $d['api_key_prefix'] ?? '';
            if (filled($d['api_key'] ?? null)) {
                $out['api_key'] = trim($d['api_key']);
            }
        } else {
            $out += ['api_key' => null, 'api_key_header' => null, 'api_key_prefix' => null];
        }
        if ($d['auth_type'] === 'oauth') {
            $out['oauth_scopes'] = $d['oauth_scopes'] ?? null;
            if (filled($d['oauth_client_id'] ?? null)) {
                $out['oauth_client_id'] = trim($d['oauth_client_id']);
            }
            if (filled($d['oauth_client_secret'] ?? null)) {
                $out['oauth_client_secret'] = trim($d['oauth_client_secret']);
            }
        } else {
            $out += ['oauth_client_id' => null, 'oauth_client_secret' => null, 'oauth_scopes' => null];
        }

        return $out;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_') ?: 'mcp', 30, '');
        $slug = $base;
        for ($i = 2; McpServer::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'_'.$i;
        }

        return $slug;
    }
}
