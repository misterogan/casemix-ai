<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AccountPasswordController extends Controller
{
    public function edit()
    {
        return view('account.password');
    }

    public function update(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers(), 'different:current_password'],
        ], [
            'current_password.required' => 'Password saat ini wajib diisi.',
            'current_password.current_password' => 'Password saat ini salah.',
            'password.required' => 'Password baru wajib diisi.',
            'password.confirmed' => 'Ulangan password baru tidak cocok.',
            'password.min' => 'Password baru minimal :min karakter.',
            'password.letters' => 'Password baru harus berisi huruf.',
            'password.numbers' => 'Password baru harus berisi angka.',
            'password.different' => 'Password baru harus berbeda dari password lama.',
        ]);

        $user = $request->user();
        $user->forceFill(['password' => Hash::make($request->input('password'))])->save();
        $request->session()->regenerate();

        Audit::log('akun.ganti_password', $user, 'Mengganti password sendiri');

        return back()->with('ok', 'Password berhasil diganti.');
    }
}
