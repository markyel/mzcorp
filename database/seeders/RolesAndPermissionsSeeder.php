<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Создаёт 4 роли MyLift, описанные в Foundation §«Роли и права доступа».
     *
     * Permissions намеренно НЕ создаём на этом шаге — права контролируются
     * через Laravel Gates/Policies и проверки на роль (`hasRole`). Если
     * понадобятся гранулярные permissions — добавим адресно по фичам.
     */
    public function run(): void
    {
        // Сбросить кеш ролей spatie перед сидом, чтобы свежие записи были видны.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RoleEnum::cases() as $role) {
            Role::firstOrCreate([
                'name' => $role->value,
                'guard_name' => 'web',
            ]);
        }
    }
}
