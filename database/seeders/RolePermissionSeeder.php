<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'claims.view' => 'Melihat berkas klaim',
        'claims.edit' => 'Membuat dan mengubah berkas, mengunggah dokumen, mengisi daftar cek',
        'claims.review' => 'Menjalankan pemeriksaan AI',
        'claims.import' => 'Mengimpor dokumen dari FTP SIMRS',
        'claims.approve' => 'Menyetujui berkas',
        'claims.reopen' => 'Membuka kembali berkas yang sudah disetujui',
        'reports.download' => 'Mengunduh laporan dan ekspor',
        'audit.view' => 'Melihat riwayat aktivitas',
        'admin.manage' => 'Mengelola pengguna, peran, penyedia AI, dan pengaturan',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (array_keys(self::PERMISSIONS) as $p) {
            Permission::findOrCreate($p, 'web');
        }

        $map = [
            'admin' => ['admin.manage', 'claims.view', 'audit.view', 'reports.download'],
            'koordinator' => ['claims.view', 'claims.edit', 'claims.review', 'claims.import', 'claims.approve', 'claims.reopen', 'reports.download', 'audit.view'],
            'petugas' => ['claims.view', 'claims.edit', 'claims.review', 'claims.import', 'reports.download'],
            'viewer' => ['claims.view', 'reports.download', 'audit.view'],
        ];

        foreach ($map as $role => $perms) {
            Role::findOrCreate($role, 'web')->syncPermissions($perms);
        }
    }
}
