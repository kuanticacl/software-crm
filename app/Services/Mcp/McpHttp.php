<?php

namespace App\Services\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Cliente HTTP con todas las protecciones: SSRF, sin redirecciones, tiempo límite y tamaño máximo de respuesta. */
class McpHttp
{
    /** @param  array<string, string>  $headers */
    public static function send(string $method, string $url, array $headers = [], mixed $body = null, bool $json = true): Response
    {
        $g = UrlGuard::check($url);
        $req = Http::withHeaders($headers)
            ->timeout((int) config('mcp.timeout'))
            ->withOptions([
                'allow_redirects' => false,
                'curl' => $g['curl'],
                'on_headers' => function ($response) {
                    if ((int) $response->getHeaderLine('Content-Length') > config('mcp.max_response_bytes')) {
                        throw new McpException('La respuesta del servidor es demasiado grande.');
                    }
                },
            ]);
        if ($json) {
            $req = $req->asJson();
        } else {
            $req = $req->asForm();
        }

        try {
            $res = $req->send($method, $url, $body === null ? [] : ($json ? ['json' => $body] : ['form_params' => $body]));
        } catch (ConnectionException $e) {
            throw new McpException('No se pudo conectar con el servidor: '.self::clean($e->getMessage()));
        }

        if (strlen($res->body()) > config('mcp.max_response_bytes')) {
            throw new McpException('La respuesta del servidor es demasiado grande.');
        }
        if ($res->redirect()) {
            throw new McpException('El servidor respondió con una redirección; por seguridad no se siguen. Usa la URL final del endpoint MCP.');
        }

        return $res;
    }

    public static function clean(string $m): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $m), 0, 200);
    }
}
