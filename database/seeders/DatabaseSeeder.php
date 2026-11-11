<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $password = env('CASEMIX_ADMIN_PASSWORD') ?: Str::password(16);

        $admin = User::firstOrCreate(
            ['email' => env('CASEMIX_ADMIN_EMAIL', 'admin@rs.local')],
            ['name' => 'Administrator', 'password' => $password, 'is_active' => true]
        );
        $admin->syncRoles(['admin']);

        if ($admin->wasRecentlyCreated) {
            $this->command->warn("Akun admin: {$admin->email} / $password  (segera ganti setelah masuk)");
        }
    }
}
