<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.users.index', [
            'users' => User::with('roles')
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$request->q}%")->orWhere('email', 'like', "%{$request->q}%")))
                ->orderBy('name')->paginate(25)->withQueryString(),
        ]);
    }

    public function create()
    {
        return view('admin.users.form', ['user' => new User(['is_active' => true]), 'roles' => config('casemix.roles')]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $user = User::create($data);
        $user->syncRoles([$data['role']]);
        Audit::log('admin.pengguna_buat', $user, "Membuat pengguna {$user->name} ({$data['role']})");

        return redirect()->route('admin.users.index')->with('ok', "Pengguna {$user->name} dibuat.");
    }

    public function edit(User $user)
    {
        return view('admin.users.form', ['user' => $user, 'roles' => config('casemix.roles')]);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        if ($user->is($request->user()) && (! $data['is_active'] || $data['role'] !== 'admin')) {
            return back()->with('warn', 'Anda tidak bisa menonaktifkan atau menurunkan peran akun sendiri.');
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        $user->syncRoles([$data['role']]);
        Audit::log('admin.pengguna_ubah', $user, "Mengubah pengguna {$user->name} (peran {$data['role']}, " . ($user->is_active ? 'aktif' : 'nonaktif') . ')');

        return redirect()->route('admin.users.index')->with('ok', "Pengguna {$user->name} disimpan.");
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($user?->id)],
            'nip' => ['nullable', 'string', 'max:40'],
            'role' => ['required', Rule::in(array_keys(config('casemix.roles')))],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
