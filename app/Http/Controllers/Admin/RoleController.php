<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function index()
    {
        return view('admin.users.roles', [
            'roles' => Role::with('permissions')->get()->keyBy('name'),
            'labels' => config('casemix.roles'),
            'permissions' => RolePermissionSeeder::PERMISSIONS,
        ]);
    }

    public function update(Request $request)
    {
        $matrix = $request->input('matrix', []);
        foreach (Role::all() as $role) {
            $perms = array_keys(array_filter($matrix[$role->name] ?? []));
            if ($role->name === 'admin' && ! in_array('admin.manage', $perms, true)) {
                $perms[] = 'admin.manage'; // jangan sampai tidak ada yang bisa mengelola sistem
            }
            $role->syncPermissions(array_intersect($perms, array_keys(RolePermissionSeeder::PERMISSIONS)));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Audit::log('admin.peran_ubah', null, 'Mengubah hak akses peran', ['matrix' => $matrix]);

        return back()->with('ok', 'Hak akses peran disimpan.');
    }
}
