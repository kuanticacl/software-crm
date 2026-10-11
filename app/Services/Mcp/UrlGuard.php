<?php

namespace App\Services\Mcp;

/**
 * Protección SSRF: solo URLs https públicas. Resuelve el DNS una vez, rechaza IPs privadas / loopback / link-local /
 * metadata de la nube y fija la IP validada para la conexión (evita DNS rebinding).
 */
class UrlGuard
{
    /**
     * @return array{url: string, curl: array<int, mixed>} URL normalizada y opciones curl con la IP fijada
     */
    public static function check(string $url): array
    {
        $p = parse_url(trim($url));
        if (! $p || empty($p['host']) || empty($p['scheme'])) {
            throw new McpException('La URL no es válida.');
        }
        $scheme = strtolower($p['scheme']);
        $allowPrivate = (bool) config('mcp.allow_private');
        if (! in_array($scheme, $allowPrivate ? ['http', 'https'] : ['https'], true)) {
            throw new McpException('Solo se permiten servidores MCP con https://.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new McpException('La URL no puede incluir usuario o contraseña; usa la autenticación del servidor.');
        }

        $host = trim(strtolower($p['host']), '[]');
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = self::resolve($host);
        if (! $ips) {
            throw new McpException("No se pudo resolver el dominio «{$host}».");
        }
        if (! $allowPrivate) {
            foreach ($ips as $ip) {
                if (! self::isPublic($ip)) {
                    throw new McpException('Esa dirección apunta a una red privada o interna y está bloqueada por seguridad.');
                }
            }
        }

        $curl = [];
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            $curl[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$ips[0]}"];
        }

        return ['url' => $url, 'curl' => $curl];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $out = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            $out[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }
        if (! $out && ($v4 = gethostbynamel($host))) {
            $out = $v4;
        }

        return array_values(array_filter($out));
    }

    public static function isPublic(string $ip): bool
    {
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && ! self::isCgnat($ip) && ! str_starts_with(strtolower($ip), 'fe80') && ! str_starts_with(strtolower($ip), 'fc') && ! str_starts_with(strtolower($ip), 'fd');
    }

    /** 100.64.0.0/10 (carrier-grade NAT). */
    private static function isCgnat(string $ip): bool
    {
        $long = ip2long($ip);

        return $long !== false && ($long & 0xFFC00000) === 0x64400000;
    }
}
