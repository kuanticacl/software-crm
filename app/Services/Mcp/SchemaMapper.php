<?php

namespace App\Services\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/** Convierte el JSON Schema de una herramienta MCP a los tipos del SDK de IA (tolerante: lo desconocido pasa como texto JSON). */
class SchemaMapper
{
    /** @param  array<string, mixed>  $inputSchema  @return array<string, Type> */
    public static function map(JsonSchema $s, array $inputSchema): array
    {
        $required = (array) ($inputSchema['required'] ?? []);
        $out = [];
        foreach (array_slice((array) ($inputSchema['properties'] ?? []), 0, 40, true) as $name => $def) {
            if (! is_string($name) || ! is_array($def)) {
                continue;
            }
            $t = self::type($s, $def, 0);
            $out[$name] = in_array($name, $required, true) ? $t->required() : $t;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $d */
    private static function type(JsonSchema $s, array $d, int $depth): Type
    {
        $type = $d['type'] ?? null;
        if (is_array($type)) {
            $nonNull = array_values(array_diff($type, ['null']));
            $type = count($nonNull) === 1 ? $nonNull[0] : null;
        }

        $t = match (true) {
            $type === 'integer' => $s->integer(),
            $type === 'number' => $s->number(),
            $type === 'boolean' => $s->boolean(),
            $type === 'array' => self::arr($s, $d, $depth),
            $type === 'object' && $depth < 3 && ! empty($d['properties']) => $s->object(self::map($s, $d)),
            default => $s->string(),
        };
        if (! $type || ($type === 'object' && empty($d['properties']) ) || ($type === 'object' && $depth >= 3)) {
            $t = $s->string();
            $d['description'] = trim(($d['description'] ?? '').' (enviar como texto JSON)');
        }

        $desc = trim((string) ($d['description'] ?? $d['title'] ?? ''));
        if ($desc !== '') {
            $t->description(mb_substr($desc, 0, 500));
        }
        if (! empty($d['enum']) && is_array($d['enum']) && in_array($type, ['string', 'integer', 'number'], true)) {
            $t->enum(array_values($d['enum']));
        }

        return $t;
    }

    /** @param  array<string, mixed>  $d */
    private static function arr(JsonSchema $s, array $d, int $depth): Type
    {
        $a = $s->array();
        $items = is_array($d['items'] ?? null) ? $d['items'] : ['type' => 'string'];
        if ($depth < 3) {
            $a->items(self::type($s, $items, $depth + 1));
        } else {
            $a->items($s->string());
        }

        return $a;
    }
}
