<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Тестовые пользователи под каждую роль — для dev/QA.
 *
 * На проде после раскатки эти учётки удаляются или меняются пароли.
 * Email-домен @mylift.test намеренно нерабочий, чтобы не путать с реальными.
 */
class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        $defaultPassword = Hash::make('password');

        $users = [
            ['name' => 'Менеджер Иванов', 'email' => 'manager@mylift.test', 'role' => RoleEnum::Manager],
            ['name' => 'РОП Сидоров',     'email' => 'rop@mylift.test',     'role' => RoleEnum::HeadOfSales],
            ['name' => 'Секретарь Петрова', 'email' => 'secretary@mylift.test', 'role' => RoleEnum::Secretary],
            ['name' => 'Директор Кузнецов', 'email' => 'director@mylift.test', 'role' => RoleEnum::Director],
        ];

        foreach ($users as $row) {
            $user = User::firstOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'password' => $defaultPassword,
                ]
            );

            if (! $user->hasRole($row['role']->value)) {
                $user->assignRole($row['role']->value);
            }
        }
    }
}
