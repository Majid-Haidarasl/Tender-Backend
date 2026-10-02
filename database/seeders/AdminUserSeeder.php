<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admins = [
            [
                'username' => 'majidsupp',
                'password' => '@Majid3510@',
                'full_name' => 'پشتیبان سایت',
                'admin_role' => 'super_admin',
            ],
            [
                'username' => 'admin',
                'password' => 'admin123',
                'full_name' => 'مدیر سیستم',
                'admin_role' => 'super_admin',
            ],
        ];

        foreach ($admins as $adminData) {
            $existing = User::where('username', $adminData['username'])->first();

            if ($existing) {
                $existing->update([
                    'full_name' => $adminData['full_name'],
                    'role' => 'admin',
                    'admin_role' => $adminData['admin_role'],
                    'admin_permissions' => null,
                    'is_active' => true,
                ]);
                $existing->setPlainPassword($adminData['password']);
                $existing->save();
                $this->command->info("Admin user updated: {$adminData['username']}");
            } else {
                $user = new User([
                    'id' => (string) Str::uuid(),
                    'username' => $adminData['username'],
                    'full_name' => $adminData['full_name'],
                    'role' => 'admin',
                    'admin_role' => $adminData['admin_role'],
                    'is_active' => true,
                ]);
                $user->setPlainPassword($adminData['password']);
                $user->save();
                $this->command->info("Admin user created: {$adminData['username']}");
            }
        }
    }
}
