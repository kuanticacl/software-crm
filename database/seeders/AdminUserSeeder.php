<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Usuario administrador inicial. Se configura con ADMIN_EMAIL, ADMIN_NAME y
     * ADMIN_PASSWORD en .env (en producción la contraseña es obligatoria).
     */
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD', app()->isProduction() ? null : 'password');

        if (! $password) {
            $this->command?->warn('ADMIN_PASSWORD no definido: no se creó el usuario administrador.');

            return;
        }

        User::firstOrCreate(['email' => env('ADMIN_EMAIL', 'admin@viviendaenregla.cl')], [
            'name' => env('ADMIN_NAME', 'Administrador Vivienda en Regla'),
            'password' => $password,
            'role_id' => Role::where('slug', config('permissions.admin_role'))->value('id'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
