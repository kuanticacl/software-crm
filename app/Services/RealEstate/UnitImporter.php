<?php

namespace App\Services\RealEstate;

use App\Models\Project;
use App\Models\Unit;
use Illuminate\Support\Str;

/**
 * Importa el inventario de un proyecto desde CSV (Excel → «Guardar como CSV»). Crea o actualiza por tipo + número.
 * Separador «;» o «,»; decimales con coma o punto; encabezados flexibles (tildes y mayúsculas no importan).
 */
class UnitImporter
{
    /** Encabezado del archivo de ejemplo. */
    public const TEMPLATE = ['tipo', 'numero', 'torre', 'modelo', 'piso', 'orientacion', 'dormitorios', 'banos', 'programa', 'sup_util', 'sup_terraza', 'sup_total', 'sup_terreno', 'precio_uf', 'estado'];

    private const ALIASES = [
        'type' => ['tipo', 'tipo_unidad', 'producto'],
        'code' => ['numero', 'n', 'nro', 'codigo', 'unidad', 'depto', 'departamento', 'numero_unidad'],
        'tower' => ['torre', 'edificio', 'etapa'],
        'model' => ['modelo', 'tipologia', 'tipo_modelo'],
        'floor' => ['piso', 'nivel'],
        'orientation' => ['orientacion', 'orient'],
        'bedrooms' => ['dormitorios', 'dorm', 'dormitorio', 'd'],
        'bathrooms' => ['banos', 'bano', 'b'],
        'program' => ['programa'],
        'surface_useful' => ['sup_util', 'superficie_util', 'm2_util', 'util', 'sup_interior', 'superficie_interior'],
        'surface_terrace' => ['sup_terraza', 'superficie_terraza', 'terraza', 'm2_terraza'],
        'surface_total' => ['sup_total', 'superficie_total', 'total_m2', 'm2_total'],
        'surface_lot' => ['sup_terreno', 'superficie_terreno', 'terreno', 'm2_terreno'],
        'list_price' => ['precio_uf', 'precio', 'valor_uf', 'precio_lista', 'valor', 'uf'],
        'status' => ['estado', 'disponibilidad'],
    ];

    /** @return array{created: int, updated: int, skipped: int, errors: array<int, string>} */
    public function import(Project $project, string $path, bool $updatePrices = true): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;                         // BOM de Excel
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        if (count($lines) < 2) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['El archivo no tiene filas de unidades.']];
        }
        $sep = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $head = array_map(fn ($h) => Str::slug(trim((string) $h), '_'), str_getcsv($lines[0], $sep));
        $map = [];
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($head as $i => $h) {
                if (in_array($h, $aliases, true)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        if (! isset($map['code'])) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Falta la columna «numero» (número o código de la unidad).']];
        }

        $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        foreach (array_slice($lines, 1) as $n => $line) {
            if (trim($line) === '' || trim($line, $sep.' ') === '') {
                continue;
            }
            $cols = str_getcsv($line, $sep);
            $get = fn (string $f) => isset($map[$f]) ? trim((string) ($cols[$map[$f]] ?? '')) : '';
            $row = $n + 2;

            $code = $get('code');
            if ($code === '') {
                $res['skipped']++;
                $res['errors'][] = "Fila {$row}: sin número de unidad.";
                continue;
            }
            $type = $this->type($get('type'));
            $data = array_filter([
                'tower' => $get('tower') ?: null,
                'model' => $get('model') ?: null,
                'floor' => $this->int($get('floor')),
                'orientation' => $get('orientation') ?: null,
                'bedrooms' => $this->int($get('bedrooms')),
                'bathrooms' => $this->int($get('bathrooms')),
                'program' => $get('program') ?: null,
                'surface_useful' => $this->num($get('surface_useful')),
                'surface_terrace' => $this->num($get('surface_terrace')),
                'surface_total' => $this->num($get('surface_total')),
                'surface_lot' => $this->num($get('surface_lot')),
            ], fn ($v) => $v !== null);
            $price = $this->num($get('list_price'));
            $status = $this->status($get('status'));

            $unit = Unit::withTrashed()->where('project_id', $project->id)->where('type', $type)->where('code', $code)->first();
            if ($unit) {
                $unit->trashed() && $unit->restore();
                $unit->fill($data);
                if ($updatePrices && $price !== null) {
                    $unit->list_price = $price;
                }
                // El estado solo se cambia entre disponible y bloqueada: reservas y ventas se manejan desde el CRM.
                if ($status && in_array($unit->status, ['disponible', 'bloqueada'], true) && in_array($status, ['disponible', 'bloqueada'], true)) {
                    $unit->status = $status;
                }
                $unit->isDirty() ? $res['updated']++ : $res['skipped']++;
                $unit->save();
            } else {
                Unit::create([...$data, 'project_id' => $project->id, 'type' => $type, 'code' => $code, 'list_price' => $price ?? 0,
                    'status' => in_array($status, ['disponible', 'bloqueada'], true) ? $status : 'disponible']);
                $res['created']++;
            }
        }

        return $res;
    }

    private function type(string $v): string
    {
        $s = Str::slug($v, '_');

        return match (true) {
            $s === '' || str_starts_with($s, 'depto') || str_starts_with($s, 'dpto') || str_starts_with($s, 'depa') => 'departamento',
            str_starts_with($s, 'estac') || $s === 'est' || str_starts_with($s, 'parking') => 'estacionamiento',
            str_starts_with($s, 'bod') => 'bodega',
            str_starts_with($s, 'quinch') => 'quincho',
            str_starts_with($s, 'casa') => 'casa',
            str_starts_with($s, 'ofi') => 'oficina',
            str_starts_with($s, 'local') => 'local',
            array_key_exists($s, Unit::TYPES) => $s,
            default => 'otro',
        };
    }

    private function status(string $v): ?string
    {
        $s = Str::slug($v, '_');

        return match (true) {
            $s === '' => null,
            str_starts_with($s, 'disp') || $s === 'libre' => 'disponible',
            str_starts_with($s, 'bloq') => 'bloqueada',
            default => null,
        };
    }

    private function num(string $v): ?float
    {
        $v = preg_replace('/[^\d,.\-]/', '', $v) ?? '';
        if ($v === '') {
            return null;
        }
        // «3.450,50» → 3450.50 ; «3450.50» → 3450.50 ; «3,5» → 3.5
        if (str_contains($v, ',')) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        } elseif (substr_count($v, '.') > 1 || preg_match('/^-?\d{1,3}\.\d{3}$/', $v)) {
            $v = str_replace('.', '', $v);   // «3.450» en Chile son 3.450 (separador de miles)
        }

        return is_numeric($v) ? (float) $v : null;
    }

    private function int(string $v): ?int
    {
        $n = $this->num($v);

        return $n === null ? null : (int) round($n);
    }
}
