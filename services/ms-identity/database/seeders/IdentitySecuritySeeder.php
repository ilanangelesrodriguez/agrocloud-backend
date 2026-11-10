<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;

class IdentitySecuritySeeder extends Seeder
{
    public function run(): void
    {
        $this->call([SecurityAndCompanySeeder::class, InspectorWorkflowSeeder::class, OperationalPermissionsSeeder::class]);
        foreach (['suscripciones.ver', 'suscripciones.gestionar'] as $code) {
            $permission = Permiso::firstOrCreate(['codigo' => $code], ['nombre' => $code, 'modulo' => 'SUSCRIPCIONES', 'estado' => true]);
            foreach (['SUPERADMIN', 'ADMIN'] as $role) {
                Rol::where('codigo', $role)->firstOrFail()->permisos()->syncWithoutDetaching([$permission->getKey()]);
            }
        }
    }
}
