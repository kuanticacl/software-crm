<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/** El asistente virtual del sitio pasa a llamarse «Agente web»: se copian los ajustes alfred.* → agentweb.* y el historial de consumo. Defensiva. */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $rows = DB::table('settings')->where('key', 'like', 'alfred.%')->get();
            foreach ($rows as $r) {
                $new = 'agentweb.'.substr($r->key, 7);
                if (! DB::table('settings')->where('key', $new)->exists()) {
                    DB::table('settings')->insert(['key' => $new, 'value' => $r->value, 'is_secret' => $r->is_secret, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('ai_runs')->where('feature', 'alfred')->update(['feature' => 'agentweb']);
            Cache::forget('settings.all');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function down(): void {}
};
